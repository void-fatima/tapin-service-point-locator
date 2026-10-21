/* Chromium + actual PHP renderer/assets; WordPress identity and REST are doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..');
const runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const fixtures = JSON.parse(execFileSync(php, ['-d', 'extension_dir=' + path.join(repo, 'artifacts/php-runtime/ext'), '-d', 'extension=zip', path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
const pending = [];
let hold = null;
let failure = null;
let shopTitle = 'شعبه تازه';
const screens = { providers: 'list-view', shops: 'shops-view', bulk: 'bulk-view', logs: 'logs-view', cache: 'cache-view', security: 'security-view' };
const calls = [];
const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/plugin/assets/')) {
      const file = path.resolve(runtime, url.pathname.split('/plugin/')[1]);
      assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
      res.writeHead(200, { 'Content-Type': file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
      return res.end(readFileSync(file));
    }
    if (url.pathname.includes('/wp-json/')) {
      const suffix = url.pathname.split('/tapin-price-check/v1/')[1];
      calls.push(suffix);
      assert.equal(req.headers['x-wp-nonce'], 'test-rest-nonce');
      let data = suffix === 'admin/providers' ? { providers: [{ ...fixtures.adminProvider, param_count: 0 }] }
        : suffix === 'admin/providers/1' ? fixtures.adminProvider
        : suffix === 'admin/shops' ? { shops: [{ id: '103', title: shopTitle, province: '1', city: '10', has_token: true }] }
        : suffix === 'admin/bulk' ? { config: fixtures.bulkConfig, providers: [fixtures.provider] }
        : suffix === 'admin/logs' ? { logs: [], filters: {} }
        : suffix === 'admin/cache' ? { settings: {}, stats: {}, locations: {} }
        : suffix === 'admin/security/route-limits' ? { routes: [], admin_groups: [] }
        : suffix === 'admin/security/allowlist' ? { allowlist: [] }
        : suffix === 'admin/security/rate-limits' ? { limits: [] }
        : suffix === 'admin/security/repeat-offenders' ? { offenders: [] }
        : suffix === 'admin/security/blocks' ? { blocks: [] }
        : suffix === 'admin/security/events' ? { events: [] } : {};
      const fail = failure === suffix;
      // WordPress private REST responses are not cacheable. Send headers before
      // holding the body so Chromium cannot serialize identical GETs on a cache lock.
      res.writeHead(fail ? 503 : 200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.flushHeaders();
      if (hold === suffix) await new Promise(resolve => pending.push({ suffix, resolve }));
      return res.end(JSON.stringify(fail ? { detail: 'خطای آزمایشی ناوبری' } : data));
    }
    const site = `http://127.0.0.1:${server.address().port}/site`;
    const mode = (url.searchParams.get('page') || 'tapin-price-providers').replace('tapin-price-', '');
    const html = execFileSync(php, [path.join(__dirname, 'price-check-ui-render.php')], { input: JSON.stringify({ site, mode, admin: true, logged: true, capable: true, query: Object.fromEntries(url.searchParams) }), encoding: 'utf8' });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end('<!doctype html><html><body style="margin:0" class="tapin-price-page">' + html + '</body></html>');
  } catch (error) { console.error(error); res.writeHead(500); res.end('Fixture error'); }
});
(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const errors = [], documents = [], frameEvents = [];
  const consoleErrors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  page.on('request', request => { if (request.resourceType() === 'document') documents.push(request.url()); });
  page.on('framenavigated', frame => frameEvents.push(frame.url()));
  const base = `http://127.0.0.1:${server.address().port}/site/wp-admin/admin.php`;
  const frame = () => page.frameLocator('iframe');
  const click = screen => frame().locator(`[data-tab="${screen}"]`).click();
  const visible = async screen => {
    await expect(frame().locator('#' + screens[screen])).toBeVisible();
    await expect(frame().locator('#main > section:not([hidden])')).toHaveCount(1);
    await expect(frame().locator(`[data-tab="${screen}"]`)).toHaveAttribute('aria-current', 'page');
    await expect(page).toHaveURL(new RegExp('page=tapin-price-' + screen));
  };
  const ready = async screen => { await visible(screen); await expect(frame().locator('#' + screens[screen] + ' .skeleton')).toHaveCount(0); };
  const afterPaint = () => page.frames()[1].evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  const release = () => { hold = null; pending.splice(0).forEach(item => item.resolve()); };
  mkdirSync(path.join(repo, 'artifacts/navigation'), { recursive: true });
  try {
    await page.goto(base + '?page=tapin-price-shops');
    await expect(frame().locator('#shops-rows')).toContainText(shopTitle);
    await page.frames()[1].evaluate(() => { window.navigationDocument = 'original'; document.querySelector('.pc-admin-shell').navigationShell = 'original'; });
    const initialDocuments = documents.length;
    await click('security');
    await expect(frame().locator('#security-view')).toBeVisible();
    await expect(frame().locator('#security-routes .skeleton')).toHaveCount(0);
    console.log('shops → security document requests:', documents.slice(initialDocuments));
    console.log('Document/shell preserved:', await page.frames()[1].evaluate(() => ({ document: window.navigationDocument, shell: document.querySelector('.pc-admin-shell').navigationShell })));
    assert.equal(documents.length, initialDocuments, 'Internal section click reloaded the WordPress page and iframe');
    assert.equal(await page.frames()[1].evaluate(() => window.navigationDocument), 'original');
    for (const width of [1440, 390]) for (const collapsed of [false, true]) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + '?page=tapin-price-shops');
      await ready('shops');
      const toggle = frame().locator('.pc-sidebar-toggle');
      if ((await toggle.getAttribute('aria-expanded') === 'false') !== collapsed) await toggle.click();
      await expect.poll(() => frame().locator('.sidebar').evaluate(n => n.getAnimations().length)).toBe(0);
      const count = documents.length;
      await page.frames()[1].evaluate(() => {
        window.navigationDocument = 'original';
        const shell = document.querySelector('.pc-admin-shell');
        shell.navigationShell = 'original';
        const sidebar = shell.querySelector('.sidebar');
        const rect = sidebar.getBoundingClientRect();
        const main = document.querySelector('#main').getBoundingClientRect();
        window.navigationSamples = { frames: 0, blank: 0, overlap: 0, hiddenFocus: 0, moved: 0, animateShell: 0 };
        window.monitorNavigation = true;
        const sample = () => {
          if (!window.monitorNavigation) return;
          const stats = window.navigationSamples;
          const views = [...document.querySelectorAll('#main > section')].filter(n => !n.hidden);
          const box = sidebar.getBoundingClientRect(), content = document.querySelector('#main').getBoundingClientRect();
          stats.frames++;
          if (!views.length || views.some(n => n.getBoundingClientRect().height === 0 || +getComputedStyle(n).opacity < 0.9)) stats.blank++;
          if (views.length > 1) stats.overlap++;
          if (document.activeElement.closest('#main > section')?.hidden) stats.hiddenFocus++;
          if (Math.abs(box.x - rect.x) > 1 || Math.abs(box.y - rect.y) > 1 || Math.abs(box.width - rect.width) > 1 || Math.abs(content.x - main.x) > 1 || Math.abs(content.width - main.width) > 1 || Math.abs(content.y - main.y) > 1) stats.moved++;
          if (shell.getAnimations().length || sidebar.getAnimations().length) stats.animateShell++;
          requestAnimationFrame(sample);
        };
        requestAnimationFrame(sample);
      });
      // Visit every directed pair, including shops → security and the return.
      for (const from of Object.keys(screens)) {
        await click(from); await ready(from);
        for (const to of Object.keys(screens)) {
          await click(to); await ready(to);
          await expect(toggle).toHaveAttribute('aria-expanded', String(!collapsed));
          await click(from); await ready(from);
        }
      }
      await click('shops'); await ready('shops');
      const requestCount = calls.length;
      await frame().locator('#shops-view form input').first().fill('unsaved');
      await click('shops');
      assert.equal(calls.length, requestCount, 'Reselecting the same section should not rebuild its form');
      await expect(frame().locator('#shops-view form input').first()).toHaveValue('unsaved');
      await frame().locator('[data-tab="security"]').focus();
      await page.keyboard.press('Enter'); await ready('security');
      await expect(frame().locator('[data-tab="security"]')).toBeFocused();
      await page.goBack(); await ready('shops');
      await page.goForward(); await ready('security');
      await frame().locator('#security-view [data-subtab="routes"]').focus();
      await page.goBack(); await ready('shops');
      assert.equal(await page.frames()[1].evaluate(() => document.activeElement.closest('#main > section')?.id), 'shops-view');
      assert.equal(documents.length, count, 'Internal/history navigation requested a new document');
      const stats = await page.frames()[1].evaluate(() => { window.monitorNavigation = false; return { ...window.navigationSamples, document: window.navigationDocument, shell: document.querySelector('.pc-admin-shell').navigationShell }; });
      assert.equal(stats.document, 'original'); assert.equal(stats.shell, 'original');
      for (const key of ['blank', 'overlap', 'hiddenFocus', 'moved', 'animateShell']) assert.equal(stats[key], 0, key + ': ' + JSON.stringify(stats));
      assert.ok(stats.frames > 0);
      console.log(`PASS ${width}px collapsed=${collapsed}: all directed pairs, same document/shell, zero blank/overlap/movement frames, history and keyboard focus.`, stats);
      await page.screenshot({ path: path.join(repo, `artifacts/navigation/shops-${width}-${collapsed}.png`) });
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await click('security'); await ready('security');
    hold = 'admin/shops'; shopTitle = 'نتیجه قدیمی';
    const oldResponse = page.waitForResponse(response => response.url().includes('/admin/shops'));
    await click('shops'); await expect.poll(() => pending.length).toBe(1);
    await expect(frame().locator('#shops-view [role="status"]')).toBeVisible();
    await click('security'); await ready('security');
    hold = null; shopTitle = 'نتیجه جدید';
    await click('shops'); await ready('shops');
    await expect(frame().locator('#shops-rows')).toContainText(shopTitle);
    release(); await (await oldResponse).finished(); await afterPaint();
    await expect(frame().locator('#shops-rows')).toContainText('نتیجه جدید');
    await expect(frame().locator('#shops-rows')).not.toContainText('نتیجه قدیمی');
    // A late editor fetch must never resurrect its view or rewrite the latest URL.
    await click('providers'); await ready('providers');
    hold = 'admin/providers/1';
    const oldEditor = page.waitForResponse(response => response.url().includes('/admin/providers/1'));
    await frame().locator('#provider-rows').getByRole('button', { name: 'ویرایش', exact: true }).click();
    await expect.poll(() => pending.length).toBe(1);
    await click('security'); await ready('security');
    release(); await (await oldEditor).finished(); await afterPaint(); await visible('security');
    // Release stale errors after a newer successful request has already painted.
    hold = failure = 'admin/shops';
    const staleError = page.waitForResponse(response => response.url().includes('/admin/shops'));
    await click('shops'); await expect.poll(() => pending.length).toBe(1);
    await click('cache'); await ready('cache');
    hold = failure = null;
    await click('shops'); await ready('shops');
    release(); await (await staleError).finished(); await afterPaint();
    await expect(frame().locator('#shops-rows .error-text')).toHaveCount(0);
    failure = 'admin/shops'; await click('security'); await ready('security'); await click('shops');
    await expect(frame().locator('#shops-rows')).toContainText('خطای آزمایشی ناوبری');
    failure = null; await click('security'); await ready('security');
    // Multiple section clicks in a single task; only the last choice may be visible.
    await frame().locator('.pc-admin-shell').evaluate(shell => ['shops', 'bulk', 'logs', 'cache', 'security', 'shops'].forEach(name => shell.querySelector(`[data-tab="${name}"]`).click()));
    await ready('shops'); await afterPaint(); await visible('shops');
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await click('security'); await ready('security');
    assert.equal(await frame().locator('#security-view').evaluate(n => n.getAnimations().length), 0);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const animation = await frame().locator('[data-tab="shops"]').evaluate(n => { n.click(); const view = document.querySelector('#shops-view'); return view.getAnimations().map(a => ({ duration: a.effect.getTiming().duration, frames: a.effect.getKeyframes() })); });
    assert.equal(animation[0].duration, 180); assert.equal(+animation[0].frames[0].opacity, 0.94);
    assert.ok(animation[0].frames.every(keyframe => !keyframe.transform));
    for (const width of [1440, 390]) for (const screen of Object.keys(screens)) {
      await page.setViewportSize({ width, height: 900 });
      await page.goto(base + '?page=tapin-price-' + screen); await ready(screen);
      await page.reload(); await ready(screen);
    }
    await page.goto(base + '?page=tapin-price-security&sub=events'); await ready('security');
    await expect(frame().locator('[data-subsection="events"]')).toBeVisible();
    await page.reload(); await expect(frame().locator('[data-subsection="events"]')).toBeVisible();
    assert.deepEqual(errors, []);
    assert.ok(consoleErrors.every(message => message.includes('503')), 'Unexpected console errors: ' + consoleErrors);
    console.log('PASS slow/latest-response wins, stale failure/editor response, current failure recovery, rapid clicks, 180ms content-only/reduced motion, all direct URLs/reloads, security deep links. Expected simulated HTTP errors:', consoleErrors.length);
  } finally {
    hold = null; pending.splice(0).forEach(item => item.resolve());
    console.log('Browser errors:', errors, 'Document requests:', documents.length, 'Frame/history events:', frameEvents.length);
    await browser.close(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
