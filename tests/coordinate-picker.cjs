/* Focused regression coverage for the admin Leaflet coordinate picker. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const providers = [{ id: 1, slug: 'fixture', name: 'Fixture provider', is_active: 1, logo: '' }];
const locations = [{ provider_id: 1, province: 'Tehran', city: 'Tehran' }];
const savedPayloads = [];
const points = [
  { id: 1, provider_id: 1, code: 'BR-1', name: 'Located branch', province: 'Tehran', city: 'Tehran', address: 'Fixture address', status: 'active', latitude: 35.7, longitude: 51.4, has_coordinates: true, metadata: null },
  { id: 2, provider_id: 1, code: 'BR-2', name: 'Address only', province: 'Tehran', city: 'Tehran', address: 'Fixture address', status: 'active', latitude: null, longitude: null, has_coordinates: false, metadata: null },
];

(async () => {
  const installedBrowser = [
    process.env.CHROME_PATH,
    process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : null,
    process.platform === 'win32' ? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe' : null,
  ].find(candidate => candidate && fs.existsSync(candidate));
  const browser = await chromium.launch({ headless: true, ...(installedBrowser ? { executablePath: installedBrowser } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/api/')) {
        const endpoint = url.pathname.slice('/api/'.length);
        if (endpoint === 'providers') return route.fulfill({ json: providers });
        if (endpoint === 'locations') return route.fulfill({ json: locations });
        if (endpoint === 'geocoding') return route.fulfill({ json: { items: [], counts: {} } });
        if (endpoint === 'points' && route.request().method() === 'GET') {
          return route.fulfill({ json: { items: points, total: points.length, page: 1, per_page: 20, total_pages: 1 } });
        }
        if (/^points\/\d+$/.test(endpoint)) return route.fulfill({ json: points.find(point => point.id === Number(endpoint.split('/').pop())) });
        if (endpoint === 'points' && route.request().method() === 'POST') {
          savedPayloads.push(JSON.parse(route.request().postData()));
          return route.fulfill({ json: { item: { id: 3 }, warnings: {} } });
        }
        throw new Error(`Unexpected API request: ${endpoint}`);
      }
      if (url.pathname.startsWith('/assets/')) {
        const relativePath = url.pathname.slice(1);
        const extension = path.extname(relativePath);
        const contentType = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.geojson': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' }[extension] || 'application/octet-stream';
        return route.fulfill({ body: fs.readFileSync(path.join(root, relativePath)), contentType });
      }
      if (url.pathname.startsWith('/tiles/')) return route.fulfill({ status: 204 });
      return route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: '<!doctype html><html><head><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/dashboard.css"></head><body class="toplevel_page_tapin-locator"><div id="tapin-admin" class="tapin-app" dir="rtl"></div><script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}",attribution:"Fixture tiles",nonce:"fixture"};</script><script src="/assets/vendor/leaflet.js"></script><script src="/assets/iran-locations.js"></script><script src="/assets/map.js"></script><script src="/assets/admin.js"></script></body></html>',
      });
    });

    await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points?new=1');
    const dialog = page.locator('dialog.tapin-dialog');
    await expect(dialog).toBeVisible();
    const lat = dialog.locator('[name=latitude]');
    const lng = dialog.locator('[name=longitude]');
    const toggle = dialog.locator('[data-coordinate-picker-toggle]');
    const map = dialog.locator('.coordinate-map');
    const validation = dialog.locator('[data-coordinate-validation]');
    expect(await lat.inputValue()).toBe('');
    expect(await lng.inputValue()).toBe('');
    await toggle.click();
    await expect(map).toBeVisible();
    expect(await map.locator('.leaflet-marker-icon').count()).toBe(0);
    const mapBounds = await map.boundingBox();
    expect(mapBounds.width).toBeGreaterThan(500);
    expect(mapBounds.height).toBeGreaterThan(250);
    await map.click({ position: { x: Math.round(mapBounds.width * 0.6), y: Math.round(mapBounds.height * 0.55) } });
    await expect(lat).not.toHaveValue('');
    await expect(lng).not.toHaveValue('');
    expect(await map.locator('.leaflet-marker-icon').count()).toBe(1);

    const beforeDrag = [await lat.inputValue(), await lng.inputValue()];
    const markerBox = await map.locator('.leaflet-marker-icon').boundingBox();
    const markerX = markerBox.x + markerBox.width / 2, markerY = markerBox.y + markerBox.height - 3;
    await page.mouse.move(markerX, markerY);
    await page.mouse.down();
    await page.mouse.move(markerX + 38, markerY + 14, { steps: 6 });
    await page.mouse.up();
    await expect.poll(async () => [await lat.inputValue(), await lng.inputValue()]).not.toEqual(beforeDrag);

    await lat.fill('35.7');
    await lng.fill('51.4');
    await expect.poll(async () => map.locator('.leaflet-marker-icon').count()).toBe(1);
    const moved = await map.locator('.leaflet-marker-icon').boundingBox();
    const beforeManual = [await lat.inputValue(), await lng.inputValue()];
    await lat.fill('36.1');
    await expect.poll(async () => map.locator('.leaflet-marker-icon').boundingBox()).not.toEqual(moved);
    expect(await lng.inputValue()).toBe(beforeManual[1]);

    await lat.fill('35.7');
    await lng.fill('');
    await expect(validation).toBeVisible();
    await expect(validation).toContainText('هر دو');
    await dialog.locator('[name=name]').fill('Test branch');
    await dialog.locator('[name=province]').fill('Tehran');
    await dialog.locator('[name=address]').fill('Test address');
    await dialog.locator('button[type=submit]').click();
    await expect(dialog.locator('.dialog-error')).toBeVisible();

    await lat.fill('91');
    await lng.fill('51.4');
    await expect(validation).toBeVisible();
    await expect(validation).toContainText('بین');
    await lat.fill('invalid');
    await expect(validation).toContainText('عدد معتبر');

    await dialog.locator('[data-coordinate-clear]').click();
    await expect(lat).toHaveValue('');
    await expect(lng).toHaveValue('');
    await expect(validation).toBeHidden();
    expect(await map.locator('.leaflet-marker-icon').count()).toBe(0);
    await dialog.locator('button[type=submit]').click();
    await expect(dialog).toBeHidden();
    expect(savedPayloads).toHaveLength(1);
    expect(savedPayloads[0].latitude).toBe('');
    expect(savedPayloads[0].longitude).toBe('');

    await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points?edit=1');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[name=latitude]')).toHaveValue('35.7');
    await expect(dialog.locator('[name=longitude]')).toHaveValue('51.4');
    await dialog.locator('[data-coordinate-picker-toggle]').click();
    const existingMarker = dialog.locator('.coordinate-map .leaflet-marker-icon');
    await expect(existingMarker).toHaveCount(1);
    await expect.poll(async () => existingMarker.boundingBox()).toBeTruthy();

    await page.setViewportSize({ width: 390, height: 844 });
    const mobileMapBox = await dialog.locator('.coordinate-map').boundingBox();
    const mobileDialogBox = await dialog.boundingBox();
    expect(mobileMapBox.width).toBeLessThanOrEqual(mobileDialogBox.width);
    expect(await dialog.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
    await page.keyboard.press('Escape');
    await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points?edit=2');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[name=latitude]')).toHaveValue('');
    await expect(dialog.locator('[name=longitude]')).toHaveValue('');
    await dialog.locator('[data-coordinate-picker-toggle]').click();
    await expect(dialog.locator('.coordinate-map .leaflet-marker-icon')).toHaveCount(0);
    expect(errors).toEqual([]);
    console.log('PASS coordinate-picker: blank default, map click, marker drag, manual edit, optional clear, invalid/incomplete validation, existing point, and mobile fit.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
