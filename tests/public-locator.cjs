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

async function setup(page, {summary=true} = {}) {
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
        if(query.has('has_coordinates'))items=items.filter(p=>Number(p.has_coordinates)===Number(query.get('has_coordinates')));
        if (query.has('north')) items=items.filter(p=>p.has_coordinates && p.latitude<=Number(query.get('north')) && p.latitude>=Number(query.get('south')) && p.longitude<=Number(query.get('east')) && p.longitude>=Number(query.get('west')));
        const showSummary=typeof summary==='function'?summary():summary;
        return route.fulfill({json:{items, total:items.length, page:1, total_pages:1, summary:showSummary?{total:items.length, located:items.filter(p=>p.has_coordinates).length, missing:items.filter(p=>!p.has_coordinates).length, distribution:providers.map(pr=>({provider_id:pr.id,total:items.filter(p=>p.provider_id===pr.id).length}))}:null}});
      }
      throw Error('Unexpected API request: '+endpoint);
    }
    if (url.pathname.startsWith('/assets/')) {
      const file = url.pathname.slice(1), extension = path.extname(file);
      return route.fulfill({body:fs.readFileSync(path.join(root,file)),contentType:({'.css':'text/css','.js':'application/javascript; charset=utf-8','.geojson':'application/json','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'})[extension] || 'application/octet-stream'});
    }
    if (url.pathname.startsWith('/tiles/')) return route.fulfill({status:204});
    const unrelated = url.searchParams.get('page_id') === '99', embedded = url.searchParams.get('layout') === 'embedded';
    const app = '<div class="tapin-app tapin-public alignwide" dir="rtl"><div class="tapin-public-root"></div></div>';
    const shell = unrelated || embedded ? '<header class="fixture-theme-content">Theme navigation</header><div class="fixture-theme-content">'+(unrelated?'Unrelated page':app)+'</div>' : '<div class="tapin-locator-page-content">'+app+'</div>';
    return route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:8px;padding:12px}.fixture-theme-content{max-width:700px;margin:auto}</style><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="'+(unrelated||embedded?'':'tapin-locator-page')+'">'+shell+'<script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}"};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];L.Map.addInitHook(function(){window.testMaps.push(this)});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script></body></html>'});
  });
  return {requests, errors, navigations};
}

