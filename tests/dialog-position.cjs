/* Focused admin service-point details dialog regression coverage. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const theme = process.env.TAPIN_TEST_THEME || 'dark';
const providers = [{ id: 1, slug: 'fixture', name: 'Fixture provider', is_active: 1, logo: '' }];
const locations = [{ provider_id: 1, province: 'Tehran', city: 'Tehran' }];
const longAddress = ('Long address segment for modal scrolling. ').repeat(180);
const points = Array.from({ length: 23 }, (_, index) => ({
  id: index + 1,
  provider_id: 1,
  code: `BR-${index + 1}`,
  name: `Branch ${index + 1}`,
  province: 'Tehran',
  city: 'Tehran',
  address: index === 19 || index === 22 ? longAddress : 'Fixture address',
  postal_code: '',
  phone: '',
  mobile_phone: '',
  landline_phone: '',
  source: '',
  status: 'active',
  has_coordinates: true,
  latitude: 35.7,
  longitude: 51.4,
  data_quality_status: 'verified',
  metadata: null,
}));

function assertCenteredAndContained(bounds, viewport, tolerance = 2) {
  expect(Math.abs((bounds.x + bounds.width / 2) - viewport.width / 2)).toBeLessThanOrEqual(tolerance);
  expect(Math.abs((bounds.y + bounds.height / 2) - viewport.height / 2)).toBeLessThanOrEqual(tolerance);
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  expect(bounds.y).toBeGreaterThanOrEqual(0);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(bounds.y + bounds.height).toBeLessThanOrEqual(viewport.height + 1);
}

(async () => {
  const installedBrowser = [
    process.env.CHROME_PATH,
    process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : null,
    process.platform === 'win32' ? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe' : null,
  ].find(candidate => candidate && fs.existsSync(candidate));
  const browser = await chromium.launch({ headless: true, ...(installedBrowser ? { executablePath: installedBrowser } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.addInitScript(theme => localStorage.setItem('tapin-color-scheme',theme), theme);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));

    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/api/')) {
        const endpoint = url.pathname.slice('/api/'.length);
        if (endpoint === 'providers') return route.fulfill({ json: providers });
        if (endpoint === 'locations') return route.fulfill({ json: locations });
        if (endpoint === 'geocoding') return route.fulfill({ json: { items: [], counts: {} } });
        if (/^points\/\d+$/.test(endpoint)) {
          return route.fulfill({ json: points.find(point => point.id === Number(endpoint.split('/').pop())) });
        }
        if (endpoint === 'points') {
          const pageNumber = Number(url.searchParams.get('page') || 1);
          const perPage = Number(url.searchParams.get('per_page') || 20);
          return route.fulfill({
            json: {
              items: points.slice((pageNumber - 1) * perPage, pageNumber * perPage),
              total: points.length,
              page: pageNumber,
              per_page: perPage,
              total_pages: Math.ceil(points.length / perPage),
            },
          });
        }
        throw new Error(`Unexpected API request: ${endpoint}`);
      }
      if (url.pathname.startsWith('/assets/')) {
        const relativePath = url.pathname.slice(1);
        const extension = path.extname(relativePath);
        const contentType = {
          '.js': 'application/javascript; charset=utf-8',
          '.css': 'text/css; charset=utf-8',
          '.json': 'application/json; charset=utf-8',
          '.geojson': 'application/json; charset=utf-8',
          '.svg': 'image/svg+xml',
          '.png': 'image/png',
          '.woff2': 'font/woff2',
        }[extension] || 'application/octet-stream';
        return route.fulfill({ body: fs.readFileSync(path.join(root, relativePath)), contentType });
      }
      if (url.pathname.startsWith('/tiles/')) return route.fulfill({ status: 204 });
      return route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html><head><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/dashboard.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="toplevel_page_tapin-locator"><div id="tapin-admin" class="tapin-app" dir="rtl"></div><script>window.TapinConfig={api:'/api/',assets:'/assets/',tiles:'/tiles/{z}/{x}/{y}',nonce:'fixture'};</script><script src="/assets/vendor/leaflet.js"></script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script><script src="/assets/admin.js"></script></body></html>`,
      });
    });

    await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points');
    const dialog = page.locator('dialog.tapin-dialog');
    const main = page.locator('.tapin-main');
    const trigger = page.locator('.points-management-table tbody tr').last().locator('[data-view]');
    await expect(trigger).toBeVisible();
    await main.evaluate(element => { element.scrollTop = element.scrollHeight; });
    expect(await main.evaluate(element => element.scrollTop)).toBeGreaterThan(0);

    await trigger.click();
    await expect(dialog).toBeVisible();
    await expect(page.locator('#tapin-admin')).toHaveAttribute('data-theme-mode',theme);
    const desktopBounds = await dialog.boundingBox();
    assertCenteredAndContained(desktopBounds, { width: 1440, height: 900 });
    expect(await dialog.evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);

    const headerClose = dialog.locator('.close-dialog');
    await headerClose.focus();
    await page.keyboard.press('Shift+Tab');
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Tab');
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    expect(await trigger.evaluate(element => document.activeElement === element)).toBe(true);

    await trigger.click();
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-close-sheet]').click();
    await expect(dialog).toBeHidden();
    expect(await trigger.evaluate(element => document.activeElement === element)).toBe(true);

    await trigger.click();
    await expect(dialog).toBeVisible();
    await dialog.locator('[data-switch-edit]').click();
    await expect(dialog.locator('input[name=name]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    expect(await trigger.evaluate(element => document.activeElement === element)).toBe(true);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points?page=2');
    const mobileTrigger = page.locator('.points-management-table tbody tr').last().locator('[data-view]');
    await mobileTrigger.click();
    await expect(dialog).toBeVisible();
    const mobileBounds = await dialog.boundingBox();
    assertCenteredAndContained(mobileBounds, { width: 390, height: 844 });
    expect(await dialog.evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true);
    await dialog.evaluate(element => { element.scrollTop = element.scrollHeight; });
    const mobileSizes = await dialog.evaluate(element => ({
      clientWidth: element.clientWidth,
      scrollWidth: element.scrollWidth,
      actionBounds: [...element.querySelectorAll('.sheet-actions button')].map(button => {
        const rect = button.getBoundingClientRect();
        return { left: rect.left, right: rect.right };
      }),
    }));
    expect(mobileSizes.scrollWidth).toBeLessThanOrEqual(mobileSizes.clientWidth + 1);
    for (const action of mobileSizes.actionBounds) {
      expect(action.left).toBeGreaterThanOrEqual(mobileBounds.x);
      expect(action.right).toBeLessThanOrEqual(mobileBounds.x + mobileBounds.width + 1);
    }
    await dialog.evaluate(element => { element.scrollTop = 0; });
    expect(await dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
    await dialog.locator('.close-dialog').click();
    await expect(dialog).toBeHidden();
    expect(await mobileTrigger.evaluate(element => document.activeElement === element)).toBe(true);
    expect(errors).toEqual([]);
    console.log('PASS dialog-position: desktop viewport centering after scroll, bounded internal scrolling, focus containment/return, Escape, close/edit actions, and mobile fit.');
  } finally {
    await browser.close();
  }
})().catch(error => {
  console.error(error);
  process.exit(1);
});
