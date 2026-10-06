/* Local Chrome regression using real admin assets and isolated API fixtures.
   Run: node tests/dashboard-list-animation.cjs (no WordPress session required). */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const artifacts = path.join(root, 'artifacts', 'dashboard-list-animation');
const providers = [{ id: 1, slug: 'tipax', name: 'تیپاکس', is_active: 1, logo: '' }];
const locations = [{ provider_id: 1, province: 'تهران', city: 'تهران' }];
const rows = [
  { id: 1, provider_id: 1, name: 'شعبه دارای موقعیت', province: 'تهران', city: 'تهران', address: 'تهران', status: 'active', has_coordinates: true, latitude: 35.7, longitude: 51.4 },
  { id: 2, provider_id: 1, name: 'شعبه بدون مختصات', province: 'تهران', city: 'تهران', address: 'تهران', status: 'active', has_coordinates: false, latitude: null, longitude: null }
];
const summary = { total: 2, located: 1, missing: 1, distribution: [{ provider_id: 1, total: 2 }] };

(async () => {
  fs.mkdirSync(artifacts, { recursive: true });
  const chrome = process.env.TAPIN_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const browser = await chromium.launch({ headless: true, ...(fs.existsSync(chrome) ? { executablePath: chrome } : {}) });
  const results = [];
  try {
    for (const { width, height } of [{ width: 1440, height: 900 }, { width: 1440, height: 1200 }, { width: 390, height: 900 }]) {
      for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({ viewport: { width, height }, hasTouch: true, reducedMotion: 'no-preference' });
        const page = await context.newPage();
        const errors = [];
        let requests = 0;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.pathname.startsWith('/api/')) {
            requests++;
            const endpoint = url.pathname.slice(5);
            if (endpoint === 'providers') return route.fulfill({ json: providers });
            if (endpoint === 'locations') return route.fulfill({ json: locations });
            if (endpoint === 'points') return route.fulfill({ json: { items: url.searchParams.get('has_coordinates') === '1' ? rows.slice(0, 1) : rows, summary, total: 2, page: 1, per_page: 20, total_pages: 1 } });
            return route.fulfill({ json: { items: [], total: 0, counts: {} } });
          }
          if (url.pathname.startsWith('/assets/')) {
            const types = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json', '.geojson': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
            return route.fulfill({ body: fs.readFileSync(path.join(root, url.pathname.slice(1))), contentType: types[path.extname(url.pathname)] || 'application/octet-stream' });
          }
          if (url.pathname.startsWith('/tiles/')) return route.fulfill({ status: 204 });
          return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/dashboard.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="toplevel_page_tapin-locator"><div id="tapin-admin" class="tapin-app" dir="rtl"></div><script>window.TapinConfig={api:'/api/',assets:'/assets/',tiles:'/tiles/{z}/{x}/{y}',nonce:'fixture'};</script><script src="/assets/vendor/leaflet.js"></script><script>window.qaMaps=[];L.Map.addInitHook(function(){window.qaMaps.push(this);this.qaResize=0;this.qaMove=0;this.on('resize',()=>this.qaResize++);this.on('moveend',()=>this.qaMove++);});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script><script src="/assets/admin.js"></script></body></html>` });
        });
        await page.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#dashboard');
        const toggle = page.locator('.directory-toggle [data-list]');
        const panel = page.locator('.directory-list-panel');
        await expect(panel.locator('tbody tr')).toHaveCount(2);
        await page.evaluate(value => window.TapinTheme.set(value), theme);
        await page.waitForLoadState('networkidle');
        await toggle.scrollIntoViewIfNeeded();
        const tableBefore = await panel.locator('tbody').innerText();
        const snapshot = () => page.evaluate(() => {
          const button = document.querySelector('.directory-toggle [data-list]');
          const main = document.querySelector('.tapin-main');
          const scrollTop = innerWidth > 850 ? main.scrollTop : scrollY;
          const b = button.getBoundingClientRect(), map = window.qaMaps[0];
          const rect = document.querySelector('.tapin-map').getBoundingClientRect();
          const root = document.querySelector('#tapin-admin');
          return { button: { x: b.x, y: b.y + scrollTop, width: b.width, height: b.height }, map: { width: rect.width, height: rect.height, center: map.getCenter(), zoom: map.getZoom(), resize: map.qaResize, move: map.qaMove }, overflow: document.documentElement.scrollWidth > innerWidth || root.scrollWidth > root.clientWidth + 2 };
        });
        const initial = await snapshot();
        const initialRequests = requests;
        const stable = async () => {
          const current = await snapshot();
          for (const key of ['x', 'y', 'width', 'height']) expect(Math.abs(current.button[key] - initial.button[key]), 'toggle ' + key).toBeLessThanOrEqual(1);
          expect(current.map).toEqual(initial.map);
          expect(current.overflow, JSON.stringify({initial,current})).toBe(false);
        };
        const settled = async expanded => {
          await expect(toggle).toHaveAttribute('aria-expanded', String(expanded));
          await expect.poll(() => panel.evaluate(el => el.getAnimations().length)).toBe(0);
          if (expanded) { await expect(panel).toBeVisible(); await expect(panel.locator('.directory-pagination')).toBeVisible(); }
          else { await expect(panel).toBeHidden(); await expect(panel).toHaveAttribute('inert', ''); await expect(panel).toHaveAttribute('aria-hidden', 'true'); }
          await stable();
        };

        // Inspect actual intermediate frames: height AND opacity interpolate,
        // while focus is removed from the collapsing content immediately.
        const fullHeight = (await panel.boundingBox()).height;
        await panel.locator('[data-details]').first().focus();
        const middle = await page.evaluate(async () => {
          const button = document.querySelector('.directory-toggle [data-list]');
          const panel = document.querySelector('.directory-list-panel');
          button.click();
          const animation = panel.getAnimations()[0];
          while (animation.currentTime < 80) await new Promise(requestAnimationFrame);
          return { height: panel.getBoundingClientRect().height, opacity: Number(getComputedStyle(panel).opacity), inert: panel.inert, focus: document.activeElement === button };
        });
        expect(middle.height).toBeGreaterThan(0);
        expect(middle.height).toBeLessThan(fullHeight);
        expect(middle.opacity).toBeGreaterThan(0);
        expect(middle.opacity).toBeLessThan(1);
        expect(middle.inert && middle.focus).toBe(true);
        await settled(false);
        await panel.locator('[data-details]').first().evaluate(el => el.focus());
        expect(await toggle.evaluate(el => document.activeElement === el)).toBe(true);
        const hiddenAccessibility = await page.locator('.dashboard-directory-panel').ariaSnapshot();
        expect(hiddenAccessibility).not.toContain('شعبه دارای موقعیت');
        expect(hiddenAccessibility).not.toContain('شعبه بدون مختصات');
        await page.screenshot({ path: path.join(artifacts, `collapsed-${theme}-${width}x${height}.png`) });

        for (let cycle = 0; cycle < 3; cycle++) {
          await toggle.click(); await settled(true);
          await toggle.click(); await settled(false);
        }
        await toggle.focus(); await page.keyboard.press('Enter'); await settled(true);
        await toggle.focus(); await page.keyboard.press('Space'); await settled(false);
        await toggle.tap(); await settled(true);

        // Reverse a live animation, including another reversal before finishing.
        const reversal = await page.evaluate(async () => {
          const button = document.querySelector('.directory-toggle [data-list]');
          const panel = document.querySelector('.directory-list-panel');
          button.click();
          while (panel.getAnimations()[0].currentTime < 80) await new Promise(requestAnimationFrame);
          const before = panel.getBoundingClientRect().height;
          button.click();
          const after = panel.getBoundingClientRect().height;
          await new Promise(requestAnimationFrame);
          button.click(); button.click();
          return { before, after };
        });
        expect(Math.abs(reversal.before - reversal.after)).toBeLessThanOrEqual(1);
        await settled(true);
        expect(await panel.locator('tbody').innerText()).toBe(tableBefore);
        await page.screenshot({ path: path.join(artifacts, `expanded-${theme}-${width}x${height}.png`) });

        await page.emulateMedia({ reducedMotion: 'reduce' });
        for (let cycle = 0; cycle < 2; cycle++) {
          for (const expanded of [false, true]) {
            const synchronous = await toggle.evaluate(el => { el.click(); const panel = document.querySelector('.directory-list-panel'); return { hidden: panel.hidden, animations: panel.getAnimations().length }; });
            expect(synchronous).toEqual({ hidden: !expanded, animations: 0 });
            await settled(expanded);
          }
        }
        // Respect a preference change even during an in-progress animation.
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await toggle.evaluate(el => el.click());
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await settled(false);
        expect(requests).toBe(initialRequests);
        expect(errors).toEqual([]);
        results.push({ width, height, theme, intermediate: middle, stableMap: initial.map, requestsDuringToggles: requests - initialRequests, errors });
        await context.close();
      }
    }
    fs.writeFileSync(path.join(artifacts, 'results.json'), JSON.stringify(results, null, 2));
    console.log('PASS dashboard-list-animation: desktop/mobile, light/dark, interpolated height/opacity, rapid reversal, mouse/touch/keyboard, inert/hidden accessibility, reduced motion, stable toggle/map, unchanged rows, no toggle requests or JS exceptions.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
