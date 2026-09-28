/* Mocked table/history fixtures plus real authenticated XLSX HTTP download. */
const {chromium,expect}=require('@playwright/test');
const fs=require('fs');
(async()=>{
 const session=JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE,'utf8'));
 const browser=await chromium.launch();let checks=0;const errors=[];
 const check=async fn=>{await fn();checks++;};
 try{
  const context=await browser.newContext({viewport:{width:1666,height:1100},acceptDownloads:true});await context.addCookies(session.cookies);
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const providers=[{id:101,slug:'post',name:'پست',is_active:1},{id:202,slug:'tipax',name:'تیپاکس',is_active:1}];
  const address='نشانی طولانی فارسی، پلاک ۱۲ '.repeat(15);
  const rows=Array.from({length:121},(_,i)=>({id:i+1,provider_id:101,name:'شعبه '+String(i+1).padStart(3,'0'),province:'تهران',city:'تهران',address,postal_code:'0012345678',landline_phone:'02112345678',has_coordinates:false,latitude:null,longitude:null,status:'active'}));
  rows.push({...rows[0],id:122,name:'ری فقط نشانی',city:'ری'});
  rows.push({...rows[0],id:123,name:'فارس دارای موقعیت',province:'فارس',city:'شیراز',provider_id:202,has_coordinates:true,latitude:29.6,longitude:52.5});
  const requests=[],exportRequests=[];let failExport=true,failHistory=false,delay=0;
  await page.route('**/tapin/v1/**',async route=>{
   const u=new URL(route.request().url()),q=u.searchParams,path=u.pathname;
   if(path.endsWith('/providers'))return route.fulfill({json:providers});
   if(path.endsWith('/locations'))return route.fulfill({json:rows.map(({provider_id,province,city})=>({provider_id,province,city}))});
   if(path.endsWith('/exports/points')){exportRequests.push(route.request().postDataJSON());return route.fulfill({status:failExport?503:200,json:{message:'INTERNAL_PATH_SENTINEL'}});}
   if(path.endsWith('/exports'))return failHistory?route.fulfill({status:503,json:{message:'STACK_SENTINEL'}}):route.fulfill({json:[{id:2,event:'export_failed',user_id:7,created_at:'2026-09-28 12:00:00',context:{format:'xlsx',filters:{}}},{id:1,event:'export_completed',user_id:7,created_at:'2026-09-28 11:00:00',context:{rows:121,format:'xlsx',filters:{provider_id:'101',province:'تهران',search_applied:true}}}]});
   if(path.endsWith('/imports'))return route.fulfill({json:[{id:1,filename:'fixture.csv',created_at:'2026-09-28 10:00:00',status:'completed',data:{provider_id:101,total:20,inserted:12,updated:3,skipped:4,failed:1,warnings:2}},{id:2,filename:'missing.csv',created_at:null,status:'preview',data:{}},{id:3,filename:'running.csv',created_at:'2026-09-28 10:00:00',status:'running',data:{total:30,inserted:4,updated:1,skipped:0,failed:0,warnings:0}},{id:4,filename:'failed.csv',created_at:'2026-09-28 10:00:00',status:'failed',data:{}}]});
   if(path.endsWith('/geocoding'))return route.fulfill({json:{configured:false,items:[],counts:{}}});
   if(/\/points\/\d+\/details$/.test(path))return route.fulfill({json:rows.find(p=>p.id===Number(path.split('/').at(-2)))});
   if(path.endsWith('/points')){
    requests.push(Object.fromEntries(q));let items=rows.filter(p=>(!q.get('province')||q.get('province')===p.province)&&(!q.get('city')||q.get('city')===p.city)&&(!q.get('provider_id')||Number(q.get('provider_id'))===p.provider_id)&&(!q.get('search')||p.name.includes(q.get('search'))));
    if(q.get('has_coordinates')==='1')items=items.filter(p=>p.has_coordinates);if(q.get('has_coordinates')==='0')items=items.filter(p=>!p.has_coordinates);
    const total=items.length,located=items.filter(p=>p.has_coordinates).length,size=Number(q.get('per_page')||20),current=Number(q.get('page')||1);
    if(delay&&!q.has('north'))await new Promise(resolve=>setTimeout(resolve,delay));
    return route.fulfill({json:{items:items.slice((current-1)*size,current*size),total,page:current,total_pages:Math.ceil(total/size),summary:{total,located,missing:total-located,distribution:providers.map(p=>({provider_id:p.id,total:items.filter(x=>x.provider_id===p.id).length}))}}});
   }
   return route.continue();
  });
  await page.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#dashboard');
  const table=page.locator('.directory-table'),body=table.locator('tbody tr');
  await check(()=>expect(body).toHaveCount(10));
  await check(()=>expect(table.locator('thead th')).toHaveText(['ارائه‌دهنده','نام شعبه','استان','شهر','آدرس','کد پستی','تلفن ثابت','وضعیت موقعیت','جزئیات']));
  await check(async()=>expect(await table.evaluate(e=>getComputedStyle(e).direction)).toBe('rtl'));
  await check(()=>expect(body.first()).toContainText('0012345678'));await check(()=>expect(body.first()).toContainText('02112345678'));
  await check(()=>expect(body.first()).toContainText('بدون مختصات'));await check(()=>expect(body.first().locator('.directory-address')).toHaveText(address.trim()));
  await table.focus();await check(()=>expect(table).toBeFocused());
  await page.locator('[data-provider-select]').selectOption('101');await page.locator('[data-province]').selectOption('تهران');await page.locator('[data-city]').selectOption('تهران');
  await check(()=>expect(page.locator('.map-status')).toContainText('۱۲۱ نتیجه'));
  await check(()=>expect(page.locator('.page-indicator')).toContainText('صفحه ۱ از ۱۳'));
  await page.locator('[data-more]').click();await check(()=>expect(body.first()).toContainText('شعبه 011'));await check(()=>expect(body).toHaveCount(10));
  await check(()=>expect(page.locator('.page-indicator')).toContainText('صفحه ۲ از ۱۳'));
  await check(()=>expect(page.locator('[data-city]')).toHaveValue('تهران'));await check(()=>expect(page.locator('[data-provider-select]')).toHaveValue('101'));
  await page.getByRole('button',{name:'صفحه قبلی',exact:true}).click();await check(()=>expect(body.first()).toContainText('شعبه 001'));
  await check(()=>expect(page.locator('.page-indicator')).toContainText('صفحه ۱ از ۱۳'));
  await page.locator('[data-export]').click();await check(()=>expect(page.locator('.export-controls [role=status]')).toContainText('دریافت خروجی اکسل انجام نشد'));
  await check(async()=>expect(exportRequests.at(-1)).toEqual({search:'',province:'تهران',city:'تهران',provider_id:'101',status:'any'}));
  await check(()=>expect(page.locator('.export-controls')).not.toContainText('INTERNAL_PATH_SENTINEL'));await check(()=>expect(page.locator('[data-export]')).toBeEnabled());
  delay=500;await page.locator('.locator-search input').fill('شعبه 001');
  await check(()=>expect(page.locator('.map-list')).toHaveAttribute('aria-busy','true'));await check(()=>expect(body).toHaveCount(1));delay=0;
  await check(()=>expect(body.first()).toContainText('شعبه 001'));await check(()=>expect(page.locator('.tapin-pin')).toHaveCount(0));
  await body.locator('[data-details]').focus();await page.keyboard.press('Enter');await check(()=>expect(page.locator('.tapin-detail')).toBeVisible());await page.keyboard.press('Escape');
  await page.locator('.locator-search input').fill('ناموجود');await check(()=>expect(page.locator('.locator-empty')).toContainText('هیچ نقطه خدماتی'));
  await page.locator('[data-clear]').evaluate(b=>b.click());await check(()=>expect(body).toHaveCount(10));await check(()=>expect(page.locator('[data-province]')).toHaveValue(''));
  await page.locator('[data-province]').selectOption('فارس');await check(()=>expect(body).toHaveCount(1));await check(()=>expect(body).toContainText('دارای موقعیت'));
  await check(()=>expect(page.locator('.tapin-pin')).toHaveCount(1));
  await page.setViewportSize({width:390,height:844});await check(async()=>expect(await page.locator('#tapin-admin').evaluate(e=>e.scrollWidth<=e.clientWidth+2)).toBe(true));
  await check(async()=>expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBe(true));
  await page.screenshot({path:'artifacts/phase3-table-mobile.png',fullPage:true});
  await page.setViewportSize({width:1666,height:1100});await page.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#points?province=تهران&city=تهران&provider_id=101&has_coordinates=0&search=شعبه');
  await page.locator('[data-export]').click();await check(()=>expect(page.locator('.export-controls [role=status]')).toContainText('دریافت خروجی'));
  await check(async()=>expect(exportRequests.at(-1)).toMatchObject({province:'تهران',city:'تهران',provider_id:'101',has_coordinates:'0',search:'شعبه'}));
  await page.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#imports');
  const imports=page.locator('.import-history');await check(()=>expect(imports.locator('tbody tr')).toHaveCount(4));
  const first=imports.locator('tbody tr').first();await check(()=>expect(first).toContainText('تکمیل‌شده با هشدار'));await check(()=>expect(first).toContainText('پست'));
  await check(()=>expect(first.locator('td').nth(3)).toHaveText('۲۰'));await check(()=>expect(first.locator('td').nth(4)).toHaveText('۱۵'));
  await check(()=>expect(first.locator('td').nth(5)).toHaveText('۴'));await check(()=>expect(first.locator('td').nth(6)).toHaveText('۱'));await check(()=>expect(first.locator('td').nth(7)).toHaveText('۲'));
  await check(()=>expect(imports.locator('tbody tr').nth(1)).toContainText('—'));await check(()=>expect(imports.locator('tbody tr').nth(2)).toContainText('در حال پردازش'));await check(()=>expect(imports.locator('tbody tr').nth(3)).toContainText('ناموفق'));
  const exports=page.locator('.export-history');await check(()=>expect(exports.locator('tbody tr')).toHaveCount(2));
  await check(()=>expect(exports.locator('tbody tr').first()).toContainText('تهیه فایل ناموفق'));await check(()=>expect(exports.locator('tbody tr').first().locator('td').nth(1)).toHaveText('—'));
  await check(()=>expect(exports.locator('tbody tr').last()).toContainText('۱۲۱'));await check(()=>expect(exports).toContainText('متن ذخیره نمی‌شود'));
  failHistory=true;await page.locator('[data-refresh-exports]').click();await check(()=>expect(exports).toContainText('دریافت تاریخچه خروجی انجام نشد'));await check(()=>expect(exports).not.toContainText('STACK_SENTINEL'));await check(()=>expect(page.locator('#upload-form')).toBeVisible());
  failHistory=false;await page.locator('[data-refresh-exports]').click();await check(()=>expect(exports.locator('tbody tr')).toHaveCount(2));
  await page.setViewportSize({width:390,height:844});await check(async()=>expect(await page.locator('#tapin-admin').evaluate(e=>e.scrollWidth<=e.clientWidth+2)).toBe(true));
  // Real network request: authenticated binary endpoint, not a mocked download.
  const live=await context.newPage();live.on('pageerror',e=>errors.push(e.message));await live.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#points?province=سمنان&has_coordinates=0');
  const downloadPromise=live.waitForEvent('download');await live.locator('[data-export]').click();const download=await downloadPromise;
  await check(async()=>expect(download.suggestedFilename()).toMatch(/^tapin-service-points-\d{4}-\d{2}-\d{2}\.xlsx$/));
  await download.saveAs('artifacts/phase3-http-export.xlsx');await check(async()=>expect(fs.readFileSync('artifacts/phase3-http-export.xlsx').subarray(0,2).toString()).toBe('PK'));
  const security=await live.evaluate(async()=>{const send=async(headers,body)=>{const r=await fetch(TapinConfig.api+'exports/points',{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify(body)});return r.status;};return [await send({},{}),await send({'X-WP-Nonce':'invalid'},{}),await send({'X-WP-Nonce':TapinConfig.nonce},{provider_id:'../1'})];});
  await check(async()=>expect(security).toEqual([401,403,400]));
  await live.goto(session.url+'/wp-admin/admin.php?page=tapin-locator#imports');await check(()=>expect(live.locator('.export-history')).toContainText('فایل آماده شد'));
  await check(async()=>expect(errors).toEqual([]));
  console.log('PASS Phase 3 browser: '+checks+' checks; table/pagination/filters, history, responsive keyboard UX, real XLSX download, REST security; zero JS errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
