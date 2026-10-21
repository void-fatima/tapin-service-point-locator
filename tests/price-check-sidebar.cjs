/* Real HTTP cache, srcdoc and packaged PHP renderer. REST/WordPress identity are doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..');
const runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const css = readFileSync(path.join(runtime, 'assets/price-check/style.css'), 'utf8');
// A visitor who used Price Check before the sidebar feature has this CSS cached.
const legacyCSS = css.slice(0, css.indexOf('/* Price Check admin sidebar'));
assert.ok(legacyCSS.length > 1000 && !legacyCSS.includes('pc-sidebar-collapsed'));
let upgraded = false;
const assetRequests = [];
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname.includes('/plugin/assets/')) {
    const relative = url.pathname.split('/plugin/')[1];
    const file = path.resolve(runtime, relative);
    assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
    assetRequests.push(req.url);
    res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : file.endsWith('.woff2') ? 'font/woff2' : 'image/png', 'Cache-Control': 'public, max-age=604800' });
    return res.end(file.endsWith('/style.css') || file.endsWith('\\style.css') ? (upgraded ? css : legacyCSS) : readFileSync(file));
  }
  if (url.pathname.includes('/wp-json/')) {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ providers: [] }));
  }
  const site = `http://127.0.0.1:${server.address().port}/site`;
  if (url.pathname === '/warm') {
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(`<link rel="stylesheet" href="${site}/plugin/assets/price-check/style.css">Old Price Check visit`);
  }
  const html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], { input: JSON.stringify({ site, mode: 'providers', admin: true, logged: true, capable: true }), encoding: 'utf8' });
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end('<!doctype html><html><body style="margin:0" class="tapin-price-page">' + html + '</body></html>');
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  page.on('requestfailed', request => errors.push(request.url() + ': ' + request.failure().errorText));
  const base = `http://127.0.0.1:${server.address().port}`;
  const frame = () => page.frameLocator('iframe');
  const toggle = frame().locator('.pc-sidebar-toggle');
  const sidebar = frame().locator('.sidebar');
  const tooltip = frame().locator('#pc-sidebar-tooltip');
  const widthOf = async locator => Math.round((await locator.boundingBox()).width);
  const expectWidth = async width => expect.poll(() => widthOf(sidebar)).toBe(width);
  const assertTooltip = async item => {
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toHaveText(await item.getAttribute('aria-label'));
    await expect(item).toHaveAttribute('aria-describedby', 'pc-sidebar-tooltip');
    const bounds = await tooltip.boundingBox();
    const size = page.viewportSize();
    assert.ok(bounds.x >= 8 && bounds.x + bounds.width <= size.width - 7, 'Tooltip clipped horizontally');
    assert.ok(bounds.y >= 8 && bounds.y + bounds.height <= size.height - 7, 'Tooltip clipped by iframe');
    if (size.width > 700) assert.ok(bounds.x + bounds.width < (await item.boundingBox()).x, 'RTL tooltip must face content');
  };
  mkdirSync(path.join(repo, 'artifacts/sidebar'), { recursive: true });
  try {
    // Do not use page/context.route: Playwright routing disables HTTP cache.
    await page.goto(base + '/warm');
    upgraded = true;
    await page.goto(base + '/site/wp-admin/admin.php?page=tapin-price-providers');
    await expect(frame().locator('#provider-rows')).not.toContainText('در حال بارگذاری');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    const before = await toggle.evaluate(n => ({ background: getComputedStyle(n).backgroundColor, border: getComputedStyle(n).borderWidth, width: n.closest('.sidebar').getBoundingClientRect().width }));
    console.log('Warm-cache rendered branding:', before);
    await page.screenshot({ path: path.join(repo, 'artifacts/sidebar/warm-cache-expanded.png') });
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    console.log('After pointer click:', await toggle.evaluate(n => ({ expanded: n.getAttribute('aria-expanded'), stateClass: n.closest('.pc-admin-shell').className, width: n.closest('.sidebar').getBoundingClientRect().width })));
    assert.equal(before.background, 'rgba(0, 0, 0, 0)', 'Cached pre-feature CSS leaves the native gray button');
    assert.equal(before.border, '0px');
    assert.equal(Math.round(before.width), 190);
    await expectWidth(72);
    assert.ok(assetRequests.some(url => /style\.css\?v=/.test(url)), 'Admin CSS must bypass the old URL');
    console.log('PASS warm HTTP cache: current CSS requested; transparent branding and visible collapse. Runtime:', runtime);
    const publicLink = frame().locator('.pc-sidebar-public-link');
    await expect(publicLink).toHaveAttribute('href', base + '/site/bulk/');
    await expect(publicLink).toHaveAttribute('target', '_parent');
    for (const width of [1440, 800, 700, 390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      const content = frame().locator('.content');
      const collapsedContentWidth = await widthOf(content);
      const items = sidebar.locator('[data-tooltip]');
      assert.equal(await items.count(), 7);
      for (let i = 0; i < await items.count(); i++) {
        const item = items.nth(i);
        await expect(item.locator('.pc-menu-label')).toBeHidden();
        await expect(item.locator('svg')).toBeVisible();
        assert.ok(await item.getAttribute('aria-label'));
        await item.hover();
        await assertTooltip(item);
        await frame().locator('#main').hover();
        await item.focus();
        await assertTooltip(item);
        await page.keyboard.press('Escape');
        await expect(tooltip).toBeHidden();
      }
      const active = sidebar.locator('[aria-current="page"]');
      await expect(active).toHaveAttribute('data-tab', 'providers');
      assert.ok((await active.evaluate(n => getComputedStyle(n).backgroundImage)).includes('rgb(255, 214, 2)'));
      assert.ok((await sidebar.evaluate(n => getComputedStyle(n).backgroundImage)).includes('rgb(53, 52, 138)'));
      await expect(toggle.locator('.brand-mark')).toBeVisible();
      await toggle.focus();
      await page.keyboard.press('Enter');
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await expect(toggle).toHaveAttribute('aria-label', 'جمع کردن منوی مدیریت');
      await expect(sidebar.locator('.pc-menu-label').first()).toBeVisible();
      await expect(toggle).toBeFocused();
      assert.equal(await toggle.evaluate(n => getComputedStyle(n).outlineStyle), 'solid');
      if (width > 960) {
        await expectWidth(190);
        assert.ok(await widthOf(content) < collapsedContentWidth, 'Content must occupy released width');
      }
      if (width <= 700) {
        assert.equal(await widthOf(sidebar), width);
        assert.equal(await sidebar.locator('.tabs.menu').evaluate(n => getComputedStyle(n).flexDirection), 'row');
      }
      await page.screenshot({ path: path.join(repo, `artifacts/sidebar/expanded-${width}.png`) });
      await page.keyboard.press('Space');
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await expect(toggle).toHaveAttribute('aria-label', 'نمایش منوی مدیریت');
      // A single listener must settle deterministically, including clicks mid-transition.
      await toggle.evaluate(n => { for (let i = 0; i < 11; i++) n.click(); });
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await page.reload();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');
      await expect(sidebar.locator('.pc-menu-label').first()).toBeHidden();
      if (width > 700) await expectWidth(72);
      await sidebar.locator('[data-tab="providers"]').hover();
      await assertTooltip(sidebar.locator('[data-tab="providers"]'));
      assert.ok(await page.frames()[1].evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'New horizontal overflow at ' + width);
      await page.screenshot({ path: path.join(repo, `artifacts/sidebar/collapsed-${width}.png`) });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await toggle.click();
    assert.equal(await sidebar.evaluate(n => getComputedStyle(n).transitionDuration), '0s');
    assert.equal(await widthOf(sidebar), 190);
    await toggle.click();
    assert.equal(await widthOf(sidebar), 72);
    // Verify unavailable storage both at initialization and when writing state.
    const blocked = await context.newPage();
    blocked.on('pageerror', error => errors.push(error.message));
    blocked.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await blocked.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Disabled', 'SecurityError'); } }));
    await blocked.goto(base + '/site/wp-admin/admin.php?page=tapin-price-providers');
    const blockedToggle = blocked.frameLocator('iframe').locator('.pc-sidebar-toggle');
    await expect(blockedToggle).toHaveAttribute('aria-expanded', 'true');
    await blockedToggle.click();
    await expect(blockedToggle).toHaveAttribute('aria-expanded', 'false');
    await blockedToggle.click();
    await expect(blockedToggle).toHaveAttribute('aria-expanded', 'true');
    await blocked.close();
    assert.deepEqual(errors, []);
    console.log('PASS sidebar: 1440/800/700/390/320px, all seven hover/focus tooltips in iframe bounds, icons/names/active styling, pointer/Enter/Space, rapid clicks, persisted reload, blocked storage, reduced motion and content resizing; no console errors or horizontal overflow.');
  } finally {
    console.log('Asset requests:', assetRequests.filter(url => /style\.css|admin\.js/.test(url)));
    console.log('Console errors:', errors);
    await context.close(); await browser.close(); await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
