/* Focused dashboard filter-fold regression: compare against the Service Points bar. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const providers = [{ id: 1, slug: 'fixture', name: 'Fixture provider', is_active: 1, logo: '' }];
const locations = [{ provider_id: 1, province: 'تهران', city: 'تهران' }];
const rows = [{ id: 1, provider_id: 1, name: 'Fixture branch', code: 'F-1', province: 'تهران', city: 'تهران', address: 'تهران', status: 'active', has_coordinates: true, latitude: 35.7, longitude: 51.4 }];

(async () => {
  const executablePath = process.platform === 'win32' && fs.existsSync('C:/Program Files/Google/Chrome/Application/chrome.exe')
    ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined;
  const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.pathname.startsWith('/api/')) {
        const endpoint = url.pathname.slice('/api/'.length).split('?')[0];
        if (endpoint === 'providers') return route.fulfill({ json: providers });
        if (endpoint === 'locations') return route.fulfill({ json: locations });
        if (endpoint === 'geocoding') return route.fulfill({ json: { items: [], counts: {} } });
        if (endpoint === 'points') return route.fulfill({ json: { items: rows, total: rows.length, page: 1, per_page: 20, total_pages: 1 } });
        return route.fulfill({ json: { items: [], total: 0, page: 1, per_page: 10, total_pages: 1 } });
      }
      if (url.pathname.startsWith('/assets/')) {
        const file = url.pathname.slice(1), ext = path.extname(file);
        const types = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.geojson': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
        return route.fulfill({ body: fs.readFileSync(path.join(root, file)), contentType: types[ext] || 'application/octet-stream' });
      }
      if (url.pathname.startsWith('/tiles/')) return route.fulfill({ status: 204 });
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/dashboard.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="toplevel_page_tapin-locator"><div id="tapin-admin" class="tapin-app" dir="rtl"></div><script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}",nonce:"fixture"};</script><script src="/assets/vendor/leaflet.js"></script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script><script src="/assets/admin.js"></script></body></html>' });
    });

    await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#dashboard');
    const rootEl = page.locator('#tapin-admin');
    const title = section => page.locator(section === 'dashboard' ? '.dashboard-map .map-tools>.filter-title' : '#point-filters>.filter-title');
    const parent = section => page.locator(section === 'dashboard' ? '.dashboard-map .map-tools' : '#point-filters');
    const collapsedBox = async section => {
      const button = title(section), bar = parent(section);
      return { button: await button.boundingBox(), bar: await bar.boundingBox(), rightInset: await bar.evaluate((el, btn) => el.getBoundingClientRect().right - btn.getBoundingClientRect().right, await button.elementHandle()) };
    };

    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      for (const theme of ['dark', 'light']) {
        await page.evaluate(value => window.TapinTheme.set(value), theme);
        for (const section of ['points', 'dashboard']) {
          await page.evaluate(name => { location.hash = '#' + name; }, section);
          const button = title(section);
          await expect(button).toBeVisible({ timeout: 15000 });
          await expect(button).toHaveAttribute('aria-expanded', 'true');
          const fields = parent(section).locator(section === 'dashboard' ? '.map-selects' : '.filter-field').first();
          await expect(fields.first()).toBeVisible();

          // Repeatedly collapse/reopen; collapsed dimensions and the header's
          // anchor must agree with the Service Points form in this viewport.
          for (let cycle = 0; cycle < 4; cycle++) {
            const before = await button.boundingBox();
            await button.click();
            await expect(button).toHaveAttribute('aria-expanded', 'false');
            await page.waitForTimeout(220);
            await expect(fields.first()).toBeHidden();
            const after = await collapsedBox(section);
            expect(after.button.width).toBeGreaterThanOrEqual(259);
            expect(after.button.height).toBe(46);
            expect(Math.abs(after.button.y - before.y)).toBeLessThanOrEqual(2);
            expect(after.rightInset).toBeGreaterThanOrEqual(0);
            expect(after.rightInset).toBeLessThanOrEqual(15);
            await button.click();
            await expect(button).toHaveAttribute('aria-expanded', 'true');
            await page.waitForTimeout(220);
            await expect(fields.first()).toBeVisible();
          }

          // Keyboard activation must expose the same accessible state.
          await button.focus();
          await page.keyboard.press('Enter');
          await expect(button).toHaveAttribute('aria-expanded', 'false');
          await page.waitForTimeout(220);
          await button.focus();
          await page.keyboard.press('Space');
          await expect(button).toHaveAttribute('aria-expanded', 'true');
          await page.waitForTimeout(220);
        }

        await page.evaluate(() => { location.hash = '#points'; });
        const pointsButton = title('points');
        await expect(pointsButton).toBeVisible();
        await pointsButton.click();
        await page.waitForTimeout(220);
        const pointsCollapsed = await collapsedBox('points');
        await page.evaluate(() => { location.hash = '#dashboard'; });
        const dashboardButton = title('dashboard');
        await expect(dashboardButton).toBeVisible();
        await dashboardButton.click();
        await page.waitForTimeout(220);
        const dashboardCollapsed = await collapsedBox('dashboard');
        expect(Math.abs(dashboardCollapsed.button.width - pointsCollapsed.button.width)).toBeLessThanOrEqual(1);
        expect(Math.abs(dashboardCollapsed.button.height - pointsCollapsed.button.height)).toBeLessThanOrEqual(1);
        expect(await rootEl.evaluate(el => el.scrollWidth <= el.clientWidth + 2)).toBe(true);
      }
    }

    expect(errors).toEqual([]);
    console.log('PASS dashboard-filter-collapse: Service Points collapsed dimensions, four repeat cycles at desktop/mobile in dark/light, keyboard toggling, aria-expanded, hidden controls and no root overflow.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
