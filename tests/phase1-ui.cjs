/* Network-only fixtures: no synthetic records are persisted to LocalWP. */
const {chromium,expect}=require('@playwright/test');const fs=require('fs');
(async()=>{
 const session=JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE,'utf8'));
 const browser=await chromium.launch();const context=await browser.newContext({viewport:{width:1670,height:1050}});await context.addCookies(session.cookies);
 try{
  const page=await context.newPage(),errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));
  const providers=[{id:1,slug:'post',name:'پست',logo:session.url+'/wp-content/plugins/tapin-service-point-locator/assets/brand/post.png',is_active:1},{id:2,slug:'tipax',name:'تیپاکس',is_active:1},{id:3,slug:'other',name:'سایر',is_active:1},{id:4,slug:'another',name:'ارائه‌دهنده دیگر',is_active:1}];
  const points=[{id:1,provider_id:1,name:'تهران دارای مکان',province:'تهران',city:'تهران',latitude:35.7,longitude:51.4},{id:2,provider_id:1,name:'تهران فقط نشانی',province:'تهران',city:'ری',latitude:null,longitude:null},{id:3,provider_id:2,name:'تهران تیپاکس',province:'تهران',city:'تهران',latitude:35.6,longitude:51.5},{id:4,provider_id:3,name:'فارس دارای مکان',province:'فارس',city:'شیراز',latitude:29.6,longitude:52.5},{id:5,provider_id:4,name:'فارس فقط نشانی',province:'فارس',city:'',latitude:null,longitude:null}].map(p=>({...p,address:'نشانی آزمایشی',has_coordinates:p.latitude!==null}));
  await page.route('**/tapin/v1/**',async route=>{
   const u=new URL(route.request().url()),q=u.searchParams;
   if(u.pathname.endsWith('/providers'))return route.fulfill({json:providers});
   if(u.pathname.endsWith('/locations'))return route.fulfill({json:points.map(({provider_id,province,city})=>({provider_id,province,city}))});
   if(!u.pathname.endsWith('/points'))return route.continue();
   requests.push(Object.fromEntries(q));let rows=points.filter(p=>(!q.get('province')||p.province===q.get('province'))&&(!q.get('city')||p.city===q.get('city'))&&(!q.get('provider_id')||p.provider_id===Number(q.get('provider_id')))&&(!q.get('search')||p.name.includes(q.get('search'))));
   if(q.get('has_coordinates')==='1')rows=rows.filter(p=>p.has_coordinates);
   const located=rows.filter(p=>p.has_coordinates).length;
   return route.fulfill({json:{items:rows,total:rows.length,total_pages:rows.length?1:0,page:1,summary:{total:rows.length,located,missing:rows.length-located,distribution:providers.map(p=>({provider_id:p.id,total:rows.filter(r=>r.provider_id===p.id).length}))}}});
  });
  await page.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#dashboard');
  const province=page.locator('[data-province]'),city=page.locator('[data-city]'),total=page.locator('.hero-number');
  await expect(total).toHaveText('۵');await expect(province.locator('option')).toHaveCount(3);
  const layout=await page.evaluate(()=>{const box=s=>document.querySelector(s).getBoundingClientRect();return {stats:box('.metric-rail').x,map:box('.dashboard-map').x,title:box('.filter-title').right,provider:box('[data-provider-select]').right,heroBottom:box('.dashboard-hero').bottom,tableTop:box('.dashboard-directory-panel').top};});
  expect(layout.stats).toBeLessThan(layout.map);expect(layout.title).toBeGreaterThan(layout.provider);expect(layout.tableTop).toBeGreaterThanOrEqual(layout.heroBottom - 5);
  await expect(page.locator('.provider-overview .provider-summary-item')).toHaveCount(3);
  await expect(page.locator('.provider-overview .provider-logo').filter({hasText:'سایر'})).toHaveCount(1);
  await expect(page.locator('.provider-overview .provider-logo').filter({hasText:'تیپاکس'})).toHaveCount(1);
  await expect(page.locator('.dashboard-bottom,.issue-list,.distribution')).toHaveCount(0);
  await page.locator('[data-list]').click();await expect(page.locator('.directory-table tbody tr')).toHaveCount(5);
  await province.selectOption('تهران');await expect(total).toHaveText('۳');await expect(city.locator('option')).toHaveCount(3);
  await city.selectOption('ری');await expect(total).toHaveText('۱');await expect(page.locator('.coordinate-totals')).toContainText('بدون مختصات');await expect(page.locator('.tapin-pin')).toHaveCount(0);await expect(page.locator('.directory-table tbody tr')).toHaveCount(1);await expect(page.locator('.directory-table tbody tr')).toContainText('بدون مختصات');
  await province.selectOption('فارس');await expect(total).toHaveText('۲');await expect(city).toHaveValue('');await expect(city.locator('option')).toHaveCount(2);
  await province.selectOption('تهران');await page.locator('[data-provider-select]').selectOption('1');await city.selectOption('تهران');await expect(total).toHaveText('۱');
  await page.locator('.locator-search input').fill('فقط');await expect(total).toHaveText('۰');await expect(page.locator('.tapin-pin')).toHaveCount(0);await expect(page.locator('.locator-empty')).toBeVisible();
  await page.locator('[data-empty-clear]').click();await expect(total).toHaveText('۵');await expect(province).toHaveValue('');await expect(city).toHaveValue('');
  // Keyboard activation fires exactly the polygon click handler, and fits the selected geometry.
  await page.locator('path[aria-label="فارس"]').focus();await page.keyboard.press('Enter');await expect(province).toHaveValue('فارس');await expect(total).toHaveText('۲');await expect(page.locator('.directory-table tbody tr')).toHaveCount(2);await expect(page.locator('.tapin-pin')).toHaveCount(1);
  await page.locator('[data-reset]').click();await expect(total).toHaveText('۵');await expect(province).toHaveValue('');
  await page.locator('path[aria-label="سمنان"]').focus();await page.keyboard.press('Enter');await expect(province).toHaveValue('سمنان');await expect(city).toBeDisabled();await expect(total).toHaveText('۰');await expect(page.locator('.tapin-pin')).toHaveCount(0);
  await page.locator('[data-reset]').click();await expect(total).toHaveText('۵');
  for(const name of ['تهران','فارس','تهران','فارس']){await province.selectOption(name);await expect(total).toHaveText(name==='فارس'?'۲':'۳');}
  await expect.poll(()=>requests.filter(r=>r.province==='فارس'&&r.north).at(-1)?.north).toBeDefined();
  await expect.poll(()=>Number(requests.filter(r=>r.province==='فارس'&&r.north).at(-1)?.north)).toBeLessThan(35);
  await page.locator('[data-list]').click();await expect(page.locator('.map-list')).toBeHidden();await page.locator('[data-list]').click();await expect(page.locator('.map-list')).toBeVisible();
  await page.screenshot({path:'artifacts/phase1-filter-desktop.png',fullPage:true});
  await page.setViewportSize({width:390,height:844});await expect(province).toBeVisible();expect(await page.locator('#tapin-admin').evaluate(e=>e.scrollWidth<=e.clientWidth+2)).toBe(true);await page.screenshot({path:'artifacts/phase1-filter-mobile.png',fullPage:true});
  expect(requests.some(r=>r.province==='تهران'&&r.city==='تهران'&&r.provider_id==='1'&&r.search==='فقط')).toBe(true);
  expect(requests.some(r=>r.province==='فارس'&&r.north&&r.west)).toBe(true);expect(errors).toEqual([]);
  console.log('PASS phase1-ui: real-data options, shared filters/counts/directory/markers, address-only, province keyboard click/focus, reset, empty state, repeated changes, layout, directory panel, mobile and zero JS errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
