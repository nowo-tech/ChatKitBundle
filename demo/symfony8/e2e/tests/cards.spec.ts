import { test, expect, type Browser, type Page, type Locator } from '@playwright/test';

/**
 * ChatKit 2.6 (spec 259): typed cards and host slots in the demo. Alice shares a vault item through the composer action
 * (the host dialog inserts the card with `nowo-chat:insert-card`); Bob sees it locked with "Request access", Carol the
 * neutral chip. "Save to vault" gets the selected text in the browser only. With CARD_SHOTS=<dir>, light and dark
 * screenshots are written there.
 */
test.describe.configure({ timeout: 90_000 });

const shots = process.env.CARD_SHOTS ?? '';

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
  await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: with_ }).first().click();
  const window = page.locator('.nowo-chat-window', { hasText: with_ });
  await expect(window).toBeVisible();
  return window;
}

async function shoot(page: Page, name: string, target: Locator): Promise<void> {
  if (shots === '') {
    return;
  }
  for (const scheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.evaluate((theme) => document.documentElement.setAttribute('data-theme', theme), scheme);
    await page.waitForTimeout(200);
    await target.screenshot({ path: `${shots}/${name}-${scheme}.png` });
  }
  await page.emulateMedia({ colorScheme: 'light' });
  await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));
}

async function readAs(browser: Browser, user: string, from: string): Promise<{ page: Page; window: Locator; close: () => Promise<void> }> {
  const context = await browser.newContext();
  const page = await context.newPage();
  const window = await openConversation(page, user, from);
  return { page, window, close: () => context.close() };
}

test.describe('typed cards and slots (2.6)', () => {
  test('Alice shares a vault item; Bob sees it locked with Request access', async ({ page, browser }) => {
    const window = await openConversation(page, 'alice', 'Bob Lee');
    const action = window.locator('.nowo-chat-composer__action[data-action-key="vault"]');
    await expect(action).toBeVisible();
    await action.click();
    const dialog = page.getByTestId('chat-slot-dialog');
    await expect(dialog.getByTestId('demo-vault-items')).toBeVisible();
    await shoot(page, 'cards-dialog', dialog);
    await dialog.getByRole('button', { name: /Prod API key/ }).click();
    const chip = window.locator('.nowo-chat-cardtray__chip');
    await expect(chip).toContainText('Prod API key');
    await window.locator('.nowo-chat-composer__input').fill(`Here it is ${Date.now()}`);
    await shoot(page, 'cards-composer', window);
    await window.locator('.nowo-chat-composer__input').press('Enter');
    const card = window.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending) .nowo-chat-card').last();
    await expect(card).toContainText('Prod API key');
    await expect(card).toHaveClass(/nowo-chat-card--open/);
    await shoot(page, 'cards-open', window);

    const bob = await readAs(browser, 'bob', 'Alice Martin');
    const locked = bob.window.locator('.nowo-chat-card').last();
    await expect(locked).toHaveClass(/nowo-chat-card--locked/);
    await expect(locked.getByRole('link', { name: 'Request access' })).toBeVisible();
    await shoot(bob.page, 'cards-locked', bob.window);
    await locked.getByRole('link', { name: 'Request access' }).click();
    await expect(bob.page.getByTestId('demo-card-request')).toContainText('Prod API key');
    await bob.close();
  });

  test('Save to vault gets the selection in the browser only', async ({ page }) => {
    const window = await openConversation(page, 'alice', 'Bob Lee');
    const text = `token-${Date.now()}`;
    await window.locator('.nowo-chat-composer__input').fill(text);
    await window.locator('.nowo-chat-composer__input').press('Enter');
    const bubble = window.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending) .nowo-chat-bubble', { hasText: text }).last();
    await expect(bubble).toBeVisible();
    const requests: string[] = [];
    page.on('request', (request) => requests.push(request.url()));
    // A text selection keeps the browser's own context menu (UiKit), so the slot is reached through the kebab.
    const message = window.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending)', { hasText: text }).last();
    await bubble.selectText();
    await message.hover();
    await message.locator('.nowo-chat-msg__menu summary').click();
    const item = message.locator('[data-part="menu-slot"][data-slot-key="save_vault"]');
    await expect(item).toBeVisible();
    await expect(item).toHaveText('Save to vault');
    await item.click();
    const field = page.getByTestId('demo-vault-value');
    await expect(field).toHaveText(text);
    expect(requests.some((url) => url.includes(text))).toBe(false);
  });
});
