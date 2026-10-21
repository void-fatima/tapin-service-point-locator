/* Screenshot/geometry audit of real PHP views and assets; localhost REST/identity doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, writeFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..'), runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const artifacts = path.join(repo, 'artifacts/themes');
mkdirSync(artifacts, { recursive: true });
const fixtures = JSON.parse(execFileSync(php, ['-d', 'extension_dir=' + path.join(repo, 'artifacts/php-runtime/ext'), '-d', 'extension=zip', path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
fixtures.adminProvider.location_config = { ...fixtures.adminProvider.location_config,
  custom_locations: { provinces: [{ code: '1', title: 'استان نمونه' }], cities: [{ code: '10', title: 'شهر مجاز', province_code: '1' }, { code: '11', title: 'شهر مسدود', province_code: '1' }] },
  blocked_locations: { provinces: [], cities: [{ code: '11', title: 'شهر مسدود', province_code: '1' }] } };
let responseState = 'populated';
const pending = [];
const server = http.createServer((req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/plugin/assets/')) {
      const file = path.resolve(runtime, url.pathname.split('/plugin/')[1]);
      assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
      res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream' });
      return res.end(readFileSync(file));
    }
    if (url.pathname.includes('/wp-json/')) {
      const suffix = url.pathname.split('/tapin-price-check/v1/')[1];
      if (suffix.startsWith('admin/')) assert.equal(req.headers['x-wp-nonce'], 'test-rest-nonce');
      let data = suffix === 'admin/providers' ? { providers: [{ ...fixtures.adminProvider, param_count: 4, visible_field_count: 4 }] }
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
        : suffix === 'admin/preview/provider-schema' ? fixtures.schema
        : suffix === 'admin/preview/bulk-schema' ? fixtures.bulkSchema
        : suffix === 'bulk' ? fixtures.bulkSchema
        : suffix === 'price-check' ? fixtures.quote
        : suffix === 'bulk-validate' ? { valid: true, errors: [] }
        : suffix === 'bulk-check' ? { results: [{ ...fixtures.quote, ok: true, variant: {}, variant_detail: [] }] } : {};
      if (responseState === 'empty' && suffix === 'admin/logs') data.logs = [];
      const send = () => { res.writeHead(responseState === 'error' ? 500 : 200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(responseState === 'error' ? { detail: 'Theme fixture error' } : data)); };
      if (responseState === 'loading' && (suffix === 'bulk-check' || suffix === 'price-check' || suffix.startsWith('admin/'))) pending.push(send); else send(); return;
    }
    const site = `http://127.0.0.1:${server.address().port}/site`;
    if (url.pathname === '/map-theme') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end('<!doctype html><html><body><div class="tapin-app"><select id="tapin-theme-select"><option>dark</option><option>light</option><option>system</option></select></div><script src="' + site + '/plugin/assets/theme.js"></script><script>document.querySelector("select").onchange = e => TapinTheme.set(e.target.value);</script></body></html>'); }
    const admin = url.pathname.includes('/wp-admin/');
    const mode = admin ? (url.searchParams.get('page') || 'tapin-price-providers').replace('tapin-price-', '') : url.pathname.includes('/bulk') ? 'bulk' : 'single';
    const html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], { input: JSON.stringify({ site, mode, admin, logged: admin, capable: admin, query: Object.fromEntries(url.searchParams), attributes: url.searchParams.has('standalone') ? { layout: 'standalone' } : {} }), encoding: 'utf8' });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><style>body{margin:0;background:#fff}#theme-marker{border:1px solid rgb(30,90,60);padding:8px} .tapin-price-frame{max-width:100%}</style></head><body class="' + (admin ? 'tapin-price-page' : '') + '">' + (admin ? '' : '<header id="theme-marker">WordPress theme</header>') + html + (url.pathname === '/double' ? html : '') + '</body></html>');
  } catch (error) { console.error(error); res.writeHead(500); res.end('Fixture error'); }
});
const targets = '.card,.pro-hero,.admin-hero,.combo-face,.combo-panel,.cal-pop,details,.cache-tile,input,textarea,select,.btn,.blk-group,.blk-city,.pc-theme-choice';
const output = {}; let framesChecked = 0;
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: 'reduce', colorScheme: 'light' });
  const page = await context.newPage();
  await context.route('https://tile.openstreetmap.org/**', route => route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64')}));
  const errors = [];
  const observe = p => { p.on('pageerror', error => errors.push(error.message)); p.on('console', message => { if (message.type() === 'error' && !/status of 500/.test(message.text())) errors.push(message.text()); }); };
  context.on('page', observe);
  observe(page);
  const base = `http://127.0.0.1:${server.address().port}/site`;
  const frame = () => page.frameLocator('iframe').first();
  const doc = () => page.frames()[1];
  const choose = async value => frame().locator('[data-tapin-theme-choice]').selectOption(value);
  const assertMode = async (p, mode, preference = mode) => {
    for (const f of p.frames().slice(1)) {
      await expect(f.locator('html')).toHaveAttribute('data-theme-mode', mode);
      await expect(f.locator('html')).toHaveAttribute('data-theme', preference);
      assert.equal(await f.evaluate(() => getComputedStyle(document.documentElement).colorScheme), mode);
    }
    await expect(p.locator('[data-tapin-theme-frame]').first()).toHaveAttribute('data-theme-mode', mode);
    assert.equal(await p.evaluate(() => document.documentElement.style.colorScheme), '', 'WordPress root recolored');
  };
  const capture = async (name, focus) => {
    await doc().evaluate(() => document.fonts.ready);
    await doc().evaluate(() => document.getAnimations().filter(a => a.effect.getTiming().iterations !== Infinity).forEach(a => a.finish()));
    const modes = [];
    for (const [preference, mode] of [['light','light'], ['dark','dark'], ['system','light'], ['system','dark']]) {
      await page.emulateMedia({colorScheme: mode});
      await choose(preference);
      if (focus) await focus.focus();
      await assertMode(page, mode, preference);
      await doc().evaluate(async () => { [...document.querySelectorAll('body *')].forEach(n => getComputedStyle(n).backgroundColor); await new Promise(requestAnimationFrame); });
      await expect.poll(() => doc().evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(mode === 'dark' ? 'rgb(11, 14, 25)' : 'rgb(248, 248, 255)');
      await doc().evaluate(() => document.getAnimations().filter(a => a.effect.getTiming().iterations !== Infinity).forEach(a => a.finish()));
      const snapshot = await doc().evaluate(selector => {
        const visible = n => n.checkVisibility({ checkVisibilityCSS: true, contentVisibilityAuto: true }) && ![...document.querySelectorAll('details:not([open])')].some(d => d !== n && d.contains(n) && !d.querySelector(':scope > summary')?.contains(n));
        const geometry = [...document.querySelectorAll(selector)].filter(visible).map(n => {
          const s = getComputedStyle(n), r = n.getBoundingClientRect();
          return [n.tagName,n.id,n.className,r.x,r.y,r.width,r.height,s.padding,s.margin,s.borderWidth,s.borderRadius,s.font];
        });
        const bright = [...document.querySelectorAll('body *')].filter(visible).filter(n => {
          const s = getComputedStyle(n), rgb = s.backgroundColor.match(/[\d.]+/g)?.map(Number);
          return rgb && (rgb.length === 3 || rgb[3] >= .5) && Math.min(...rgb.slice(0,3)) > 210 && !n.matches('img,.logo,.logo-fallback,.rc-logo,.provider-logo,.adv-logo') && !n.closest('.logo,.logo-fallback');
        }).map(n => [n.tagName,n.className,getComputedStyle(n).backgroundColor]);
        return { geometry, bright, body: getComputedStyle(document.body).backgroundColor, overflow: document.documentElement.scrollWidth > innerWidth + 1 };
      }, targets);
      assert.equal(snapshot.overflow, false, 'Overflow: ' + name + preference);

      assert.equal(snapshot.body, mode === 'dark' ? 'rgb(11, 14, 25)' : 'rgb(248, 248, 255)');
      if (mode === 'dark') assert.deepEqual(snapshot.bright, [], 'Unconverted light surface: ' + name);
      if (modes.length) assert.deepEqual(snapshot.geometry, modes[0].geometry, 'Theme changed geometry: ' + name);
      modes.push(snapshot); framesChecked++;
      await page.screenshot({ path: path.join(artifacts, `${name}-${preference}-${mode}.png`), fullPage: true });
      if (await page.locator('#theme-marker').count()) assert.equal(await page.locator('#theme-marker').evaluate(n => getComputedStyle(n).borderTopColor), 'rgb(30, 90, 60)');
    }
    await page.emulateMedia({colorScheme:'light'});
    output[name] = modes.map(({ body, geometry }) => ({ body, elements: geometry.length }));
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
        }
        if (screen === 'cache') {
          const control = frame().locator('.cache-tile input').first();
          for (const mode of ['light','dark','system']) {
            await choose(mode); await control.focus();
            assert.equal(await control.evaluate(n => getComputedStyle(n).outlineStyle), 'solid');
            await capture(`cache-focus-${mode}-${width}`, control);
          }
        }
      }
      await page.goto(base + '/single/');
      await expect(frame().locator('#provider-list .tile')).toHaveCount(1); await capture(`public-choose-${width}`);
      await frame().locator('#provider-search').fill('ناشناخته'); await capture(`public-empty-${width}`);
      await frame().locator('#provider-search').fill('');
      await frame().locator('#provider-list .tile button').click();
      await expect(frame().locator('#form-panel')).toBeVisible(); await capture(`public-form-${width}`);
      await frame().locator('.combo-face').first().click(); await expect(frame().locator('.combo-panel').first()).toBeVisible(); await capture(`public-dropdown-${width}`);
      await frame().locator('.combo-face').first().click();
      await frame().locator('#single-submit-btn').click(); await capture(`public-validation-${width}`);
      await page.goto(base + '/bulk/');
      await expect(frame().locator('#bulk-form button[type="submit"]')).toBeVisible(); await capture(`public-bulk-${width}`);
      await frame().locator('#bulk-advanced-toggle').click(); await expect(frame().locator('#bulk-advanced-panel')).toBeVisible(); await capture(`public-advanced-${width}`);
      await frame().locator('[name="bulk:weight"]').fill('2'); responseState = 'loading'; await frame().locator('#bulk-form button[type="submit"]').click();
      await expect(frame().locator('#bulk-form button[type="submit"]')).toBeDisabled(); await capture('public-loading-' + width);
      responseState = 'populated'; pending.splice(0).forEach(send => send());
      await expect(frame().locator('#bulk-result')).toContainText('۱۲٬۵۰۰'); await capture(`public-result-${width}`);
      await doc().evaluate(({provider,bulk}) => { localStorage.setItem('tapin.preview.provider',JSON.stringify(provider)); localStorage.setItem('tapin.preview.bulk',JSON.stringify(bulk)); }, {provider:fixtures.adminProvider,bulk:fixtures.bulkConfig});
      for (const kind of ['provider','bulk']) {
        await page.goto(base + '/wp-admin/admin.php?page=tapin-price-providers&tapin_price_preview=' + kind);
        await expect(frame().locator('#preview-banner')).toBeVisible();
        await expect(frame().locator(kind === 'provider' ? '[name="param:weight"]' : '[name="bulk:weight"]')).toBeVisible();
        await capture('preview-' + kind + '-' + width);
      }
      await page.goto(base + '/single/?standalone=1');
      await expect(frame().locator('.topbar,.footer')).toHaveCount(0); await capture('public-standalone-' + width);
      const originalParams = fixtures.locationSchema.params;
      fixtures.locationSchema.params = [...originalParams, {name:'coordinates',label:'Coordinates',input:'map',type:'object',required:false,visible:true}];
      await page.goto(base + '/single/'); await frame().locator('#provider-list .tile button').click();
      await expect(frame().locator('.leaflet-control-zoom')).toBeVisible(); await capture('public-coordinate-picker-' + width);
      fixtures.locationSchema.params = originalParams;
      responseState = 'empty'; await page.goto(base + '/wp-admin/admin.php?page=tapin-price-logs');
      await expect(frame().locator('.empty-illust')).toBeVisible(); await capture(`logs-empty-${width}`);
      responseState = 'loading'; await page.goto(base + '/wp-admin/admin.php?page=tapin-price-cache');
      await expect(frame().locator('.skeleton').first()).toBeVisible(); await capture(`cache-loading-${width}`);
      responseState = 'populated'; pending.splice(0).forEach(send => send());
      await expect(frame().locator('.skeleton')).toHaveCount(0);
      responseState = 'error'; await page.goto(base + '/wp-admin/admin.php?page=tapin-price-providers');
      await expect(frame().locator('#provider-rows')).toContainText('Theme fixture error'); await capture(`admin-error-${width}`);
      responseState = 'populated';
    }
    // Same preference/service as the map; tab and source-document synchronization.
    const map = await context.newPage(); await map.goto(base.replace('/site','') + '/map-theme');
    const second = await context.newPage(); await second.goto(base + '/single/');
    await frame().locator('[data-tapin-theme-choice]').evaluate(select => { for (const v of ['dark','light','system','light','dark']) {select.value=v;select.dispatchEvent(new Event('change',{bubbles:true}));} }); await assertMode(second, 'dark'); await assertMode(page,'dark');
    await expect(map.locator('.tapin-app')).toHaveAttribute('data-theme-mode','dark');
    await map.locator('select').selectOption('light'); await assertMode(page, 'light'); await assertMode(second,'light');
    await choose('system'); await page.emulateMedia({colorScheme:'dark'}); await assertMode(page,'dark','system');
    await page.emulateMedia({colorScheme:'light'}); await assertMode(page,'light','system');
    await choose('dark'); await page.emulateMedia({colorScheme:'light'}); await assertMode(page,'dark');
    await choose('light'); await page.emulateMedia({colorScheme:'dark'}); await assertMode(page,'light');
    await choose('dark'); await page.reload(); await assertMode(page,'dark');
    await expect(frame().locator('select[data-tapin-theme-choice]')).toHaveValue('dark');
    await page.setViewportSize({width:1440,height:900});
    await frame().locator('.pc-sidebar-toggle').click(); await expect(frame().locator('.pc-sidebar-toggle')).toHaveAttribute('aria-expanded','false');
    await choose('light'); await frame().locator('[data-tab="shops"]').click(); await frame().locator('[data-tab="security"]').click();
    await expect(frame().locator('#security-view')).toBeVisible(); await expect(frame().locator('.pc-sidebar-toggle')).toHaveAttribute('aria-expanded','false');
    await page.reload(); await expect(frame().locator('.pc-sidebar-toggle')).toHaveAttribute('aria-expanded','false'); await assertMode(page,'light');
    // Storage blocked: current session, new frames and siblings still synchronize.
    const blocked = await context.newPage();
    await blocked.addInitScript(() => { Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Disabled','SecurityError');}}); Object.defineProperty(window,'BroadcastChannel',{value:undefined}); });
    await blocked.goto(base.replace('/site','') + '/double');
    const choices = blocked.frameLocator('iframe').first().locator('[data-tapin-theme-choice]');
    await choices.selectOption('dark'); await assertMode(blocked,'dark');
    await choices.selectOption('light'); await assertMode(blocked,'light');
    await blocked.evaluate(() => { const f=document.querySelector('iframe');f.parentNode.append(f.cloneNode(true)); });
    await expect(blocked.locator('iframe')).toHaveCount(3); await assertMode(blocked,'light');
    await blocked.close(); await map.close(); await second.close();
    // Hold blocking CSS: the head controller already resolved preference before any body exists.
    await choose('dark'); let releaseCSS, cssHeld = false; const gate = new Promise(resolve => releaseCSS=resolve);
    await page.route('**/price-check/style.css*', async route => { cssHeld=true; await gate; await route.continue(); });
    try {
      await page.reload({waitUntil:'commit'});
      await expect.poll(() => cssHeld).toBe(true);
      await expect.poll(() => doc()?.evaluate(() => document.documentElement.dataset.themeMode)).toBe('dark');
      assert.equal(await doc().evaluate(() => document.body), null);
      assert.equal(await doc().evaluate(() => document.documentElement.style.colorScheme), 'dark');
      assert.equal(await page.locator('iframe').evaluate(n => getComputedStyle(n).backgroundColor), 'rgb(11, 14, 25)');
    } finally { releaseCSS(); }
    await page.waitForLoadState('load'); await assertMode(page,'dark');
    assert.deepEqual(errors, []);
    writeFileSync(path.join(artifacts,'report.json'),JSON.stringify({runtime,framesChecked,screens:output,errors},null,2));
    console.log(`PASS themes: ${framesChecked} captures, geometry unchanged Light/Dark/System; all six admin screens/public controls; 1440/390px; map/tabs/iframes; storage blocked; System changes; reload; sidebar; pre-CSS initialization.`);
  } finally { pending.splice(0).forEach(send => send()); await context.close(); await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode=1; });
