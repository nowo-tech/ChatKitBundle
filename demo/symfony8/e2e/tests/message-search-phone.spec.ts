import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * 1.39.1 — the message search page on a phone (Beacon report): UiKit page anatomy at 375 and 414 px wide — title and
 * back link, the text field with its label above it, the primary "Search" button, the filters folded in a disclosure,
 * the results area with its empty states — no horizontal scroll and no axe violation. Runs in the phone projects.
 * SEARCH_SHOTS_DIR (optional) saves one screenshot per width and state.
 */
test.describe.configure({ timeout: 90_000 });

const shotsDir = process.env.SEARCH_SHOTS_DIR;

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

async function sendToBob(page: Page, text: string): Promise<void> {
  await page.route('**/.well-known/mercure**', (route) => route.abort());
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Bob Lee' }).first().click();
  const input = page.locator('.nowo-chat-composer__input');
  await input.fill(text);
  await input.press('Enter');
  await expect(page.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text }).first()).toBeAttached();
}

async function noHorizontalScroll(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
}

async function shot(page: Page, name: string, projectName: string): Promise<void> {
  if (!shotsDir) {
    return;
  }
  mkdirSync(shotsDir, { recursive: true });
  await page.screenshot({ path: resolve(shotsDir, `${name}-${projectName}.png`), fullPage: true });
}

async function axe(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).include('[data-testid="chat-search"]').analyze();
  expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

for (const width of [375, 414]) {
  test.describe(`message search page at ${width}px (1.39.1)`, () => {
    test.use({ viewport: { width, height: 844 } });

    test('page anatomy, filters disclosure, empty states and results', async ({ page }, testInfo) => {
      const word = `meridian${Date.now()}`;
      await signIn(page, 'alice');
      await sendToBob(page, `Release plan ${word} for the phone`);

      await page.goto('/chat/search');
      await expect(page.getByRole('heading', { level: 1, name: 'Search messages' })).toBeVisible();
      const back = page.getByTestId('chat-search-back');
      await expect(back).toBeVisible();
      await expect(back).toHaveAttribute('href', /\/chat\/?(\?.*)?$/);

      const input = page.getByTestId('chat-search-input');
      const label = page.locator('label[for="q"]');
      await expect(label).toBeVisible();
      const labelBox = await label.boundingBox();
      const inputBox = await input.boundingBox();
      expect(labelBox && inputBox && labelBox.y + labelBox.height <= inputBox.y + 1).toBeTruthy();
      expect(inputBox!.x + inputBox!.width).toBeLessThanOrEqual(width);

      const submit = page.getByTestId('chat-search-submit');
      await expect(submit).toBeVisible();
      await expect(submit).toHaveClass(/nowo-ui-btn--primary/);
      const submitBox = await submit.boundingBox();
      expect(submitBox!.height).toBeGreaterThanOrEqual(44);

      const filters = page.getByTestId('chat-search-filters');
      await expect(filters).not.toHaveAttribute('open', /.*/);
      await expect(page.getByLabel('Has a link')).toBeHidden();
      await expect(page.getByTestId('chat-search-start')).toBeVisible();
      await noHorizontalScroll(page);
      await axe(page);
      await shot(page, `search-${width}-start`, testInfo.project.name);

      await filters.locator('summary').click();
      await expect(page.getByLabel('Has a link')).toBeVisible();
      await noHorizontalScroll(page);
      await axe(page);
      await shot(page, `search-${width}-filters`, testInfo.project.name);

      await input.fill('nothinglikethisatall');
      await submit.click();
      await expect(page.getByTestId('chat-search-empty')).toBeVisible();
      await expect(page.getByTestId('chat-search-empty')).toContainText('No results');
      await noHorizontalScroll(page);
      await axe(page);

      await page.getByTestId('chat-search-input').fill(word);
      await page.getByTestId('chat-search-submit').click();
      const item = page.getByTestId('chat-search-item');
      await expect(item).toHaveCount(1);
      await expect(item.locator('mark')).toHaveText(word);
      await noHorizontalScroll(page);
      await axe(page);
      await shot(page, `search-${width}-results`, testInfo.project.name);

      await page.getByTestId('chat-search-back').click();
      await expect(page).toHaveURL(/\/chat\/?(\?.*)?$/);
    });
  });
}
