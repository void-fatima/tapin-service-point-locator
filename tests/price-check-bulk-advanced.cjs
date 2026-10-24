/* Actual PHP renderer and browser assets; public REST and quotation HTTP are local doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..');
const runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const fixtures = JSON.parse(execFileSync(php, [path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
const logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4FoAAAAASUVORK5CYII=';
const catalog = [
  { id: 8, name: 'شرکت + /&', display_name: 'شرکت خارج از گروه', logo, active: true },
  { ...fixtures.provider, active: true },
  { id: 12, name: 'third', display_name: 'شرکت سوم', logo, active: true },
  { id: 99, name: 'inactive', display_name: 'شرکت غیرفعال', logo, active: false },
];
const active = catalog.filter(provider => provider.active);
let state;
const errors = [], external = [];
const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/plugin/assets/')) {
      const file = path.resolve(runtime, url.pathname.split('/plugin/')[1]);
      assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      return res.end(readFileSync(file));
    }
    if (url.pathname.includes('/wp-json/') || url.searchParams.has('rest_route')) {
      const suffix = (url.searchParams.get('rest_route') || url.pathname.split('/wp-json/')[1]).replace(/^\/?tapin-price-check\/v1\//, '');
      const scenario = state;
      const chunks = []; for await (const chunk of req) chunks.push(chunk);
      const body = chunks.length ? JSON.parse(Buffer.concat(chunks).toString()) : null;
      assert.equal(req.headers['x-wp-nonce'], undefined, 'Public requests must not gain an admin nonce');
      scenario.calls.push({ method: req.method, suffix, body });
      let data, status = 200;
      if (suffix === 'providers') {
        if (scenario.holdCatalog) await new Promise(resolve => scenario.pendingCatalog.push(resolve));
        if (scenario.catalog === 'error') { status = 503; data = { detail: 'Fixture catalog unavailable' }; }
        else data = { providers: scenario.catalog === 'empty' ? [] : scenario.catalog === 'malformed' ? null : catalog };
      } else if (suffix === 'bulk') {
        if (scenario.holdBulk) await new Promise(resolve => scenario.pendingBulk.push(resolve));
        if (scenario.bulk === 'error') { status = 503; data = { detail: 'Fixture bulk unavailable' }; }
        else data = { ...fixtures.bulkSchema, providers: scenario.bulk === 'empty' ? [] : fixtures.bulkSchema.providers };
      } else if (suffix.startsWith('providers/')) {
        const identifier = decodeURIComponent(suffix.slice('providers/'.length));
        const provider = active.find(item => item.name === identifier || String(item.id) === identifier);
        assert.ok(provider, 'Navigation requested an unknown/inactive provider');
        data = { ...fixtures.schema, ...provider };
      } else if (suffix === 'bulk-validate') {
        assert.deepEqual(body.values, { weight: '2' });
        data = { valid: true, errors: [] };
      } else if (suffix === 'bulk-check') {
        assert.deepEqual(Object.keys(body).sort(), ['client', 'values']);
        assert.deepEqual(body.values, { weight: '2' }, 'Catalog must not expand the bulk wire payload');
        if (scenario.holdQuote) await new Promise(resolve => scenario.pendingQuote.push(resolve));
        data = { results: [{ ...fixtures.quote, ok: true, variant: {}, variant_detail: [] }] };
      } else throw new Error('Unexpected REST operation: ' + suffix);
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      return res.end(JSON.stringify(data));
    }
    assert.equal(url.pathname, '/site/');
    const site = 'http://127.0.0.1:' + server.address().port + '/site';
    let html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], {
      input: JSON.stringify({ site, mode: url.searchParams.get('page_id') === '51' ? 'single' : 'bulk', admin: false }), encoding: 'utf8',
    }).replaceAll(site + '/single/', site + '/?page_id=51').replaceAll(site + '/bulk/', site + '/?page_id=54');
    if (state.queryRest) html = html.replaceAll(site + '/wp-json/tapin-price-check/v1/', site + '/index.php?rest_route=/tapin-price-check/v1/');
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0">' + html + '</body></html>');
  } catch (error) { errors.push(error.message); res.writeHead(500); res.end('Local fixture error'); }
});
function scenario(overrides = {}) {
  return { catalog: 'success', bulk: 'success', calls: [], pendingCatalog: [], pendingBulk: [], pendingQuote: [], ...overrides };
}
function release(list) { list.splice(0).forEach(resolve => resolve()); }
(async () => {
  let browser, context;
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    browser = await chromium.launch();
    const origin = 'http://127.0.0.1:' + server.address().port;
    mkdirSync(path.join(repo, 'artifacts/bulk-advanced'), { recursive: true });
    for (const width of [1440, 390]) {
      context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
      await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === origin) return route.continue();
        external.push(route.request().url()); return route.abort();
      });
      const page = await context.newPage();
      page.on('pageerror', error => errors.push(error.message));
      const frame = () => page.frameLocator('iframe.tapin-price-frame');
      const grid = () => frame().locator('#bulk-advanced-grid');
      const panel = () => frame().locator('#bulk-advanced-panel');
      const toggle = () => frame().locator('#bulk-advanced-toggle');
      const calls = suffix => state.calls.filter(call => call.suffix === suffix);
      const boot = async overrides => {
        state = scenario(overrides);
        await page.goto(origin + '/site/?page_id=54');
        await expect(toggle()).toBeVisible();
        await expect.poll(() => calls('providers').length, { message: 'Bulk boot must request the complete public catalog' }).toBe(1);
        await page.evaluate(value => window.TapinTheme.set(value), width === 390 ? 'dark' : 'light');
      };
      const open = async () => { await toggle().click(); await expect(panel()).toBeVisible(); };
      const catalogReady = async () => {
        await expect(grid().locator('.adv-card')).toHaveCount(3);
        assert.deepEqual(await grid().locator('.adv-card strong').allTextContents(), active.map(provider => provider.display_name));
        assert.deepEqual(await grid().locator('.adv-card img').evaluateAll(images => images.map(image => image.getAttribute('src'))), active.filter(provider => provider.logo).map(provider => provider.logo));
        await expect(grid()).toHaveAttribute('aria-busy', 'false');
        await expect(frame().locator('#bulk-advanced-count')).toHaveText('۳');
        await expect(grid()).not.toContainText('شرکت غیرفعال');
      };
      const reopen = async () => {
        await page.keyboard.press('Escape'); await expect(panel()).toBeHidden(); await open();
      };

      // Open before the full list arrives. Bulk's one provider must not become the final list.
      await boot({ holdCatalog: true, queryRest: width === 390 });
      await expect(frame().locator('#bulk-form button[type=submit]')).toBeVisible();
      await toggle().focus(); await page.keyboard.press('Enter');
      await expect(panel()).toBeVisible();
      await expect(grid()).toContainText('لیست شرکت‌ها در حال بارگذاری است');
      await expect(grid()).toHaveAttribute('aria-busy', 'true');
      await expect(grid().locator('.adv-card')).toHaveCount(0);
      await reopen(); assert.equal(calls('providers').length, 1);
      release(state.pendingCatalog); await catalogReady();
      await expect(panel()).toBeVisible();
      await reopen(); await reopen(); assert.equal(calls('providers').length, 1);
      await grid().locator('.adv-card').last().click({ trial: true, timeout: 2000 });
      assert.ok(await frame().locator('html').evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await panel().screenshot({ path: path.join(repo, 'artifacts/bulk-advanced', width + '-catalog.png') });

      // The bulk UI still plans one provider, and sends the original values/client request only.
      await page.keyboard.press('Escape');
      state.holdQuote = true;
      await frame().locator('[name="bulk:weight"]').fill('2');
      await frame().locator('#bulk-form button[type=submit]').click();
      await expect.poll(() => calls('bulk-check').length).toBe(1);
      await expect(frame().locator('#bulk-result .skel-card')).toHaveCount(2); // Intro + one bulk provider.
      release(state.pendingQuote);
      await expect(frame().locator('#bulk-form button[type=submit]')).toBeEnabled();
      await expect(frame().locator('#bulk-result .results-grid .result-card')).toHaveCount(1);

      // A non-bulk provider navigates via WordPress page URLs, including encoded technical names.
      await open(); await grid().locator('.adv-card').first().click();
      await expect(page).toHaveURL(url => url.searchParams.get('page_id') === '51' && url.searchParams.get('provider') === active[0].name);
      await expect(frame().locator('#form-title')).toHaveText(active[0].display_name);
      assert.ok(state.calls.some(call => call.suffix === 'providers/' + encodeURIComponent(active[0].name)));
      assert.equal(new URL(page.url()).pathname, '/site/');

      await boot({ bulk: 'empty' }); await open(); await catalogReady();
      await expect(frame().locator('#bulk-form')).toContainText('استعلام هنوز در بخش مدیریت تنظیم نشده است.');
      assert.equal(calls('bulk-check').length, 0);

      await boot({ catalog: 'empty' }); await open();
      await expect(grid()).toHaveText('هنوز شرکت فعالی ثبت نشده است.');
      await expect(grid()).toHaveAttribute('aria-busy', 'false');
      await expect(frame().locator('#bulk-advanced-count')).toBeHidden();
      await reopen(); assert.equal(calls('providers').length, 1);
      await expect(grid().locator('.adv-card')).toHaveCount(0);

      for (const failed of ['error', 'malformed']) {
        await boot({ catalog: failed }); await open();
        await expect(grid()).toContainText('فهرست شرکت‌ها بارگذاری نشد.');
        await expect(grid()).toHaveAttribute('aria-busy', 'false');
        await expect(grid().locator('.adv-card')).toHaveCount(0);
        await expect(frame().locator('#bulk-advanced-count')).toBeHidden();
        await reopen(); assert.equal(calls('providers').length, 1);
        await expect(frame().locator('#bulk-form button[type=submit]')).toBeEnabled();
        state.catalog = 'success'; state.holdCatalog = true;
        await grid().getByRole('button', { name: 'تلاش مجدد', exact: true }).click();
        await expect(grid()).toHaveAttribute('aria-busy', 'true');
        await expect.poll(() => calls('providers').length).toBe(2);
        await reopen(); assert.equal(calls('providers').length, 2);
        release(state.pendingCatalog); await catalogReady();
        await reopen(); assert.equal(calls('providers').length, 2);
      }

      await boot({ holdBulk: true }); await open(); await catalogReady();
      await expect(frame().locator('#bulk-form .skeleton')).toBeVisible();
      release(state.pendingBulk);
      await expect(frame().locator('#bulk-form button[type=submit]')).toBeVisible();
      await boot({ bulk: 'error' }); await open(); await catalogReady();
      await expect(frame().locator('#bulk-form')).toContainText('Fixture bulk unavailable');
      await context.close(); context = null;
    }
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    console.log('Bulk advanced: complete active catalog, independent empty/slow/failed bulk and catalog states, retry/cache deduplication, unchanged bulk payload/planning, encoded WordPress page navigation, pretty/query REST and embedded desktop/mobile passed (local REST/quotation doubles only).');
  } finally {
    if (state) for (const key of ['pendingCatalog', 'pendingBulk', 'pendingQuote']) release(state[key]);
    if (context) await context.close();
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
