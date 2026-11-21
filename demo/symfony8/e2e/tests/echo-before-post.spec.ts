import { test, expect, type Page } from '@playwright/test';

/**
 * 1.34.1 — the realtime echo of a message just sent arrives before the send response (201). The line must end up
 * sent, once, with no "Sending…" left (1.32–1.34.0 kept the pending line forever).
 *
 * The demo runs without a Mercure hub, so Alice's browser gets a scripted stand-in (as in resync.spec.ts) that can
 * deliver an event on demand. The send request is held by a route: the server saves the message, the test delivers
 * its echo, waits for the window to paint it, and only then lets the response through.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-platform')).toContainText(username);
}

/** A live connection the test feeds (`window.__chatHub.emit(event)`). */
async function withScriptedHub(page: Page): Promise<void> {
  await page.route('**/api/realtime', (route) =>
    route.fulfill({
      json: { enabled: true, hubUrl: '/e2e-hub', token: 'e2e', topic: 'e2e', expiresAt: new Date(Date.now() + 3_600_000).toISOString() },
    }),
  );
  await page.addInitScript(() => {
    type Handler = ((event?: unknown) => void) | null;
    const sources: { url: string; onopen: Handler; onmessage: Handler; closed: boolean }[] = [];
    class ScriptedEventSource {
      onopen: Handler = null;
      onmessage: Handler = null;
      onerror: Handler = null;
      closed = false;
      readonly url: string;
      constructor(url: string | URL) {
        this.url = String(url);
        sources.push(this);
        window.setTimeout(() => this.onopen?.(), 50);
      }
      close(): void {
        this.closed = true;
      }
    }
    Object.defineProperty(window, 'EventSource', { value: ScriptedEventSource, writable: false, configurable: false });
    (window as unknown as { __chatHub: unknown }).__chatHub = {
      emit: (event: unknown) =>
        // Only the dock's connection (the demo's hot reload opens one too).
        sources.filter((source) => !source.closed && source.url.includes('/e2e-hub')).forEach((source) => source.onmessage?.({ data: JSON.stringify(event) })),
    };
  });
}

test.describe('echo before the send response (1.34.1)', () => {
  test('the message ends up sent, exactly once, when its echo beats the 201', async ({ page }) => {
    await withScriptedHub(page);
    await signIn(page, 'alice');
    await page.locator('.nowo-chat-launcher').click();
    await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    const thread = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    await expect(thread).toBeVisible();
    await expect(page.locator('.is-live')).toHaveCount(1);

    const text = `Echo first ${Date.now()}`;
    let echoed = false;
    await page.route(
      (url) => /\/conversations\/[0-9a-f-]{36}\/messages$/.test(url.pathname),
      async (route) => {
        if (route.request().method() !== 'POST') {
          await route.fallback();
          return;
        }
        // The server saves the message; its response is held until the echo is on screen.
        const response = await route.fetch();
        const body = (await response.json()) as { message: { id: number } };
        const conversationUuid = (/\/conversations\/([0-9a-f-]{36})\/messages$/.exec(new URL(route.request().url()).pathname) as RegExpExecArray)[1];
        await page.evaluate(
          (event) => (window as unknown as { __chatHub: { emit(event: unknown): void } }).__chatHub.emit(event),
          { type: 'message', conversationUuid, message: body.message },
        );
        await expect(thread.locator(`.nowo-chat-msg[data-id="${body.message.id}"]`)).toHaveCount(1);
        // The echo is painted while the send is still pending.
        await expect(thread.locator('.nowo-chat-msg--pending')).toHaveCount(1);
        echoed = true;
        await route.fulfill({ response, json: body });
      },
    );

    await thread.locator('.nowo-chat-composer__input').fill(text);
    await thread.locator('.nowo-chat-composer__input').press('Enter');

    await expect.poll(() => echoed).toBe(true);
    await expect(thread.locator('.nowo-chat-msg--pending')).toHaveCount(0);
    await expect(thread).not.toContainText('Sending');
    const sent = thread.locator('.nowo-chat-msg--mine', { has: page.locator('.nowo-chat-bubble', { hasText: text }) });
    await expect(sent).toHaveCount(1);
    await expect(sent).toHaveAttribute('data-id', /^\d+$/);

    // A later catch-up / replay of the same message adds nothing.
    await page.waitForTimeout(1_500);
    await expect(thread.locator('.nowo-chat-bubble', { hasText: text })).toHaveCount(1);
    await expect(thread.locator('.nowo-chat-msg--pending')).toHaveCount(0);
  });
});
