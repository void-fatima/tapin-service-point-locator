/* Focused regression coverage for admin province and dependent city selects. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const providers = [{ id: 1, slug: 'fixture', name: 'Fixture provider', is_active: 1, logo: '' }];
const locations = [{ provider_id: 1, province: 'تهران', city: 'تهران' }];
const points = [
  { id: 1, provider_id: 1, code: 'BR-1', name: 'Located branch', province: 'تهران', city: 'تهران', address: 'نشانی آزمایشی', status: 'active', latitude: null, longitude: null, has_coordinates: false, metadata: null },
  { id: 2, provider_id: 1, code: 'BR-2', name: 'Legacy branch', province: 'استان قدیمی', city: 'شهر قدیمی', address: 'نشانی قدیمی', status: 'active', latitude: null, longitude: null, has_coordinates: false, metadata: null },
];
const savedPayloads = [];

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
        if (endpoint === 'points' && route.request().method() === 'GET') return route.fulfill({ json: { items: points, total: points.length, page: 1, per_page: 20, total_pages: 1 } });
        if (/^points\/\d+$/.test(endpoint) && route.request().method() === 'GET') return route.fulfill({ json: points.find(point => point.id === Number(endpoint.split('/').pop())) });
        if (/^points(?:\/\d+)?$/.test(endpoint) && route.request().method() === 'POST') {
          savedPayloads.push(JSON.parse(route.request().postData()));
          return route.fulfill({ json: { item: { id: 2 }, warnings: {} } });
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

    const baseUrl = 'http://tapin.test/wp-admin/admin.php?page=tapin-locator';
    await page.goto(`${baseUrl}#points?new=1`);
    const dialog = page.locator('dialog.tapin-dialog');
    await expect(dialog).toBeVisible();
    const province = dialog.locator('[name=province]');
    const city = dialog.locator('[name=city]');
    await expect(city).toBeDisabled();
    await expect(province.locator('option')).toHaveCount(32);
    await province.selectOption('تهران');
    await expect(city).toBeEnabled();
    await expect(city.locator('option')).toHaveCount(1 + await page.evaluate(() => window.TapinLocationCatalog.find(row => row.province === 'تهران').cities.length));
    await city.selectOption('تهران');
    await province.selectOption('یزد');
    await expect(city).toHaveValue('');
    await expect(city.locator('option[value="تهران"]')).toHaveCount(0);
    await city.selectOption('یزد');

    await page.keyboard.press('Escape');
    await page.goto(`${baseUrl}#points?edit=1`);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[name=province]')).toHaveValue('تهران');
    await expect(dialog.locator('[name=city]')).toHaveValue('تهران');

    await page.keyboard.press('Escape');
    await page.goto(`${baseUrl}#points?edit=2`);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('[name=province]')).toHaveValue('استان قدیمی');
    await expect(dialog.locator('[name=province] option:checked')).toContainText('خارج از فهرست');
    await expect(dialog.locator('[name=city]')).toBeEnabled();
    await expect(dialog.locator('[name=city]')).toHaveValue('شهر قدیمی');
    await expect(dialog.locator('[name=city] option:checked')).toContainText('خارج از فهرست');
    await dialog.locator('button[type=submit]').click();
    await expect(dialog).toBeHidden();
    expect(savedPayloads).toHaveLength(1);
    expect(savedPayloads[0].province).toBe('استان قدیمی');
    expect(savedPayloads[0].city).toBe('شهر قدیمی');
    expect(errors).toEqual([]);
    console.log('PASS admin-location-selectors: province list, dependent city options, incompatible city clearing, existing values, and unknown-value retention.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
