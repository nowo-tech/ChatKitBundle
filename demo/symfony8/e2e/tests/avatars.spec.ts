import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';

/**
 * ChatKit 1.16 profile pictures: the demo resolver gives Alice and Bob a picture and Carol a broken URL
 * (/does-not-exist.webp), so her bubbles fall back to the initials.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

/**
 * axe on a region: no image issue at all, and no contrast issue on a bubble (the pictures are decorative, inside
 * aria-hidden bubbles; `color: transparent` applies only while a picture covers the initials). Contrast findings
 * elsewhere in the region are not caused by the pictures and are printed for the record.
 */
async function expectNoPictureIssues(page: Page, selector: string): Promise<void> {
  const results = await new AxeBuilder({ page }).include(selector).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  const ids = results.violations.map((violation) => violation.id);
  expect(ids).not.toContain('image-alt');
  expect(ids).not.toContain('role-img-alt');
  const contrast = results.violations.filter((violation) => violation.id === 'color-contrast').flatMap((violation) => violation.nodes.map((node) => node.html));
  expect(contrast.filter((html) => /nowo-chat-avatar|mention-option__initials/.test(html))).toEqual([]);
  if (contrast.length > 0) {
    console.log(`axe color-contrast outside the bubbles in ${selector}:`, contrast);
  }
}

test.describe('chat kit demo profile pictures (1.16)', () => {
  test('pictures show in the list, thread, picker and mentions; a broken one falls back to initials', async ({ page }) => {
    await signIn(page, 'alice');
    // The user's own button.
    await expect(page.locator('.nowo-chat-me .nowo-chat-avatar.has-image img.nowo-chat-avatar__image')).toBeVisible();

    await page.locator('.nowo-chat-launcher').click();
    const row = page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' });
    const rowImage = row.locator('img.nowo-chat-avatar__image');
    await expect(rowImage).toBeVisible();
    await expect(rowImage).toHaveAttribute('src', '/avatars/bob.svg');
    await expect(rowImage).toHaveAttribute('alt', '');
    await expect.poll(() => rowImage.evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0)).toBe(true);
    await expectNoPictureIssues(page, '.nowo-chat-panel');

    // The thread: header and Bob's messages.
    await row.click();
    const window = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
    await expect(window.locator('.nowo-chat-window__avatar img.nowo-chat-avatar__image')).toBeVisible();
    await expect(window.locator('.nowo-chat-msg img.nowo-chat-avatar__image[src="/avatars/bob.svg"]').first()).toBeVisible();
    await expectNoPictureIssues(page, '.nowo-chat-window');

    // The people picker: Bob with his picture, Carol back to her initials after the error.
    await page.locator('.nowo-chat-panel__compose').click();
    const picker = page.locator('.nowo-chat-picker');
    const bob = picker.locator('.nowo-chat-row', { hasText: 'Bob Lee' }).last();
    await expect(bob.locator('img.nowo-chat-avatar__image')).toBeVisible();
    const carol = picker.locator('.nowo-chat-row', { hasText: 'Carol' }).last();
    await expect(carol.locator('.nowo-chat-avatar')).toBeVisible();
    await expect(carol.locator('img')).toHaveCount(0);
    await expect(carol.locator('.nowo-chat-avatar')).not.toHaveClass(/has-image/);
    await expect(carol.locator('.nowo-chat-avatar')).toHaveText(/\S/);
    await expectNoPictureIssues(page, '.nowo-chat-picker');
    await page.locator('[data-part="picker-back"]').click();

    // The @mention list of the General channel.
    await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'General' }).click();
    const general = page.locator('.nowo-chat-window', { hasText: 'General' });
    const input = general.locator('.nowo-chat-composer__input');
    await input.fill('@');
    await input.press('End');
    await input.pressSequentially('b');
    const list = page.locator('.nowo-chat-mention-list');
    await expect(list.locator('.nowo-chat-mention-option', { hasText: 'Bob Lee' }).locator('img.nowo-chat-avatar__image')).toBeVisible();
    await expectNoPictureIssues(page, '.nowo-chat-mention-list');
  });
});
