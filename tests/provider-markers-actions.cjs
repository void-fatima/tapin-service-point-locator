/* Provider logo, marker and icon-only admin action regression coverage. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const providers = [
  { id: 1, slug: 'post', name: 'شرکت ملی پست', marker_color: '#ffbd18', color: '#ffbd18', logo: 'https://invalid.example/wrong-post.svg', is_active: 1 },
  { id: 2, slug: 'tipax', name: 'تیپاکس', marker_color: '#00ba88', color: '#00ba88', logo: '', is_active: 1 },
  { id: 3, slug: 'other', name: 'ارائه‌دهنده ناشناس', marker_color: '#7349ff', color: '#7349ff', logo: '/broken/other.png', is_active: 1 },
];
const locations = [{ provider_id: 1, province: 'تهران', city: 'تهران' }];
const points = [
  { id: 1, provider_id: 1, name: 'Postal branch', code: 'P-1', province: 'تهران', city: 'تهران', address: 'نشانی پستی', latitude: 35.7, longitude: 51.4, has_coordinates: true, status: 'active', metadata: null },
  { id: 2, provider_id: 2, name: 'Tipax branch', code: 'T-1', province: 'آذربایجان شرقی', city: 'تبریز', address: 'نشانی تیپاکس', latitude: 38.08, longitude: 46.29, has_coordinates: true, status: 'active', metadata: null },
  { id: 3, provider_id: 3, name: 'Unknown branch', code: 'U-1', province: 'فارس', city: 'شیراز', address: 'نشانی سایر', latitude: 29.59, longitude: 52.58, has_coordinates: true, status: 'active', metadata: null },
];

async function setup(page, { publicPage = false, failTipax = false, clustered = false } = {}) {
  const fixturePoints = clustered ? [...points, {...points[0], id: 4, name: "Second postal branch"}] : points;
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => { if (message.type() === 'error' && !/tile|net::ERR_ABORTED|responded with a status of 404/i.test(message.text())) errors.push(message.text()); });
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/api/')) {
      const endpoint = url.pathname.slice('/api/'.length);
      if (endpoint === 'providers') return route.fulfill({ json: providers });
      if (endpoint === 'locations') return route.fulfill({ json: locations });
      if (endpoint === 'geocoding') return route.fulfill({ json: { items: [], counts: {} } });
      if (endpoint === 'public/filters') return route.fulfill({ json: { providers, locations, summary: { total: fixturePoints.length, located: fixturePoints.length, missing: 0, distribution: providers.map(provider => ({ provider_id: provider.id, total: 1, located: 1 })) } } });
      if (/^public\/points\/\d+$/.test(endpoint)) return route.fulfill({ json: fixturePoints.find(point => point.id === Number(endpoint.split('/').pop())) });
      if (endpoint === 'public/directory' || endpoint === 'public/points' || endpoint === 'points') {
        const providerIds = (url.searchParams.get('provider_id') || '').split(',').filter(Boolean).map(Number);
        const items = fixturePoints.filter(point => !providerIds.length || providerIds.includes(point.provider_id));
        if (/^points\/\d+$/.test(endpoint)) return route.fulfill({ json: fixturePoints.find(point => point.id === Number(endpoint.split('/').pop())) });
        return route.fulfill({ json: { items, total: items.length, page: 1, total_pages: 1, per_page: 20, summary: { total: items.length, located: items.length, missing: 0, distribution: providers.map(provider => ({ provider_id: provider.id, total: items.filter(point => point.provider_id === provider.id).length })) } } });
      }
      throw new Error(`Unexpected API request: ${endpoint}`);
    }
    if (failTipax && /\/assets\/brand\/tipax\.svg$/.test(url.pathname)) return route.fulfill({ status: 404, body: 'Missing test logo' });
    if (url.pathname.startsWith('/assets/')) {
      const relativePath = url.pathname.slice(1), extension = path.extname(relativePath);
      const contentType = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json; charset=utf-8', '.geojson': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' }[extension] || 'application/octet-stream';
      return route.fulfill({ body: fs.readFileSync(path.join(root, relativePath)), contentType });
    }
    if (url.pathname.startsWith('/tiles/')) return route.fulfill({ status: 204 });
    const appRoot = publicPage ? '<div class="tapin-app tapin-public"><div class="tapin-public-root"></div></div>' : '<div id="tapin-admin" class="tapin-app" dir="rtl"></div>';
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css">${publicPage ? '' : '<link rel="stylesheet" href="/assets/dashboard.css">'}</head><body class="toplevel_page_tapin-locator">${appRoot}<script>window.TapinConfig={api:'/api/',assets:'/assets/',tiles:'/tiles/{z}/{x}/{y}',attribution:'Fixture tiles',nonce:'fixture'};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];window.testMarkers=[];L.Map.addInitHook(function(){window.testMaps.push(this)});L.Marker.addInitHook(function(){window.testMarkers.push(this)});</script><script src="/assets/iran-locations.js"></script><script src="/assets/map.js"></script>${publicPage ? '' : '<script src="/assets/admin.js"></script>'}</body></html>` });
  });
  return errors;
}

async function verifyPins(page) {
  for (const slug of ['post', 'tipax']) {
    const pin = page.locator('.' + slug + '-marker');
    await expect(pin).toBeVisible();
    await expect(pin).toBeInViewport();
    await expect(pin.locator('img')).toHaveCount(1);
    await expect(pin.locator('img')).toHaveAttribute('src', new RegExp('/assets/markers/' + slug + '\\.png$'));
    await expect(pin.locator('b, .cluster-count, svg, .provider-marker-medallion')).toHaveCount(0);
    await expect.poll(() => pin.locator('img').evaluate(image => image.naturalWidth)).toBeGreaterThan(0);
    expect(await pin.boundingBox()).toMatchObject({ width: 48, height: 72 });
    expect(await pin.evaluate(element => getComputedStyle(element, '::before').content)).toBe('none');
  }
  const measurements = await page.evaluate(() => ['post', 'tipax'].map(slug => {
    const element = document.querySelector('.' + slug + '-marker'), image = element.querySelector('img');
    const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
    const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, 0);
    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    let left = canvas.width, right = -1, top = canvas.height, bottom = -1;
    for (let y = 0; y < canvas.height; y++) for (let x = 0; x < canvas.width; x++) {
      if (pixels[(y * canvas.width + x) * 4 + 3] > 240) {
        left = Math.min(left, x); right = Math.max(right, x); top = Math.min(top, y); bottom = Math.max(bottom, y);
      }
    }
    const marker = window.testMarkers.find(item => item.getElement() === element), map = window.testMaps.find(item => item.hasLayer(marker));
    const latLng = marker.getLatLng(), position = map.latLngToContainerPoint(latLng), mapRect = map.getContainer().getBoundingClientRect();
    const rect = element.getBoundingClientRect(), imageRect = image.getBoundingClientRect();
    const scale = Math.min(imageRect.width / canvas.width, imageRect.height / canvas.height);
    const imageTop = imageRect.y + (imageRect.height - canvas.height * scale) / 2;
    return { width: (right-left+1)*scale, height: (bottom-top+1)*scale,
      tipY: imageTop+(bottom+1)*scale, anchorY: rect.y+72,
      dx: rect.x+24-mapRect.x-position.x, dy: rect.y+72-mapRect.y-position.y,
      iconAnchor: marker.options.icon.options.iconAnchor, lat: latLng.lat, lng: latLng.lng };
  }));
  expect(Math.abs(measurements[0].width-measurements[1].width)).toBeLessThan(0.1);
  expect(Math.abs(measurements[0].height-measurements[1].height)).toBeLessThan(0.1);
  for (const [index, item] of measurements.entries()) {
    expect(item.iconAnchor).toEqual([24, 72]);
    expect(Math.abs(item.tipY-item.anchorY)).toBeLessThan(0.1);
    expect(Math.abs(item.dx)).toBeLessThanOrEqual(1);
    expect(Math.abs(item.dy)).toBeLessThanOrEqual(1);
    expect(item.lat).toBe(points[index].latitude); expect(item.lng).toBe(points[index].longitude);
  }
}

(async () => {
  const installedBrowser = [process.env.CHROME_PATH, process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : null, process.platform === 'win32' ? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe' : null].find(candidate => candidate && fs.existsSync(candidate));
  const browser = await chromium.launch({ headless: true, ...(installedBrowser ? { executablePath: installedBrowser } : {}) });
  try {
    const publicPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const publicErrors = await setup(publicPage, { publicPage: true });
    await publicPage.goto('http://tapin.test/public');
    await expect(publicPage.locator('.post-marker')).toBeVisible();
    await expect(publicPage.locator('.tipax-marker')).toBeVisible();
    await expect(publicPage.locator('.provider-medallion-other .provider-logo-fallback')).toHaveText('ا');
    const postMarker = publicPage.locator('.post-marker').first();
    const tipaxMarker = publicPage.locator('.tipax-marker').first();
    await expect(postMarker.locator('img')).toHaveAttribute('src', /assets\/markers\/post\.png$/);
    await expect(tipaxMarker.locator('img')).toHaveAttribute('src', /assets\/markers\/tipax\.png$/);
    await expect.poll(() => postMarker.locator('img').evaluate(image => image.naturalWidth)).toBeGreaterThan(0);
    await expect.poll(() => tipaxMarker.locator('img').evaluate(image => image.naturalWidth)).toBeGreaterThan(0);
    await verifyPins(publicPage);
    const screenshotDir = path.join(root, 'artifacts', 'provider-pins');
    fs.mkdirSync(screenshotDir, { recursive: true });
    await publicPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'public-map.png') });
    await postMarker.click();
    await expect(publicPage.locator('.tapin-detail')).toBeVisible();
    await expect(publicPage.locator('.detail-body h3')).toHaveText('Postal branch');
    await publicPage.keyboard.press('Escape');
    expect(publicErrors).toEqual([]);

    const failedLogoPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const failedErrors = await setup(failedLogoPage, { publicPage: true, failTipax: true });
    await failedLogoPage.goto('http://tapin.test/public');
    const failedTipaxMarker = failedLogoPage.locator('.tipax-marker');
    await expect(failedLogoPage.locator('.public-providers .provider-fallback-tipax')).toHaveText('ت');
    await expect(failedTipaxMarker.locator('img')).toHaveAttribute('src', /assets\/markers\/tipax\.png$/);
    expect(failedErrors).toEqual([]);

    const clusterPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const clusterErrors = await setup(clusterPage, { publicPage: true, clustered: true });
    await clusterPage.goto('http://tapin.test/public');
    await expect(clusterPage.locator('.tapin-cluster .cluster-count')).toHaveText('۲');
    await expect(clusterPage.locator('.tapin-cluster img, .tapin-cluster b')).toHaveCount(0);
    await expect(clusterPage.locator('.post-marker')).toHaveCount(0);
    await expect(clusterPage.locator('.tipax-marker')).toBeVisible();
    await expect(clusterPage.locator('.provider-image-pin .cluster-count, .provider-image-pin b')).toHaveCount(0);
    await clusterPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'cluster-map.png') });
    const zoomBefore = await clusterPage.evaluate(() => window.testMaps[0].getZoom());
    await clusterPage.locator('.tapin-cluster').click();
    await expect.poll(() => clusterPage.evaluate(() => window.testMaps[0].getZoom())).toBeGreaterThan(zoomBefore);
    expect(clusterErrors).toEqual([]);

    const adminPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const adminErrors = await setup(adminPage);
    await adminPage.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points');
    const firstRow = adminPage.locator('.points-management-table tbody tr').first();
    await expect(firstRow).toBeVisible();
    await expect(firstRow.locator('.point-provider-icon img')).toHaveAttribute('src', /assets\/brand\/post\.png$/);
    await expect(adminPage.locator('.point-provider-icon img[src$="/assets/brand/tipax.svg"]')).toHaveCount(1);
    await expect(adminPage.locator('.point-provider-icon .provider-logo-fallback')).toHaveCount(1);
    const actions = firstRow.locator('.tapin-row-actions button');
    await expect(actions).toHaveCount(3);
    for (const [index, verb] of ['مشاهده', 'ویرایش', 'حذف'].entries()) {
      const button = actions.nth(index);
      expect((await button.textContent()).trim()).toBe('');
      await expect(button).toHaveAttribute('aria-label', new RegExp(verb));
      await expect(button).toHaveAttribute('data-tooltip', verb);
      await button.hover();
      await expect.poll(() => button.evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1');
      expect(await button.evaluate(element => getComputedStyle(element, '::after').content)).toContain(verb);
    }
    await actions.first().focus();
    await adminPage.keyboard.press('Tab');
    await expect(actions.nth(1)).toBeFocused();
    await expect.poll(() => actions.nth(1).evaluate(element => getComputedStyle(element, '::after').opacity)).toBe('1');
    await actions.nth(2).click();
    const deleteDialog = adminPage.locator('dialog.tapin-dialog');
    await expect(deleteDialog).toBeVisible();
    await expect(deleteDialog).toContainText('قابل بازگشت نیست');
    await deleteDialog.locator('.close-dialog').click();
    await expect(deleteDialog).toBeHidden();
    await adminPage.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#dashboard');
    await verifyPins(adminPage);
    await adminPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'admin-map.png') });
    await adminPage.setViewportSize({ width: 390, height: 844 });
    await adminPage.evaluate(() => {
      const map = window.testMaps[window.testMaps.length - 1];
      map.invalidateSize();
      map.fitBounds([[29.59, 46.29], [38.08, 52.58]], { padding: [40, 80], animate: false });
    });
    await verifyPins(adminPage);
    await adminPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'mobile-map.png') });
    expect(adminErrors).toEqual([]);
    console.log('PASS provider-markers-actions: complete PNG pins, equal visible size, bottom-center coordinate anchors, distinct cluster counts, marker click, admin logos/fallback, admin row tooltips/accessibility/focus, and delete confirmation.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
