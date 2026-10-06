/* Provider logo, marker and icon-only admin action regression coverage. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const theme = process.env.TAPIN_TEST_THEME || 'dark';
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

async function setup(page, { publicPage = false, failTipax = false, clusterProviders = null } = {}) {
  const fixturePoints = clusterProviders ? [
    ...clusterProviders.map((providerId, index) => ({ ...points[0], id: index+10, provider_id: providerId, name: 'Cluster branch '+index, longitude: 51.4+index*0.08 })),
    {...points[0], id: 99, provider_id: 2, name: 'Address only', has_coordinates: false, latitude: null, longitude: null},
  ] : points;
  await page.addInitScript(theme => localStorage.setItem('tapin-color-scheme',theme), theme);
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
      if (/^points\/\d+\/details$/.test(endpoint)) return route.fulfill({ json: fixturePoints.find(point => point.id === Number(endpoint.split('/')[1])) });
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
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css">${publicPage ? '' : '<link rel="stylesheet" href="/assets/dashboard.css">'}<link rel="stylesheet" href="/assets/theme.css"></head><body class="toplevel_page_tapin-locator">${appRoot}<script>window.TapinConfig={api:'/api/',assets:'/assets/',tiles:'/tiles/{z}/{x}/{y}',attribution:'Fixture tiles',nonce:'fixture'};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];window.testMarkers=[];L.Map.addInitHook(function(){window.testMaps.push(this)});L.Marker.addInitHook(function(){window.testMarkers.push(this)});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script>${publicPage ? '' : '<script src="/assets/admin.js"></script>'}</body></html>` });
  });
  return errors;
}

async function verifyPins(page) {
  await expect.poll(()=>page.evaluate(()=>window.testMaps.length)).toBeGreaterThan(0);
  const publicPage=Boolean(await page.locator('.tapin-public').count());
  for(const point of points){
    if(publicPage)await page.locator('[data-provider="'+point.provider_id+'"]').click();
    else await page.locator('[data-provider-select]').selectOption(String(point.provider_id));
    await page.evaluate(p=>window.testMaps[0].setView([p.latitude,p.longitude],13,{animate:false}),point);
    const marker=page.locator('.logo-marker');
    await expect(marker).toHaveCount(1);await expect(marker).toBeInViewport();
    expect(await marker.boundingBox()).toMatchObject({width:20,height:20});
    await expect(page.locator('.marker-chart,.cluster-count,.provider-pin-image,.province-composition')).toHaveCount(0);
    await expect(marker.locator('.marker-logo')).toHaveAttribute('data-provider-slug',providers.find(p=>p.id===point.provider_id).slug);
    if(point.provider_id!==3)await expect(marker.locator('img')).toHaveAttribute('src',new RegExp('/assets/markers/'+providers.find(p=>p.id===point.provider_id).slug+'\\.png$'));
    else await expect(marker.locator('.provider-logo-fallback')).toHaveCount(1);
    const coord=await marker.evaluate(el=>window.testMarkers.find(m=>m.getElement()===el).getLatLng());
    expect(coord).toEqual({lat:point.latitude,lng:point.longitude});
  }
}
(async () => {
  const installedBrowser = [process.env.CHROME_PATH, process.platform === 'win32' ? 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' : null, process.platform === 'win32' ? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe' : null].find(candidate => candidate && fs.existsSync(candidate));
  const browser = await chromium.launch({ headless: true, ...(installedBrowser ? { executablePath: installedBrowser } : {}) });
  try {
    const publicPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const publicErrors = await setup(publicPage, { publicPage: true });
    await publicPage.goto('http://tapin.test/public');
    await expect(publicPage.locator('.chart-marker')).toHaveCount(3);
    await expect(publicPage.locator('.map-badge img,.map-badge .cluster-count')).toHaveCount(0);
    const legend=publicPage.locator('.map-legend');
    await expect(legend).toHaveAttribute('dir','rtl');
    await expect(legend.locator('.map-legend-item')).toHaveCount(3);
    expect(await legend.locator('.map-legend-item').allTextContents()).toEqual(['پست','تیپاکس','سایر']);
    for (const slug of ['post','tipax','other']) await expect(legend.locator('img[src$="/markers/'+slug+'.png"]')).toBeVisible();
    const screenshotDir = path.join(root, 'artifacts', 'provider-pins', theme);
    fs.mkdirSync(screenshotDir, { recursive: true });
    await publicPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'public-map-initial.png') });
    await verifyPins(publicPage);
    await publicPage.locator('[data-provider="1"]').click();
    await publicPage.evaluate(([lat,lng])=>window.testMaps[0].setView([lat,lng],13,{animate:false}),[points[0].latitude,points[0].longitude]);
    const postMarker = publicPage.locator('.post-marker').first();
    await postMarker.click();
    await expect(publicPage.locator('.tapin-detail')).toBeVisible();
    await expect(publicPage.locator('.detail-body h3')).toHaveText('Postal branch');
    await publicPage.keyboard.press('Escape');
    expect(publicErrors).toEqual([]);

    const failedLogoPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const failedErrors = await setup(failedLogoPage, { publicPage: true, failTipax: true });
    await failedLogoPage.goto('http://tapin.test/public');
    await expect.poll(()=>failedLogoPage.evaluate(()=>window.testMaps.length)).toBeGreaterThan(0);
    await failedLogoPage.locator('[data-provider="2"]').click();
    await failedLogoPage.evaluate(([lat,lng])=>window.testMaps[0].setView([lat,lng],13,{animate:false}),[points[1].latitude,points[1].longitude]);
    const failedTipaxMarker = failedLogoPage.locator('.tipax-marker');
    await expect(failedLogoPage.locator('.public-providers .provider-fallback-tipax')).toHaveText('ت');
    await expect(failedTipaxMarker.locator('img')).toHaveAttribute('src', /assets\/markers\/tipax\.png$/);
    expect(failedErrors).toEqual([]);

    for (const publicPage of [true, false]) for (const [name, ids] of [['post', [1,1]], ['tipax', [2,2]], ['other', [3,3]], ['mixed', [1,2]], ['all', [1,2,3]]]) {
      const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
      const errors = await setup(page, { publicPage, clusterProviders: ids });
      await page.goto(publicPage ? 'http://tapin.test/public' : 'http://tapin.test/wp-admin/admin.php?page=tapin-locator#dashboard');
      const cluster = page.locator('.tapin-cluster');
      await expect(cluster).toHaveCount(1);
      await expect(cluster).toBeInViewport();
      await expect(cluster.locator('.marker-chart')).toHaveCount(1);
      await expect(page.locator('.tapin-pin img,.cluster-count,.provider-image-pin,.provider-pin-image,.province-composition')).toHaveCount(0);
      if(ids.includes(3)) await expect(cluster).toHaveAttribute('aria-label',/سایر/);
      await expect(cluster).toHaveText('');
      await expect(cluster).toHaveAttribute('aria-label', new RegExp('^'+ids.length.toLocaleString('fa-IR')+' '));
      await cluster.focus();
      await expect(page.locator('.leaflet-tooltip')).toContainText(ids.length.toLocaleString('fa-IR'));
      await cluster.evaluate(element => element.blur());
      await page.locator('.tapin-map').screenshot({path: path.join(screenshotDir, name+'-'+(publicPage?'public':'admin')+'-initial.png')});
      const zoom = await page.evaluate(() => window.testMaps[0].getZoom());
      await cluster.click();
      await expect.poll(() => page.evaluate(() => window.testMaps[0].getZoom())).toBeGreaterThan(zoom);
      await expect(page.locator('.tapin-cluster .marker-chart')).toHaveCount(1);
      await page.evaluate(count => window.testMaps[0].fitBounds([[35.7,51.4],[35.7,51.4+(count-1)*0.08]],{padding:[40,40],maxZoom:13,animate:false}),ids.length);
      await expect(page.locator('.tapin-cluster')).toHaveCount(0);
      await expect(page.locator('.map-badge')).toHaveCount(ids.length);
      await expect(page.locator('.marker-chart')).toHaveCount(ids.length);
      const coords=await page.evaluate(() => window.testMarkers.filter(m=>m.getElement()?.classList.contains('map-badge') && window.testMaps[0].hasLayer(m)).map(m=>[m.getLatLng().lat,m.getLatLng().lng]));
      expect(coords).toEqual(ids.map((_,index)=>[35.7,51.4+index*0.08]));
      await page.locator('.tapin-map').screenshot({path: path.join(screenshotDir, name+'-'+(publicPage?'public':'admin')+'-close.png')});
      expect(errors).toEqual([]);
      await page.close();
    }

    const adminPage = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const adminErrors = await setup(adminPage);
    await adminPage.goto('http://tapin.test/wp-admin/admin.php?page=tapin-locator#points');
    const firstRow = adminPage.locator('.points-management-table tbody tr').first();
    await expect(firstRow).toBeVisible();
    await expect(adminPage.locator('#tapin-admin')).toHaveAttribute('data-theme-mode',theme);
    // Wait for the deliberately broken provider logo to become its fallback
    // before enumerating image locators; otherwise the last locator goes stale.
    await expect(adminPage.locator('.point-provider-icon .provider-logo-fallback')).toHaveCount(1);
    for(const image of await adminPage.locator('.point-provider-icon img').all()) {
      await image.scrollIntoViewIfNeeded();
      await expect.poll(()=>image.evaluate(el=>el.naturalWidth)).toBeGreaterThan(0);
    }
    await firstRow.scrollIntoViewIfNeeded();
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
    await adminPage.locator('[data-provider-select]').selectOption('1');
    await adminPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'admin-map.png') });
    await adminPage.evaluate(([lat,lng])=>window.testMaps[window.testMaps.length-1].setView([lat,lng],13,{animate:false}),[points[0].latitude,points[0].longitude]);
    const detailTrigger = adminPage.locator('.post-marker').first();
    await detailTrigger.click();
    const detailDialog = adminPage.locator('dialog.tapin-detail');
    await expect(detailDialog).toBeVisible();
    const desktopBounds = await detailDialog.boundingBox();
    expect(Math.abs(desktopBounds.x + desktopBounds.width/2 - 720)).toBeLessThanOrEqual(1);
    expect(Math.abs(desktopBounds.y + desktopBounds.height/2 - 500)).toBeLessThanOrEqual(1);
    expect(await detailDialog.locator('header h2').evaluate(element => getComputedStyle(element).textAlign)).toBe('center');
    expect(await detailDialog.locator('.detail-body').evaluate(element => getComputedStyle(element).textAlign)).toBe('center');
    expect(await detailDialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
    expect(await detailDialog.evaluate(element => getComputedStyle(element, '::backdrop').backdropFilter)).toContain('blur');
    await adminPage.keyboard.press('Tab');
    expect(await detailDialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
    await adminPage.keyboard.press('Escape');
    await expect(detailDialog).toBeHidden();
    await expect(detailTrigger).toBeFocused();
    await adminPage.setViewportSize({ width: 390, height: 844 });
    await adminPage.evaluate(() => {
      const map = window.testMaps[window.testMaps.length - 1];
      map.invalidateSize();
      map.fitBounds([[29.59, 46.29], [38.08, 52.58]], { padding: [40, 80], animate: false });
    });
    await verifyPins(adminPage);
    await adminPage.locator('.tapin-map').screenshot({ path: path.join(screenshotDir, 'mobile-map.png') });
    await adminPage.locator('[data-provider-select]').selectOption('1');
    await adminPage.evaluate(([lat,lng])=>window.testMaps[window.testMaps.length-1].setView([lat,lng],13,{animate:false}),[points[0].latitude,points[0].longitude]);
    const mobileTrigger = adminPage.locator('.post-marker').first();
    await mobileTrigger.click();
    await expect(detailDialog).toBeVisible();
    const mobileBounds = await detailDialog.boundingBox();
    expect(Math.abs(mobileBounds.x + mobileBounds.width/2 - 195)).toBeLessThanOrEqual(1);
    expect(Math.abs(mobileBounds.y + mobileBounds.height/2 - 422)).toBeLessThanOrEqual(1);
    await detailDialog.locator('[data-close]').click();
    await expect(detailDialog).toBeHidden();
    await expect(mobileTrigger).toBeFocused();
    expect(adminErrors).toEqual([]);
    console.log('PASS provider-markers-actions: shared admin/public charts and provider-only logos, no numbered/paired-pin structures, stored anchors, address-only exclusion, accessible counts, click-to-zoom, logos/fallback, row actions and delete confirmation.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
