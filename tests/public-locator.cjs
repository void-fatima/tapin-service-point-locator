/* Public locator regression with network fixtures; no WordPress records are changed. */
const {chromium, expect} = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const providers = [{id:1, slug:'post', name:'پست', is_active:1}, {id:2, slug:'tipax', name:'تیپاکس', is_active:1}];
const points = [
  {id:1, provider_id:1, name:'Tehran branch', province:'تهران', city:'تهران', address:'Fixture', has_coordinates:true, latitude:35.7, longitude:51.4},
  {id:2, provider_id:2, name:'Bushehr branch', province:'بوشهر', city:'بوشهر', address:'Fixture', has_coordinates:true, latitude:28.92, longitude:50.84},
  {id:3, provider_id:2, name:'Address only', province:'بوشهر', city:'بوشهر', address:'Fixture', has_coordinates:false, latitude:null, longitude:null},
];

async function setup(page) {
  const requests = [], errors = [], navigations = [];
  page.on('pageerror', error => errors.push(error.message));
  page.on('framenavigated', frame => {if (frame === page.mainFrame()) navigations.push(frame.url());});
  await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/api/')) {
      const endpoint = url.pathname.slice(5), query = url.searchParams;
      requests.push(endpoint);
      if (endpoint === 'public/filters') return route.fulfill({json:{providers, locations:points.map(p=>({provider_id:p.provider_id, province:p.province, city:p.city}))}});
      if (/^public\/points\/\d+$/.test(endpoint)) return route.fulfill({json:points.find(p=>p.id===Number(endpoint.split('/').pop()))});
      if (endpoint === 'public/directory' || endpoint === 'public/points') {
        let items = points.filter(p=>(!query.get('provider_id') || query.get('provider_id').split(',').includes(String(p.provider_id))) && (!query.get('search') || p.name.toLowerCase().includes(query.get('search').toLowerCase())));
        if (query.has('north')) items=items.filter(p=>p.has_coordinates && p.latitude<=Number(query.get('north')) && p.latitude>=Number(query.get('south')) && p.longitude<=Number(query.get('east')) && p.longitude>=Number(query.get('west')));
        return route.fulfill({json:{items, total:items.length, page:1, total_pages:1, summary:{total:items.length, located:items.filter(p=>p.has_coordinates).length, missing:items.filter(p=>!p.has_coordinates).length, distribution:providers.map(pr=>({provider_id:pr.id,total:items.filter(p=>p.provider_id===pr.id).length}))}}});
      }
      throw Error('Unexpected API request: '+endpoint);
    }
    if (url.pathname.startsWith('/assets/')) {
      const file = url.pathname.slice(1), extension = path.extname(file);
      return route.fulfill({body:fs.readFileSync(path.join(root,file)),contentType:({'.css':'text/css','.js':'application/javascript; charset=utf-8','.geojson':'application/json','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'})[extension] || 'application/octet-stream'});
    }
    if (url.pathname.startsWith('/tiles/')) return route.fulfill({status:204});
    return route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><html><head><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/theme.css"></head><body><div class="tapin-app tapin-public" dir="rtl"><div class="tapin-public-root"></div></div><script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}"};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];L.Map.addInitHook(function(){window.testMaps.push(this)});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script></body></html>'});
  });
  return {requests, errors, navigations};
}

(async()=>{
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({viewport:{width:1440,height:900}});
    await page.clock.install();
    const {requests,errors,navigations}=await setup(page);
    await page.goto('http://locator.test/?page_id=22');
    await expect(page.locator('.branch-card')).toHaveCount(3);
    await page.clock.runFor(1000);
    await expect(page.locator('.provider-image-pin')).toHaveCount(2);
    const initial=requests.length;
    await page.clock.fastForward(180000);
    await page.waitForTimeout(100);
    expect(requests.length).toBe(initial);
    await page.locator('[data-provider="2"]').click();
    await expect(page.locator('.branch-card')).toHaveCount(2);
    expect(requests.length).toBeGreaterThan(initial);
    await page.locator('[data-clear]').click();
    await expect(page.locator('.branch-card')).toHaveCount(3);
    await page.locator('.branch-card').first().locator('[data-details]').click();
    await expect(page.locator('.tapin-detail')).toBeVisible();
    await page.clock.runFor(1000);
    const detailRequests=requests.length;
    await page.clock.fastForward(180000);
    await page.waitForTimeout(100);
    await expect(page.locator('.tapin-detail')).toBeVisible();
    expect(requests.length).toBe(detailRequests);
    await page.keyboard.press('Escape');
    await expect(page.locator('.tapin-detail')).toBeHidden();
    expect(navigations).toHaveLength(1);
    expect(errors).toEqual([]);
    console.log('PASS public locator: no periodic data refresh or navigation; provider/reset actions update data, open details stay intact.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
