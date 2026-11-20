import { test, expect, type Page } from '@playwright/test';

/**
 * 1.37 — message search (the demo sets `search.enabled: true`): Alice finds a message she just sent to Bob on the
 * search page, the word is marked, "Open" leads to the message; Carol, who is not in that conversation, finds nothing;
 * markup in a message is shown as text in the excerpt and never runs. The page works without the dock script.
 */
test.describe.configure({ timeout: 60_000 });

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-platform')).toContainText(username);
}

async function sendToBob(page: Page, text: string): Promise<void> {
  await page.route('**/.well-known/mercure**', (route) => route.abort());
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Bob Lee' }).first().click();
  const input = page.locator('.nowo-chat-composer__input');
  await input.fill(text);
  await input.press('Enter');
  await expect(page.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text.slice(-12) })).toBeAttached();
}

test.describe('message search (1.37)', () => {
  test('finds a readable message, marks the word and opens it; others find nothing', async ({ page, browser }) => {
    const word = `zephyr${Date.now()}`;
    await signIn(page, 'alice');
    await sendToBob(page, `Launch notes ${word} for the team`);

    await page.goto('/chat/search');
    await page.getByTestId('chat-search-input').fill(word);
    await page.getByRole('button', { name: 'Search' }).click();
    const item = page.getByTestId('chat-search-item');
    await expect(item).toHaveCount(1);
    await expect(item.locator('mark')).toHaveText(word);
    await expect(item).toContainText('Bob Lee');
    await item.getByTestId('chat-search-open').click();
    await expect(page).toHaveURL(/\/chat\/[0-9a-f-]{36}\?message=[0-9a-f-]{36}/);

    const other = await browser.newPage();
    await signIn(other, 'carol');
    await other.goto(`/chat/search?q=${word}`);
    await expect(other.getByTestId('chat-search-empty')).toBeVisible();
    await expect(other.getByTestId('chat-search-item')).toHaveCount(0);
    await other.close();
  });

  test('markup in a message stays text in the excerpt', async ({ page }) => {
    const word = `quokka${Date.now()}`;
    let dialogs = 0;
    page.on('dialog', async (dialog) => {
      dialogs += 1;
      await dialog.dismiss();
    });
    await signIn(page, 'alice');
    await sendToBob(page, `<img src=x onerror=alert(1)> <script>alert(2)</script> ${word}`);

    await page.goto(`/chat/search?q=${word}`);
    const snippet = page.getByTestId('chat-search-snippet');
    await expect(snippet).toHaveCount(1);
    await expect(snippet).toContainText('<img src=x onerror=alert(1)>');
    await expect(snippet.locator('img, script')).toHaveCount(0);
    expect(dialogs).toBe(0);
  });

  test('the dock header links to the search page', async ({ page }) => {
    await signIn(page, 'alice');
    await page.goto('/en');
    await page.locator('.nowo-chat-launcher').click();
    const link = page.locator('[data-part="message-search"]').first();
    await expect(link).toHaveAttribute('href', /\/chat\/search$/);
  });
});
