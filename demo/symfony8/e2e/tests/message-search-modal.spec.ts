import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * 1.40 — the search modal of the conversation header (demo: `search.enabled`, `search.conversation_entry` on by
 * default), at desktop and phone sizes: open from the header, search this conversation, pick a result (the modal closes,
 * the message scrolls into view and is highlighted), Escape closes and gives focus back to the button, the footer stays
 * visible while the body scrolls, "all my conversations" opens another conversation at the message, an axe check of
 * the open modal, and the no-script fallback (the button is a link to /chat/search). SEARCH_SHOTS_DIR (optional) saves
 * screenshots.
 */
test.describe.configure({ timeout: 120_000 });

const shotsDir = process.env.SEARCH_SHOTS_DIR;

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

async function openConversation(page: Page, title: string): Promise<void> {
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: title }).first().click();
  await expect(page.locator('.nowo-chat-window .nowo-chat-composer__input').first()).toBeVisible();
}

async function send(page: Page, text: string): Promise<void> {
  const input = page.locator('.nowo-chat-composer__input').first();
  await input.fill(text);
  await input.press('Enter');
  await expect(page.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text.slice(-14) }).first()).toBeAttached();
}

async function axe(page: Page): Promise<void> {
  // No hover state (the pointer stays on the button just clicked) and the dialog's entrance transition finished
  // (axe reads colours mid-fade otherwise).
  await page.mouse.move(1, 1);
  await page.getByTestId('chat-search-dialog').evaluate((dialog) => Promise.all(dialog.getAnimations({ subtree: true }).map((animation) => animation.finished)));
  const results = await new AxeBuilder({ page }).include('[data-testid="chat-search-dialog"]').analyze();
  expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => `${n.target.join(' ')} (${n.any.map((check) => check.message).join('; ')})`).join(', ')}`)).toEqual([]);
}

async function shot(page: Page, name: string): Promise<void> {
  if (!shotsDir) {
    return;
  }
  mkdirSync(shotsDir, { recursive: true });
  await page.screenshot({ path: resolve(shotsDir, `${name}.png`) });
}

for (const size of [
  { name: 'desktop', viewport: { width: 1280, height: 800 } },
  { name: 'phone', viewport: { width: 390, height: 844 } },
]) {
  test.describe(`search modal (${size.name}, 1.40)`, () => {
    test.use({ viewport: size.viewport });

    test('open, search, pick a result (scroll + highlight), Escape returns focus', async ({ page }) => {
      const word = `tamarind${Date.now()}`;
      await page.route('**/.well-known/mercure**', (route) => route.abort());
      await signIn(page, 'alice');
      await openConversation(page, 'Bob Lee');
      await send(page, `Kick-off notes ${word} for the sprint`);
      // Push the message out of view with a few tall messages (the demo allows 30 sends a minute).
      for (let i = 0; i < 3; i += 1) {
        await send(page, `${Array.from({ length: 14 }, (_, line) => `Filler ${i}.${line}`).join('\n')}\nend ${i} ${Date.now()}`);
      }

      const button = page.locator('.nowo-chat-window [data-part="message-search"]').first();
      await expect(button).toBeVisible();
      await expect(button).toHaveAttribute('href', /\/chat\/search\?conversation=[0-9a-f-]{36}$/);
      await button.click();
      const dialog = page.getByTestId('chat-search-dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog.getByRole('heading', { level: 2, name: 'Search messages' })).toBeVisible();
      await expect(dialog.getByTestId('chat-search-scope')).toHaveValue('current');
      await expect(dialog.getByTestId('chat-msearch-filters')).not.toHaveAttribute('open', /.*/);
      await expect(dialog.getByTestId('chat-search-input')).toBeFocused();
      await axe(page);

      await dialog.getByTestId('chat-search-input').fill(word);
      await dialog.getByTestId('chat-msearch-submit').click();
      const item = dialog.getByTestId('chat-msearch-item');
      await expect(item).toHaveCount(1);
      await expect(item.locator('mark')).toHaveText(word);
      await axe(page);
      await shot(page, `search-modal-${size.name}-results`);

      // Footer and header stay in the viewport (the body scrolls, the page does not).
      const viewportHeight = size.viewport.height;
      const footer = dialog.locator('footer');
      await expect(footer).toBeVisible();
      const footerBox = await footer.boundingBox();
      expect(footerBox!.y + footerBox!.height).toBeLessThanOrEqual(viewportHeight + 1);
      if (size.name === 'phone') {
        const box = await dialog.boundingBox();
        expect(Math.round(box!.width)).toBe(size.viewport.width);
      }

      await item.click();
      await expect(dialog).toBeHidden();
      const target = page.locator('article.nowo-chat-msg', { hasText: word }).first();
      await expect(target).toHaveClass(/is-highlighted/);
      await expect(target).toBeInViewport();

      // Escape closes and gives focus back to the header button.
      await button.click();
      await expect(page.getByTestId('chat-search-dialog')).toBeVisible();
      // Tab stays inside the modal.
      for (let i = 0; i < 12; i += 1) {
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.activeElement?.closest('[data-testid="chat-search-dialog"]') !== null)).toBe(true);
      }
      await page.keyboard.press('Escape');
      await expect(page.getByTestId('chat-search-dialog')).toHaveCount(0);
      await expect(button).toBeFocused();
    });

    test('all my conversations opens the other conversation at the message', async ({ page }) => {
      const word = `quince${Date.now()}`;
      await page.route('**/.well-known/mercure**', (route) => route.abort());
      await signIn(page, 'alice');
      await openConversation(page, 'General');
      await send(page, `Channel note ${word} for everyone`);
      await openConversation(page, 'Bob Lee');

      await page.locator('.nowo-chat-window [data-part="message-search"]').first().click();
      const dialog = page.getByTestId('chat-search-dialog');
      await dialog.getByTestId('chat-search-input').fill(word);
      await dialog.getByTestId('chat-msearch-submit').click();
      await expect(dialog.getByTestId('chat-msearch-empty')).toBeVisible();

      await dialog.getByTestId('chat-search-scope').selectOption('all');
      await dialog.getByTestId('chat-msearch-submit').click();
      const item = dialog.getByTestId('chat-msearch-item');
      await expect(item).toHaveCount(1);
      await item.click();
      await expect(page).toHaveURL(/\/chat\/[0-9a-f-]{36}\?message=[0-9a-f-]{36}/);
      await expect(page.locator('article.nowo-chat-msg', { hasText: word }).first()).toHaveClass(/is-highlighted/);
    });
  });
}

test('without JavaScript the conversation links to the search page', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await signIn(page, 'alice');
  await page.goto('/chat/');
  await page.locator('.nowo-chat-page__row', { hasText: 'Bob Lee' }).first().click();
  const link = page.getByTestId('chat-page-search');
  await expect(link).toHaveAttribute('href', /\/chat\/search\?conversation=[0-9a-f-]{36}$/);
  await link.click();
  await expect(page.getByTestId('chat-search-input')).toBeVisible();
  await page.getByTestId('chat-search-input').fill('chat dock');
  await page.getByRole('button', { name: 'Search' }).click();
  await expect(page.getByTestId('chat-search-item').first()).toBeVisible();
  await context.close();
});
