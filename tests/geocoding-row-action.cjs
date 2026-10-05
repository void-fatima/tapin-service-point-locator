/* Compact row-level geocoding action regression coverage. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const providers = [{ id: 1, slug: 'fixture', name: 'Fixture provider', is_active: 1 }];
const locations = [{ provider_id: 1, province: 'تهران', city: 'تهران' }];
const points = [
  { id: 1, provider_id: 1, name: 'Located branch', code: 'L-1', province: 'تهران', city: 'تهران', address: 'نشانی دارای مختصات', latitude: 35.7, longitude: 51.4, has_coordinates: true, status: 'active', metadata: null },
  { id: 2, provider_id: 1, name: 'Address-only branch', code: 'A-1', province: 'تهران', city: 'تهران', address: 'نشانی بدون مختصات', latitude: null, longitude: null, has_coordinates: false, status: 'active', metadata: null },
];

(async () => {
  const executablePath = process.platform === 'win32' && fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe')
    ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined;
  const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  try {
    for (const width of [1440, 390]) for (const theme of ['dark', 'light']) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      await page.addInitScript(value => localStorage.setItem('tapin-color-scheme', value), theme);
      const errors = [];
      let retryRequest = null;
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.pathname.startsWith('/api/')) {
          const endpoint = url.pathname.slice('/api/'.length);
          if (endpoint === 'providers') return route.fulfill({ json: providers });
          if (endpoint === 'locations') return route.fulfill({ json: locations });
          if (endpoint === 'geocoding') return route.fulfill({ json: { configured: true, items: [], counts: {} } });
          if (endpoint === 'geocoding/retry') {
            retryRequest = request.postDataJSON();
            return route.fulfill({ json: { configured: true, items: [{ point_id: 2, status: 'pending', attempts: 1 }] } });
          }
          if (endpoint === 'points') return route.fulfill({ json: { items: points, total: points.length, page: 1, per_page: 20, total_pages: 1 } });
          return route.fulfill({ json: { items: [], total: 0 } });
        }
        if (url.pathname.startsWith('/assets/')) {
          const file = url.pathname.slice(1), ext = path.extname(file);
          const types = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.geojson': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
          return route.fulfill({ body: fs.readFileSync(path.join(root, file)), contentType: types[ext] || 'application/octet-stream' });
        }
        if (url.pathname.startsWith('/tiles/')) return route.fulfill({ status: 204 });
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/dashboard.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="toplevel_page_tapin-locator"><div id="tapin-admin" class="tapin-app" dir="rtl"></div><script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}",nonce:"fixture"};</script><script src="/assets/vendor/leaflet.js"></script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script><script src="/assets/admin.js"></script></body></html>' });
      });

      await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points');
      await page.evaluate(value => window.TapinTheme.set(value), theme);
      const rows = page.locator('.points-management-table tbody tr');
      await expect(rows).toHaveCount(2);
      const locatedActions = rows.nth(0).locator('.cell-actions .tapin-row-actions button');
      const addressActions = rows.nth(1).locator('.cell-actions .tapin-row-actions button');
      await expect(locatedActions).toHaveCount(3);
      await expect(addressActions).toHaveCount(4);

      const findButton = addressActions.filter({ has: page.locator('svg') }).last();
      await expect(findButton).toHaveAttribute('aria-label', 'یافتن موقعیت');
      await expect(findButton).toHaveAttribute('data-tooltip', 'یافتن موقعیت');
      await expect(findButton).toHaveAttribute('title', 'یافتن موقعیت');
      await expect(findButton).toHaveText('');
      await expect(findButton.locator('svg')).toBeVisible();
      await findButton.hover();
      await expect.poll(() => findButton.evaluate(el => getComputedStyle(el, '::after').opacity)).toBe('1');
      expect(await findButton.evaluate(el => getComputedStyle(el, '::after').content)).toContain('یافتن موقعیت');
      await findButton.focus();
      await page.keyboard.press('Tab');
      await findButton.focus();
      await expect(findButton).toBeFocused();
      await expect.poll(() => findButton.evaluate(el => getComputedStyle(el, '::after').opacity)).toBe('1');
      expect(await findButton.evaluate(el => getComputedStyle(el).outlineStyle)).not.toBe('none');

      const geometry = await page.evaluate(() => {
        const button = document.querySelector('.btn-geocode'), actions = button.parentElement, cell = actions.parentElement;
        const box = element => { const r = element.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom }; };
        return { button: box(button), actions: box(actions), cell: box(cell), buttonRects: [...actions.querySelectorAll('button')].map(box), scroller: { width: document.querySelector('.points-management-table').clientWidth, scrollWidth: document.querySelector('.points-management-table').scrollWidth }, documentWidth: document.documentElement.scrollWidth, viewportWidth: innerWidth };
      });
      expect(geometry.button.left).toBeGreaterThanOrEqual(geometry.actions.left - 1);
      expect(geometry.button.right).toBeLessThanOrEqual(geometry.actions.right + 1);
      expect(geometry.actions.left).toBeGreaterThanOrEqual(geometry.cell.left - 1);
      expect(geometry.actions.right).toBeLessThanOrEqual(geometry.cell.right + 1);
      for (let i = 0; i < geometry.buttonRects.length; i++) for (let j = i + 1; j < geometry.buttonRects.length; j++) {
        const a = geometry.buttonRects[i], b = geometry.buttonRects[j];
        expect(Math.min(a.right, b.right) - Math.max(a.left, b.left)).toBeLessThanOrEqual(0);
      }
      expect(geometry.documentWidth).toBeLessThanOrEqual(geometry.viewportWidth + 1);
      if (width === 390) expect(geometry.scroller.scrollWidth).toBeGreaterThan(geometry.scroller.width);

      await findButton.click();
      await expect.poll(() => retryRequest).toEqual({ ids: [2] });
      expect(errors).toEqual([]);
      await page.close();
    }
    console.log('PASS geocoding-row-action: address-only rows retain the compact action; located rows do not; tooltip, keyboard focus, correct retry target and contained table geometry verified at desktop/mobile in dark/light.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exit(1); });
