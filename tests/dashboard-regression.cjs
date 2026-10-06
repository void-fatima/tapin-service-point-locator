/* Dashboard startup and pagination integration, using read-only network fixtures. */
const {chromium,expect}=require('@playwright/test');
const fs=require('fs');
const path=require('path');
const {execFileSync}=require('child_process');
const root=path.resolve(__dirname,'..');
const providers=[{id:1,slug:'other',name:'Fixture provider',is_active:1,logo:''}];
const locations=[{provider_id:1,province:'تهران',city:'تهران'}];
const points=Array.from({length:23},(_,i)=>({id:i+1,provider_id:1,name:'Branch '+(i+1),province:'تهران',city:'تهران',address:'Fixture address',status:'active',has_coordinates:true,latitude:35.7,longitude:51.4}));
(async()=>{
 const browser=await chromium.launch();
 try{
  const page=await browser.newPage({viewport:{width:1440,height:900}}),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.startsWith('/api/')){
    const endpoint=url.pathname.slice(5),params=url.searchParams;
    if(endpoint==='providers')return route.fulfill({json:providers});
    if(endpoint==='locations')return route.fulfill({json:locations});
    if(endpoint==='public/filters')return route.fulfill({json:{providers,locations}});
    if(endpoint==='geocoding')return route.fulfill({json:{items:[],counts:{}}});
    if(endpoint==='dashboard')return route.fulfill({json:{summary:{total:points.length}}});
    if(['imports','exports'].includes(endpoint))return route.fulfill({json:[]});
    if(/points\/\d+$/.test(endpoint))return route.fulfill({json:points.find(p=>p.id===Number(endpoint.split('/').pop()))});
    if(['points','public/points','public/directory'].includes(endpoint)){
     const rows=points.filter(p=>params.get('search')!=='missing'&&(!params.get('province')||params.get('province')===p.province)&&(!params.get('city')||params.get('city')===p.city));
     const n=Number(params.get('page')||1),size=Number(params.get('per_page')||10);
     return route.fulfill({json:{items:rows.slice((n-1)*size,n*size),total:rows.length,total_pages:Math.ceil(rows.length/size),summary:{total:rows.length,located:rows.length,missing:0,distribution:[{provider_id:1,total:rows.length}]}}});
    }
    throw Error('Unexpected API request: '+endpoint);
   }
   if(url.pathname.startsWith('/assets/')){
    const file=url.pathname.slice(1);
    const body=process.env.TAPIN_COMMITTED_ASSETS==='1'&&/assets\/(map\.js|admin\.js|app\.css|dashboard\.css)$/.test(file)?execFileSync('git',['show',':'+file],{cwd:root}):fs.readFileSync(path.join(root,file));
    const ext=path.extname(file);
    return route.fulfill({body,contentType:({'.js':'application/javascript; charset=utf-8','.css':'text/css','.geojson':'application/json','.svg':'image/svg+xml','.png':'image/png','.woff2':'font/woff2'})[ext]||'application/octet-stream'});
   }
   if(url.pathname.startsWith('/tiles/'))return route.fulfill({status:204});
   const publicPage=url.pathname==='/public';
   return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><html><head><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css">${publicPage?'':'<link rel="stylesheet" href="/assets/dashboard.css">'}</head><body class="toplevel_page_tapin-locator">${publicPage?'<div class="tapin-app tapin-public"><div class="tapin-public-root"></div></div>':'<div id="tapin-admin" class="tapin-app" dir="rtl"></div>'}<script>window.TapinConfig={api:'/api/',assets:'/assets/',tiles:'/tiles/{z}/{x}/{y}',nonce:'fixture'};</script><script src="/assets/vendor/leaflet.js"></script><script src="/assets/iran-locations.js"></script><script src="/assets/map.js"></script>${publicPage?'':'<script src="/assets/admin.js"></script>'}</body></html>`});
  });
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});
   await page.goto('http://dashboard.test/#dashboard');
   const panel=page.locator('.dashboard-directory-panel'),pager=panel.locator('.dashboard-pager');
   await expect(panel.locator('tbody tr')).toHaveCount(10);
   const provinceSelect=page.locator('[data-province]'),citySelect=page.locator('[data-city]');
   await expect(provinceSelect.locator('option')).toHaveCount(32);
   expect(await page.evaluate(()=>window.TapinLocationCatalog.every(p=>p.cities.length>0))).toBe(true);
   await provinceSelect.selectOption('یزد');
   await expect(citySelect.locator('option')).toHaveCount(1+await page.evaluate(()=>window.TapinLocationCatalog.find(p=>p.province==='یزد').cities.length));
   await citySelect.selectOption('یزد');
   await expect(panel.locator('.locator-empty')).toBeVisible();
   await expect(page.locator('.tapin-pin')).toHaveCount(0);
   await page.locator('[data-provider-select]').selectOption('1');
   await expect(provinceSelect).toHaveValue('یزد');
   await expect(citySelect).toHaveValue('یزد');
   await expect(provinceSelect.locator('option')).toHaveCount(32);
   await page.locator('[data-clear]').click();
   await expect(panel.locator('tbody tr')).toHaveCount(10);
   await expect(page.locator('.hero-number')).toHaveText('۲۳');
   await expect(page.locator('[data-retry]')).toBeHidden();
   await expect(page.locator('.tapin-pin .marker-chart').first()).toBeAttached();
   await expect(page.locator('.cluster-count,.provider-pin-image,.province-composition')).toHaveCount(0);
   await expect(page.locator('.tapin-province-boundary').first()).toBeAttached();
   await pager.locator('.pagination-next').click();
   await expect(panel.locator('tbody tr').first()).toContainText('Branch 11');
   await panel.locator('[data-list]').click();
   await expect(panel.locator('.map-list')).toBeHidden();
   await expect(pager).toBeHidden();
   await panel.locator('[data-list]').click();
   await expect(pager).toBeVisible();
   await pager.locator('.pagination-last').click();
   await expect(panel.locator('tbody tr')).toHaveCount(3);
   await expect(pager.locator('.pagination-next')).toBeDisabled();
   await page.locator('.locator-search .btn-search').click();await page.locator('.locator-search input').fill('missing');
   await page.locator('.locator-search').evaluate(el=>el.requestSubmit());
   await expect(panel.locator('.locator-empty')).toBeVisible();
   await page.locator('[data-clear]').click();
   await expect(panel.locator('tbody tr')).toHaveCount(10);
   for(const [route,selector] of [['points','.points-management-table'],['imports','#upload-form'],['providers','.provider-cards'],['settings','.guide'],['dashboard','.dashboard-directory-panel tbody tr']]){
    await page.evaluate(hash=>location.hash=hash,route);
    await expect(page.locator(selector).first()).toBeVisible();
   }
   await expect(page.locator('.tapin-detail')).toHaveCount(1);
   if(width===1440){
    const before=await page.locator('.tapin-sidebar').boundingBox();
    await page.locator('.tapin-main').evaluate(el=>el.scrollTop=el.scrollHeight);
    expect((await page.locator('.tapin-sidebar').boundingBox()).y).toBe(before.y);
    expect(await page.locator('.tapin-main').evaluate(el=>el.scrollTop)).toBeGreaterThan(0);
   }
  }
  await page.goto('http://dashboard.test/public');
  await expect(page.locator('[data-province] option')).toHaveCount(32);
  await expect(page.locator('.branch-card')).toHaveCount(23);
  await expect(page.locator('.tapin-pin .marker-chart').first()).toBeAttached();
  expect(errors).toEqual([]);
  console.log('PASS: dashboard startup, numbered pagination, collapse synchronization, filters, routes, sidebar scrolling, mobile and public locator; no JavaScript runtime errors.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
