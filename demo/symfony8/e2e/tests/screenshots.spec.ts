import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { seedTraffic } from './helpers';

/**
 * REQ-DEMO-013 — the Http Log admin pages in the demo platform shell (top bar, aside, page), captured without the
 * Symfony WebProfiler toolbar on a viewport tall enough for the whole page.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;
const HIDE_TOOLBAR = '.sf-toolbar, .sf-minitoolbar, [id^="sfwdt"] { display: none !important; }';

test.use({ viewport: { width: WIDTH, height: 900 } });

test.beforeAll(async ({ request }) => {
  mkdirSync(outDir, { recursive: true });
  await seedTraffic(request);
});

/** Grow the viewport so the whole shell fits, without the profiler toolbar. */
async function fitViewport(page: Page): Promise<void> {
  await page.addStyleTag({ content: HIDE_TOOLBAR });
  const height = await page.evaluate(() => Math.ceil(document.documentElement.scrollHeight));
  await page.setViewportSize({ width: WIDTH, height: Math.max(720, height) });
  await page.evaluate(() => window.scrollTo(0, 0));
}

async function shoot(page: Page, file: string): Promise<void> {
  await page.addStyleTag({ content: HIDE_TOOLBAR });
  await page.locator('[data-testid="demo-platform"]').screenshot({ path: resolve(outDir, `${file}.png`) });
}

/** The newest logged JSON POST to /api/echo. */
async function openEchoEntry(page: Page): Promise<void> {
  await page.goto('/admin/http-log?http_log_filter%5Bpath%5D=%2Fapi%2Fecho');
  await page.locator('tbody tr').first().locator('.nowo-ui-action--view').click();
  await expect(page.locator('[data-nowo-ui-page="show"]')).toBeVisible();
}

test.describe('http log screenshots', () => {
  test('admin list — captured traffic, filters, export / purge actions', async ({ page }) => {
    await page.goto('/admin/http-log');
    await expect(page.locator('[data-nowo-ui-page="list"] tbody tr').first()).toBeVisible();
    await fitViewport(page);
    await shoot(page, 'admin-list');
  });

  test('entry — request / response headers and bodies of a JSON POST', async ({ page }) => {
    await openEchoEntry(page);
    await fitViewport(page);
    await shoot(page, 'admin-show');
  });

  test('entry — delete confirm dialog', async ({ page }) => {
    await openEchoEntry(page);
    await page.setViewportSize({ width: WIDTH, height: 900 });
    await page.locator('.nowo-ui-action--delete').first().click();
    await expect(page.locator('dialog[open]')).toBeVisible();
    await page.waitForTimeout(300); // dialog open transition
    await shoot(page, 'admin-delete-confirm');
  });
});
