/* Real PHP renderer/assets in Chromium; every REST read/write is a localhost fixture. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');

const repo = path.resolve(__dirname, '..');
const runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const fixture = JSON.parse(execFileSync(php, [path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4FoAAAAASUVORK5CYII=', 'base64');
const initialLogo = 'data:image/png;base64,' + png.toString('base64');
const longFilename = 'لوگوی-شرکت-' + 'long_filename_'.repeat(18) + '.svg';
const svg = Buffer.from('<svg xmlns="http://www.w3.org/2000/svg" width="128" height="64"><rect width="128" height="64" fill="#faa627"/><circle cx="64" cy="32" r="24" fill="#2d2b74"/></svg>');
const selectedLogo = 'data:image/svg+xml;base64,' + svg.toString('base64');
const providers = new Map([[1, { ...structuredClone(fixture.adminProvider), logo: initialLogo }]]);
const writes = [], fixtureErrors = [], externalRequests = [], pageErrors = [];

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/wp-json/')) {
      const suffix = url.pathname.split('/wp-json/tapin-price-check/v1/')[1];
      assert.equal(req.headers['x-wp-nonce'], 'test-rest-nonce');
      let data, status = 200;
      if (req.method === 'GET' && suffix === 'admin/providers') {
        data = { providers: [...providers.values()].map(provider => ({ ...provider, param_count: provider.params.length })) };
      } else if (req.method === 'GET' && suffix === 'admin/providers/1') {
        data = providers.get(1);
      } else if ((req.method === 'PUT' && suffix === 'admin/providers/1') || (req.method === 'POST' && suffix === 'admin/providers')) {
        const chunks = [];
        for await (const chunk of req) chunks.push(chunk);
        const body = JSON.parse(Buffer.concat(chunks).toString());
        writes.push({ method: req.method, suffix, body });
        const id = req.method === 'PUT' ? 1 : 2;
        providers.set(id, { ...body, id });
        status = req.method === 'POST' ? 201 : 200;
        data = { id, detail: 'ذخیره شد.' };
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
    assert.equal(url.pathname, '/site/wp-admin/admin.php');
    const site = `http://127.0.0.1:${server.address().port}/site`;
    const html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], {
      input: JSON.stringify({ site, mode: 'providers', admin: true, logged: true, capable: true, query: Object.fromEntries(url.searchParams) }), encoding: 'utf8',
    });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body class="tapin-price-page" style="margin:0">' + html + '</body></html>');
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
    const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin === origin) return route.continue();
      externalRequests.push(route.request().url());
      return route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    page.on('pageerror', error => pageErrors.push(error.message));
    const frame = () => page.frameLocator('iframe.tapin-price-frame');
    const box = () => frame().locator('.provider-logo-upload');
    const input = () => box().locator('.provider-logo-upload__input');
    const preview = () => box().locator('.provider-logo-upload__preview');
    const choose = () => box().locator('.provider-logo-upload__choose');
    const filename = () => box().locator('.provider-logo-upload__filename');
    const remove = () => box().locator('.provider-logo-upload__remove');
    const error = () => box().locator('.provider-logo-upload__error');
    const openExisting = async () => {
      await expect(frame().locator('#provider-rows')).toContainText('sample');
      await frame().locator('#provider-rows tbody tr').filter({ hasText: 'sample' }).getByRole('button', { name: 'ویرایش', exact: true }).click();
      await expect(box()).toBeVisible();
    };
    const save = async (method, logo) => {
      const before = writes.length;
      await frame().getByRole('button', { name: 'ذخیره', exact: true }).click();
      await expect(frame().locator('#list-view')).toBeVisible();
      await expect.poll(() => writes.length).toBe(before + 1);
      assert.equal(writes.at(-1).method, method);
      assert.equal(writes.at(-1).body.logo, logo, 'The logo must retain its existing data-URL/empty-string representation');
      assert.equal(Object.hasOwn(writes.at(-1).body, 'logo_filename'), false, 'Display-only filenames must not enter provider storage');
    };
    const select = async (name = longFilename, buffer = svg, mimeType = 'image/svg+xml') => {
      await input().setInputFiles({ name, mimeType, buffer });
      await expect(preview().locator('img')).toHaveAttribute('src', 'data:' + mimeType + ';base64,' + buffer.toString('base64'));
      await expect(filename()).toHaveText(name);
      await expect(filename()).toHaveAttribute('title', name);
      await expect(error()).toBeEmpty();
    };
    const noLogo = async () => {
      await expect(preview().locator('img')).toHaveCount(0);
      await expect(preview().getByRole('img', { name: 'بدون لوگو', exact: true })).toBeVisible();
      await expect(filename()).not.toBeEmpty();
      assert.equal(await remove().isVisible(), false);
      assert.equal(await input().evaluate(node => node.value), '');
    };
    const layout = async (width, theme, state) => {
      await page.setViewportSize({ width, height: 1100 });
      await page.evaluate(value => window.TapinTheme.set(value), theme);
      await expect(frame().locator('html')).toHaveAttribute('data-theme-mode', theme);
      await box().scrollIntoViewIfNeeded();
      const geometry = await box().evaluate(node => {
        const rect = element => { const r = element.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height }; };
        const visible = element => !element.hidden && getComputedStyle(element).display !== 'none';
        const controls = [...node.querySelectorAll('.provider-logo-upload__preview, .provider-logo-upload__choose, .provider-logo-upload__filename, .provider-logo-upload__remove')].filter(visible).map(rect);
        const style = getComputedStyle(node), image = node.querySelector('img');
        const filename = node.querySelector('.provider-logo-upload__filename');
        return {
          box: rect(node), preview: rect(node.querySelector('.provider-logo-upload__preview')), choose: rect(node.querySelector('.provider-logo-upload__choose')),
          controls, scrollWidth: node.scrollWidth, clientWidth: node.clientWidth, documentWidth: document.documentElement.scrollWidth, viewport: innerWidth,
          border: style.borderInlineStartWidth, borderColor: style.borderInlineStartColor, background: style.backgroundColor,
          direction: style.direction, objectFit: image && getComputedStyle(image).objectFit,
          filenameHeight: rect(filename).height, filenameLineHeight: parseFloat(getComputedStyle(filename).lineHeight),
          padding: ['paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].map(key => parseFloat(style[key])),
        };
      });
      assert.equal(geometry.direction, 'rtl');
      assert.ok(Math.abs(geometry.preview.width - 64) < 1 && Math.abs(geometry.preview.height - 64) < 1, 'Logo frame must stay 64 × 64');
      if (geometry.objectFit) assert.equal(geometry.objectFit, 'contain');
      assert.ok(parseFloat(geometry.border) >= 1 && geometry.borderColor !== 'rgba(0, 0, 0, 0)', 'Upload box must have a visible border');
      assert.notEqual(geometry.background, 'rgba(0, 0, 0, 0)', 'Upload box must have a theme-aware surface');
      assert.ok(geometry.padding.every(value => value >= 10), 'Controls need comfortable container padding');
      assert.ok(geometry.scrollWidth <= geometry.clientWidth + 1, `${width}px ${theme} ${state}: upload content overflows horizontally`);
      assert.ok(geometry.documentWidth <= geometry.viewport + 1, `${width}px ${theme} ${state}: document overflows horizontally`);
      assert.ok(geometry.filenameHeight <= 2 * geometry.filenameLineHeight + 1, 'Long filenames must remain within two visible lines to keep the upload box compact');
      for (const control of geometry.controls) {
        assert.ok(control.left >= geometry.box.left + 8 && control.right <= geometry.box.right - 8, 'Every visible logo control must fit inside the padded box');
        assert.ok(control.top >= geometry.box.top + 8 && control.bottom <= geometry.box.bottom - 8, 'Every visible logo control must have vertical padding');
      }
      const p = geometry.preview, c = geometry.choose;
      assert.ok(p.right <= c.left || c.right <= p.left || p.bottom <= c.top || c.bottom <= p.top, 'Preview and choose control must not overlap');
      if (width === 1440) assert.ok(p.left >= c.right, 'Desktop RTL preview must sit to the right of upload controls');
      await box().screenshot({ path: path.join(repo, 'artifacts/logo-upload', `${width}-${theme}-${state}.png`) });
      return geometry;
    };

    mkdirSync(path.join(repo, 'artifacts/logo-upload'), { recursive: true });
    await page.goto(origin + '/site/wp-admin/admin.php?page=tapin-price-providers');
    await openExisting();
    await expect(frame().getByText('لوگو - انتخاب فایل تصویری', { exact: true })).toBeVisible();
    await expect(input()).toHaveAttribute('type', 'file');
    await expect(input()).toHaveAttribute('accept', 'image/*');
    await expect(choose()).toHaveText('انتخاب تصویر');
    await expect(preview().locator('img')).toHaveAttribute('src', initialLogo);
    await expect(remove()).toHaveText('حذف لوگو');
    assert.ok(await choose().evaluate(node => node.control === node.closest('.provider-logo-upload').querySelector('input[type=file]')), 'The visible selection label must target the real file input');
    await save('PUT', initialLogo);
    await openExisting();
    const themeSurfaces = {};
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
      themeSurfaces[theme] = (await layout(width, theme, 'existing')).background;
    }
    assert.notEqual(themeSurfaces.light, themeSurfaces.dark, 'Upload surface must adapt to the selected theme');

    // Clicking the visible label opens the native chooser and selects through its actual input.
    const chooserPromise = page.waitForEvent('filechooser');
    await choose().click();
    await (await chooserPromise).setFiles({ name: 'new-logo.png', mimeType: 'image/png', buffer: png });
    await expect(filename()).toHaveText('new-logo.png');
    await select();
    await expect(preview().locator('img')).toHaveJSProperty('naturalWidth', 128);
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
      await layout(width, theme, 'long-filename');
      // Reach the visually hidden, native input with Tab and show focus on its visible label.
      await input().focus();
      await page.keyboard.press('Shift+Tab');
      await page.keyboard.press('Tab');
      await expect(input()).toBeFocused();
      const focus = await choose().evaluate(node => { const style = getComputedStyle(node); return { outline: parseFloat(style.outlineWidth), shadow: style.boxShadow }; });
      assert.ok(focus.outline >= 2 || focus.shadow !== 'none', 'Keyboard focus must visibly identify the image-selection control');
      await box().screenshot({ path: path.join(repo, 'artifacts/logo-upload', `${width}-${theme}-focus.png`) });
      await page.mouse.move(0, 0);
      await expect.poll(() => choose().evaluate(node => node.matches(':hover'))).toBe(false);
      const originalColor = await choose().evaluate(node => getComputedStyle(node).borderColor);
      await choose().hover();
      await expect.poll(() => choose().evaluate(node => getComputedStyle(node).borderColor)).not.toBe(originalColor);
      await input().evaluate(node => { node.disabled = true; });
      const disabled = await choose().evaluate(node => { const style = getComputedStyle(node); return { opacity: Number(style.opacity), cursor: style.cursor }; });
      assert.ok(disabled.opacity < 1 && disabled.cursor === 'not-allowed', 'The visible label must reflect the native input disabled state');
      await input().evaluate(node => { node.disabled = false; });
      await input().focus();
      await page.keyboard.press('Tab');
      await expect(remove()).toBeFocused();
      const removeFocus = await remove().evaluate(node => getComputedStyle(node).outlineWidth);
      assert.ok(parseFloat(removeFocus) >= 2, 'The remove action must retain a visible keyboard focus ring');
      const removeBackground = await remove().evaluate(node => getComputedStyle(node).backgroundColor);
      await remove().hover();
      await expect.poll(() => remove().evaluate(node => getComputedStyle(node).backgroundColor)).not.toBe(removeBackground);
      await remove().evaluate(node => { node.disabled = true; });
      const removeDisabled = await remove().evaluate(node => { const style = getComputedStyle(node); return { opacity: Number(style.opacity), cursor: style.cursor }; });
      assert.ok(removeDisabled.opacity < 1 && removeDisabled.cursor === 'not-allowed', 'The remove action must visibly reflect its disabled state');
      await remove().evaluate(node => { node.disabled = false; });
    }
    await input().focus();
    const keyboardChooser = page.waitForEvent('filechooser');
    await page.keyboard.press('Space');
    await (await keyboardChooser).setFiles({ name: longFilename, mimeType: 'image/svg+xml', buffer: svg });
    await expect(filename()).toHaveText(longFilename);

    // Validation rejects the new file while retaining the valid draft image and filename.
    await input().setInputFiles({ name: 'not-an-image.txt', mimeType: 'text/plain', buffer: Buffer.from('invalid') });
    await expect(error()).toContainText('فقط فایل تصویری انتخاب کنید.');
    await expect(error()).toHaveAttribute('role', 'alert');
    await expect(input()).toHaveAttribute('aria-invalid', 'true');
    assert.ok((await input().getAttribute('aria-describedby')).split(/\s+/).includes(await error().getAttribute('id')), 'Validation feedback must describe the actual file input');
    await expect(preview().locator('img')).toHaveAttribute('src', selectedLogo);
    await expect(filename()).toHaveText(longFilename);
    assert.equal(await input().evaluate(node => node.value), '');
    await input().setInputFiles({ name: 'too-large.png', mimeType: 'image/png', buffer: Buffer.alloc(1024 * 1024 + 1) });
    await expect(error()).toContainText('حجم لوگو نباید بیشتر از ۱ مگابایت باشد.');
    await expect(preview().locator('img')).toHaveAttribute('src', selectedLogo);
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) await layout(width, theme, 'validation');
    await select();
    await save('PUT', selectedLogo);
    await openExisting();
    await expect(preview().locator('img')).toHaveAttribute('src', selectedLogo);
    await remove().click();
    await noLogo();
    await select('same-file.svg');
    await remove().click();
    await noLogo();
    await select('same-file.svg');
    await remove().click();
    await noLogo();
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) await layout(width, theme, 'empty');
    await save('PUT', '');
    await openExisting();
    await noLogo();
    await frame().getByRole('button', { name: 'بازگشت', exact: true }).click();
    await frame().getByRole('button', { name: '＋ افزودن شرکت', exact: true }).click();
    await noLogo();
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) await layout(width, theme, 'create');
    await select('created-logo.svg');
    await frame().getByLabel('نام ارسالی در وب‌سرویس', { exact: true }).fill('logo-fixture-new');
    await save('POST', selectedLogo);
    assert.equal(providers.size, 2);
    assert.equal(providers.get(1).logo, '');
    assert.equal(providers.get(2).logo, selectedLogo);
    assert.deepEqual(pageErrors, []);
    assert.deepEqual(fixtureErrors, []);
    assert.deepEqual(externalRequests, [], 'The browser must never contact live WordPress or a provider');
    console.log('Logo upload browser: existing/empty/Create/Edit; native chooser and Tab focus; select/replace/remove/reselect; type/1 MiB validation; data-URL/empty-string saves; desktop 1440px/mobile 390px in light/dark with long names, padding, overflow, hover, focus and disabled states passed (localhost REST fixtures only). Screenshots: artifacts/logo-upload/.');
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
