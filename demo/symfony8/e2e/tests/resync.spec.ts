import { test, expect, type BrowserContext, type Page } from '@playwright/test';

/**
 * 1.26.1 — an open conversation window catches up after a dropped live connection (1.26.2: edits and deletions made
 * meanwhile too). CI runs this spec on every pull request (`make -C demo/symfony8 test-e2e-resync`, audit LN-I4).
 *
 * The demo runs without a Mercure hub, so Alice's browser gets a scripted stand-in: `/chat/api/realtime` answers
 * "enabled" and `EventSource` is a fake the test opens and drops. It never delivers an event, so a message Bob sends
 * meanwhile is missed for good unless the reconnection fetches it.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

/** A context whose live connection the test controls (`window.__chatHub.drop()`). */
async function withScriptedHub(context: BrowserContext): Promise<void> {
  await context.route('**/api/realtime', (route) =>
    route.fulfill({
      json: { enabled: true, hubUrl: '/e2e-hub', token: 'e2e', topic: 'e2e', expiresAt: new Date(Date.now() + 3_600_000).toISOString() },
    }),
  );
  await context.addInitScript(() => {
    type Handler = (() => void) | null;
    const sources: { onopen: Handler; onerror: Handler; closed: boolean }[] = [];
    class ScriptedEventSource {
      onopen: Handler = null;
      onmessage: Handler = null;
      onerror: Handler = null;
      closed = false;
      constructor() {
        sources.push(this);
        window.setTimeout(() => this.onopen?.(), 50);
      }
      close(): void {
        this.closed = true;
      }
    }
    // Fixed: dev tooling in the demo (the debug toolbar's hot reload) wraps EventSource later; the wrapper would hide
    // the handlers the dock sets.
    Object.defineProperty(window, 'EventSource', { value: ScriptedEventSource, writable: false, configurable: false });
    (window as unknown as { __chatHub: unknown }).__chatHub = {
      drop: () => sources.filter((source) => !source.closed).forEach((source) => source.onerror?.()),
    };
  });
}

test.describe('realtime resync (1.26.1)', () => {
  test('a message missed while the connection was down shows in the open window, exactly once', async ({ browser }) => {
    const aliceContext = await browser.newContext();
    await withScriptedHub(aliceContext);
    const alice = await aliceContext.newPage();
    await signIn(alice, 'alice');
    await alice.locator('.nowo-chat-launcher').click();
    await alice.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    const thread = alice.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    await expect(thread).toBeVisible();
    await expect(alice.locator('.is-live')).toHaveCount(1);

    // Bob writes; Alice's (scripted) live connection delivers nothing.
    const bobContext = await browser.newContext();
    const bob = await bobContext.newPage();
    await signIn(bob, 'bob');
    await bob.locator('.nowo-chat-launcher').click();
    await bob.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Alice Martin' }).click();
    const bobWindow = bob.locator('.nowo-chat-window', { hasText: 'Alice Martin' });
    const text = `Missed while offline ${Date.now()}`;
    await bobWindow.locator('.nowo-chat-composer__input').fill(text);
    await bobWindow.locator('.nowo-chat-composer__input').press('Enter');
    await expect(bobWindow.locator('.nowo-chat-bubble', { hasText: text })).toBeVisible();
    const missed = thread.locator('.nowo-chat-bubble', { hasText: text });
    await expect(missed).toHaveCount(0);

    // The connection drops and comes back (backoff about 1–2 s): the open window fetches what it missed.
    await alice.evaluate(() => (window as unknown as { __chatHub: { drop(): void } }).__chatHub.drop());
    await expect(alice.locator('.is-reconnecting')).toHaveCount(1);
    await expect(alice.locator('.is-live')).toHaveCount(1, { timeout: 10_000 });
    await expect(missed).toHaveCount(1, { timeout: 10_000 });

    // Later ticks never add it twice.
    await alice.waitForTimeout(2_000);
    await expect(missed).toHaveCount(1);

    await aliceContext.close();
    await bobContext.close();
  });

  // 1.26.2 (audit LN-I1): `?after=` only returns newer messages; the resync also reads the lines shown again.
  test('an edit and a deletion made while the connection was down show after the reconnection', async ({ browser }) => {
    const stamp = Date.now();
    const toEdit = `Edit me ${stamp}`;
    const edited = `Edited while offline ${stamp}`;
    const toDelete = `Delete me ${stamp}`;

    // Bob writes two lines first, so Alice's window loads them.
    const bobContext = await browser.newContext();
    const bob = await bobContext.newPage();
    await signIn(bob, 'bob');
    await bob.locator('.nowo-chat-launcher').click();
    await bob.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Alice Martin' }).click();
    const bobWindow = bob.locator('.nowo-chat-window', { hasText: 'Alice Martin' });
    for (const line of [toEdit, toDelete]) {
      await bobWindow.locator('.nowo-chat-composer__input').fill(line);
      await bobWindow.locator('.nowo-chat-composer__input').press('Enter');
      await expect(bobWindow.locator('.nowo-chat-bubble', { hasText: line })).toBeVisible();
    }

    const aliceContext = await browser.newContext();
    await withScriptedHub(aliceContext);
    const alice = await aliceContext.newPage();
    await signIn(alice, 'alice');
    await alice.locator('.nowo-chat-launcher').click();
    await alice.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    const thread = alice.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    await expect(thread.locator('.nowo-chat-bubble', { hasText: toEdit })).toHaveCount(1);
    await expect(thread.locator('.nowo-chat-bubble', { hasText: toDelete })).toHaveCount(1);
    await expect(alice.locator('.is-live')).toHaveCount(1);

    // Bob edits one line and deletes the other; Alice's (scripted) live connection delivers nothing.
    const bobLine = (text: string) => bobWindow.locator('.nowo-chat-msg', { has: bob.locator('.nowo-chat-bubble', { hasText: text }) });
    await bobLine(toEdit).hover();
    await bobLine(toEdit).locator('[data-part="edit"]').click();
    await bobWindow.locator('.nowo-chat-edit__input').fill(edited);
    await bobWindow.locator('.nowo-chat-edit__input').press('Enter');
    await expect(bobWindow.locator('.nowo-chat-bubble', { hasText: edited })).toBeVisible();
    await bobLine(toDelete).hover();
    await bobLine(toDelete).locator('[data-part="delete"]').click();
    await bobWindow.locator('.nowo-chat-confirm [data-part="confirm"]').click();
    await expect(bobWindow.locator('.nowo-chat-bubble', { hasText: toDelete })).toHaveCount(0);
    await expect(thread.locator('.nowo-chat-bubble', { hasText: toEdit })).toHaveCount(1);
    await expect(thread.locator('.nowo-chat-bubble', { hasText: toDelete })).toHaveCount(1);

    // The connection drops and comes back: the window shows the edit and the deletion.
    await alice.evaluate(() => (window as unknown as { __chatHub: { drop(): void } }).__chatHub.drop());
    await expect(alice.locator('.is-reconnecting')).toHaveCount(1);
    await expect(alice.locator('.is-live')).toHaveCount(1, { timeout: 10_000 });
    await expect(thread.locator('.nowo-chat-bubble', { hasText: edited })).toHaveCount(1, { timeout: 10_000 });
    await expect(thread.locator('.nowo-chat-bubble', { hasText: toEdit })).toHaveCount(0);
    await expect(thread.locator('.nowo-chat-bubble', { hasText: toDelete })).toHaveCount(0);

    await aliceContext.close();
    await bobContext.close();
  });
});
