import { test, expect, type Browser, type Page, type Locator } from '@playwright/test';

/**
 * 1.35 — the richer message menu on the desktop: quick reactions and "More…", Copy text, Quote, Save for later and the
 * Saved page, Mark unread from here, and Message info. The demo enables every item (`message_menu.save`,
 * `message_menu.message_info`) and UiKit's context menus.
 */
test.describe.configure({ timeout: 60_000 });

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-platform')).toContainText(username);
}

async function openConversation(page: Page, user: string, with_: string): Promise<Locator> {
  await signIn(page, user);
  await page.locator('.nowo-chat-launcher').click();
  await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: with_ }).click();
  const window = page.locator('.nowo-chat-window', { hasText: with_ });
  await expect(window).toBeVisible();
  return window;
}

/** Bob writes to Alice from his own browser. */
async function bobWrites(browser: Browser, text: string, shown: string = text): Promise<void> {
  const context = await browser.newContext();
  const page = await context.newPage();
  const window = await openConversation(page, 'bob', 'Alice Martin');
  await window.locator('.nowo-chat-composer__input').fill(text);
  await window.locator('.nowo-chat-composer__input').press('Enter');
  await expect(window.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending)', { hasText: shown })).toBeVisible();
  await context.close();
}

const menu = (page: Page) => page.locator('.nowo-ui-context-menu:not(.nowo-ui-context-menu--sub)');

async function rightClick(page: Page, message: Locator): Promise<void> {
  await message.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await message.locator('.nowo-chat-bubble').click({ button: 'right' });
  await expect(menu(page)).toBeVisible();
}

async function theirMessage(page: Page, browser: Browser, prefix: string): Promise<{ window: Locator; message: Locator; text: string }> {
  const text = `${prefix} ${Date.now()}`;
  // What the bubble shows (the Markdown rendered).
  const shown = text.replace(/\*\*/g, '');
  await bobWrites(browser, text, shown);
  const window = await openConversation(page, 'alice', 'Bob Lee');
  const message = window.locator('.nowo-chat-msg:not(.nowo-chat-msg--mine)', { hasText: shown }).last();
  await expect(message).toBeVisible();
  return { window, message, text };
}

test.describe('message menu (1.35)', () => {
  test('a quick reaction and "More…" react through the menu; the row is one group (Left / Right)', async ({ page, browser }) => {
    const { message } = await theirMessage(page, browser, 'React to me');
    await rightClick(page, message);
    const row = menu(page).getByRole('group', { name: 'Quick reactions' });
    await expect(row.getByRole('menuitem')).toHaveCount(7);
    await expect(row.getByRole('menuitem').first()).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(row.getByRole('menuitem').nth(1)).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(message.locator('.nowo-chat-reaction', { hasText: '❤️' })).toBeVisible();

    await rightClick(page, message);
    await menu(page).getByRole('menuitem', { name: 'More reactions' }).click();
    const picker = message.locator('.nowo-chat-emoji--reactions');
    await expect(picker).toBeVisible();
    await picker.getByRole('button', { name: '🚀' }).click();
    await expect(message.locator('.nowo-chat-reaction', { hasText: '🚀' })).toBeVisible();
  });

  test('Copy text copies the plain text; Quote fills the composer', async ({ page, context, browser }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    const { window, message, text } = await theirMessage(page, browser, '**Bold** words');
    await rightClick(page, message);
    await menu(page).getByRole('menuitem', { name: 'Copy text' }).click();
    await expect(window.locator('[data-part="notice"]')).toHaveText('Text copied');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(text.replace('**Bold**', 'Bold'));

    await rightClick(page, message);
    await menu(page).getByRole('menuitem', { name: 'Quote' }).click();
    await expect(window.locator('.nowo-chat-composer__input')).toHaveValue(new RegExp(`^> \\*\\*Bob Lee\\*\\* wrote:\\n> ${text.replace('**Bold**', 'Bold')}\\n\\n`));
    await window.locator('.nowo-chat-composer__input').fill('');
  });

  test('Save for later lists the message on the Saved page; Remove takes it off', async ({ page, browser }) => {
    const { window, message, text } = await theirMessage(page, browser, 'Keep this');
    await rightClick(page, message);
    await menu(page).getByRole('menuitem', { name: 'Save for later' }).click();
    await expect(window.locator('[data-part="notice"]')).toHaveText('Saved for later');
    await rightClick(page, message);
    await expect(menu(page).getByRole('menuitem', { name: 'Remove from saved' })).toBeVisible();
    await page.keyboard.press('Escape');

    await page.locator('.nowo-chat-panel__saved').click();
    await expect(page).toHaveURL(/\/chat\/saved/);
    const item = page.getByTestId('chat-saved-item').filter({ hasText: text });
    await expect(item).toBeVisible();
    await item.getByTestId('chat-saved-remove').click();
    await expect(item).toHaveCount(0);
  });

  test('Mark unread from here brings the unread badge back', async ({ page, browser }) => {
    const { window, message } = await theirMessage(page, browser, 'Unread me');
    await rightClick(page, message);
    await menu(page).getByRole('menuitem', { name: 'Mark unread from here' }).click();
    await expect(window.locator('[data-part="notice"]')).toHaveText('Marked unread');
    await expect(page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee', hasNotText: 'General' }).locator('.nowo-chat-badge')).toBeVisible();
  });

  test('Message info on my own message says who read it', async ({ page, browser }) => {
    const window = await openConversation(page, 'alice', 'Bob Lee');
    const text = `Did you read ${Date.now()}`;
    await window.locator('.nowo-chat-composer__input').fill(text);
    await window.locator('.nowo-chat-composer__input').press('Enter');
    const mine = window.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text });
    await expect(mine).toBeVisible();
    // Bob opens the conversation: it is read.
    const bob = await browser.newContext();
    const bobPage = await bob.newPage();
    const bobWindow = await openConversation(bobPage, 'bob', 'Alice Martin');
    await expect(bobWindow.locator('.nowo-chat-msg', { hasText: text })).toBeVisible();
    await bobPage.waitForTimeout(1500);
    await bob.close();

    await rightClick(page, mine);
    await menu(page).getByRole('menuitem', { name: 'Message info' }).click();
    const dialog = page.locator('dialog.nowo-chat-info');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-part="info-reader"]', { hasText: 'Bob Lee' })).toContainText('Read');
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
  });
});
