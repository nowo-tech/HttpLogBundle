import { test, expect, type Page } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { seedTraffic } from './helpers';

/**
 * REQ-DEMO-013 — the Http Log admin pages in full demo context: demo navbar → UiKit page → Symfony WebProfiler
 * toolbar, captured as a padded union clip on a viewport tall enough for the whole page.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

const WIDTH = 1280;
const PAD = 16;
const CHROME = 'nav.navbar, main';

test.use({ viewport: { width: WIDTH, height: 900 } });

test.beforeAll(async ({ request }) => {
  mkdirSync(outDir, { recursive: true });
  await seedTraffic(request);
});

/** Wait for the loaded profiler toolbar and grow the viewport so the whole page fits above it. */
async function fitViewport(page: Page): Promise<void> {
  const toolbar = page.locator('.sf-toolbar .sf-toolbarreset');
  await expect(toolbar).toBeVisible({ timeout: 15_000 });
  // the toolbar first renders a "Loading…" stub: wait for the real panels
  await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading', { timeout: 15_000 });
  const toolbarHeight = Math.ceil((await toolbar.boundingBox())?.height ?? 36);
  const contentBottom = await page.evaluate((sel) => Math.max(...[...document.querySelectorAll(sel)]
    .map((el) => el.getBoundingClientRect().bottom + window.scrollY)), CHROME);
  await page.setViewportSize({ width: WIDTH, height: Math.max(720, Math.ceil(contentBottom) + 24 + toolbarHeight) });
  await page.evaluate(() => window.scrollTo(0, 0));
}

async function shoot(page: Page, file: string): Promise<void> {
  await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading');
  const box = await page.evaluate((sel) => {
    const rects = [...document.querySelectorAll(`${sel}, .sf-toolbar .sf-toolbarreset`)]
      .map((el) => el.getBoundingClientRect())
      .filter((r) => r.width > 0 && r.height > 0);
    return {
      x: Math.min(...rects.map((r) => r.left)),
      y: Math.min(...rects.map((r) => r.top)),
      r: Math.max(...rects.map((r) => r.right)),
      b: Math.max(...rects.map((r) => r.bottom)),
    };
  }, CHROME);
  const vp = page.viewportSize()!;
  const x = Math.max(0, box.x - PAD);
  const y = Math.max(0, box.y - PAD);
  await page.screenshot({
    path: resolve(outDir, `${file}.png`),
    clip: { x, y, width: Math.min(vp.width, box.r + PAD) - x, height: Math.min(vp.height, box.b + PAD) - y },
  });
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
    await expect(page.locator('.sf-toolbar').first()).not.toContainText('Loading', { timeout: 15_000 });
    await page.locator('.nowo-ui-action--delete').first().click();
    await expect(page.locator('dialog[open]')).toBeVisible();
    await page.waitForTimeout(300); // dialog open transition
    await shoot(page, 'admin-delete-confirm');
  });
});
