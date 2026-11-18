import { test, expect, type Browser, type Page, type Locator } from '@playwright/test';

/**
 * 1.33 — the message menu (UiKit kebab: Reply, Edit, Copy link, Pin, Delete) and its UiKit 2.54 context menu: a
 * right-click or Shift+F10 on a message opens the same items, filtered by the bundle's rules. The demo turns the
 * context menu on through UiKit's theme flag (`nowo_ui_kit.theme.context_menus: true`).
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

async function openBob(page: Page): Promise<Locator> {
  await signIn(page, 'alice');
  await page.locator('.nowo-chat-launcher').click();
  await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
  const window = page.locator('.nowo-chat-window', { hasText: 'Bob Lee' });
  await expect(window).toBeVisible();
  return window;
}

/** Bob writes to Alice from his own browser (the message alice's tests act on is then on the first page). */
async function bobWrites(browser: Browser, text: string): Promise<void> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await signIn(page, 'bob');
  await page.locator('.nowo-chat-launcher').click();
  await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Alice Martin' }).click();
  const window = page.locator('.nowo-chat-window', { hasText: 'Alice Martin' });
  await window.locator('.nowo-chat-composer__input').fill(text);
  await window.locator('.nowo-chat-composer__input').press('Enter');
  await expect(window.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending)', { hasText: text })).toBeVisible();
  await context.close();
}

async function sendOwn(window: Locator, text: string): Promise<Locator> {
  await window.locator('.nowo-chat-composer__input').fill(text);
  await window.locator('.nowo-chat-composer__input').press('Enter');
  const message = window.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending)', { hasText: text });
  await expect(message).toBeVisible();
  // Confirmed by the server (its kebab is filled in).
  await expect(message.locator('[data-part="menu"]')).toBeAttached();
  return message;
}

const menu = (page: Page) => page.locator('.nowo-ui-context-menu:not(.nowo-ui-context-menu--sub)');

/** Right-clicks a message once it is in view (the context menu closes when a scroller around the row scrolls). */
async function rightClick(page: Page, message: Locator): Promise<void> {
  await message.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await message.locator('.nowo-chat-bubble').click({ button: 'right' });
  await expect(menu(page)).toBeVisible();
}

test.describe('message context menu (1.33)', () => {
  test('a right-click on my fresh message opens Reply, Edit, Copy link, Pin and Delete; Reply quotes it', async ({ page }) => {
    const window = await openBob(page);
    const text = `Context ${Date.now()}`;
    const message = await sendOwn(window, text);

    await rightClick(page, message);
    // 1.35: the quick reactions row first, then the groups.
    await expect(menu(page).getByRole('menuitem')).toHaveText(['👍', '❤️', '😂', '😮', '😢', '🙏', '+', 'Reply', 'Reply in thread', 'Quote', 'Forward', 'Copy text', 'Copy link', /^(Save for later|Remove from saved)$/, /^(Pin|Unpin)$/, 'Message info', 'Edit', 'Delete']);
    await expect(message).toHaveAttribute('data-nowo-ui-context-menu-open', '');

    await menu(page).getByRole('menuitem', { name: 'Reply', exact: true }).click();
    await expect(menu(page)).toHaveCount(0);
    await expect(window.locator('.nowo-chat-composer__input')).toHaveValue(new RegExp(`^> Alice Martin: ${text}\\n\\n`));
    await expect(window.locator('.nowo-chat-composer__input')).toBeFocused();
    await window.locator('.nowo-chat-composer__input').fill('');
  });

  test('someone else\'s message offers no Edit nor Delete; Copy link copies its address', async ({ page, context, browser }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    const text = `From Bob ${Date.now()}`;
    await bobWrites(browser, text);
    const window = await openBob(page);
    const theirs = window.locator('.nowo-chat-msg:not(.nowo-chat-msg--mine)', { hasText: text }).last();
    await expect(theirs).toBeVisible();

    await rightClick(page, theirs);
    await expect(menu(page).getByRole('menuitem')).toHaveText(['👍', '❤️', '😂', '😮', '😢', '🙏', '+', 'Reply', 'Reply in thread', 'Quote', 'Forward', 'Copy text', 'Copy link', /^(Save for later|Remove from saved)$/, 'Mark unread from here', /^(Pin|Unpin)$/]);
    await menu(page).getByRole('menuitem', { name: 'Copy link' }).click();
    await expect(window.locator('[data-part="notice"]')).toHaveText('Link to the message copied');
    const copied = await page.evaluate(() => navigator.clipboard.readText());
    expect(copied).toMatch(/\/chat\/[0-9a-f-]{36}\?message=[0-9a-f-]{36}$/);

    // The copied address opens the full-page chat on that message.
    await page.goto(copied);
    await expect(page.locator('.nowo-chat-msg.is-highlighted', { hasText: text })).toBeVisible();
    await expect(page).not.toHaveURL(/message=/);
  });

  test('the keyboard reaches the kebab and Shift+F10 opens the same menu', async ({ page }) => {
    const window = await openBob(page);
    const message = await sendOwn(window, `Keyboard ${Date.now()}`);

    await message.focus();
    await expect(message.locator('.nowo-ui-kebab__summary')).toBeVisible();
    await page.keyboard.press('Shift+F10');
    await expect(menu(page)).toBeVisible();
    await expect(menu(page).getByRole('menuitem').first()).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu(page)).toHaveCount(0);
    await expect(message).toBeFocused();

    // The kebab itself: Tab from the message reaches its toggle; Enter opens it, Escape closes it.
    const summary = message.locator('.nowo-ui-kebab__summary');
    await summary.focus();
    await page.keyboard.press('Enter');
    await expect(message.locator('details[open] [data-part="menu-delete"]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(message.locator('details[open]')).toHaveCount(0);
    await expect(summary).toBeFocused();

    // Delete from the context menu asks first, like the kebab.
    await page.mouse.click(5, 5);
    await page.waitForTimeout(900);
    await rightClick(page, message);
    await menu(page).getByRole('menuitem', { name: 'Delete' }).click();
    await expect(message.locator('.nowo-chat-confirm')).toBeVisible();
    const id = await message.getAttribute('data-id');
    await message.locator('[data-part="confirm"]').click();
    await expect(window.locator(`.nowo-chat-msg[data-id="${id}"] .nowo-chat-bubble--deleted`)).toBeVisible();
  });
});
