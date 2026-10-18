import { test, expect } from '@playwright/test';
import { seedTraffic } from './helpers';

/**
 * REQ-DEMO-013 / REQ-UI-003 — the Http Log admin pages (UiKit list / show scaffolds) inside the demo host layout:
 * captured traffic is listed, filtered, inspected and deleted through the UiKit confirm dialog.
 */
test.describe('http log admin', () => {
  test.beforeAll(async ({ request }) => {
    await seedTraffic(request);
  });

  test('home renders', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('HTTP request / response logging');
  });

  test('list shows captured traffic and filters by method', async ({ page }) => {
    await page.goto('/admin/http-log');
    const list = page.locator('[data-nowo-ui-page="list"][data-testid="http-log-list"]');
    await expect(list).toBeVisible();
    await expect(page.getByRole('heading', { level: 1, name: 'HTTP Log Entries' })).toBeVisible();
    await expect(list.locator('[data-nowo-ui-region="filters"]')).toBeVisible();
    await expect(list.locator('tbody tr', { hasText: '/api/echo' }).first()).toBeVisible();

    await page.locator('#http_log_filter_method').selectOption('POST');
    await page.locator('.nowo-ui-filters__submit').click();
    await expect(page).toHaveURL(/http_log_filter%5Bmethod%5D=POST|http_log_filter\[method\]=POST/);
    await expect(page.locator('#http_log_filter_method')).toHaveValue('POST');
    const methods = list.locator('tbody .nowo-http-log-method');
    await expect(methods.first()).toHaveText('POST');
    expect((await methods.allTextContents()).every((m) => m.trim() === 'POST')).toBe(true);
  });

  test('purge opens the UiKit confirm dialog', async ({ page }) => {
    await page.goto('/admin/http-log');
    await page.getByRole('button', { name: 'Purge old entries' }).click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-nowo-confirm-close]').first().click();
    await expect(page.locator('dialog[open]')).toHaveCount(0);
  });

  test('entry page shows the exchange and deletes through the confirm dialog', async ({ page, request }) => {
    await request.post('/api/echo', { data: { e2e: Date.now() } });
    await page.goto('/admin/http-log?http_log_filter%5Bpath%5D=%2Fapi%2Fecho');
    await page.locator('tbody tr').first().locator('.nowo-ui-action--view').click();
    await expect(page.locator('[data-nowo-ui-page="show"][data-testid="http-log-show"]')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('HTTP Log Entry #');
    await expect(page.locator('main')).toContainText('/api/echo');

    await page.locator('.nowo-ui-action--delete').first().click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toBeVisible();
    await dialog.locator('.nowo-ui-confirm__submit').click();
    await expect(page.locator('[data-nowo-ui-page="list"]')).toBeVisible();
  });
});
