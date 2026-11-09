import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';

/**
 * REQ-DEMO-013 — happy-path e2e against the Chat Kit demo (seeded with `make setup-db`).
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

test.describe('chat kit demo', () => {
  test('guests see the landing page without the dock', async ({ page }) => {
    const response = await page.goto('/en');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1')).toContainText('Chat Kit Bundle');
    await expect(page.locator('.nowo-chat-launcher')).toHaveCount(0);
  });

  test('alice opens the conversation with Bob and sends a message', async ({ page }) => {
    await signIn(page, 'alice');
    await page.locator('.nowo-chat-launcher').click();
    await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    const window = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    await expect(window).toBeVisible();
    const text = `Hello from e2e ${Date.now()}`;
    await window.locator('.nowo-chat-composer__input').fill(text);
    await window.locator('.nowo-chat-composer__input').press('Enter');
    await expect(window.locator('.nowo-chat-bubble', { hasText: text })).toBeVisible();
  });

  test('alice sets her chat status from the dock and bob sees it', async ({ browser }) => {
    const alice = await (await browser.newContext()).newPage();
    await signIn(alice, 'alice');
    const me = alice.locator('.nowo-chat-me');
    await me.click();
    const menu = alice.locator('.nowo-chat-status-menu');
    await expect(menu).toBeVisible();
    await menu.locator('.nowo-chat-status-form__text').fill('In a meeting');
    await menu.locator('[data-status="busy"]').click();
    await expect(me).toHaveAttribute('data-status', 'busy');
    await expect(menu).toBeHidden();

    const bob = await (await browser.newContext()).newPage();
    await signIn(bob, 'bob');
    await bob.locator('.nowo-chat-launcher').click();
    const row = bob.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Alice Martin' });
    await expect(row).toHaveAttribute('data-peer-status', 'busy');
    await expect(row.locator('.nowo-chat-row__status')).toHaveText('In a meeting');

    // Back to available, without text.
    await me.click();
    await menu.locator('.nowo-chat-status-form__clear').click();
    await me.click();
    await menu.locator('[data-status="available"]').click();
    await expect(me).toHaveAttribute('data-status', 'available');
  });

  test('alice closes and reopens a conversation, and pins a message', async ({ page }) => {
    await signIn(page, 'alice');
    await page.locator('.nowo-chat-launcher').click();
    const list = page.locator('.nowo-chat-list');
    const row = list.locator('li', { hasText: 'Bob Lee' });
    await row.hover();
    await row.locator('[data-part="row-archive"]').click();
    await expect(list.locator('li', { hasText: 'Bob Lee' })).toHaveCount(0);
    await expect(page.getByTestId('nowo-chat-toast')).toBeVisible();
    await page.locator('[data-filter="archived"]').click();
    const closed = list.locator('li', { hasText: 'Bob Lee' });
    await expect(closed).toBeVisible();
    await closed.hover();
    await closed.locator('[data-part="row-archive"]').click();
    await page.locator('[data-filter="all"]').click();
    await list.locator('.nowo-chat-row', { hasText: 'Bob Lee' }).click();

    const window = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    const text = `Pin me ${Date.now()}`;
    await window.locator('.nowo-chat-composer__input').fill(text);
    await window.locator('.nowo-chat-composer__input').press('Enter');
    const bubble = window.locator('.nowo-chat-msg', { hasText: text });
    await bubble.hover();
    await bubble.locator('[data-part="pin"]').click();
    const bar = window.getByTestId('nowo-chat-pins');
    await expect(bar).toContainText(text);
    await expect(window.locator('.nowo-chat-pin-line').last()).toBeVisible();
    await bubble.hover();
    await bubble.locator('[data-part="pin"]').click();
    await expect(bar).not.toContainText(text);
  });

  test('alice formats a message and inserts a quick reply', async ({ page }) => {
    await signIn(page, 'alice');
    await page.locator('.nowo-chat-launcher').click();
    await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    const window = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    const input = window.locator('.nowo-chat-composer__input');
    const word = `bold${Date.now()}`;
    await input.fill(word);
    await input.selectText();
    await input.press('Control+b');
    await input.press('End');
    await input.press('Enter');
    await expect(window.locator('.nowo-chat-bubble strong', { hasText: word })).toBeVisible();

    await window.locator('[data-part="quick-replies"]').click();
    const menu = window.getByTestId('nowo-chat-quick-replies');
    await menu.locator('.nowo-chat-quick__add').click();
    const title = `QR ${Date.now()}`;
    await menu.locator('input').fill(title);
    await menu.locator('textarea').fill('Thanks, on it.');
    await menu.locator('.nowo-chat-quick__save').click();
    await menu.locator('.nowo-chat-quick__use', { hasText: title }).click();
    await expect(input).toHaveValue('Thanks, on it.');
  });

  test('the dock steps aside while a modal overlay is open (1.17)', async ({ page }) => {
    await signIn(page, 'alice');
    const dock = page.locator('.nowo-chat[data-testid="chat-dock"]');
    const launcher = page.locator('.nowo-chat-launcher');
    await expect(launcher).toBeVisible();
    const signal = (type: 'open' | 'close', depth: number) =>
      page.evaluate(
        ([kind, value]) => {
          if (value > 0) {
            document.documentElement.dataset.nowoOverlay = String(value);
          } else {
            delete document.documentElement.dataset.nowoOverlay;
          }
          document.dispatchEvent(new CustomEvent(`nowo:overlay-${kind}`, { detail: { depth: value, kind: 'drawer', owner: document.body } }));
        },
        [type, depth] as const,
      );
    await signal('open', 1);
    await expect(dock).toHaveAttribute('data-overlay', 'hidden');
    await expect(launcher).toBeHidden();
    expect(await dock.evaluate((element) => (element as HTMLElement).inert)).toBe(true);
    await signal('close', 0);
    await expect(dock).not.toHaveAttribute('data-overlay', /.+/);
    await expect(launcher).toBeVisible();
    await launcher.click();
    await expect(page.locator('.nowo-chat-panel')).toHaveClass(/is-open/);
  });

  test('the full-page chat offers the dock options in page mode (1.13)', async ({ page }) => {
    await signIn(page, 'alice');
    await page.goto('/chat/');
    const app = page.getByTestId('nowo-chat-page-mode');
    await expect(app).toBeVisible();
    // The floating dock stays away while the page shows the chat.
    await expect(page.locator('.nowo-chat-dock')).toBeHidden();
    await expect(app.locator('.nowo-chat-me')).toBeVisible();
    await expect(app.locator('.nowo-chat-chip[data-filter="archived"]')).toBeVisible();
    await expect(app.locator('.nowo-chat-search').first()).toBeVisible();
    await expect(app.locator('.nowo-chat-pagemode__empty')).toHaveText('Pick a conversation.');
    await app.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    await expect(page).toHaveURL(/\/chat\/[0-9a-f-]{36}$/);
    const thread = app.locator('.nowo-chat-window[role="region"]');
    await expect(thread.locator('.nowo-chat-window__title')).toHaveText('Bob Lee');
    await expect(thread.locator('[data-part="format-toggle"]')).toBeVisible();
    const text = `Hello from the page ${Date.now()}`;
    await thread.locator('.nowo-chat-composer__input').fill(text);
    await thread.locator('.nowo-chat-composer__input').press('Enter');
    await expect(thread.locator('.nowo-chat-bubble', { hasText: text })).toBeVisible();
    // Back returns to the list alone.
    await page.goBack();
    await expect(page).toHaveURL(/\/chat\/$/);
    await expect(app.locator('.nowo-chat-pagemode__empty')).toBeVisible();
    // REQ-UI-003: the page renders in the UiKit host layout (nowo_ui_kit.layouts.admin) with the UiKit page header.
    await expect(page.locator('main[data-nowo-ui-bundle="NowoChatKitBundle"] [data-nowo-ui-region="header"] h1')).toHaveText('Messages');
    await expect(page.locator('nav.demo-navbar')).toBeVisible();
  });
});

test.describe('chat kit demo on a phone', () => {
  test.use({ viewport: { width: 375, height: 667 }, isMobile: true, hasTouch: true });

  test('the folded launcher keeps its accessible name (axe button-name, 1.15.3)', async ({ page }) => {
    await signIn(page, 'alice');
    await expect(page.locator('.nowo-chat.is-mobile')).toHaveCount(1);
    const launcher = page.locator('.nowo-chat-launcher');
    await expect(launcher).toBeVisible();
    await expect(page.locator('.nowo-chat-panel')).not.toHaveClass(/is-open/);
    // Named by its translated label, which is hidden visually only; the icon is decorative.
    await expect(page.getByRole('button', { name: 'Messaging' })).toBeVisible();
    await expect(launcher.locator('.nowo-chat-launcher__icon')).toHaveAttribute('aria-hidden', 'true');
    // A round button: the 48px icon (1.23, MUX-02; 40px plus padding before), the label takes no room.
    const size = await launcher.evaluate((element) => {
      const style = getComputedStyle(element);
      const icon = (element.querySelector('.nowo-chat-launcher__icon') as HTMLElement).getBoundingClientRect();
      const box = element.getBoundingClientRect();
      const frame = (side: 'Left' | 'Right' | 'Top' | 'Bottom'): number => parseFloat(style.getPropertyValue(`padding-${side.toLowerCase()}`)) + parseFloat(style.getPropertyValue(`border-${side.toLowerCase()}-width`));
      return { width: box.width, height: box.height, icon: icon.width, iconHeight: icon.height, around: icon.width + frame('Left') + frame('Right'), aroundY: icon.height + frame('Top') + frame('Bottom') };
    });
    expect(size.icon).toBe(48);
    expect(size.iconHeight).toBe(48);
    expect(size.width).toBe(size.around);
    expect(size.height).toBe(size.aroundY);
    expect(size.width).toBe(size.height);
    const label = await launcher.locator('.nowo-chat-launcher__label').boundingBox();
    expect(label?.width ?? 0).toBeLessThanOrEqual(1);
    expect(label?.height ?? 0).toBeLessThanOrEqual(1);

    const results = await new AxeBuilder({ page })
      .include('.nowo-chat-launcher')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(results.violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([]);
    expect(results.passes.map((pass) => pass.id)).toContain('button-name');

    // With no unread badge (its text would name the button), the label alone names it.
    await page.addStyleTag({ content: '.nowo-chat-launcher .nowo-chat-badge { display: none; }' });
    await expect(page.getByRole('button', { name: 'Messaging', exact: true })).toBeVisible();
    const alone = await new AxeBuilder({ page }).include('.nowo-chat-launcher').withRules(['button-name']).analyze();
    expect(alone.violations).toEqual([]);

    // The 1.15.2 rule (label display: none) is what axe flagged: the check catches it.
    await page.addStyleTag({ content: '.nowo-chat.is-mobile .nowo-chat-panel:not(.is-open) .nowo-chat-launcher__label { display: none; }' });
    const before = await new AxeBuilder({ page }).include('.nowo-chat-launcher').withRules(['button-name']).analyze();
    expect(before.violations.map((violation) => violation.id)).toEqual(['button-name']);
  });
});

test.describe('chat kit demo without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the full-page chat works without JavaScript', async ({ page }) => {
    await signIn(page, 'alice');
    await page.goto('/chat/');
    await page.locator('.nowo-chat-page__row', { hasText: 'Bob Lee' }).click();
    await expect(page.locator('.nowo-chat-page__header h2')).toHaveText('Bob Lee');
    await expect(page.locator('.nowo-chat-page__composer textarea')).toBeVisible();
    await expect(page.locator('main[data-nowo-ui-bundle="NowoChatKitBundle"] [data-nowo-ui-region="header"] h1')).toHaveText('Messages');
    await expect(page.locator('.nowo-chat-page__composer button[type="submit"]')).toHaveClass(/nowo-ui-btn--primary/);
  });
});