(async()=>{
  const browser = await chromium.launch(process.env.TAPIN_CHROME_PATH?{executablePath:process.env.TAPIN_CHROME_PATH}:{});
  try {
    fs.mkdirSync(path.join(root,'artifacts/public-locator'),{recursive:true});
    for (const width of [1440,390]) for (const mode of ['light','dark']) {
    const page = await browser.newPage({viewport:{width,height:900}});
    await page.addInitScript(mode=>localStorage.setItem('tapin-color-scheme',mode),mode);
    await page.clock.install();
    const {requests,errors,navigations}=await setup(page);
    await page.goto('http://locator.test/?page_id=22');
    await expect(page.locator('.branch-card')).toHaveCount(3);
    await page.clock.runFor(1000);
    const visiblePointCount=await page.evaluate(points=>points.filter(p=>p.has_coordinates && window.testMaps[0].getBounds().contains([p.latitude,p.longitude])).length,points);
    expect(visiblePointCount).toBeGreaterThan(0);
    // These fixtures occupy separate provinces at country zoom.
    await expect(page.locator('.public-chart-marker')).toHaveCount(visiblePointCount);
    await expect(page.locator('.public-map-badge img,.public-map-badge .cluster-count')).toHaveCount(0);
    await expect(page.locator('.tapin-public')).toHaveAttribute('data-theme-mode',mode);
    const app=await page.locator('.tapin-public').boundingBox();
    expect(app.x).toBe(0);
    expect(app.width).toBe(width);
    expect(app.height).toBeGreaterThanOrEqual(900);
    const surface=await page.locator('.tapin-public').evaluate(el=>({bg:getComputedStyle(el).backgroundColor,overflow:el.scrollWidth>el.clientWidth+1}));
    expect(surface.bg).toBe(mode==='light'?'rgb(243, 246, 251)':'rgb(11, 14, 25)');
    expect(surface.overflow).toBe(false);
    const summarySurface=await page.locator('.provider-summary-item').first().evaluate(el=>getComputedStyle(el).backgroundColor);
    expect(summarySurface).toBe(mode==='light'?'rgb(255, 255, 255)':'rgb(21, 25, 40)');
    // The fixture tile server returns 204, so its visible warning also tests theme contrast.
    await expect(page.locator('.tile-warning')).toBeVisible();
    const warning=await page.locator('.tile-warning').evaluate(el=>({bg:getComputedStyle(el).backgroundColor,color:getComputedStyle(el).color}));
    if(mode==='light')expect(warning).toEqual({bg:'rgb(255, 244, 216)',color:'rgb(121, 81, 18)'});
    const map=await page.locator('.tapin-map').boundingBox();
    expect(map.x).toBeGreaterThanOrEqual(0);
    expect(map.x+map.width).toBeLessThanOrEqual(width);
    await page.screenshot({path:path.join(root,`artifacts/public-locator/${mode}-${width}.png`),fullPage:true});
    const initial=requests.length;
    await page.clock.fastForward(180000);
    await page.waitForTimeout(100);
    expect(requests.length).toBe(initial);
    await page.locator('[data-provider="2"]').click();
    await expect(page.locator('.branch-card')).toHaveCount(2);
    expect(requests.length).toBeGreaterThan(initial);
    await page.locator('[data-clear]').click();
    await expect(page.locator('.branch-card')).toHaveCount(3);
    await page.locator('[data-coordinates]').selectOption('0');
    await expect(page.locator('.branch-card')).toHaveCount(1);
    await expect(page.locator('.branch-card')).toContainText('Address only');
    await expect(page.locator('.map-status')).toContainText('نمایش ۱ از ۱');
    await page.clock.runFor(1000);
    await expect(page.locator('.public-map-badge')).toHaveCount(0);
    await page.screenshot({path:path.join(root,`artifacts/public-locator/without-coordinates-${mode}-${width}.png`),fullPage:true});
    await page.locator('[data-coordinates]').selectOption('1');
    await expect(page.locator('.branch-card')).toHaveCount(2);
    await expect(page.locator('.map-status')).toContainText('نمایش ۲ از ۲');
    await expect(page.locator('.coordinate-note')).toHaveCount(0);
    await page.screenshot({path:path.join(root,`artifacts/public-locator/with-coordinates-${mode}-${width}.png`),fullPage:true});
    await page.locator('[data-clear]').click();
    await expect(page.locator('[data-coordinates]')).toHaveValue('');
    await expect(page.locator('.branch-card')).toHaveCount(3);
    await page.locator('.locator-search input').fill('Tehran');
    await page.locator('.locator-search').evaluate(form=>form.requestSubmit());
    await expect(page.locator('.branch-card')).toHaveCount(1);
    await page.locator('[data-clear]').click();
    await expect(page.locator('.branch-card')).toHaveCount(3);
    await page.evaluate(()=>window.testMaps[0].setView([35.7,51.4],14,{animate:false}));
    await page.clock.runFor(1000);
    await expect(page.locator('.post-marker')).toHaveCount(1);
    const center=await page.evaluate(()=>({lat:window.testMaps[0].getCenter().lat,lng:window.testMaps[0].getCenter().lng}));
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
    expect(await page.evaluate(()=>({lat:window.testMaps[0].getCenter().lat,lng:window.testMaps[0].getCenter().lng}))).toEqual(center);
    if(width===1440 && mode==='light') {
      await page.evaluate(()=>window.TapinTheme.set('system'));
      await page.emulateMedia({colorScheme:'light'});
      await expect(page.locator('.tapin-public')).toHaveAttribute('data-theme-mode','light');
      await page.emulateMedia({colorScheme:'dark'});
      await expect(page.locator('.tapin-public')).toHaveAttribute('data-theme-mode','dark');
    }
    expect(navigations).toHaveLength(1);
    expect(errors).toEqual([]);
    console.log(`PASS public locator ${mode} ${width}px: full viewport, saved theme, no periodic refresh/navigation, filter/search/reset, close zoom and stable details.`);
    await page.close();
    }
    for(const width of [1363,390]) for(const mode of ['light','dark']) {
      const page=await browser.newPage({viewport:{width,height:900}});
      await page.addInitScript(mode=>localStorage.setItem('tapin-color-scheme',mode),mode);
      let showSummary=false;
      const {errors}=await setup(page,{summary:()=>showSummary});
      await page.goto('http://locator.test/?page_id=22');
      await expect(page.locator('.branch-card')).toHaveCount(3);
      await expect(page.locator('.dashboard-overview')).toBeHidden();
      await expect(page.locator('.public-metric')).toBeEmpty();
      await expect(page.locator('.public-providers')).toBeEmpty();
      const hero=await page.locator('.dashboard-hero').boundingBox();
      const panel=await page.locator('.dashboard-map').boundingBox();
      expect(panel.x).toBeCloseTo(hero.x,0);
      expect(panel.width).toBeCloseTo(hero.width,0);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBe(width);
      await page.screenshot({path:path.join(root,`artifacts/public-locator/no-summary-${mode}-${width}.png`),fullPage:true});
      showSummary=true;
      await page.locator('[data-provider="1"]').click();
      await expect(page.locator('.dashboard-overview')).toBeVisible();
      await expect(page.locator('.hero-number')).toHaveText('۱');
      showSummary=false;
      await page.locator('[data-clear]').click();
      await expect(page.locator('.branch-card')).toHaveCount(3);
      await expect(page.locator('.dashboard-overview')).toBeHidden();
      await expect(page.locator('.public-metric')).toBeEmpty();
      const mapSize=await page.locator('.tapin-map').boundingBox();
      expect(await page.evaluate(()=>window.testMaps[0].getSize().x)).toBeCloseTo(mapSize.width,0);
      expect(errors).toEqual([]);
      await page.close();
    }
    console.log('PASS empty/missing public overview: no reserved space, valid summary retained, absent summary clears stale content and map resizes in both themes at desktop/mobile widths.');
    for(const url of ['http://locator.test/?page_id=99','http://locator.test/?page_id=22&layout=embedded']) {
      const page=await browser.newPage({viewport:{width:1440,height:900}});
      const {errors}=await setup(page);
      await page.goto(url);
      expect((await page.locator('.fixture-theme-content').first().boundingBox()).width).toBe(700);
      await expect(page.locator('body')).not.toHaveClass(/tapin-locator-page/);
      expect(await page.locator('body').evaluate(el=>getComputedStyle(el).margin)).toBe('8px');
      if(url.includes('embedded')) {
        await expect(page.locator('.branch-card')).toHaveCount(3);
        expect((await page.locator('.tapin-public').boundingBox()).width).toBe(700);
      } else await expect(page.locator('.tapin-app')).toHaveCount(0);
      expect(errors).toEqual([]);
      await page.close();
    }
    console.log('PASS embedded and unrelated page theme shells retain their width and margins.');
  } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
