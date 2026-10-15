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

  test('the full-page chat works without the dock', async ({ page }) => {
    await signIn(page, 'alice');
    await page.goto('/chat/');
    await page.locator('.nowo-chat-page__row', { hasText: 'Bob Lee' }).click();
    await expect(page.locator('.nowo-chat-page__header h2')).toHaveText('Bob Lee');
    await expect(page.locator('.nowo-chat-page__composer textarea')).toBeVisible();
  });
});
