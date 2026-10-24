/* Actual PHP renderer and Chromium controls; localhost REST persistence/WordPress identity are doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');

const repo = path.resolve(__dirname, '..');
const runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const fixtures = JSON.parse(execFileSync(php, [path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
const origins = fixtures.adminProvider.params.filter(param => param.name.startsWith('origin_'));
const params = structuredClone(fixtures.adminProvider.params.filter(param => !param.name.startsWith('origin_')));
// Retain real schema IDs/defaults, with populated settings in every editor tab.
for (const param of params) for (const field of param.fields || []) delete field.options;
params.find(param => param.name === 'weight').kind = 'weight';
params.find(param => param.name === 'weight').default = 250;
params.find(param => param.name === 'items').fields[0].default = 'saved package';
params.find(param => param.name === 'details').fields[0].visible = false;
params.find(param => param.name === 'details').fields[0].default = 'saved hidden field';
const expected = {
  name: 'sample', display_name: 'شرکت نمونه', active: true,
  logo: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4FoAAAAASUVORK5CYII=',
  check_price_url: 'https://provider.invalid/quote', shop_id: '101', timeout: 35,
  main_price_key: 'total', price_currency_in: 'rial', price_currency_out: 'toman',
  price_input_param: 'details.note', weight_unit_in: 'gram', weight_unit_out: 'kilogram',
  weight_input_param: 'weight', price_fields: [{ key: 'total', label: 'هزینه نهایی' }], params,
  referral: { enabled: true, title: 'پیگیری نمونه', url: 'https://referral.invalid/track', description: 'saved referral' },
  location_config: {
    origin: { enabled: true, display: 'combined', source: 'custom', custom_mode: 'only' },
    destination: { enabled: false, display: 'separate', province_param: null, city_param: null, source: 'custom', custom_mode: 'merge' },
    custom_locations: { provinces: [{ code: '1', title: 'استان نمونه' }], cities: [{ code: '10', title: 'شهر نمونه', province_code: '1' }] },
    blocked_locations: { provinces: [], cities: [{ code: '20', title: 'شهر مسدود', province_code: '1' }] },
  },
  rules: [{ name: 'saved rule', if_field: 'weight', if_op: 'gt', if_value: '0', if_value2: '', then_field: 'service', then_op: 'eq', then_value: 'normal', then_value2: '', message: 'روش ارسال را بررسی کنید.' }],
};
const providers = new Map([[1, { ...structuredClone(fixtures.adminProvider), ...structuredClone(expected), id: 1, params: [...origins, ...structuredClone(params)] }]]);
const calls = [], fixtureErrors = [], externalRequests = [];
let stringId = false, queryRest = false, rejectWrite = false, holdWrite = false;
const pending = [];
const writes = () => calls.filter(call => call.method === 'PUT' || call.method === 'POST');

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    const restRoute = url.searchParams.get('rest_route');
    if (url.pathname.includes('/wp-json/') || restRoute) {
      const suffix = (restRoute || url.pathname.split('/wp-json/')[1]).replace(/^\/?tapin-price-check\/v1\//, '');
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const raw = Buffer.concat(chunks).toString();
      const body = raw ? JSON.parse(raw) : null;
      assert.equal(req.headers['x-wp-nonce'], 'test-rest-nonce');
      if (body) assert.match(req.headers['content-type'], /^application\/json/);
      calls.push({ method: req.method, suffix, body, pathname: url.pathname, restRoute });
      let data, status = 200;
      if (req.method === 'GET' && suffix === 'admin/providers') {
        data = { providers: [...providers.values()].map(provider => ({ ...provider, id: stringId ? String(provider.id) : provider.id, param_count: provider.params.length })) };
      } else if (req.method === 'GET' && /^admin\/providers\/\d+$/.test(suffix)) {
        const provider = providers.get(Number(suffix.split('/').pop()));
        assert.ok(provider, 'Unknown fixture provider');
        data = { ...provider, id: stringId ? String(provider.id) : provider.id };
      } else if ((req.method === 'PUT' && suffix === 'admin/providers/1') || (req.method === 'POST' && suffix === 'admin/providers')) {
        assert.equal(Object.hasOwn(body, 'id'), false, 'The disabled display ID must not become the JSON identity');
        if (holdWrite) await new Promise(resolve => pending.push(resolve));
        if (rejectWrite) {
          status = 409;
          data = { detail: 'ذخیره در پایگاه داده ناموفق بود.', errors: [] };
        } else {
          const id = req.method === 'PUT' ? 1 : 2;
          providers.set(id, { ...structuredClone(body), id });
          status = req.method === 'POST' ? 201 : 200;
          data = { id, detail: 'ذخیره شد.' };
        }
      } else {
        throw new Error(`Unexpected REST operation: ${req.method} ${suffix}`);
      }
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      return res.end(JSON.stringify(data));
    }
    if (url.pathname.includes('/plugin/assets/')) {
      const file = path.resolve(runtime, url.pathname.split('/plugin/')[1]);
      assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
      const mime = file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream';
      res.writeHead(200, { 'Content-Type': mime });
      return res.end(readFileSync(file));
    }
    assert.equal(url.pathname, '/site/wp-admin/admin.php', 'Only the local admin fixture may be rendered');
    const site = `http://127.0.0.1:${server.address().port}/site`;
    let html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], {
      input: JSON.stringify({ site, mode: 'providers', admin: true, logged: true, capable: true, query: Object.fromEntries(url.searchParams) }), encoding: 'utf8',
    });
    if (queryRest) html = html.replaceAll(site + '/wp-json/tapin-price-check/v1/', site + '/index.php?rest_route=/tapin-price-check/v1/');
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><body style="margin:0">' + html + '</body></html>');
  } catch (error) {
    fixtureErrors.push(error.message);
    res.writeHead(500, { 'Content-Type': 'text/plain' });
    res.end('Local fixture error');
  }
});

(async () => {
  let browser;
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    browser = await chromium.launch();
    const origin = `http://127.0.0.1:${server.address().port}`;
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      externalRequests.push(route.request().url());
      return route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    const frame = () => page.frameLocator('iframe.tapin-price-frame');
    const base = origin + '/site/wp-admin/admin.php?page=tapin-price-providers';
    const ready = () => expect(frame().locator('#provider-rows')).toContainText(expected.display_name);
    const openExisting = async () => {
      await ready();
      await frame().locator('#provider-rows tbody tr').filter({ hasText: 'sample' }).getByRole('button', { name: 'ویرایش', exact: true }).click();
      await expect(frame().locator('#editor-view form')).toBeVisible();
      await expect(frame().getByLabel('شناسه', { exact: true })).toBeDisabled();
      await expect(frame().getByLabel('شناسه', { exact: true })).toHaveValue('1');
    };
    const saveExisting = async () => {
      const before = writes().length;
      await frame().getByRole('button', { name: 'ذخیره', exact: true }).click();
      await expect(frame().locator('#list-view')).toBeVisible();
      await expect(frame().locator('#toast .ok')).toHaveText('ذخیره شد.');
      await ready();
      assert.equal(writes().length, before + 1, 'One click must submit exactly once');
      const write = writes().at(-1);
      assert.equal(write.method, 'PUT');
      assert.equal(write.suffix, 'admin/providers/1');
      assert.equal(Object.hasOwn(write.body, 'id'), false);
      assert.deepEqual(write.body, expected, 'Saving a tab must retain the full draft, including hidden tab settings');
      assert.deepEqual(providers.get(1), { ...expected, id: 1 });
      assert.equal(providers.size, 1);
      return write;
    };

    await page.goto(base);
    await openExisting();
    expected.display_name = 'نام نمایشی ذخیره‌شده';
    await frame().getByLabel('نام نمایشی', { exact: true }).fill(expected.display_name);
    await saveExisting();
    // Reopen after each save; each tab submits its unchanged full record.
    for (const tabName of [/^فیلدها \(/, /^قیمت و وزن \(/, 'تنظیمات شهر و استان', 'لینک ارجاع', /^شرط‌ها \(/, 'تنظیمات شرکت']) {
      await openExisting();
      await frame().locator('#editor-view .subtabs').getByRole('button', { name: tabName, exact: typeof tabName === 'string' }).click();
      await saveExisting();
    }
    assert.equal(writes().filter(call => call.method === 'POST').length, 0, 'Existing providers must never use collection POST');

    // Also exercise the live installation's query-style REST URL and a string ID.
    stringId = queryRest = true;
    await page.goto(base);
    await openExisting();
    const queryWrite = await saveExisting();
    assert.equal(queryWrite.pathname, '/site/index.php');
    assert.equal(queryWrite.restRoute, '/tapin-price-check/v1/admin/providers/1');

    // A pending or failed database response must never paint success or navigate away.
    await page.goto(base);
    await openExisting();
    holdWrite = rejectWrite = true;
    const beforeFailure = writes().length;
    const persisted = structuredClone(providers.get(1));
    await frame().getByLabel('نام نمایشی', { exact: true }).fill('failed draft');
    await frame().getByRole('button', { name: 'ذخیره', exact: true }).click();
    await expect.poll(() => pending.length).toBe(1);
    await expect(frame().locator('#toast .ok')).toHaveCount(0);
    await expect(frame().locator('#editor-view')).toBeVisible();
    holdWrite = false;
    pending.splice(0).forEach(resolve => resolve());
    await expect(frame().locator('#toast .error')).toHaveText('ذخیره در پایگاه داده ناموفق بود.');
    await expect(frame().locator('#toast .ok')).toHaveCount(0);
    await expect(frame().locator('#editor-view')).toBeVisible();
    assert.equal(writes().length, beforeFailure + 1);
    assert.equal(writes().at(-1).method, 'PUT');
    assert.equal(writes().at(-1).suffix, 'admin/providers/1');
    assert.deepEqual(providers.get(1), persisted);
    assert.equal(providers.size, 1);
    rejectWrite = false;

    // New-company state uses the separate create endpoint, then clears on Edit.
    await page.goto(base);
    await ready();
    await frame().getByRole('button', { name: '＋ افزودن شرکت', exact: true }).click();
    await expect(frame().getByLabel('شناسه', { exact: true })).toHaveValue('خودکار');
    await frame().getByLabel('نام ارسالی در وب‌سرویس', { exact: true }).fill('new-save-fixture');
    await frame().getByLabel('نام نمایشی', { exact: true }).fill('شرکت جدید آزمایشی');
    await frame().locator('#editor-view .subtabs').getByRole('button', { name: /^فیلدها \(/ }).click();
    await frame().getByLabel('JSON پارامترها', { exact: true }).fill(JSON.stringify([{ name: 'weight', label: 'وزن', input: 'number', type: 'float', visible: true, required: true }]));
    await frame().getByRole('button', { name: 'پیش‌نمایش و افزودن', exact: true }).click();
    const beforeCreate = writes().length;
    await frame().getByRole('button', { name: 'ذخیره', exact: true }).click();
    await expect(frame().locator('#provider-rows')).toContainText('شرکت جدید آزمایشی');
    await expect(frame().locator('#toast .ok')).toHaveText('ذخیره شد.');
    assert.equal(writes().length, beforeCreate + 1);
    assert.equal(writes().at(-1).method, 'POST');
    assert.equal(writes().at(-1).suffix, 'admin/providers');
    assert.equal(writes().at(-1).body.name, 'new-save-fixture');
    assert.equal(writes().at(-1).body.params[0].name, 'weight');
    assert.equal(providers.size, 2);
    assert.deepEqual(providers.get(1), persisted);
    await openExisting();
    await expect(frame().getByLabel('نام نمایشی', { exact: true })).toHaveValue(expected.display_name);
    assert.deepEqual(pageErrors, []);
    assert.deepEqual(fixtureErrors, []);
    assert.deepEqual(externalRequests, [], 'All requests must stay on the local fixture origin');
    console.log('Provider save browser: full payload from all six tabs; unchanged/repeated PUT; number/string ID; query-style REST; pending/error response; separate POST; one request per click passed (mock REST only).');
  } finally {
    holdWrite = false;
    pending.splice(0).forEach(resolve => resolve());
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
