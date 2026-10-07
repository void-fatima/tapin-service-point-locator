/* Record the anonymous live locator. Uses normal UI controls; does not change site data. */
const { chromium } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const output = path.join(root, 'docs', 'media');
const temporary = path.join(root, 'artifacts', 'demo-recording');
const url = process.env.TAPIN_DEMO_URL || 'https://iot-core.ir/wordpress_b/?page_id=22';

async function pause(page, milliseconds = 2500) {
  await page.waitForTimeout(milliseconds);
}

async function directoryReady(page) {
  await page.locator('.branch-card').first().waitFor({ timeout: 45000 });
  await page.waitForFunction(() => {
    const directory = document.querySelector('.map-status');
    const map = document.querySelector('.marker-status');
    return directory && map && !/در حال/.test(directory.textContent + map.textContent);
  }, null, { timeout: 45000 });
  await pause(page, 1500);
}

(async () => {
  fs.mkdirSync(output, { recursive: true });
  fs.mkdirSync(temporary, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 1080 },
    colorScheme: 'dark',
    recordVideo: { dir: temporary, size: { width: 1440, height: 1080 } },
  });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    if (!response || response.status() !== 200) throw Error('Public locator did not return HTTP 200.');
    await directoryReady(page);
    await page.screenshot({ path: path.join(output, 'live-public-locator.png') });
    await pause(page, 3000);

    await page.locator('[data-province]').selectOption({ label: 'تهران' });
    await directoryReady(page);
    await page.screenshot({ path: path.join(output, 'live-province-filter.png') });
    await pause(page, 3000);

    await page.locator('[data-provider]').filter({ hasText: 'تیپاکس' }).click();
    await directoryReady(page);
    await pause(page, 3000);

    await page.locator('.branch-card').first().scrollIntoViewIfNeeded();
    await pause(page, 2000);
    await page.locator('.branch-card [data-details]').first().click();
    await page.locator('.tapin-detail').waitFor({ state: 'visible' });
    await pause(page, 3500);
    await page.screenshot({ path: path.join(output, 'live-branch-details.png') });
    await page.keyboard.press('Escape');

    await page.locator('[data-clear]').click();
    await page.locator('[data-province]').scrollIntoViewIfNeeded();
    await directoryReady(page);
    await page.locator('[data-coordinates]').selectOption('0');
    await directoryReady(page);
    await pause(page, 3000);

    await page.locator('[data-clear]').click();
    await directoryReady(page);
    await pause(page, 2500);

    const video = page.video();
    await context.close();
    await video.saveAs(path.join(output, 'public-demo.webm'));
    console.log(JSON.stringify({ url, file: 'docs/media/public-demo.webm', pageErrors: errors }, null, 2));
    if (errors.length) process.exitCode = 1;
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
