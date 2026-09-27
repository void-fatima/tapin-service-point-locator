/* Deterministic browser checks with synthetic network fixtures, never saved as branch data. */
const {chromium,expect}=require('@playwright/test');
const fs=require('fs');
(async()=>{
 const session=JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE,'utf8'));
 const browser=await chromium.launch({headless:true});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}}), errors=[], requests=[];
  page.on('pageerror',e=>errors.push(e.message));
  const asset=session.url+'/wp-content/plugins/tapin-service-point-locator/assets/';
  const providers=[{id:1,name:'پست',is_active:1,logo:asset+'brand/post.png'},{id:2,name:'تیپاکس',is_active:1,logo:asset+'brand/tipax.svg'},{id:3,name:'سایر',is_active:1,logo:''}];
  const points=Array.from({length:501},(_,i)=>({id:i+1,provider_id:i%3+1,name:'Fixture '+i,province:'تهران',city:'تهران',address:'Test only',has_coordinates:true,latitude:35.7+(i%10)*.001,longitude:51.4+(i%20)*.001,mobile_phone:'09121234567',landline_phone:'02112345678',postal_code:'1234567890'}));
  const missing={...points[0],id:999,name:'Address only fixture',has_coordinates:false,latitude:null,longitude:null};
  await page.route('**/tapin/v1/public/**',async route=>{
   const url=new URL(route.request().url()),params=url.searchParams;
   if(url.pathname.endsWith('/filters'))return route.fulfill({json:{providers,locations:providers.map(p=>({provider_id:p.id,province:'تهران',city:'تهران'}))}});
   const directory=url.pathname.endsWith('/directory');
   if(!directory)requests.push(Object.fromEntries(params));
   let rows=directory?[missing,...points]:points;
   if(params.get('provider_id'))rows=rows.filter(p=>p.provider_id===Number(params.get('provider_id')));
   if(params.get('province'))rows=rows.filter(p=>p.province===params.get('province'));
   const n=Number(params.get('page')||1),size=Number(params.get('per_page')||500);
   await route.fulfill({json:{items:rows.slice((n-1)*size,n*size),total:rows.length,page:n,total_pages:Math.ceil(rows.length/size)}});
  });
  await page.goto(session.public_url);
  await expect(page.locator('.marker-status')).toContainText('۵۰۱');
  if(!requests.some(r=>r.page==='2'&&r.north&&r.west))throw Error('Viewport pagination missing');
  if(await page.locator('.tapin-pin').count()>=100)throw Error('Dense points were not grouped');
  await expect(page.locator('.all-pin').first()).toBeVisible();
  await page.locator('[data-more]').click();
  const address=page.locator('.branch-card').filter({hasText:'Address only fixture'});
  await expect(address).toBeVisible();await expect(address.locator('[data-point]')).toHaveCount(0);
  await page.locator('[data-provider="1"]').click();
  await expect(page.locator('.provider-pin img').first()).toHaveAttribute('src',/post\.png/);
  await page.locator('[data-provider="2"]').click();
  await expect(page.locator('.provider-pin img').first()).toHaveAttribute('src',/tipax\.svg/);
  await page.locator('.branch-card [data-point]').first().click();
  await expect(page.locator('.tapin-popup')).toContainText('09121234567');
  await expect(page.locator('.tapin-popup')).toContainText('02112345678');
  await expect(page.locator('.tapin-popup')).toContainText('1234567890');
  await expect(page.locator('.tapin-detail')).toHaveAttribute('open','');
  await page.keyboard.press('Escape');
  await expect(page.locator('.tapin-detail')).not.toBeVisible();
  await page.locator('[data-provider="3"]').click();
  await expect(page.locator('.provider-pin').first()).toBeVisible();
  await expect(page.locator('.provider-pin img')).toHaveCount(0);
  await page.locator('path[aria-label="سمنان"]').focus();await page.keyboard.press('Enter');
  await expect(page.locator('.marker-status')).toContainText('۰ نقطه');
  await expect(page.locator('.tapin-pin')).toHaveCount(0);
  if(!requests.some(r=>r.province==='سمنان'))throw Error('Province omitted from marker API');
  if(errors.length)throw Error(errors.join('\n'));
  console.log('PASS map engine: viewport pagination, dense grouping, address-only exclusion, Post/Tipax/neutral pins, empty province and no JS errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
