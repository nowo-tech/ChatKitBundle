import { test, expect } from '@playwright/test';

/**
 * REQ-DEMO-013 — happy-path e2e against the Chat Kit demo.
 * Skeleton phase: landing page + sign-in. Extend with dock scenarios
 * (open conversation, send message, typing, read receipts) as they ship.
 */
test.describe('chat kit demo', () => {
  test('home shows the bundle landing page and the compiled entry', async ({ page }) => {
    const response = await page.goto('/en');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('h1')).toContainText('Chat Kit Bundle');
    await expect(page.locator('#nowo-chat-token')).toHaveText('--nowo-chat-accent');
  });

  test('alice can sign in', async ({ page }) => {
    await page.goto('/en/login');
    await page.getByLabel('Username').fill('alice');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.locator('nav')).toContainText('alice');
  });
});
