/* Screenshot/geometry audit of real PHP views and assets; localhost REST/identity doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, writeFileSync, mkdirSync, existsSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..'), runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const baseline = process.env.TAPIN_BORDER_AUDIT === 'before';
const artifacts = path.join(repo, 'artifacts/borders');
mkdirSync(artifacts, { recursive: true });
const baselineCSS = process.env.TAPIN_BORDER_BASELINE_CSS || path.join(artifacts, 'before.css');
const fixtures = JSON.parse(execFileSync(php, ['-d', 'extension_dir=' + path.join(repo, 'artifacts/php-runtime/ext'), '-d', 'extension=zip', path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
fixtures.adminProvider.location_config = { ...fixtures.adminProvider.location_config,
  custom_locations: { provinces: [{ code: '1', title: 'استان نمونه' }], cities: [{ code: '10', title: 'شهر مجاز', province_code: '1' }, { code: '11', title: 'شهر مسدود', province_code: '1' }] },
  blocked_locations: { provinces: [], cities: [{ code: '11', title: 'شهر مسدود', province_code: '1' }] } };
const server = http.createServer((req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/plugin/assets/')) {
      const file = path.resolve(runtime, url.pathname.split('/plugin/')[1]);
      assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
      res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream' });
      return res.end(readFileSync(baseline && file.endsWith('style.css') && existsSync(baselineCSS) ? baselineCSS : file));
    }
    if (url.pathname.includes('/wp-json/')) {
      const suffix = url.pathname.split('/tapin-price-check/v1/')[1];
      if (suffix.startsWith('admin/')) assert.equal(req.headers['x-wp-nonce'], 'test-rest-nonce');
      const data = suffix === 'admin/providers' ? { providers: [{ ...fixtures.adminProvider, param_count: 4, visible_field_count: 4 }] }
        : suffix === 'admin/providers/1' ? fixtures.adminProvider
        : suffix === 'admin/providers/1/unserved-locations' ? { provinces: [], cities_by_province: {}, city_count: 0, province_count: 0 }
        : suffix === 'admin/shops' ? { shops: [{ id: '103', title: 'شعبه نمونه', province: '1', city: '10', has_token: true }] }
        : suffix === 'admin/bulk' ? { config: fixtures.bulkConfig, providers: [fixtures.provider] }
        : suffix === 'admin/logs' ? { logs: [{ id: 1, provider_id: 1, provider_name: 'sample', provider_display_name: 'شرکت نمونه', status: 'error', error_message: 'خطای آزمایشی', created_at: '2026-10-21T12:00:00', payload: { weight: 1 }, response: null }], filters: {} }
        : suffix === 'admin/cache' ? { settings: { price: 86400, price_retention: 864000, locations: 21600, quote_logs: 604800 }, stats: { price_rows: 0, quote_logs_rows: 0 }, locations: { cached: false } }
        : suffix === 'admin/security/route-limits' ? { routes: [{ route: 'price-check', title: 'استعلام قیمت', methods: ['POST'], max_requests: 4, window_seconds: 60, default_max: 4, default_window: 60 }], admin_groups: [] }
        : suffix === 'admin/security/allowlist' ? { allowlist: [] }
        : suffix === 'admin/security/rate-limits' ? { limits: [] }
        : suffix === 'admin/security/repeat-offenders' ? { offenders: [] }
        : suffix === 'admin/security/blocks' ? { blocks: [] }
        : suffix === 'admin/security/events' ? { events: [] }
        : suffix === 'providers' ? { providers: [fixtures.provider] }
        : suffix === 'providers/sample' || suffix === 'providers/1' ? fixtures.locationSchema
        : suffix === 'bulk' ? fixtures.bulkSchema
        : suffix === 'price-check' ? fixtures.quote
        : suffix === 'bulk-validate' ? { valid: true, errors: [] }
        : suffix === 'bulk-check' ? { results: [{ ...fixtures.quote, ok: true, variant: {}, variant_detail: [] }] } : {};
      res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      return res.end(JSON.stringify(data));
    }
    const site = `http://127.0.0.1:${server.address().port}/site`;
    const admin = url.pathname.includes('/wp-admin/');
    const mode = admin ? (url.searchParams.get('page') || 'tapin-price-providers').replace('tapin-price-', '') : url.pathname.includes('/bulk') ? 'bulk' : 'single';
    const html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], { input: JSON.stringify({ site, mode, admin, logged: admin, capable: admin, query: Object.fromEntries(url.searchParams), attributes: url.searchParams.has('standalone') ? { layout: 'standalone' } : {} }), encoding: 'utf8' });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><style>body{margin:0;background:#fff}#theme-marker{border:1px solid rgb(30,90,60);padding:8px} .tapin-price-frame{max-width:100%}</style></head><body class="' + (admin ? 'tapin-price-page' : '') + '">' + (admin ? '' : '<header id="theme-marker">WordPress theme</header>') + html + '</body></html>');
  } catch (error) { console.error(error); res.writeHead(500); res.end('Fixture error'); }
});
const targets = '.card,.admin-hero,.pro-hero,.guide-box,.table-scroll,details,.combo-panel,.range-box,.range-value,.cal-pop,.map-canvas,.checkline,.blk-toolbar,.blk-group,.blk-chev,.blk-city,.loc-sticky,.adv-menu,.adv-card,.sort-toggle,input[type="text"],input[type="number"],select,textarea,.combo-face,.btn,.stat-mini,.pill,.badge,.hint.notice';
const geometry = ['x','y','width','height','borderTopWidth','borderRightWidth','borderBottomWidth','borderLeftWidth','borderRadius','padding','margin','font','background','color'];
const luminance = color => {
  const rgb = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
  return rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
};
const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + .05) / (Math.min(luminance(a), luminance(b)) + .05);
const output = {};
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  // Compare layout at rest; the existing hover/entry animations otherwise
  // contribute fractional transformed positions unrelated to border geometry.
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  const base = `http://127.0.0.1:${server.address().port}/site`;
  const frame = () => page.frameLocator('iframe');
  const doc = () => page.frames()[1];
  let focusChecks = 0;
  const checkFocus = async locator => {
    if (baseline) return;
    const before = await locator.boundingBox();
    const normal = await locator.evaluate(n => getComputedStyle(n).borderTopColor);
    await locator.hover();
    await locator.evaluate(n => Promise.all(n.getAnimations().map(a => a.finished.catch(() => {}))));
    const hover = await locator.evaluate(n => getComputedStyle(n).borderTopColor);
    assert.notEqual(hover, normal, 'Hover boundary must differ');
    await page.keyboard.press('Tab'); await locator.focus();
    await locator.evaluate(n => Promise.all(n.getAnimations().map(a => a.finished.catch(() => {}))));
    const focus = await locator.evaluate(n => { const s = getComputedStyle(n), r = n.getBoundingClientRect(); return { outline: s.outlineColor, width: s.outlineWidth, style: s.outlineStyle, background: s.backgroundColor, x: r.x, right: r.right, focusVisible: n.matches(':focus-visible'), active: n === document.activeElement, class: n.className }; });
    assert.equal(focus.style, 'solid'); assert.ok(parseFloat(focus.width) >= 2, JSON.stringify(focus));
    assert.ok(contrast(focus.outline, focus.background === 'rgba(0, 0, 0, 0)' ? 'rgb(255,255,255)' : focus.background) >= 3);
    assert.equal((await locator.boundingBox()).width, before.width); assert.equal((await locator.boundingBox()).height, before.height);
    assert.ok(focus.x >= 4 && focus.right <= await doc().evaluate(() => innerWidth - 4), 'Focus clipped by iframe');
    await locator.evaluate(n => n.blur()); focusChecks++;
  };
  const capture = async name => {
    await doc().evaluate(() => document.fonts.ready);
    await doc().evaluate(() => scrollTo(0, 0));
    await frame().locator('#main').hover();
    await doc().evaluate(() => Promise.all(document.getAnimations().filter(a => a.effect.getTiming().iterations !== Infinity).map(a => a.finished.catch(() => {}))));
    output[name] = await doc().evaluate(selector => [...document.querySelectorAll(selector)].filter(n => n.getClientRects().length && getComputedStyle(n).visibility !== 'hidden').map(n => {
      const s = getComputedStyle(n), r = n.getBoundingClientRect();
      return { tag: n.tagName, class: n.className, id: n.id, error: !!n.closest('.has-error'), selected: n.matches('.checkline:has(input:checked)'), x: +r.x.toFixed(2), y: +r.y.toFixed(2), width: +r.width.toFixed(2), height: +r.height.toFixed(2), borderTopWidth: s.borderTopWidth, borderRightWidth: s.borderRightWidth, borderBottomWidth: s.borderBottomWidth, borderLeftWidth: s.borderLeftWidth, borderRadius: s.borderRadius, padding: s.padding, margin: s.margin, font: s.font, background: s.background, color: s.color, borderTopColor: s.borderTopColor, borderRightColor: s.borderRightColor, borderLeftColor: s.borderLeftColor, borderBottomColor: s.borderBottomColor };
    }), targets);
    assert.ok(await doc().evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Horizontal overflow: ' + name);
    if (await page.locator('#theme-marker').count()) assert.equal(await page.locator('#theme-marker').evaluate(n => getComputedStyle(n).borderTopColor), 'rgb(30, 90, 60)');
    await page.screenshot({ path: path.join(artifacts, `${baseline ? 'before' : 'after'}-${name}.png`), fullPage: true });
  };
  try {
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      for (const [screen, view] of Object.entries({ providers: 'list', shops: 'shops', bulk: 'bulk', logs: 'logs', cache: 'cache', security: 'security' })) {
        await page.goto(base + '/wp-admin/admin.php?page=tapin-price-' + screen);
        await expect(frame().locator('#' + view + '-view')).toBeVisible();
        await expect(frame().locator('#' + view + '-view .skeleton')).toHaveCount(0);
        await capture(`${screen}-${width}`);
        if (screen === 'logs') {
          await frame().getByRole('button', { name: 'فیلتر', exact: true }).click();
          await capture(`log-filters-${width}`);
          await frame().getByRole('button', { name: 'تقویم', exact: false }).click();
          await capture(`log-calendar-${width}`);
        }
        if (screen === 'providers') {
          await frame().getByRole('button', { name: 'ویرایش', exact: true }).click();
          await expect(frame().locator('#editor-view input')).not.toHaveCount(0);
          await capture(`editor-${width}`);
          await frame().getByRole('button', { name: /^فیلدها/ }).click();
          await capture(`editor-fields-${width}`);
          await frame().getByRole('button', { name: 'تنظیمات شهر و استان', exact: true }).click();
          await capture(`locations-${width}`);
          await frame().getByRole('button', { name: /^بلاک‌شده‌ها/ }).click();
          await frame().getByRole('button', { name: 'بازکردن همه', exact: true }).click();
          await expect(frame().locator('.blk-city')).toHaveCount(2); await capture(`blocked-locations-${width}`);
          await frame().locator('.blk-city').last().scrollIntoViewIfNeeded();
          await page.screenshot({ path: path.join(artifacts, `${baseline ? 'before' : 'after'}-blocked-controls-${width}.png`) });
        }
        if (screen === 'cache') { await checkFocus(frame().locator('.cache-tile input').first()); await checkFocus(frame().getByRole('button', { name: 'بازخوانی', exact: true })); }
        if (screen === 'logs') { await page.emulateMedia({ colorScheme: 'dark' }); await capture(`logs-dark-preference-${width}`); await page.emulateMedia({ colorScheme: 'light' }); }
      }
      await page.goto(base + '/single/');
      await expect(frame().locator('#provider-list .tile')).toHaveCount(1); await capture(`public-choose-${width}`);
      await frame().locator('#provider-list .tile button').click();
      await expect(frame().locator('#form-panel')).toBeVisible(); await capture(`public-form-${width}`);
      await frame().locator('.combo-face').first().click(); await expect(frame().locator('.combo-panel').first()).toBeVisible(); await capture(`public-dropdown-${width}`);
      await frame().locator('.combo-face').first().click();
      await checkFocus(frame().locator('[name="param:weight"]'));
      await checkFocus(frame().locator('.combo-face').first());
      await frame().locator('#single-submit-btn').click(); await capture(`public-validation-${width}`);
      await page.goto(base + '/bulk/');
      await expect(frame().locator('#bulk-form button[type="submit"]')).toBeVisible(); await capture(`public-bulk-${width}`);
      await frame().locator('#bulk-advanced-toggle').click(); await expect(frame().locator('#bulk-advanced-panel')).toBeVisible(); await capture(`public-advanced-${width}`);
      await frame().locator('[name="bulk:weight"]').fill('2'); await frame().locator('#bulk-form button[type="submit"]').click();
      await expect(frame().locator('#bulk-result')).toContainText('۱۲٬۵۰۰'); await capture(`public-result-${width}`);
      // Price Check has no dark theme; OS preference must not darken its light surfaces.
      const light = await frame().locator('body').evaluate(n => getComputedStyle(n).backgroundColor);
      await page.emulateMedia({ colorScheme: 'dark' });
      assert.equal(await frame().locator('body').evaluate(n => getComputedStyle(n).backgroundColor), light);
      await capture(`public-dark-preference-${width}`); await page.emulateMedia({ colorScheme: 'light' });
    }
    if (!baseline && existsSync(path.join(artifacts, 'before.json'))) {
      const before = JSON.parse(readFileSync(path.join(artifacts, 'before.json'), 'utf8'));
      for (const [name, nodes] of Object.entries(output)) {
        assert.equal(nodes.length, before[name].length, 'Changed DOM inventory: ' + name);
        nodes.forEach((node, index) => {
          geometry.forEach(key => assert.deepEqual(node[key], before[name][index][key], `${name} ${node.tag}.${node.class} changed ${key}`));
          for (const edge of ['Top','Right','Bottom','Left']) {
            const semantic = /\b(btn-remove|pill-ok|pill-off|stat-mini (ok|err|gold)|badge (fail|ok|best|cached)|notice|is-blocked)\b/.test(node.class) || node.error || node.selected;
            if (semantic || parseFloat(node['border' + edge + 'Width']) >= 3) assert.equal(node['border' + edge + 'Color'], before[name][index]['border' + edge + 'Color'], 'Semantic/accent border changed: ' + node.class);
          }
        });
      }
    }
    if (!baseline) {
      for (const name of ['logs-1440','cache-1440','public-form-1440','public-bulk-1440','logs-390','cache-390','public-form-390']) {
        for (const node of output[name].filter(n => /\bcard\b/.test(n.class) && n.borderTopWidth === '1px')) {
          for (const bg of ['rgb(255,255,255)','rgb(248,248,255)','rgb(243,245,253)']) assert.ok(contrast(node.borderTopColor, bg) >= 3, 'Insufficient neutral boundary contrast: ' + node.class);
        }
      }
    }
    writeFileSync(path.join(artifacts, (baseline ? 'before' : 'after') + '.json'), JSON.stringify(output, null, 2));
    assert.deepEqual(errors, []);
    console.log(baseline ? 'CAPTURE baseline:' : 'PASS boundary audit:', Object.keys(output).length, 'screens/states;', focusChecks, 'hover/focus checks; admin/public, 1440/390px, OS dark preference and theme isolation.', baseline ? '' : 'Neutral cards >=3:1; compared previous geometry/status colors: ' + existsSync(path.join(artifacts, 'before.json')));
  } finally { await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
