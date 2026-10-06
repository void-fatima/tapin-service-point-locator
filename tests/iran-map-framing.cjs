/* Real project geometry/assets with isolated multi-province network fixtures.
   node tests/iran-map-framing.cjs; no live data or WordPress session is used. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const geometry = JSON.parse(fs.readFileSync(path.join(root, 'assets/iran-provinces.geojson'), 'utf8'));
function ringContains(x, y, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i], [xj, yj] = ring[j];
    if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) inside = !inside;
  }
  return inside;
}
function contains(point, feature) {
  const polygons = feature.geometry.type === 'MultiPolygon' ? feature.geometry.coordinates : [feature.geometry.coordinates];
  return polygons.some(rings => ringContains(point.longitude, point.latitude, rings[0]) && !rings.slice(1).some(ring => ringContains(point.longitude, point.latitude, ring)));
}
const definitions = [
  ['اصفهان', 32.65, 51.67, 1], ['اصفهان', 31.99, 51.85, 2], ['اصفهان', 33.99, 51.44, 2],
  ['بوشهر', 28.92, 50.84, 1], ['بوشهر', 29.266, 51.219, 2],
  ['مازندران', 36.56, 53.06, 1], ['گیلان', 37.28, 49.59, 2],
  ['هرمزگان', 27.18, 56.27, 1], ['هرمزگان', 26.95, 56.1, 2],
  ['سیستان و بلوچستان', 25.43, 60.74, 1], ['سیستان و بلوچستان', 29.50, 60.86, 2],
  ['آذربایجان شرقی', 38.08, 46.29, 1], ['تهران', 35.7, 51.4, 1],
  ['البرز', 35.84, 50.95, 2], ['قم', 34.64, 50.88, 1]
];
const points = definitions.map(([province, latitude, longitude, provider_id], index) => ({ id: index + 1, provider_id, province, latitude, longitude, city: province, name: 'Fixture ' + (index + 1), address: 'Fixture address', has_coordinates: true, status: 'active' }));
// Dense fixture crosses marker API pages and transitions through local clusters.
for (let i = 0; i < 501; i++) points.push({ ...points[0], id: i + 100, provider_id: i % 2 + 1, latitude: 32.65 + (i % 10) * .001, longitude: 51.67 + (i % 20) * .001 });
const addressOnly = { ...points[0], id: 1000, province: 'خراسان شمالی', has_coordinates: false, latitude: null, longitude: null, name: 'Address only' };
const outside = { ...points[0], id: 1001, province: 'خوزستان', latitude: 33, longitude: 44, name: 'Outside geometry' };
const invalid = { ...points[0], id: 1002, latitude: 95, name: 'Invalid coordinates' };
const rows = [...points, addressOnly, outside, invalid];
const providers = [{ id: 1, slug: 'post', name: 'پست', color: '#ffbd18', is_active: 1 }, { id: 2, slug: 'tipax', name: 'تیپاکس', color: '#00ba88', is_active: 1 }];
const locations = [...new Map(rows.map(p => [p.province, { province: p.province, city: p.city, provider_id: p.provider_id }])).values()];
const artifacts = path.join(root, 'artifacts', 'iran-map-framing');

(async () => {
  for (const point of points) if (!geometry.features.some(f => contains(point, f))) throw Error('Fixture outside supplied geometry: ' + JSON.stringify(point));
  expect(geometry.features.some(f => contains(outside, f))).toBe(false);
  fs.mkdirSync(artifacts, { recursive: true });
  const chrome = process.env.TAPIN_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const browser = await chromium.launch({ headless: true, ...(fs.existsSync(chrome) ? { executablePath: chrome } : {}) });
  const evidence = [];
  try {
    for (const admin of [false, true]) for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      const errors = [], requests = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.addInitScript(theme => localStorage.setItem('tapin-color-scheme', theme), theme);
      await page.route('**/*', async route => {
        const url = new URL(route.request().url()), q = url.searchParams;
        if (url.pathname.startsWith('/api/')) {
          const endpoint = url.pathname.slice(5);
          if (endpoint === 'providers') return route.fulfill({ json: providers });
          if (endpoint === 'locations') return route.fulfill({ json: locations });
          if (endpoint === 'public/filters') return route.fulfill({ json: { providers, locations } });
          if (/points\/\d+(\/details)?$/.test(endpoint)) return route.fulfill({ json: rows.find(p => p.id === Number(endpoint.match(/points\/(\d+)/)[1])) });
          if (['points', 'public/points', 'public/directory'].includes(endpoint)) {
            const markerRequest = q.get('has_coordinates') === '1';
            if (markerRequest) requests.push(Object.fromEntries(q));
            let selected = rows.filter(p => (!q.get('province') || q.get('province') === p.province) && (!q.get('provider_id') || q.get('provider_id').split(',').includes(String(p.provider_id))));
            if (markerRequest) selected = selected.filter(p => p.has_coordinates && p.latitude >= Number(q.get('south') || -90) && p.latitude <= Number(q.get('north') || 90) && p.longitude >= Number(q.get('west') || -180) && p.longitude <= Number(q.get('east') || 180));
            const pageNo = Number(q.get('page') || 1), size = Number(q.get('per_page') || 10);
            const summary = { total: selected.length, located: selected.filter(p => p.has_coordinates).length, missing: selected.filter(p => !p.has_coordinates).length, distribution: providers.map(pr => ({ provider_id: pr.id, total: selected.filter(p => p.provider_id === pr.id).length })) };
            return route.fulfill({ json: { items: selected.slice((pageNo - 1) * size, pageNo * size), page: pageNo, per_page: size, total: selected.length, total_pages: Math.ceil(selected.length / size), ...(admin ? { summary } : {}) } });
          }
          return route.fulfill({ json: { items: [], counts: {}, total: 0 } });
        }
        if (url.pathname.startsWith('/assets/')) {
          const types = { '.js': 'application/javascript; charset=utf-8', '.css': 'text/css', '.geojson': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2' };
          return route.fulfill({ body: fs.readFileSync(path.join(root, url.pathname.slice(1))), contentType: types[path.extname(url.pathname)] || 'application/octet-stream' });
        }
        // A valid transparent tile isolates framing/markers without tile errors.
        if (url.pathname.startsWith('/tiles/')) return route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"></svg>' });
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css">${admin ? '<link rel="stylesheet" href="/assets/dashboard.css">' : ''}<link rel="stylesheet" href="/assets/theme.css"></head><body class="${admin ? 'toplevel_page_tapin-locator' : 'tapin-locator-page'}">${admin ? '<div id="tapin-admin" class="tapin-app" dir="rtl"></div>' : '<div class="tapin-app tapin-public" dir="rtl"><div class="tapin-public-root"></div></div>'}<script>window.TapinConfig={api:'/api/',assets:'/assets/',tiles:'/tiles/{z}/{x}/{y}',nonce:'fixture'};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];window.testMarkers=[];L.Map.addInitHook(function(){window.testMaps.push(this);});L.Marker.addInitHook(function(){window.testMarkers.push(this);});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script>${admin ? '<script src="/assets/admin.js"></script>' : ''}</body></html>` });
      });
      await page.goto('http://tapin.test/' + (admin ? 'wp-admin/admin.php?page=tapin-locator#dashboard' : 'public'));
      await expect(page.locator('.marker-status')).toContainText(points.length.toLocaleString('fa-IR'));
      const expectedProvinces = [...new Set(points.map(p => geometry.features.find(f => contains(p, f)).properties.shapeName))];
      const getMarkers = () => page.evaluate(() => window.testMarkers.filter(m => window.testMaps[0].hasLayer(m) && m.getElement()?.classList.contains('tapin-pin')).map(m => ({ lat: m.getLatLng().lat, lng: m.getLatLng().lng, province: m.getElement().dataset.markerProvince, composition: m.getElement().querySelector('.province-composition,.marker-chart')?.style.background, label: m.getElement().getAttribute('aria-label'), count: m.getElement().querySelector('.cluster-count')?.textContent || m.getElement().getAttribute('aria-label').match(/^(\S+) شعبه/)?.[1], size: m.options.icon.options.iconSize, anchor: m.options.icon.options.iconAnchor, images: [...m.getElement().querySelectorAll('img')].map(img => ({ source: img.getAttribute('src'), loaded: img.complete && img.naturalWidth > 0, left: img.offsetLeft, width: img.clientWidth })) })));
      const country = await getMarkers();
      expect(country.length).toBe(expectedProvinces.length);
      const represented = [];
      for (const marker of country) {
        const record = points.find(p => p.latitude === marker.lat && p.longitude === marker.lng);
        expect(record).toBeTruthy();
        const province = geometry.features.find(f => contains({ latitude: marker.lat, longitude: marker.lng }, f));
        represented.push(province.properties.shapeName);
        const members = points.filter(p => geometry.features.find(f => contains(p, f)).properties.shapeName === province.properties.shapeName);
        expect(marker.count).toBe(members.length.toLocaleString('fa-IR'));
        expect(marker.label).toContain('تجمیع استانی');
        if (marker.composition) {
          for (const provider of new Set(members.map(p => p.provider_id))) {
            expect(marker.composition).toContain(provider === 1 ? '255, 189, 24' : '0, 186, 136');
            expect(marker.label).toContain(provider === 1 ? 'پست' : 'تیپاکس');
          }
        } else expect(marker.images.length).toBe(new Set(members.map(p => p.provider_id)).size);
        for (let i = 1; i < marker.images.length; i++) expect(marker.images[i].left).toBeGreaterThanOrEqual(marker.images[i - 1].left + marker.images[i - 1].width);
      }
      expect(represented.sort()).toEqual(expectedProvinces.sort());
      await expect(page.locator('.cluster-count,.provider-pin-image,.province-composition,.province-compact')).toHaveCount(0);
      for(const marker of country) expect(marker.size).toEqual([20,20]);
      await expect(page.locator('.province-aggregate[data-marker-province="خراسان شمالی"]')).toHaveCount(0);
      for (const marker of await page.locator('.province-aggregate').all()) await expect(marker).toBeInViewport();
      for (const image of await page.locator('.province-aggregate img').all()) await expect.poll(() => image.evaluate(el => el.naturalWidth)).toBeGreaterThan(0);
      const contrasts = await page.locator('.province-aggregate .cluster-count:not([hidden])').evaluateAll(nodes => {
        const luminance = css => css.match(/[\d.]+/g).slice(0, 3).map(n => Number(n) / 255).map(n => n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4).reduce((sum, n, i) => sum + n * [.2126, .7152, .0722][i], 0);
        return nodes.map(node => { const style = getComputedStyle(node), a = luminance(style.color), b = luminance(style.backgroundColor); return (Math.max(a, b) + .05) / (Math.min(a, b) + .05); });
      });
      for (const contrast of contrasts) expect(contrast).toBeGreaterThanOrEqual(4.5);
      const collisions = await page.evaluate(() => {
        const logoBounds = new Map(), boxes = [];
        for (const marker of document.querySelectorAll('.province-aggregate')) {
          const name = marker.dataset.markerProvince;
          const circle = marker.querySelector('.province-composition,.marker-chart');
          if (circle) boxes.push({ name, round: true, ...circle.getBoundingClientRect().toJSON() });
          for (const image of marker.querySelectorAll('img')) {
            if (!logoBounds.has(image.src)) {
              const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
              const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, 0);
              const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
              let left = 896, right = 128, top = 900, bottom = 180;
              for (let y = 180; y < 900; y++) for (let x = 128; x < 896; x++) {
                const i = (y * canvas.width + x) * 4;
                if (pixels[i + 3] > 240 && pixels[i] > 235 && pixels[i + 1] > 235 && pixels[i + 2] > 235) { left = Math.min(left, x); right = Math.max(right, x); top = Math.min(top, y); bottom = Math.max(bottom, y); }
              }
              logoBounds.set(image.src, { left, right, top, bottom });
            }
            const logo = logoBounds.get(image.src), rect = image.getBoundingClientRect(), scale = rect.width / image.naturalWidth;
            boxes.push({ name, left: rect.left + logo.left * scale, right: rect.left + logo.right * scale, top: rect.top + logo.top * scale, bottom: rect.top + logo.bottom * scale });
          }
          const count = marker.querySelector('.cluster-count:not([hidden])');
          if (count) boxes.push({ name, ...count.getBoundingClientRect().toJSON() });
        }
        return boxes.flatMap((a, i) => boxes.slice(i + 1).filter(b => a.name !== b.name && (a.round && b.round ? Math.hypot((a.left+a.right-b.left-b.right)/2,(a.top+a.bottom-b.top-b.bottom)/2)<(a.width+b.width)/2 : a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top)).map(b => [a.name, b.name]));
      });
      expect(collisions).toEqual([]);
      const getFraming = () => page.evaluate(async () => {
        const data = await (await fetch('/assets/iran-provinces.geojson')).json(), map = window.testMaps[0];
        const size = map.getSize(), vertices = [];
        function walk(coords) { if (typeof coords[0] === 'number') vertices.push(map.latLngToContainerPoint([coords[1], coords[0]])); else coords.forEach(walk); }
        data.features.forEach(f => walk(f.geometry.coordinates));
        return { size, zoom: map.getZoom(), minX: Math.min(...vertices.map(p => p.x)), maxX: Math.max(...vertices.map(p => p.x)), minY: Math.min(...vertices.map(p => p.y)), maxY: Math.max(...vertices.map(p => p.y)) };
      });
      const frameFits = frame => frame.minX >= 27 && frame.maxX <= frame.size.x - 27 && frame.minY >= 47 && frame.maxY <= frame.size.y - 47;
      const framing = await getFraming();
      expect(frameFits(framing)).toBe(true);
      expect(requests.some(r => r.page === '2')).toBe(true);
      const prefix = `${admin ? 'admin' : 'public'}-${theme}-${width}`;
      await page.locator('.tapin-map').screenshot({ path: path.join(artifacts, prefix + '-country.png') });
      if (width === 1440) {
        await page.setViewportSize({ width: 390, height: 1000 });
        await expect.poll(async () => frameFits(await getFraming())).toBe(true);
        await expect(page.locator('.marker-status')).toContainText(points.length.toLocaleString('fa-IR'));
        await expect(page.locator('.province-aggregate')).toHaveCount(expectedProvinces.length);
        await page.setViewportSize({ width, height: 1000 });
        await expect.poll(async () => frameFits(await getFraming())).toBe(true);
        await expect(page.locator('.marker-status')).toContainText(points.length.toLocaleString('fa-IR'));
        await expect(page.locator('.province-aggregate')).toHaveCount(expectedProvinces.length);
      }

      const isfahan = page.locator('.province-aggregate[data-marker-province="اصفهان"]');
      await isfahan.focus(); await expect(page.locator('.leaflet-tooltip')).toContainText('اصفهان');
      await isfahan.press('Enter');
      await expect.poll(() => page.evaluate(() => window.testMaps[0].getZoom())).toBeGreaterThan(framing.zoom);
      await expect(page.locator('.province-aggregate')).toHaveCount(0);
      await expect(page.locator('.tapin-cluster')).not.toHaveCount(0);
      for (const marker of await getMarkers()) expect(geometry.features.some(f => contains({ latitude: marker.lat, longitude: marker.lng }, f))).toBe(true);
      await page.locator('.tapin-map').screenshot({ path: path.join(artifacts, prefix + '-intermediate.png') });
      // Mainland/island records must not create an offshore local-cluster anchor.
      await page.evaluate(() => window.testMaps[0].setView([27.065, 56.185], 8, { animate: false }));
      await expect(page.locator('.tapin-cluster.mixed-marker')).toHaveCount(1);
      const coastal = await getMarkers();
      expect(coastal[0].count).toBe('۲');
      expect(geometry.features.some(f => f.properties.shapeName === 'Hormozgan' && contains({ latitude: coastal[0].lat, longitude: coastal[0].lng }, f))).toBe(true);
      await page.evaluate(() => window.testMaps[0].setView([28.92, 50.84], 15, { animate: false }));
      await expect(page.locator('.tapin-pin:not(.tapin-cluster)')).toHaveCount(1);
      const detailed = await getMarkers();
      expect(detailed[0].lat).toBe(points[3].latitude); expect(detailed[0].lng).toBe(points[3].longitude);
      expect(detailed[0].size).toEqual([20,20]);
      await page.locator('.tapin-pin').click(); await expect(page.locator('.tapin-detail')).toBeVisible();
      await expect(page.locator('.detail-body h3')).toHaveText(points[3].name); await page.keyboard.press('Escape');
      await page.locator('.tapin-map').screenshot({ path: path.join(artifacts, prefix + '-detailed.png') });
      if (width === 1440) {
        await page.setViewportSize({ width: 390, height: 1000 });
        await page.waitForTimeout(250); // Leaflet's actual resize frame, no fake clock.
        expect(await page.evaluate(() => window.testMaps[0].getZoom())).toBe(15);
        const center = await page.evaluate(() => window.testMaps[0].getCenter());
        expect(Math.abs(center.lat - points[3].latitude)).toBeLessThan(.001);
        expect(Math.abs(center.lng - points[3].longitude)).toBeLessThan(.001);
        await page.setViewportSize({ width, height: 1000 });
      }
      await page.locator('[data-reset]').click();
      await expect(page.locator('.province-aggregate')).toHaveCount(expectedProvinces.length);
      await page.locator('[data-province]').selectOption('خراسان شمالی');
      await expect(page.locator('.marker-status')).toContainText('۰ نقطه');
      await expect(page.locator('.tapin-pin')).toHaveCount(0);
      const directoryRows = page.locator(admin ? '.map-list tbody tr' : '.branch-card');
      await expect(directoryRows).toHaveCount(1);
      await expect(directoryRows.first()).toContainText('Address only');
      await page.locator('[data-clear]').click();
      await expect(page.locator('.province-aggregate')).toHaveCount(expectedProvinces.length);
      expect(errors).toEqual([]);
      evidence.push({ admin, width, theme, framing, country, contrasts, collisions, markerPages: requests.filter(r => r.page === '2').length, errors });
      await page.close();
    }
    fs.writeFileSync(path.join(artifacts, 'results.json'), JSON.stringify(evidence, null, 2));
    console.log('PASS iran-map-framing: full geometry/padding, province coverage/counts, mixed providers, real-member anchors, pagination, outside/address-only exclusion, local clusters, stored-coordinate detail pins, click/keyboard, admin/public and desktop/mobile light/dark.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
