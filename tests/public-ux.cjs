/* Phase 2: real-data read-only acceptance plus isolated HTTP fixtures. Never imports data. */
const {chromium,expect}=require('@playwright/test');
const fs=require('fs');
(async()=>{
 const session=JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE,'utf8'));
 const browser=await chromium.launch({headless:true});
 const out='artifacts/phase2';fs.mkdirSync(out,{recursive:true});
 let release=()=>{};
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1050},reducedMotion:'reduce'}),errors=[],requests=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('request',r=>{if(r.url().includes('/public/points?'))requests.push(new URL(r.url()).searchParams);});
  await page.goto(session.public_url);
  await expect(page.locator('.marker-status')).toContainText('۲۳۰');
  await expect(page.locator('.locator-heading img')).toHaveAttribute('alt','تاپین');
  await expect(page.locator('[data-provider=""]')).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('.leaflet-country-context-pane svg path').first()).toBeAttached();
  await page.screenshot({path:out+'/desktop.png',fullPage:true});
  await page.locator('.locator-search input').fill('سمنان');await page.locator('.locator-search [type=submit]').click();
  await expect(page.locator('.branch-card')).toHaveCount(10);await expect(page.locator('.marker-status')).toHaveText(/^۰ نقطه/);
  if(!requests.some(p=>p.get('search')==='سمنان'))throw Error('Search not sent to marker endpoint');
  await page.locator('[data-provider="2"]').click();await expect(page.locator('.locator-empty')).toBeVisible();
  await page.locator('[data-empty-clear]').click();await expect(page.locator('.marker-status')).toContainText('۲۳۰');
  await expect(page.locator('.locator-search input')).toHaveValue('');await expect(page.locator('[data-provider=""]')).toHaveAttribute('aria-pressed','true');
  await page.locator('[data-province]').selectOption('تهران');await expect(page.locator('.marker-status')).toContainText('۲۳۰');
  await expect.poll(()=>requests.some(p=>p.get('province')==='تهران'&&Number(p.get('north'))-Number(p.get('south'))<5)).toBeTruthy();
  await page.locator('[data-provider="2"]').click();await expect(page.locator('.provider-pin img').first()).toHaveAttribute('src',/markers\/tipax\.png/);
  await page.screenshot({path:out+'/tipax-province.png',fullPage:true});
  await page.locator('[data-provider="1"]').click();await page.locator('[data-province]').selectOption('سمنان');await expect(page.locator('.branch-card')).toHaveCount(10);
  await expect(page.locator('.tapin-pin')).toHaveCount(0);await expect(page.locator('[data-point]')).toHaveCount(0);
  const opener=page.locator('[data-details]').first();await opener.focus();await page.keyboard.press('Enter');
  await expect(page.locator('.tapin-detail')).toBeVisible();await expect(page.locator('[data-close]')).toBeFocused();
  await expect(page.locator('.tapin-detail a[href="tel:02333348602"]')).toBeVisible();
  await expect(page.locator('.tapin-detail')).not.toContainText('تلفن همراه');
  for(let i=0;i<6;i++){await page.keyboard.press('Tab');if(!await page.evaluate(()=>document.activeElement.closest('.tapin-detail')!==null))throw Error('Focus escaped modal');}
  await page.keyboard.press('Escape');await expect(opener).toBeFocused();
  await page.setViewportSize({width:390,height:844});await expect(page.locator('.map-viewport')).toBeVisible();await expect(page.locator('.directory-panel')).not.toBeVisible();
  await page.locator('button[data-view="list"]').click();await expect(page.locator('.directory-panel')).toBeVisible();await expect(page.locator('.map-viewport')).not.toBeVisible();
  await page.screenshot({path:out+'/mobile-list.png',fullPage:true});
  await opener.click();await page.screenshot({path:out+'/mobile-drawer.png',fullPage:true});await page.keyboard.press('Escape');
  if(await page.locator('.tapin-public').evaluate(el=>el.scrollWidth>el.clientWidth+2))throw Error('Mobile horizontal overflow');
  await page.locator('[data-clear]').click();await page.locator('button[data-view="map"]').click();await expect(page.locator('.marker-status')).toContainText('۲۳۰');
  await page.screenshot({path:out+'/mobile-map.png',fullPage:true});
  console.log('PASS real public UX: search/filter consistency, province focus, logo legend, empty clear, address-only drawer, tel links, keyboard focus, mobile views');

  const mock=await browser.newPage({viewport:{width:1440,height:1000},reducedMotion:'reduce'});mock.on('pageerror',e=>errors.push(e.message));
  const assets=session.url+'/wp-content/plugins/tapin-service-point-locator/assets/';
  const providers=[{id:1,slug:'post',name:'پست',is_active:1,logo:assets+'brand/post.png'},{id:2,slug:'tipax',name:'تیپاکس',is_active:1,logo:assets+'brand/tipax.svg'},{id:3,slug:'other',name:'Neutral fixture',is_active:1,logo:''}];
  const base={province:'تهران',city:'تهران',address:'Synthetic test only',latitude:35.7,longitude:51.4,has_coordinates:true};
  const rows=providers.map(p=>({...base,id:p.id,provider_id:p.id,name:'Fixture '+p.id}));
  rows.push({...base,id:4,provider_id:1,name:'Foreign fixture',latitude:48.8,longitude:2.3},{...base,id:5,provider_id:1,name:'Invalid fixture',latitude:'bad'},{...base,id:6,provider_id:1,name:'Address fixture',latitude:null,longitude:null,has_coordinates:false});
  let fail=false,hold=true,markerRequests=0;
  const gate=new Promise(resolve=>{release=resolve;});
  const mockRoute=async route=>{
   const url=new URL(route.request().url()),p=url.searchParams;
   const detail=url.pathname.match(/\/public\/points\/(\d+)$/);
   if(detail)return route.fulfill({json:rows.find(row=>row.id===Number(detail[1]))});
   if(url.pathname.endsWith('/filters'))return route.fulfill({json:{providers,locations:providers.map(pr=>({provider_id:pr.id,province:'تهران',city:'تهران'}))}});
   const directory=url.pathname.endsWith('/directory');
   if(!directory){markerRequests++;if(hold)await gate;if(fail)return route.fulfill({status:503,json:{message:'Fixture marker outage'}});}
   let filtered=rows.filter(r=>!p.get('provider_id')||String(r.provider_id)===p.get('provider_id'));
   if(p.get('search'))filtered=filtered.filter(r=>r.name.includes(p.get('search')));
   if(!directory)filtered=filtered.filter(r=>r.has_coordinates);
   return route.fulfill({json:{items:filtered,total:filtered.length,total_pages:1,page:1}});
  };
  await mock.route('**/tapin/v1/public/**',mockRoute);await mock.goto(session.public_url);
  await expect(mock.locator('.marker-status')).toContainText('در حال دریافت');hold=false;release();
  await expect(mock.locator('.marker-status')).toContainText('۳ نقطه');await expect(mock.locator('.all-pin')).toHaveCount(1);
  await expect(mock.locator('.branch-card').filter({hasText:'Foreign fixture'}).locator('[data-point]')).toHaveCount(0);
  for(let i=0;i<10&&!await mock.locator('.tapin-detail').isVisible();i++){
   const previous=markerRequests;await mock.locator('.tapin-pin').first().click();
   await expect.poll(async()=>await mock.locator('.tapin-detail').isVisible()||markerRequests>previous).toBeTruthy();
   if(!await mock.locator('.tapin-detail').isVisible())await expect(mock.locator('.tapin-pin')).toHaveCount(1);
  }
  await expect(mock.locator('.tapin-detail')).toBeVisible();await expect(mock.locator('[data-cluster-detail]')).toHaveCount(3);await mock.locator('[data-cluster-detail="2"]').click();await expect(mock.locator('.detail-body article')).toHaveCount(1);await expect(mock.locator('.detail-body')).toContainText('Fixture 2');await mock.keyboard.press('Escape');
  for(const id of ['1','2','3']){await mock.locator('[data-provider="'+id+'"]').click();await expect(mock.locator('.provider-pin')).toHaveCount(1);if(id==='3')await expect(mock.locator('.provider-pin img')).toHaveCount(0);else await expect(mock.locator('.provider-pin img')).toHaveAttribute('src',id==='1'?/post\.png/:/tipax\.svg/);}
  fail=true;await mock.locator('[data-clear]').click();await expect(mock.locator('.marker-status')).toContainText('Fixture marker outage');await expect(mock.locator('[data-retry]')).toBeVisible();
  fail=false;await mock.locator('[data-retry]').click();await expect(mock.locator('.marker-status')).toContainText('۳ نقطه');
  await mock.route('**/iran-provinces.geojson',route=>route.fulfill({status:503,body:'unavailable'}));await mock.reload();
  await expect(mock.locator('.tile-warning[role=alert]')).toBeVisible();await expect(mock.locator('.tapin-pin')).toHaveCount(0);await expect(mock.locator('.branch-card')).toHaveCount(6);
  await expect(mock.locator('.leaflet-tile-pane')).toHaveCSS('opacity','0');
  if(errors.length)throw Error(errors.join('\n'));
  console.log('PASS isolated states: loading, foreign/invalid exclusion, cluster zoom and co-located drawer, provider pins, marker error/retry, geography failure and usable directory');
 }finally{release();await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
