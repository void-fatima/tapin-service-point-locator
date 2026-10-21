/* Screenshot/geometry audit of real PHP views and assets; localhost REST/identity doubles. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, writeFileSync, mkdirSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..'), runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const artifacts = path.join(repo, 'artifacts/public-layout');
mkdirSync(artifacts, { recursive: true });
const fixtures = JSON.parse(execFileSync(php, ['-d', 'extension_dir=' + path.join(repo, 'artifacts/php-runtime/ext'), '-d', 'extension=zip', path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
fixtures.adminProvider.location_config = { ...fixtures.adminProvider.location_config,
  custom_locations: { provinces: [{ code: '1', title: 'استان نمونه' }], cities: [{ code: '10', title: 'شهر مجاز', province_code: '1' }, { code: '11', title: 'شهر مسدود', province_code: '1' }] },
  blocked_locations: { provinces: [], cities: [{ code: '11', title: 'شهر مسدود', province_code: '1' }] } };
let responseState = 'populated'; const calls=[]; let fixtureState='data';
const pending = [];
const server = http.createServer((req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/plugin/assets/')) {
      const file = path.resolve(runtime, url.pathname.split('/plugin/')[1]);
      assert.ok(file.startsWith(path.join(runtime, 'assets') + path.sep));
      res.writeHead(200, { 'Content-Type': file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream' });
      return res.end(readFileSync(file));
    }
    if (url.pathname.includes('/wp-json/')) {
      const suffix = url.pathname.split('/tapin-price-check/v1/')[1];
      if (suffix.startsWith('admin/')) assert.equal(req.headers['x-wp-nonce'], 'test-rest-nonce');
      let data = suffix === 'admin/providers' ? { providers: [{ ...fixtures.adminProvider, param_count: 4, visible_field_count: 4 }] }
        : suffix === 'admin/providers/1' ? fixtures.adminProvider
        : suffix === 'admin/providers/1/unserved-locations' ? { provinces: [], cities_by_province: {}, city_count: 0, province_count: 0 }
        : suffix === 'admin/shops' ? { shops: [{ id: '103', title: 'شعبه نمونه', province: '1', city: '10', has_token: true }] }
        : suffix === 'admin/bulk' ? { config: fixtures.bulkConfig, providers: [fixtures.provider] }
        : suffix === 'admin/logs' ? { logs: [{ id: 1, provider_id: 1, provider_name: 'sample', provider_display_name: 'شرکت نمونه', status: 'error', error_message: 'خطای آزمایشی', created_at: '2026-10-21T12:00:00', payload: { weight: 1 }, response: null }], filters: {} }
        : suffix === 'admin/cache' ? { settings: { price: 86400, price_retention: 864000, locations: 21600, quote_logs: 604800 }, stats: { price_rows: 0, quote_logs_rows: 0 }, locations: { cached: false } }
        : suffix === 'admin/security/route-limits' ? { routes: [{ route: 'price-check', title: 'استعلام قیمت', methods: ['POST'], max_requests: 4, window_seconds: 60, default_max: 4, default_window: 60 }], admin_groups: [] }
        : suffix === 'admin/security/allowlist' ? { allowlist: [] }
        : suffix === 'admin/security/rate-limits' ? { limits: [] }
        : suffix === 'admin/security/repeat-offenders' ? { offenders: [] }
        : suffix === 'admin/security/blocks' ? { blocks: [] }
        : suffix === 'admin/security/events' ? { events: [] }
        : suffix === 'providers' ? { providers: [fixtures.provider] }
        : suffix === 'providers/sample' || suffix === 'providers/1' ? fixtures.locationSchema
        : suffix === 'admin/preview/provider-schema' ? fixtures.schema
        : suffix === 'admin/preview/bulk-schema' ? fixtures.bulkSchema
        : suffix === 'bulk' ? fixtures.bulkSchema
        : suffix === 'price-check' ? fixtures.quote
        : suffix === 'bulk-validate' ? { valid: true, errors: [] }
        : suffix === 'bulk-check' ? { results: [{ ...fixtures.quote, ok: true, variant: {}, variant_detail: [] }] } : {};
      calls.push({method:req.method,suffix});
      if(suffix==='providers') data.providers=fixtureState==='empty'?[]:Array.from({length:6},(_,i)=>({...fixtures.provider,id:i+1,name:i?'sample'+i:'sample',display_name:fixtures.provider.display_name+' '+(i+1)}));
      if(suffix==='bulk'&&fixtureState==='empty') data={...fixtures.bulkSchema,providers:[]};
      if(suffix==='bulk-check') data={results:Array.from({length:5},(_,i)=>({...fixtures.quote,provider:{...fixtures.quote.provider,name:'sample'+i,display_name:fixtures.provider.display_name+' '+(i+1)},ok:true,variant:{},variant_detail:[]}))};
      if((suffix==='providers/1'||suffix==='providers/sample')&&fixtureState==='long') data={...fixtures.schema,params:[...fixtures.schema.params,...Array.from({length:20},(_,i)=>({name:'extra'+i,label:'Extra field '+i,input:'text',type:'str',visible:true}))]};
      if (responseState === 'empty' && suffix === 'admin/logs') data.logs = [];
      const send = () => { res.writeHead(responseState === 'error' ? 500 : 200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(responseState === 'error' ? { detail: 'Theme fixture error' } : data)); };
      if (responseState === 'loading' && (suffix === 'bulk-check' || suffix === 'price-check' || suffix.startsWith('admin/'))) pending.push(send); else send(); return;
    }
    const site = `http://127.0.0.1:${server.address().port}/site`;

    fixtureState=url.searchParams.get('fixture')||'data';
    const admin = false;
    const mode = admin ? (url.searchParams.get('page') || 'tapin-price-providers').replace('tapin-price-', '') : url.pathname.includes('/bulk') ? 'bulk' : 'single';
    const html = execFileSync(php, [path.join(__dirname, 'price-check-public-layout-render.php')], { input: JSON.stringify({ site, mode, admin, logged: admin, capable: admin, query: Object.fromEntries(url.searchParams), toolbar:!url.searchParams.has('anonymous'),mixed:url.searchParams.has('mixed'),attributes:url.searchParams.has('embedded')?{layout:'embedded'}:{} }), encoding: 'utf8' });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(html);
  } catch (error) { console.error(error); res.writeHead(500); res.end('Fixture error'); }
});
const before=process.env.TAPIN_PUBLIC_LAYOUT_AUDIT==='before',output={},errors=[];
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const browser=await chromium.launch(),context=await browser.newContext({viewport:{width:1440,height:900},reducedMotion:'reduce'}),page=await context.newPage();
 page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
 await context.route('**/*',route=>{assert.equal(new URL(route.request().url()).hostname,'127.0.0.1','Unexpected external request');return route.continue();});
 const base=`http://127.0.0.1:${server.address().port}/site`,f=()=>page.frameLocator('iframe'),doc=()=>page.frames()[1];
 const capture=async(name,{embedded=false,menu=null}={})=>{
  await doc().evaluate(()=>document.fonts.ready);
  await doc().evaluate(()=>document.getAnimations().filter(a=>a.effect.getTiming().iterations!==Infinity).forEach(a=>a.finish()));
  await expect.poll(()=>doc().evaluate(()=>Math.abs(innerHeight-parseFloat(window.frameElement.style.height||innerHeight)))).toBeLessThanOrEqual(1);
  const parent=await page.evaluate(()=>{const n=document.querySelector('[data-tapin-theme-frame]'),r=n.getBoundingClientRect(),frame=n.querySelector('iframe'),q=frame.getBoundingClientRect();return{viewport:innerWidth,height:innerHeight,hostWidth:r.width,iframeWidth:q.width,iframeHeight:q.height,iframeTop:q.top+scrollY,hostX:r.x,template:document.documentElement.className,overflow:document.documentElement.scrollWidth>innerWidth+1,bodyBackground:getComputedStyle(document.body).backgroundColor};});
  const app=await doc().evaluate(()=>{const measure=selector=>{const n=[...document.querySelectorAll(selector)].find(n=>n.checkVisibility());if(!n)return null;const r=n.getBoundingClientRect(),s=getComputedStyle(n);return{width:r.width,height:r.height,x:r.x,y:r.y,display:s.display,columns:s.gridTemplateColumns,font:s.font};};return{main:measure('#main'),hero:measure('.pro-hero'),grid:measure('.pro-layout'),form:measure('form'),formColumn:measure('.pro-form-col'),result:measure('#result,#bulk-result'),resultColumn:measure('.pro-result-col'),providers:measure('.provider-grid'),input:measure('input:not([type=hidden])'),bodyHeight:document.body.getBoundingClientRect().height,iframeHeight:innerHeight,overflow:document.documentElement.scrollWidth>innerWidth+1,innerScroll:document.documentElement.scrollHeight>innerHeight+1};});
  assert.equal(parent.overflow,false,name+' host overflow');assert.equal(app.overflow,false,name+' child overflow');
  if(!before){
   if(!embedded){assert.ok(Math.abs(parent.hostWidth-parent.viewport)<2,name+' narrow host');assert.equal(parent.iframeWidth,parent.hostWidth);assert.equal(app.main.width,parent.iframeWidth);assert.ok(parent.iframeHeight>=parent.height-parent.iframeTop-1,name+' short background');await expect(page.locator('#theme-header,#theme-title,#theme-footer')).toHaveCount(0);}
   else{assert.ok(parent.hostWidth<=645);assert.equal(app.main.width,parent.hostWidth);await expect(page.locator('#theme-header,#theme-footer')).toHaveCount(2);}
   await expect(f().locator('.topbar,.footer,.brand,#nav-bulk,#nav-advanced')).toHaveCount(0);
   assert.ok(!app.innerScroll,name+' nested scrollbar');
   if(app.grid){if(parent.iframeWidth>1024){assert.ok(app.formColumn.width>400);assert.ok(app.resultColumn.x+app.resultColumn.width<=app.formColumn.x+1);assert.ok(app.grid.width>parent.iframeWidth-50);}else{assert.ok(Math.abs(app.formColumn.width-app.resultColumn.width)<1);assert.ok(app.resultColumn.y>=app.formColumn.y+app.formColumn.height);}}
   if(menu){await expect.poll(()=>f().locator(menu).evaluate(n=>n.getBoundingClientRect().bottom<=innerHeight+1)).toBe(true);}
  }
  await page.evaluate(()=>scrollTo(0,0));await doc().evaluate(()=>scrollTo(0,0));
  output[name]={...parent,...app};
  await page.screenshot({path:path.join(artifacts,`${before?'before':'after'}-${name}.png`),fullPage:true});
 };
 try{
  for(const width of [1440,1920,768,390]){await page.setViewportSize({width,height:900});for(const mode of ['single','bulk'])for(const theme of ['light','dark']){
   const prefix=`${mode}-${width}-${theme}`;
   await page.goto(base+'/'+mode+'/?fixture=empty');await expect(f().locator(mode==='single'?'#provider-list .empty':'#bulk-form .empty')).toBeVisible();await f().locator('[data-tapin-theme-choice]').selectOption(theme);await capture(prefix+'-empty');
   await page.goto(base+'/'+mode+'/');await expect(f().locator(mode==='single'?'#provider-list .tile':'#bulk-form button[type=submit]').first()).toBeVisible();
   if(mode==='single'){await capture(prefix+'-providers');await f().locator('#provider-list .tile button').first().click();await expect(f().locator('#form-panel')).toBeVisible();}
   await capture(prefix+'-form');
   if(mode==='single'){
    const combo=f().locator('.combo-face').first();await combo.click();await expect(f().locator('.combo-panel').first()).toBeVisible();await capture(prefix+'-dropdown',{menu:'.combo.open .combo-panel'});await combo.click();
    await f().locator('#single-submit-btn').click();await expect(f().locator('.field.has-error')).not.toHaveCount(0);await f().locator('[name="param:weight"]').fill('2');await f().locator('#single-submit-btn').click();await expect(f().locator('#result')).toContainText('۱۲٬۵۰۰');
   }else{
    await f().locator('#bulk-advanced-toggle').click();await expect(f().locator('#bulk-advanced-panel')).toBeVisible();await capture(prefix+'-dropdown',{menu:'#bulk-advanced-panel'});await f().locator('#bulk-advanced-toggle').click();
    await f().locator('[name="bulk:weight"]').fill('2');await f().locator('#bulk-form button[type=submit]').click();await expect(f().locator('#bulk-result')).toContainText('۱۲٬۵۰۰');
   }
   await capture(prefix+'-results');
  }console.log(`${before?'Before':'After'} ${width}px: single/bulk, Light/Dark, empty/providers/form/dropdown/results`);}
  if(!before){
   for(const width of [1440,1920,768,390]){
    await page.setViewportSize({width,height:900});await page.goto(base+'/single/?fixture=long');await f().locator('#provider-list .tile button').first().click();await expect(f().locator('[name="param:extra19"]')).toBeVisible();await capture('long-'+width);await f().locator('[name="param:extra19"]').fill('last field');
    await page.goto(base+'/single/?embedded=1&mixed=1');await expect(f().locator('#provider-list .tile')).toHaveCount(6);await capture('embed-'+width,{embedded:true});await expect(page.locator('#before-module,#after-module')).toHaveCount(2);
   }
   await page.goto(base+'/bulk/?anonymous=1');await expect(f().locator('#bulk-form button')).not.toHaveCount(0);await capture('anonymous');assert.equal(output.anonymous.iframeTop,0);
   await f().locator('[data-tapin-theme-choice]').selectOption('system');await page.emulateMedia({colorScheme:'dark'});await expect(page.locator('html')).toHaveAttribute('data-theme-mode','dark');await expect(f().locator('html')).toHaveAttribute('data-theme-mode','dark');
   await page.reload();await expect(f().locator('[data-tapin-theme-choice]')).toHaveValue('system');
  }
  assert.deepEqual(errors,[]);writeFileSync(path.join(artifacts,before?'before.json':'after.json'),JSON.stringify({runtime,captures:Object.keys(output).length,measurements:output,errors,quoteCalls:calls.filter(c=>c.suffix==='price-check'||c.suffix==='bulk-check').length},null,2));
  console.log(`PASS public layout ${before?'before':'after'}: ${Object.keys(output).length} screenshots/measurements, no external requests or browser errors.`);
 }finally{await context.close();await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;});
