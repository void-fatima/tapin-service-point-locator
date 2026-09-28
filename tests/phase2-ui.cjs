/* Phase 2 browser regression: synthetic responses never enter the database. */
const {chromium,expect}=require('@playwright/test');
const fs=require('fs');
(async()=>{
 const session=JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE,'utf8'));
 const browser=await chromium.launch({headless:true});
 let checks=0;const errors=[];let release=()=>{};
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});
  page.on('pageerror',e=>errors.push(e.message));
  const asset=session.url+'/wp-content/plugins/tapin-service-point-locator/assets/';
  const providers=[{id:101,slug:'post',name:'پست',marker_color:'#ffbd18',logo:asset+'brand/post.png',is_active:1},{id:202,slug:'tipax',name:'تیپاکس',marker_color:'#00ba88',logo:asset+'brand/tipax.svg',is_active:1},{id:303,slug:'other',name:'سایر',marker_color:'#7349ff',logo:'',is_active:1}];
  const base={province:'تهران',city:'تهران',address:'خیابان آزادی پلاک 12',postal_code:'1234567890',landline_phone:'02112345678 داخلی 9',mobile_phone:'09121234567',phone:'02188888888',has_coordinates:true,latitude:35.7,longitude:51.4};
  const rows=providers.map((p,i)=>({...base,id:i+1,provider_id:p.id,name:'Branch '+(i+1)}));
  rows.push({...base,id:4,provider_id:101,name:'Address only',has_coordinates:false,latitude:null,longitude:null});
  let holdId=0,gate=Promise.resolve(),detailRequests=0,geocodingRequests=0;
  const hold=id=>{holdId=id;gate=new Promise(r=>release=r);};
  await page.route('**/tapin/v1/**',async route=>{
   const u=new URL(route.request().url()),q=u.searchParams;
   if(u.pathname.includes('/geocoding'))geocodingRequests++;
   if(u.pathname.endsWith('/filters'))return route.fulfill({json:{providers,locations:providers.map(p=>({provider_id:p.id,province:'تهران',city:'تهران'}))}});
   const match=u.pathname.match(/\/public\/points\/(\d+)$/);
   if(match){detailRequests++;const id=Number(match[1]);if(id===holdId)await gate;return route.fulfill({json:rows.find(p=>p.id===id)}).catch(()=>{});}
   let items=rows.filter(p=>(!q.get('provider_id')||p.provider_id===Number(q.get('provider_id')))&&(!q.get('search')||p.name.includes(q.get('search'))));
   if(u.pathname.endsWith('/points'))items=items.filter(p=>p.has_coordinates);
   return route.fulfill({json:{items,total:items.length,total_pages:1,page:1}});
  });
  await page.goto(session.public_url);
  for(const [index,rgb,logo] of [[0,'rgb(255, 189, 24)',/post\.png/],[1,'rgb(0, 186, 136)',/tipax\.svg/],[2,'rgb(115, 73, 255)',null]]){
   await page.locator('[data-provider="'+providers[index].id+'"]').click();
   const marker=page.locator('.tapin-pin').first();await expect(marker).toHaveCSS('background-color',rgb);checks++;
   if(logo)await expect(marker.locator('img')).toHaveAttribute('src',logo);else await expect(marker.locator('img')).toHaveCount(0);checks++;
   await marker.focus();await expect(page.locator('.leaflet-tooltip')).toContainText(rows[index].name);checks++;
   await marker.press('Enter');await expect(page.locator('.detail-body h3')).toHaveText(rows[index].name);checks++;
   for(const field of ['address','postal_code','landline_phone','mobile_phone','phone']){await expect(page.locator('.detail-body')).toContainText(rows[index][field]);checks++;}
   await expect(page.locator('.detail-body a[href="tel:02112345678"]')).toBeVisible();checks++;
   await expect(page.locator('.directions-btn')).toHaveAttribute('href',/destination=35\.7%2C51\.4/);checks++;
   await page.keyboard.press('Escape');await expect(page.locator('.tapin-detail')).not.toBeVisible();await expect(marker).toBeFocused();checks+=2;
  }
  await page.locator('[data-clear]').click();
  const opener=page.locator('[data-details="4"]');await opener.click();
  await expect(page.locator('.detail-body h3')).toHaveText('Address only');await expect(page.locator('.detail-body')).toContainText('موقعیت روی نقشه هنوز در دسترس نیست');await expect(page.locator('.directions-btn')).toHaveCount(0);checks+=3;
  await page.keyboard.press('Escape');await expect(opener).toBeFocused();checks++;
  hold(1);await page.locator('[data-details="1"]').click();await expect(page.locator('.detail-body')).toContainText('در حال دریافت');
  await page.keyboard.press('Escape');await page.locator('[data-details="2"]').click();await expect(page.locator('.detail-body h3')).toHaveText('Branch 2');
  holdId=0;release();await expect(page.locator('.detail-body h3')).toHaveText('Branch 2');checks++;
  await page.keyboard.press('Escape');hold(1);await page.locator('[data-details="1"]').click();await page.locator('[data-close]').click();holdId=0;release();await expect(page.locator('.tapin-detail')).not.toBeVisible();checks++;
  await page.locator('[data-details="2"]').click();
  await page.route('https://www.google.com/maps/**',route=>route.fulfill({body:'Offline directions destination'}));
  const popupPromise=page.waitForEvent('popup');await page.locator('.directions-btn').click();const popup=await popupPromise;await popup.close();
  expect(geocodingRequests).toBe(0);expect(detailRequests).toBeGreaterThan(5);checks+=2;
  await page.keyboard.press('Escape');
  await page.setViewportSize({width:390,height:844});await page.locator('[data-view="list"]').click();await page.locator('[data-details="4"]').click();await expect(page.locator('.tapin-detail')).toBeVisible();expect(await page.locator('.tapin-public').evaluate(el=>el.scrollWidth<=el.clientWidth+2)).toBe(true);checks+=2;

  const context=await browser.newContext();await context.addCookies(session.cookies);const admin=await context.newPage();admin.on('pageerror',e=>errors.push(e.message));
  await admin.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#points');await expect(admin.locator('.geocoding-controls')).toBeVisible();checks++;
  const statuses=await admin.evaluate(async()=>{
   const send=async(method,nonce)=>{const response=await fetch(TapinConfig.api+'geocoding'+(method==='POST'?'/retry':''),{method,headers:{'Content-Type':'application/json',...(nonce?{'X-WP-Nonce':nonce}:{})},...(method==='POST'?{body:JSON.stringify({ids:[1]})}:{})});return response.status;};
   return [await send('GET','invalid'),await send('POST','invalid'),await send('POST',''),await send('GET',TapinConfig.nonce)];
  });expect(statuses).toEqual([403,403,401,200]);checks++;
  await expect(admin.locator('[data-geocoding-summary]')).toContainText('تنظیم نشده');checks++;
  expect(errors).toEqual([]);checks++;
  console.log('PASS Phase 2 browser: '+checks+' checks; provider colors/logos, detail fields, phone/directions, delayed-response cancellation, focus, address-only/mobile, admin status, nonce/security, zero JS errors');
 }finally{release();await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
