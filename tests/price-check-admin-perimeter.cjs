/* Actual Admin callbacks/assets inside a WP shell fixture using installed public core CSS. */
const { chromium, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const { readFileSync, writeFileSync, mkdirSync, existsSync } = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const repo = path.resolve(__dirname, '..'), runtime = process.env.TAPIN_PACKAGE_ROOT || repo;
const php = process.env.TAPIN_PHP || path.join(repo, 'artifacts/php-runtime/php.exe');
const artifacts = path.join(repo, 'artifacts/perimeter');
mkdirSync(artifacts, { recursive: true });
const fixtures = JSON.parse(execFileSync(php, ['-d', 'extension_dir=' + path.join(repo, 'artifacts/php-runtime/ext'), '-d', 'extension=zip', path.join(__dirname, 'price-check-ui-fixtures.php')], { encoding: 'utf8' }));
fixtures.adminProvider.location_config = { ...fixtures.adminProvider.location_config,
  custom_locations: { provinces: [{ code: '1', title: 'استان نمونه' }], cities: [{ code: '10', title: 'شهر مجاز', province_code: '1' }, { code: '11', title: 'شهر مسدود', province_code: '1' }] },
  blocked_locations: { provinces: [], cities: [{ code: '11', title: 'شهر مسدود', province_code: '1' }] } };
let responseState = 'populated';
const pending = [];
const server = http.createServer((req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.includes('/wp-admin/css/')) {
      res.writeHead(200, {'Content-Type':'text/css'});
      return res.end(readFileSync(path.join(artifacts, url.pathname.endsWith('-rtl.css') ? 'wp-admin-common-rtl.css' : 'wp-admin-common.css')));
    }
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
      if (responseState === 'empty' && suffix === 'admin/logs') data.logs = [];
      const send = () => { res.writeHead(responseState === 'error' ? 500 : 200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' }); res.end(JSON.stringify(responseState === 'error' ? { detail: 'Theme fixture error' } : data)); };
      if (responseState === 'loading' && (suffix === 'bulk-check' || suffix === 'price-check' || suffix.startsWith('admin/'))) pending.push(send); else send(); return;
    }
    const site = `http://127.0.0.1:${server.address().port}/site`;
    if (url.pathname === '/map-theme') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end('<!doctype html><html><body><div class="tapin-app"><select id="tapin-theme-select"><option>dark</option><option>light</option><option>system</option></select></div><script src="' + site + '/plugin/assets/theme.js"></script><script>document.querySelector("select").onchange = e => TapinTheme.set(e.target.value);</script></body></html>'); }
    const admin = url.pathname.includes('/wp-admin/');
    const mode = admin ? (url.searchParams.get('page') || 'tapin-price-providers').replace('tapin-price-', '') : url.pathname.includes('/bulk') ? 'bulk' : 'single';
    const html = execFileSync(php, [path.join(__dirname, 'price-check-admin-perimeter-render.php')], { input: JSON.stringify({ site, mode, price: url.pathname !== '/unrelated', dir: url.searchParams.get('dir') || 'ltr', query: Object.fromEntries(url.searchParams), attributes: url.searchParams.has('standalone') ? { layout: 'standalone' } : {} }), encoding: 'utf8' });
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(html);
  } catch (error) { console.error(error); res.writeHead(500); res.end('Fixture error'); }
});
const { PNG } = require(path.join(path.dirname(require.resolve('playwright-core/package.json')), 'lib/utilsBundle.js'));
const before = process.env.TAPIN_PERIMETER_AUDIT === 'before';
const output = {}; const errors = [];
(async () => {
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const browser = await chromium.launch();
  const context = await browser.newContext({viewport:{width:1440,height:900},reducedMotion:'reduce',colorScheme:'light'});
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  page.on('console', message => {if(message.type()==='error') errors.push(message.text());});
  const base=`http://127.0.0.1:${server.address().port}/site`;
  const frame=()=>page.frameLocator('iframe'); const doc=()=>page.frames()[1];
  const capture = async name => {
    await doc().evaluate(()=>document.fonts.ready);
    await doc().evaluate(()=>document.getAnimations().filter(a=>a.effect.getTiming().iterations!==Infinity).forEach(a=>a.finish()));
    await page.evaluate(()=>scrollTo(0,0));
    const host = await page.evaluate(()=>{
      const selectors=['html','body','#wpwrap','#wpcontent','#wpbody','#wpbody-content','.tapin-price-admin','[data-tapin-theme-frame]','iframe'];
      const geometry=selectors.map(selector=>{const n=document.querySelector(selector),r=n.getBoundingClientRect(),s=getComputedStyle(n);return [selector,r.x,r.y,r.width,r.height,s.padding,s.margin,s.borderWidth,s.overflowX,s.overflowY,s.position];});
      const r=document.querySelector('iframe').getBoundingClientRect();
      return {geometry,rect:{x:r.x,y:r.y,width:r.width,height:r.height},overflow:document.documentElement.scrollWidth>innerWidth,mode:document.documentElement.dataset.themeMode,colors:selectors.map(s=>[s,getComputedStyle(document.querySelector(s)).backgroundColor])};
    });
    const child=await doc().evaluate(()=>['html','body','.layout','.sidebar','.content','#main'].filter(s=>document.querySelector(s)).map(selector=>{const n=document.querySelector(selector),r=n.getBoundingClientRect(),s=getComputedStyle(n);return [selector,r.x,r.y,r.width,r.height,s.padding,s.margin,s.borderWidth,s.font];}));
    const mode=await doc().evaluate(()=>document.documentElement.dataset.themeMode);
    const rgb=mode==='dark'?[11,14,25]:[248,248,255];
    const shot=PNG.sync.read(await page.screenshot({path:path.join(artifacts,`${before?'before':'after'}-${name}.png`),fullPage:true}));
    const r=host.rect;
    const points={top:[r.x+r.width/2,r.y-3],left:[r.x-3,r.y+50],right:[r.x+r.width+3,r.y+50],bottom:[r.x+r.width/2,r.y+r.height+3]};
    const edges={};
    for(const [edge,[x,y]] of Object.entries(points)){
      const offset=(Math.floor(y)*shot.width+Math.floor(x))*4;
      const pixel=[...shot.data.subarray(offset,offset+3)];
      const owner=await page.evaluate(({x,y})=>{scrollTo(0,Math.max(0,y-innerHeight/2));let n=document.elementFromPoint(x,y-scrollY);const hit=n?.id||n?.className||n?.tagName;while(n){const bg=getComputedStyle(n).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)'&&bg!=='transparent')return {hit,paint:n.id||n.className||n.tagName,background:bg};n=n.parentElement;}return {hit,background:'transparent'};},{x,y});
      edges[edge]={pixel,...owner};
      assert.deepEqual(pixel,before?[240,240,241]:rgb,`${name}: ${edge} perimeter`);
    }
    assert.equal(host.overflow,false,'New host overflow');
    assert.ok(await doc().evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'New child overflow');
    if(!before){assert.equal(host.mode,mode);assert.equal(await frame().locator('body').evaluate(n=>getComputedStyle(n).backgroundColor),`rgb(${rgb.join(', ')})`);}
    const chrome=await page.locator('#wpadminbar').evaluate(n=>getComputedStyle(n).backgroundColor);
    assert.equal(chrome,'rgb(29, 35, 39)','WordPress toolbar recolored');
    output[name]={geometry:host.geometry,child,edges,mode};
    if(!before&&existsSync(path.join(artifacts,'before.json'))){const baseline=JSON.parse(readFileSync(path.join(artifacts,'before.json'),'utf8'))[name];if(baseline){assert.deepEqual(host.geometry,baseline.geometry,'Host layout changed: '+name);assert.deepEqual(child,baseline.child,'Child layout changed: '+name);}}
  };
  try {
    const screens={providers:'list',shops:'shops',bulk:'bulk',logs:'logs',cache:'cache',security:'security'};
    for(const width of [1440,390]){
      await page.setViewportSize({width,height:900});
      for(const [screen,view] of Object.entries(screens)){
        await page.goto(base+'/wp-admin/admin.php?page=tapin-price-'+screen);
        await expect(frame().locator('#'+view+'-view')).toBeVisible();
        await expect(frame().locator('#'+view+'-view .card').first()).toBeVisible();
        await expect(frame().locator('#'+view+'-view .skeleton')).toHaveCount(0);
        for(const collapsed of [false,true]){
          const toggle=frame().locator('.pc-sidebar-toggle');
          if((await toggle.getAttribute('aria-expanded'))!==String(!collapsed)) await toggle.click();
          for(const [pref,mode] of [['light','light'],['dark','dark'],['system','light'],['system','dark']]){
            await page.emulateMedia({colorScheme:mode});await frame().locator('[data-tapin-theme-choice]').selectOption(pref);
            await expect(frame().locator('html')).toHaveAttribute('data-theme-mode',mode);
            if(!before) await expect(page.locator('html')).toHaveAttribute('data-theme-mode',mode);
            await capture(`${screen}-${width}-${collapsed?'rail':'expanded'}-${pref}-${mode}`);
          }
        }
      }
      console.log(`${before?'Baseline':'Fixed'} ${width}px: six admin screens, both sidebar states, Light/Dark/System; four sampled edges.`);
    }
    if(!before){
      for(const width of [1440,390]) {
        await page.setViewportSize({width,height:900});
        await page.goto(base+'/wp-admin/admin.php?page=tapin-price-cache');
        await expect(frame().locator('.cache-tile')).not.toHaveCount(0);
        for(const collapsed of [false,true]) {
          const toggle=frame().locator('.pc-sidebar-toggle');
          if((await toggle.getAttribute('aria-expanded'))!==String(!collapsed)) await toggle.click();
          for(const [pref,mode] of [['light','light'],['dark','dark'],['system','light'],['system','dark']]) {
            await page.emulateMedia({colorScheme:mode});await frame().locator('[data-tapin-theme-choice]').selectOption(pref);
            await page.reload();await expect(frame().locator('.cache-tile')).not.toHaveCount(0);
            await expect(frame().locator('[data-tapin-theme-choice]')).toHaveValue(pref);
            await expect(frame().locator('.pc-sidebar-toggle')).toHaveAttribute('aria-expanded',String(!collapsed));
            await capture('reload-'+width+'-'+collapsed+'-'+pref+'-'+mode);
          }
        }
      }
      const sibling=await context.newPage();
      await sibling.goto(base+'/wp-admin/admin.php?page=tapin-price-logs');
      await sibling.frameLocator('iframe').locator('[data-tapin-theme-choice]').selectOption('light');
      await expect(page.locator('html')).toHaveAttribute('data-theme-mode','light');
      await expect(frame().locator('html')).toHaveAttribute('data-theme-mode','light');
      await sibling.frameLocator('iframe').locator('[data-tapin-theme-choice]').selectOption('dark');
      await expect(page.locator('html')).toHaveAttribute('data-theme-mode','dark');
      await expect(frame().locator('html')).toHaveAttribute('data-theme-mode','dark');
      await sibling.close();
      // Host mode must remain synchronized after reload, before any iframe CSS/body is ready.
      await frame().locator('[data-tapin-theme-choice]').selectOption('dark');
      let held=false,release;const gate=new Promise(resolve=>release=resolve);
      await page.route('**/price-check/style.css*',async route=>{held=true;await gate;await route.continue();});
      try{
        await page.reload({waitUntil:'commit'});await expect.poll(()=>held).toBe(true);
        await expect(page.locator('html')).toHaveAttribute('data-theme-mode','dark');
        await expect(frame().locator('html')).toHaveAttribute('data-theme-mode','dark');
        assert.equal(await doc().evaluate(()=>document.body),null);
        assert.equal(await page.locator('body').evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(11, 14, 25)');
        // CDP captures the current paint without Playwright waiting on the deliberately blocked frame fonts/CSS.
        const cdp=await context.newCDPSession(page);
        const initial=await cdp.send('Page.captureScreenshot',{format:'png'});
        writeFileSync(path.join(artifacts,'after-before-iframe-css.png'),Buffer.from(initial.data,'base64'));
        await cdp.detach();
      }finally{release();}
      await page.waitForLoadState('load');await page.unroute('**/price-check/style.css*');
      await expect(frame().locator('[data-tapin-theme-choice]')).toHaveValue('dark');
      await page.goto(base+'/wp-admin/admin.php?page=tapin-price-cache&dir=rtl');await expect(frame().locator('.cache-tile')).not.toHaveCount(0);await capture('rtl-cache');
      await page.goto(base.replace('/site','')+'/unrelated');
      assert.equal(await page.locator('body').evaluate(n=>getComputedStyle(n).backgroundColor),'rgb(240, 240, 241)');
      await expect(page.locator('html')).not.toHaveClass(/tapin-price-admin-host/);
    }
    assert.deepEqual(errors,[]);
    writeFileSync(path.join(artifacts,before?'before.json':'after.json'),JSON.stringify(output,null,2));
    console.log(`PASS ${before?'reproduction':'perimeter'}: ${Object.keys(output).length} states; ${Object.keys(output).length*4} pixel samples; runtime ${runtime}; no JS errors/overflow.`);
    if(before) console.log('Original dark bulk perimeter:',output['bulk-1440-expanded-dark-dark'].edges);
  }finally{await context.close();await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
