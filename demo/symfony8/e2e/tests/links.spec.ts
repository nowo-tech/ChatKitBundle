import { test, expect, type Page } from '@playwright/test';

/**
 * 1.19 — links: a pinned Teams link in the bar under the header (add through the dialog, edit, remove), a link card
 * attached from the composer, and the Links panel (pinned and shared links, provider filter, go to message).
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

const TEAMS = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_e2e%40thread.v2/0';

test.describe('links', () => {
  test('alice pins a Teams link, attaches a link card and finds both in the Links panel', async ({ page }) => {
    await signIn(page, 'alice');
    await page.locator('.nowo-chat-launcher').click();
    await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    const window = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    await expect(window).toBeVisible();
    const title = `Daily ${Date.now()}`;

    // Pinned link through the dialog; javascript: is refused.
    await window.getByTestId('nowo-chat-links-add').click();
    const dialog = page.getByTestId('nowo-chat-link-dialog');
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-part="link-url"]').fill('javascript:alert(1)');
    await dialog.locator('[data-part="link-save"]').click();
    await expect(dialog.locator('[data-part="link-error"]')).toBeVisible();
    await dialog.locator('[data-part="link-url"]').fill(TEAMS);
    await expect(dialog.locator('[data-part="link-detected"]')).toContainText('Microsoft Teams');
    await dialog.locator('[data-part="link-title"]').fill(title);
    await dialog.locator('[data-part="link-save"]').click();
    await expect(dialog).toBeHidden();
    const chip = window.getByTestId('nowo-chat-link-chip').filter({ hasText: title });
    await expect(chip).toBeVisible();
    await expect(chip).toHaveAttribute('href', TEAMS);
    await expect(chip).toHaveAttribute('rel', /noopener/);
    await expect(chip).toHaveAttribute('target', '_blank');

    // A link card from the composer.
    await window.getByTestId('nowo-chat-attach-link').click();
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-part="link-url"]').fill('https://meet.google.com/abc-defg-hij');
    await dialog.locator('[data-part="link-title"]').fill(`Retro ${title}`);
    await dialog.locator('[data-part="link-save"]').click();
    await window.locator('.nowo-chat-composer__input').press('Enter');
    const card = window.locator('.nowo-chat-linkcard', { hasText: `Retro ${title}` });
    await expect(card).toBeVisible();
    await expect(card).toHaveAttribute('data-provider', 'meet');
    await expect(card).toHaveAttribute('rel', /noopener noreferrer/);

    // The Links panel lists both; the provider filter narrows it; "go to message" closes it.
    await window.getByTestId('nowo-chat-links-open').click();
    const panel = window.getByTestId('nowo-chat-links-panel');
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-part="links-pinned"]')).toContainText(title);
    await expect(panel.locator('[data-part="links-shared"]')).toContainText(`Retro ${title}`);
    await panel.locator('[data-part="links-provider"]').selectOption('teams');
    await expect(panel.locator('[data-part="links-shared"]')).not.toContainText(`Retro ${title}`);
    await panel.locator('[data-part="links-provider"]').selectOption('');
    await panel.locator('[data-part="links-shared"] [data-part="link-goto"]').first().click();
    await expect(panel).toBeHidden();

    // Bob sees the pinned link; alice removes it.
    const bob = await (await page.context().browser()!.newContext()).newPage();
    await signIn(bob, 'bob');
    await bob.locator('.nowo-chat-launcher').click();
    await bob.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Alice Martin' }).click();
    await expect(bob.getByTestId('nowo-chat-link-chip').filter({ hasText: title })).toBeVisible();

    await window.getByTestId('nowo-chat-links-bar').locator('[data-part="link-edit"]').last().click();
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-part="link-remove"]').click();
    await expect(window.getByTestId('nowo-chat-link-chip').filter({ hasText: title })).toHaveCount(0);
  });
});
