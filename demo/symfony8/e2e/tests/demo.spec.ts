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

  test('filter row: grouped fields, "Any" selects, native date-time pickers', async ({ page }) => {
    await page.goto('/admin/http-log');
    const filters = page.locator('[data-nowo-ui-region="filters"]');
    const names = await filters.locator('input[name^="http_log_filter"], select[name^="http_log_filter"]').evaluateAll((els) =>
      els.map((el) => (el as HTMLInputElement).name.replace(/^http_log_filter\[|\]$/g, '')),
    );
    expect(names).toEqual(['q', 'method', 'statusCode', 'clientIp', 'path', 'routeName', 'bodyContentType', 'createdFrom', 'createdTo']);
    await expect(page.locator('#http_log_filter_method option').first()).toHaveText('Any');
    await expect(page.locator('#http_log_filter_q')).toHaveAttribute('placeholder', /Path, route or IP/);
    await expect(page.locator('#http_log_filter_createdFrom')).toHaveAttribute('type', 'datetime-local');
    // created from / to share a row
    const [from, to] = [await page.locator('#http_log_filter_createdFrom').boundingBox(), await page.locator('#http_log_filter_createdTo').boundingBox()];
    expect(Math.abs((from?.y ?? 0) - (to?.y ?? 100))).toBeLessThan(2);
  });

  test('profiler / toolbar requests are not recorded by default', async ({ page }) => {
    await page.goto('/'); // dev: the page loads /_wdt/<token>
    await expect(page.locator('.sf-toolbar').first()).toBeVisible({ timeout: 15_000 });
    await page.goto('/admin/http-log?http_log_filter%5Bpath%5D=%2F_wdt%2F');
    await expect(page.locator('[data-nowo-ui-region="empty"]')).toBeVisible();
    await page.goto('/admin/http-log?http_log_filter%5Bpath%5D=%2F_profiler%2F');
    await expect(page.locator('[data-nowo-ui-region="empty"]')).toBeVisible();
  });

  test('entry page: badges, URL with port, header table with redacted cookie, pretty JSON body', async ({ page, request, baseURL }) => {
    await request.post('/api/echo', { data: { pretty: true, id: 7 }, headers: { Cookie: 'session=secret' } });
    await page.goto('/admin/http-log?http_log_filter%5Bpath%5D=%2Fapi%2Fecho');
    await page.locator('tbody tr').first().locator('.nowo-ui-action--view').click();
    const show = page.locator('[data-nowo-ui-page="show"]');
    await expect(show.locator('.nowo-http-log-method')).toHaveText('POST');
    await expect(show.locator('.nowo-http-log-status')).toHaveText('200');
    const port = new URL(baseURL ?? 'http://127.0.0.1').port;
    await expect(show.locator('#http-log-entry')).toContainText(`:${port}/api/echo`);
    const headers = show.locator('#http-log-request-headers table');
    await expect(headers.locator('tbody tr', { hasText: 'content-type' })).toContainText('application/json');
    await expect(headers.locator('tbody tr', { hasText: 'cookie' }).locator('td')).toHaveText('[REDACTED]');
    await expect(show).not.toContainText('session=secret');
    await expect(show.locator('#http-log-request-body code')).toContainText('"pretty": true');
    const body = show.locator('#http-log-request-body pre');
    expect(await body.evaluate((el) => getComputedStyle(el).whiteSpace)).toBe('pre-wrap');
  });
});
