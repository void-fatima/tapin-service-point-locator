/* Run against price-check-wp-theme.php on a loopback PHP server; every REST response is a fixture. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { mkdirSync } = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const base = process.env.TAPIN_THEME_URL || 'http://127.0.0.1:8842';
assert.equal(new URL(base).hostname, '127.0.0.1');
const fixtures = JSON.parse(execFileSync(process.env.TAPIN_PHP || 'php', [path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const calls = [], errors = [];
  await context.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.hostname !== '127.0.0.1') return route.abort();
    if (!url.pathname.includes('/wp-json/tapin-price-check/v1/')) return route.continue();
    const suffix = url.pathname.split('/tapin-price-check/v1/')[1];
    calls.push(suffix);
    let data;
    if (suffix === 'providers') data = { providers: [fixtures.provider] };
    else if (suffix.startsWith('providers/')) data = fixtures.schema;
    else if (suffix === 'bulk') data = fixtures.bulkSchema;
    else if (suffix === 'price-check') data = fixtures.quote;
    else if (suffix === 'bulk-validate') data = { valid: true, errors: [] };
    else if (suffix === 'bulk-check') data = { results: [{ ...fixtures.quote, ok: true, variant: {}, variant_detail: [] }] };
    else throw new Error('Unmocked REST request: ' + suffix);
    return route.fulfill({ json: data });
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  mkdirSync(path.resolve(__dirname, '../artifacts/price-check-embed'), { recursive: true });
  try {
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 900 });
      for (const mode of ['single', 'bulk']) {
        await page.goto(`${base}/${mode}/`);
        const frame = page.frameLocator('iframe.tapin-price-frame');
        await expect(page.locator('body > .wp-site-blocks > header')).toBeVisible();
        await expect(page.locator('body > .wp-site-blocks > footer')).toBeVisible();
        await expect(page.locator('#fixture-before')).toBeVisible();
        await expect(page.locator('#fixture-after')).toBeVisible();
        await expect(frame.locator('.topbar,.footer,.brand')).toHaveCount(0);
        if (mode === 'single') {
          await frame.locator('#provider-list .tile button').click();
          await frame.locator('#single-submit-btn').click();
          await expect(frame.locator('.field.has-error')).toHaveCount(1);
          await frame.locator('[name="param:weight"]').fill('2');
          await frame.locator('#single-submit-btn').click();
          await expect(frame.locator('#result')).toContainText('۱۲٬۵۰۰');
        } else {
          await frame.locator('[name="bulk:weight"]').fill('2');
          await frame.locator('#bulk-form button[type="submit"]').click();
          await expect(frame.locator('#bulk-result')).toContainText('۱۲٬۵۰۰');
        }
        await expect.poll(() => page.frames()[1].evaluate(() => Math.abs(innerHeight - Math.ceil(document.body.getBoundingClientRect().height)))).toBeLessThanOrEqual(1);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Theme page overflowed');
        assert.ok(await page.evaluate(() => document.querySelector('iframe').getBoundingClientRect().width <= document.querySelector('#fixture-before').getBoundingClientRect().width + 1), 'Module bypassed the theme content width');
        assert.ok(await page.frames()[1].evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Module overflowed');
        const bounds = await page.evaluate(() => {
          const f = document.querySelector('iframe').getBoundingClientRect();
          return { frameTop: f.top, frameBottom: f.bottom, headerBottom: document.querySelector('.wp-site-blocks > header').getBoundingClientRect().bottom, footerTop: document.querySelector('.wp-site-blocks > footer').getBoundingClientRect().top };
        });
        assert.ok(bounds.frameTop >= bounds.headerBottom && bounds.footerTop >= bounds.frameBottom, 'Module overlaps theme chrome');
        await page.screenshot({ path: path.resolve(__dirname, `../artifacts/price-check-embed/wordpress-${mode}-${width}.png`), fullPage: true });
        await frame.locator(mode === 'single' ? '#nav-bulk' : '#nav-advanced').click();
        await expect(page).toHaveURL(`${base}/${mode === 'single' ? 'bulk' : 'single'}/`);
      }
    }
    assert.deepEqual(errors, []);
    assert.ok(calls.includes('price-check') && calls.includes('bulk-check'));
    console.log('Real WordPress/Twenty Twenty-Five: both default shortcodes at 1440px/390px; theme header/footer/content, validation/results, navigation, auto-height and no overflow passed. REST mocked; no plugin install or page writes.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
