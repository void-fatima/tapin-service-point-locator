/* Actual admin assets, isolated network fixtures; no WordPress records or settings written. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '..');
const rows = fs.readFileSync(path.join(__dirname, 'fixtures/mixed-provider-schema.csv'), 'utf8').trim().split(/\r?\n/).map(line => line.split(','));
const fields = ['name', 'postal_code', 'address', 'province', 'city', 'latitude', 'longitude', 'phone', 'provider'];
const mapping = Object.fromEntries(rows[0].map((header, index) => [header, fields[index]]));
const providers = [{ id: 11, name: 'تیپاکس', slug: 'tipax', is_active: 1 }, { id: 22, name: 'شرکت ملی پست', slug: 'post', is_active: 1 }, { id: 33, name: 'حمل ریلی آزمایشی', slug: 'rail', is_active: 1 }];
const values = [...new Set(rows.slice(1).map(row => row[8]))].map(value => ({ value, total: rows.slice(1).filter(row => row[8] === value).length, provider_id: value === 'تیپاکس' ? 11 : value === 'پست' ? 22 : 0 }));
const preview = { id: 1, filename: 'mixed-schema.xlsx', status: 'preview', created_at: '2026-10-06 00:00:00', data: { headers: rows[0], mapping, preview: rows.slice(1, 6), provider_values: values, total: rows.length - 1, processed: 0, inserted: 0, updated: 0, skipped: 0, failed: 0, warnings: 0, issues: [] } };

(async () => {
  const executablePath = process.platform === 'win32' && fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe') ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined;
  const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  try {
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.addInitScript(value => localStorage.setItem('tapin-color-scheme', value), theme);
      const errors = [], requests = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const url = new URL(route.request().url()), endpoint = url.pathname.replace(/^\/api\//, '');
        if (url.pathname.startsWith('/api/')) {
          if (endpoint === 'providers') return route.fulfill({ json: providers });
          if (endpoint === 'locations') return route.fulfill({ json: [] });
          if (/\/start$/.test(endpoint)) { requests.push(route.request().postDataJSON()); return route.fulfill({ json: { ...preview, status: 'running' } }); }
          if (/\/step$/.test(endpoint)) return route.fulfill({ json: { ...preview, status: 'completed', data: { ...preview.data, processed: preview.data.total, inserted: preview.data.total } } });
          if (endpoint === 'imports/2') return route.fulfill({ json: { ...preview, id: 2, data: { ...preview.data, mapping: { ...mapping, [rows[0][0]]: '' } } } });
          if (endpoint === 'imports/3') return route.fulfill({ json: { ...preview, id: 3, data: { ...preview.data, headers: rows[0].slice(0, 8), mapping: Object.fromEntries(Object.entries(mapping).filter(([, field]) => field !== 'provider')), provider_values: [], preview: rows.slice(1, 6).map(row => row.slice(0, 8)) } } });
          return route.fulfill({ json: preview });
        }
        if (url.pathname.startsWith('/assets/')) {
          const file = url.pathname.slice(1), ext = path.extname(file);
          const types = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
          return route.fulfill({ body: fs.readFileSync(path.join(root, file)), contentType: types[ext] || 'application/octet-stream' });
        }
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/dashboard.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="toplevel_page_tapin-locator"><div id="tapin-admin" class="tapin-app" dir="rtl"></div><script>window.TapinConfig={api:"/api/",assets:"/assets/",nonce:"fixture"};</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script><script src="/assets/admin.js"></script></body></html>' });
      });
      await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#imports?id=1');
      await expect(page.locator('#mapping-form')).toBeVisible();
      await expect(page.locator('.mapping-provider-choice')).toBeHidden();
      await expect(page.locator('[name=provider_id]')).toBeDisabled();
      await expect(page.locator('[data-provider-value="تیپاکس"]')).toHaveValue('11');
      await expect(page.locator('[data-provider-value="پست"]')).toHaveValue('22');
      const rail = page.locator('[data-provider-value="قطار بار"]');
      await expect(rail).toHaveValue('');
      expect(await rail.evaluate(el => el.required && !el.checkValidity())).toBe(true);
      await page.locator('#mapping-form button[type=submit]').click();
      expect(requests).toHaveLength(0);
      await rail.selectOption('33');
      await expect(page.locator('[data-provider-mapping]')).toContainText('قطار بار');
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      await page.locator('#mapping-form button[type=submit]').click();
      await expect.poll(() => requests.length).toBe(1);
      expect(requests[0]).toEqual({ provider_id: null, duplicate_action: 'skip', provider_mapping: { 'تیپاکس': '11', 'پست': '22', 'قطار بار': '33' } });
      await expect(page.locator('#mapping-form')).toHaveCount(0);
      await page.evaluate(() => { location.hash = 'imports?id=2'; });
      await expect(page.locator('[data-missing-columns]')).toContainText('نام شعبه');
      await expect(page.locator('[data-missing-columns]')).not.toContainText('استان');
      await expect(page.locator('#mapping-form button[type=submit]')).toBeDisabled();
      await page.evaluate(() => { location.hash = 'imports?id=3'; });
      await expect(page.locator('.mapping-provider-choice')).toBeVisible();
      const fallback = page.locator('#mapping-form [name=provider_id]');
      await expect(fallback).toHaveValue('');
      expect(await fallback.evaluate(el => el.required && !el.checkValidity())).toBe(true);
      await fallback.selectOption('11');
      await page.locator('#mapping-form button[type=submit]').click();
      await expect.poll(() => requests.length).toBe(2);
      expect(requests[1]).toEqual({ provider_id: '11', duplicate_action: 'skip', provider_mapping: {} });
      expect(errors).toEqual([]);
      await page.close();
    }
    console.log('PASS mixed-provider-preview: recognized headers, explicit rail choice, no global override, exact missing-column alert, explicit fallback and responsive layout in light/dark desktop/mobile.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
