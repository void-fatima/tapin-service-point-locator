/* Shared ADMIN DASHBOARD/public marker regression with actual runtime assets.
   Run: node tests/map-marker-badges.cjs. API data/tiles are isolated fixtures,
   not a WordPress installation. TAPIN_PACKAGE_ROOT also tests extracted ZIPs. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs'), path = require('path');
const crypto = require('crypto');
const root = path.resolve(__dirname, '..');
const assetRoot = process.env.TAPIN_PACKAGE_ROOT || root;
const geometry = JSON.parse(fs.readFileSync(path.join(assetRoot, 'assets/iran-provinces.geojson'), 'utf8'));
const providers = [{id:1,slug:'post',name:'پست',is_active:1},{id:2,slug:'tipax',name:'تیپاکس',is_active:1},{id:3,slug:'cargo',name:'قطار بار',logo:'/fixture/cargo.svg',is_active:1}];
const definitions = [
  ...[1,1,1,2,2,3].map((provider,i)=>['اصفهان',32.65+i*.002,51.67+i*.03,provider]),
  ['بوشهر',28.92,50.84,1],['بوشهر',29.266,51.219,2],
  ['تهران',35.7,51.4,1],['تهران',35.72,51.43,2],['البرز',35.84,50.95,2],
  ['مازندران',36.56,53.06,1],['مازندران',36.57,53.07,3],['گیلان',37.28,49.59,2],
  ['فارس',29.59,52.58,3],['فارس',29.6,52.59,2],
  ['هرمزگان',27.18,56.27,1],['هرمزگان',26.95,56.1,2],
  ['سیستان و بلوچستان',25.43,60.74,1],['سیستان و بلوچستان',29.5,60.86,2]
];
const points = definitions.map(([province,latitude,longitude,provider_id],i)=>({id:i+1,province,latitude,longitude,provider_id,name:'Fixture '+(i+1),city:province,address:'Fixture address',has_coordinates:true,status:'active'}));
const rows = [...points,{...points[0],id:100,has_coordinates:false,latitude:null,longitude:null,name:'Address only'},{...points[0],id:101,latitude:33,longitude:44,name:'Outside Iran'}];
function contains(p,f){
  const inRing=ring=>{let inside=false;for(let i=0,j=ring.length-1;i<ring.length;j=i++){const [x,y]=ring[i],[u,v]=ring[j];if((y>p.latitude)!==(v>p.latitude)&&p.longitude<(u-x)*(p.latitude-y)/(v-y)+x)inside=!inside;}return inside;};
  return (f.geometry.type==='MultiPolygon'?f.geometry.coordinates:[f.geometry.coordinates]).some(r=>inRing(r[0])&&!r.slice(1).some(inRing));
}
const artifacts = path.join(root,'artifacts/map-marker-zoom-policy'+(assetRoot!==root?'-package':''));
const palette = {1:'#ffbd18',2:'#00ba88',3:'#dc3448'};
const digits = text => Number(text.replace(/[۰-۹]/g,ch=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(ch)));

(async()=>{
  for(const p of points)expect(geometry.features.some(f=>contains(p,f))).toBe(true);
  fs.mkdirSync(artifacts,{recursive:true});
  const chrome=process.env.TAPIN_CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const browser=await chromium.launch({headless:true,...(fs.existsSync(chrome)?{executablePath:chrome}:{})});
  const evidence=[];
  try{
    const surfaces=process.env.TAPIN_TEST_SURFACE==='admin'?[true]:process.env.TAPIN_TEST_SURFACE==='public'?[false]:[true,false];
    const widths=process.env.TAPIN_TEST_WIDTH?[Number(process.env.TAPIN_TEST_WIDTH)]:[1440,390],themes=process.env.TAPIN_TEST_THEME?[process.env.TAPIN_TEST_THEME]:['dark','light'];
    for(const admin of surfaces)for(const width of widths)for(const theme of themes){
      const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];
      const prefix=admin?'admin':'public',assetResponses=[];
      page.on('response',response=>{
        if(/\/assets\/(map\.js|admin\.js|app\.css|dashboard\.css|markers\/(post|tipax|other)\.png)$/.test(new URL(response.url()).pathname))assetResponses.push((async()=>({url:response.url(),hash:crypto.createHash('sha256').update(await response.body()).digest('hex')}))());
      });
      let fixtureRows=rows;
      page.on('pageerror',e=>errors.push(e.message));
      await page.addInitScript(theme=>localStorage.setItem('tapin-color-scheme',theme),theme);
      await page.route('**/*',async route=>{
        const url=new URL(route.request().url()),q=url.searchParams;
        if(url.pathname.startsWith('/api/')){
          const endpoint=url.pathname.slice(5);
          if(endpoint==='providers')return route.fulfill({json:providers});
          if(endpoint==='locations')return route.fulfill({json:points.map(p=>({province:p.province,city:p.city,provider_id:p.provider_id}))});
          if(endpoint==='public/filters')return route.fulfill({json:{providers,locations:points.map(p=>({province:p.province,city:p.city,provider_id:p.provider_id}))}});
          if(/^(public\/points\/\d+|points\/\d+\/details)$/.test(endpoint))return route.fulfill({json:fixtureRows.find(p=>p.id===Number(endpoint.match(/\d+/)[0]))});
          let selected=fixtureRows.filter(p=>!q.get('provider_id')||p.provider_id===Number(q.get('provider_id')));
          if(q.get('has_coordinates')==='1')selected=selected.filter(p=>p.has_coordinates&&p.latitude>=Number(q.get('south')||-90)&&p.latitude<=Number(q.get('north')||90)&&p.longitude>=Number(q.get('west')||-180)&&p.longitude<=Number(q.get('east')||180));
          const summary={total:selected.length,located:selected.filter(p=>p.has_coordinates).length,missing:selected.filter(p=>!p.has_coordinates).length,distribution:providers.map(p=>({provider_id:p.id,total:selected.filter(row=>row.provider_id===p.id).length}))};
          return route.fulfill({json:{items:selected,total:selected.length,page:1,total_pages:1,summary}});
        }
        if(url.pathname==='/fixture/cargo.svg')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><rect width="32" height="32" fill="#30579a"/><path d="M8 8h16v16H8z" fill="white"/></svg>'});
        if(url.pathname.startsWith('/assets/'))return route.fulfill({body:fs.readFileSync(path.join(assetRoot,url.pathname.slice(1))),contentType:({'.js':'application/javascript; charset=utf-8','.css':'text/css','.geojson':'application/json','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2'})[path.extname(url.pathname)]||'application/octet-stream'});
        if(url.pathname.startsWith('/tiles/'))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"></svg>'});
        return route.fulfill({contentType:'text/html; charset=utf-8',body:`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css">${admin?'<link rel="stylesheet" href="/assets/dashboard.css">':''}<link rel="stylesheet" href="/assets/theme.css"></head><body class="${admin?'toplevel_page_tapin-locator':'tapin-locator-page'}">${admin?'<div id="tapin-admin" class="tapin-app" dir="rtl"></div>':'<div class="tapin-app tapin-public" dir="rtl"><div class="tapin-public-root"></div></div>'}<script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}"};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];window.testMarkers=[];L.Map.addInitHook(function(){window.testMaps.push(this)});L.Marker.addInitHook(function(){window.testMarkers.push(this)});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script>${admin?'<script src="/assets/admin.js"></script>':''}</body></html>`});
      });
      const pageUrl='http://badges.test/'+(admin?'wp-admin/admin.php?page=tapin-locator#dashboard':'?page_id=22');
      await page.goto(pageUrl);
      await expect(page.locator('.marker-status')).toContainText(points.length.toLocaleString('fa-IR'));
      if(admin)await expect(page.locator('#tapin-admin .dashboard-hero .map-panel')).toBeVisible();
      const loadedAssets=await Promise.all(assetResponses);
      expect(loadedAssets.filter(a=>/\/assets\/(map\.js|admin\.js|app\.css|dashboard\.css)$/.test(new URL(a.url).pathname)).length).toBe(admin?4:2);
      for(const asset of loadedAssets){const relative=new URL(asset.url).pathname.slice(1);expect(asset.hash).toBe(crypto.createHash('sha256').update(fs.readFileSync(path.join(root,relative))).digest('hex'));}
      evidence.push({page:prefix,width,theme,loadedAssets});
      const frame=await page.evaluate(()=>({center:window.testMaps[0].getCenter(),zoom:window.testMaps[0].getZoom()}));
      const snapshot=()=>page.evaluate(()=>{
        const map=window.testMaps[0],box=map.getContainer().getBoundingClientRect();
        return window.testMarkers.filter(m=>map.hasLayer(m)&&m.getElement()?.classList.contains('tapin-pin')).map(m=>{
          const el=m.getElement(),rect=el.getBoundingClientRect(),chart=el.querySelector('.marker-chart'),pin=el.querySelector('.provider-pin-image');
          const center=L.point(rect.x+rect.width/2-box.x,rect.y+rect.height/2-box.y),radius=11;
          return {position:m.getLatLng(),province:el.dataset.markerProvince,label:el.getAttribute('aria-label'),tooltip:m.getTooltip().getContent().textContent,text:el.textContent,chart:chart?.getAttribute('style'),pin:pin?new URL(pin.src).pathname:undefined,classes:[...el.classList],images:[...el.querySelectorAll('img')].map(i=>new URL(i.src).pathname),size:m.options.icon.options.iconSize,anchor:m.options.icon.options.iconAnchor,border:chart?getComputedStyle(chart).border:null,center:{x:center.x,y:center.y},edge:chart?Array.from({length:8},(_,i)=>map.containerPointToLatLng([center.x+radius*Math.cos(i*Math.PI/4),center.y+radius*Math.sin(i*Math.PI/4)])):[]};
        });
      });
      const verifyLayers=async()=>{
        const layers=await page.evaluate(()=>{
          const map=window.testMaps[0],active=[];map.eachLayer(layer=>{if(layer instanceof L.Marker)active.push(layer);});
          return {active:active.length,tracked:window.testMarkers.filter(m=>map.hasLayer(m)).length,icons:document.querySelectorAll('.leaflet-marker-pane .leaflet-marker-icon').length,groups:new Set(active.map(m=>m.getElement()?.parentElement)).size,keys:active.map(m=>[m.getLatLng().lat,m.getLatLng().lng,m.getElement()?.getAttribute('aria-label')].join('|'))};
        });
        expect(layers.active).toBe(layers.tracked);expect(layers.active).toBe(layers.icons);expect(layers.groups).toBe(1);expect(new Set(layers.keys).size).toBe(layers.active);
        await expect(page.locator('.tapin-pin .cluster-count,.provider-image-pin,.province-compact,.province-composition,.tapin-pin b,.marker-logo,.logo-marker,.map-badge .provider-pin-image')).toHaveCount(0);
      };
      const verify=async(mode,zoom,expected,suffix='')=>{
        await expect(page.locator('.marker-status')).toContainText(expected.length.toLocaleString('fa-IR'));
        const chartExpected=zoom==='country'||expected.length>1;
        await expect(page.locator('.tapin-pin')).toHaveCount(zoom==='country'?new Set(expected.map(p=>p.province)).size:1);
        await expect(page.locator(chartExpected?'.branch-pin':'.map-badge')).toHaveCount(0);
        const markers=await snapshot();
        // Fail on old structures, even if CSS hides their text or images.
        await verifyLayers();
        await expect(page.locator('.tapin-pin')).toHaveCount(markers.length);
        for(const marker of markers){
          expect(marker.text).toBe('');
          expect(geometry.features.some(f=>contains({latitude:marker.position.lat,longitude:marker.position.lng},f))).toBe(true);
          for(const edge of marker.edge)if(!geometry.features.some(f=>contains({latitude:edge.lat,longitude:edge.lng},f))){
            await page.locator('.tapin-map').screenshot({path:path.join(artifacts,'failed-layout.png')});
            fs.writeFileSync(path.join(artifacts,'failed-layout.json'),JSON.stringify(await page.evaluate(()=>({size:window.testMaps[0].getSize(),markers:window.testMarkers.filter(m=>window.testMaps[0].hasLayer(m)&&m.getElement()?.classList.contains('map-badge')).map(m=>({label:m.getElement().getAttribute('aria-label'),anchor:m.options.icon.options.iconAnchor,style:m.getElement().getAttribute('style'),position:m.getLatLng()}))})),null,2));
            throw Error('Badge edge outside Iran: '+JSON.stringify({width,theme,mode,zoom,marker,edge}));
          }
          const members=zoom==='country'?expected.filter(p=>p.province===marker.province):expected;
          const countMatch=marker.tooltip.match(/([۰-۹]+) شعبه:/);expect(digits(countMatch[1])).toBe(members.length);
          if(chartExpected){
            expect(marker.classes).toContain('map-badge');expect(marker.classes).toContain('chart-marker');expect(marker.size).toEqual([20,20]);
            expect(marker.images).toEqual([]);let start=0;
            for(const provider of providers){const count=members.filter(p=>p.provider_id===provider.id).length;if(!count)continue;const end=start+count/members.length*100;expect(marker.chart).toContain(palette[provider.id]+' '+start+'% '+end+'%');expect(marker.tooltip).toContain(count.toLocaleString('fa-IR')+' '+(provider.id===3?'سایر':provider.name));start=end;}
          }else{
            const point=members[0],slug=point.provider_id===3?'other':providers.find(p=>p.id===point.provider_id).slug;
            expect(marker.classes).toContain('branch-pin');expect(marker.chart).toBeUndefined();expect(marker.size).toEqual([40,60]);
            expect(marker.images).toEqual(['/assets/markers/'+slug+'.png']);expect(marker.pin).toBe('/assets/markers/'+slug+'.png');
            expect(marker.position).toEqual({lat:point.latitude,lng:point.longitude});
            await expect.poll(()=>page.locator('.branch-pin img').evaluate(i=>i.naturalWidth)).toBeGreaterThan(0);
            const anchor=await page.evaluate(p=>{
              const m=window.testMarkers.find(m=>window.testMaps[0].hasLayer(m)&&m.getLatLng().lat===p.latitude&&m.getLatLng().lng===p.longitude),rect=m.getElement().getBoundingClientRect(),map=window.testMaps[0],box=map.getContainer().getBoundingClientRect(),xy=map.latLngToContainerPoint(m.getLatLng()),img=m.getElement().querySelector('img'),r=img.getBoundingClientRect();
              window.testPinTips ||= new Map();if(!window.testPinTips.has(img.src)){
                const c=document.createElement('canvas');c.width=img.naturalWidth;c.height=img.naturalHeight;const ctx=c.getContext('2d');ctx.drawImage(img,0,0);const pixels=ctx.getImageData(0,0,c.width,c.height).data;let bottom=-1;for(let y=c.height-1;y>=0&&bottom<0;y--)for(let x=0;x<c.width;x++)if(pixels[(y*c.width+x)*4+3]>240){bottom=y;break;}window.testPinTips.set(img.src,bottom);
              }
              const scale=Math.min(r.width/img.naturalWidth,r.height/img.naturalHeight),tipY=r.y+(r.height-img.naturalHeight*scale)/2+(window.testPinTips.get(img.src)+1)*scale;
              return {x:rect.left-box.left+m.options.icon.options.iconAnchor[0]-xy.x,y:rect.top-box.top+m.options.icon.options.iconAnchor[1]-xy.y,tipDelta:tipY-(box.top+xy.y),anchor:m.options.icon.options.iconAnchor};
            },point);
            expect(anchor.anchor).toEqual([20,60]);expect(Math.abs(anchor.x)).toBeLessThan(1);expect(Math.abs(anchor.y)).toBeLessThan(1);expect(Math.abs(anchor.tipDelta)).toBeLessThan(1);
            evidence.push({page:prefix,width,theme,provider:mode||'all',zoom,pinAnchor:anchor});
          }
        }
        for(let i=0;i<markers.length;i++)for(let j=i+1;j<markers.length;j++)expect(Math.hypot(markers[i].center.x-markers[j].center.x,markers[i].center.y-markers[j].center.y)).toBeGreaterThanOrEqual(22);
        expect(new Set(markers.map(m=>m.border)).size).toBe(1);
        for(const image of await page.locator('.branch-pin img').all())await expect.poll(()=>image.evaluate(i=>i.naturalWidth)).toBeGreaterThan(0);
        const marker=page.locator('.tapin-pin').first();await marker.focus();await expect(page.locator('.leaflet-tooltip-top')).toContainText('شعبه');await marker.evaluate(el=>el.blur());
        if(!suffix.startsWith('-cycle-'))await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`${prefix}-${theme}-${width}-${mode||'all'}-${zoom}${suffix}.png`)});
        if(admin&&mode===''&&zoom==='country'&&!suffix)await page.screenshot({path:path.join(artifacts,`${prefix}-${theme}-${width}-all-dashboard.png`),fullPage:true});
        evidence.push({page:prefix,width,theme,provider:mode||'all',zoom,markers});
      };
      for(const mode of ['','1','2','3']){
        await page.evaluate(frame=>window.testMaps[0].setView(frame.center,frame.zoom,{animate:false}),frame);
        if(admin)await page.locator('[data-provider-select]').selectOption(mode);else await page.locator(`[data-provider="${mode}"]`).click();
        const matching=points.filter(p=>!mode||p.provider_id===Number(mode));
        await verify(mode,'country',matching);
        const circle=page.locator('.province-aggregate[data-marker-province="اصفهان"]'),before=await page.evaluate(()=>window.testMaps[0].getZoom());
        await circle.click();await expect.poll(()=>page.evaluate(()=>window.testMaps[0].getZoom())).toBeGreaterThan(before);
        await page.waitForTimeout(500); // Wait for Leaflet zoom animation and its debounced marker redraw.
        for(let click=0;click<8&&!(await page.locator('.branch-pin').count());click++){
          await expect(page.locator('.marker-status')).not.toContainText('دریافت');
          const cluster=page.locator('.map-badge').first();if(!await cluster.count())break;const current=await page.evaluate(()=>window.testMaps[0].getZoom());await cluster.click();
          await expect.poll(()=>page.evaluate(()=>window.testMaps[0].getZoom())).toBeGreaterThan(current);await page.waitForTimeout(500);
        }
        await expect(page.locator('.branch-pin').first()).toBeAttached();await verifyLayers();
        for(const pin of (await snapshot()).filter(m=>m.pin)){const record=matching.find(p=>p.latitude===pin.position.lat&&p.longitude===pin.position.lng);expect(record).toBeTruthy();expect(pin.pin).toBe('/assets/markers/'+(record.provider_id===3?'other':providers.find(p=>p.id===record.provider_id).slug)+'.png');}
        await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`${prefix}-${theme}-${width}-${mode||'all'}-click-detailed.png`)});
        await page.evaluate(frame=>window.testMaps[0].setView(frame.center,frame.zoom,{animate:false}),frame);
        await verify(mode,'country',matching,'-zoom-out');
        await page.evaluate(()=>window.testMaps[0].setView([32.65,51.74],9,{animate:false}));
        await verify(mode,'intermediate',matching.filter(p=>p.province==='اصفهان'));
        if(mode===''){
          const z=await page.evaluate(()=>window.testMaps[0].getZoom());await page.locator('.map-badge').press('Enter');await expect.poll(()=>page.evaluate(()=>window.testMaps[0].getZoom())).toBeGreaterThan(z);
        }
        const detail=matching.find(p=>p.province==='اصفهان');
        await page.evaluate(p=>window.testMaps[0].setView([p.latitude,p.longitude],16,{animate:false}),detail);
        await verify(mode,'detailed',[detail]);
        const active=(await snapshot())[0];expect(active.position.lat).toBe(detail.latitude);expect(active.position.lng).toBe(detail.longitude);
        await page.locator('.branch-pin').click();await expect(page.locator('.detail-body h3')).toHaveText(detail.name);await page.keyboard.press('Escape');
      }
      await page.locator('[data-clear]').click();
      await verify('','country',points,'-reset');
      for(let cycle=0;cycle<3;cycle++){
        for(const mode of ['1','2','']){
          if(admin)await page.locator('[data-provider-select]').selectOption(mode);else await page.locator(`[data-provider="${mode}"]`).click();
          const matching=points.filter(p=>!mode||p.provider_id===Number(mode)),detail=matching.find(p=>p.province==='اصفهان');
          await page.evaluate(p=>window.testMaps[0].setView([p.latitude,p.longitude],16,{animate:false}),detail);
          await verify(mode,'detailed',[detail],'-cycle-'+cycle);
          await page.evaluate(frame=>window.testMaps[0].setView(frame.center,frame.zoom,{animate:false}),frame);
          await verify(mode,'country',matching,'-cycle-'+cycle);
        }
      }
      if(admin){await expect(page.locator('[data-provider-select]')).toHaveValue('');await expect(page.locator('.directory-table')).toContainText('Address only');}
      else{await expect(page.locator('.branch-card')).toHaveCount(rows.length);const address=page.locator('.branch-card').filter({hasText:'Address only'});await expect(address.locator('[data-point]')).toHaveCount(0);}
      fixtureRows=[1,2,3].map((provider_id,index)=>({...points[0],id:300+index,provider_id,name:'Coincident '+provider_id}));
      await page.reload();
      await verify('','country',fixtureRows,'-coincident');
      await page.evaluate(()=>window.testMaps[0].setView([32.65,51.67],18,{animate:false}));
      await verify('','detailed',fixtureRows,'-coincident');
      await page.locator('.map-badge').click();await expect(page.locator('[data-cluster-detail]')).toHaveCount(3);
      await page.locator('[data-cluster-detail="300"]').click();await expect(page.locator('.detail-body h3')).toHaveText('Coincident 1');await page.keyboard.press('Escape');
      evidence.push({page:prefix,width,theme,coincidentCoordinates:{charts:1,zoom:18,listRecords:3}});
      fixtureRows=[...fixtureRows,{...fixtureRows[0],id:303,name:'Coincident Post 2'}];
      if(admin)await page.locator('[data-provider-select]').selectOption('1');else await page.locator('[data-provider="1"]').click();
      await verify('1','detailed',fixtureRows.filter(p=>p.provider_id===1),'-coincident-filtered');
      await page.locator('.map-badge').click();await expect(page.locator('[data-cluster-detail]')).toHaveCount(2);await page.keyboard.press('Escape');
      fixtureRows=rows;
      if(theme==='dark')for(const stressWidth of width===390?[390,320]:[width]){
        // One synthetic record per real province exercises dense country layout.
        // Interior fixture coordinates are derived only for this network test.
        const features=[...new Map(geometry.features.map(f=>[f.properties.shapeName,f])).values()];
        fixtureRows=features.map((feature,i)=>{
          const polygons=feature.geometry.type==='MultiPolygon'?feature.geometry.coordinates:[feature.geometry.coordinates];
          const coords=polygons.flatMap(r=>r[0]),xs=coords.map(p=>p[0]),ys=coords.map(p=>p[1]);
          const west=Math.min(...xs),east=Math.max(...xs),south=Math.min(...ys),north=Math.max(...ys),center={longitude:(west+east)/2,latitude:(south+north)/2};
          let point=center;
          if(!contains(point,feature)){
            const candidates=[];
            for(let y=1;y<20;y++)for(let x=1;x<20;x++){const p={longitude:west+(east-west)*x/20,latitude:south+(north-south)*y/20};if(contains(p,feature))candidates.push(p);}
            candidates.sort((a,b)=>Math.hypot(a.latitude-center.latitude,a.longitude-center.longitude)-Math.hypot(b.latitude-center.latitude,b.longitude-center.longitude));point=candidates[0];
          }
          expect(point).toBeTruthy();
          return {...points[0],...point,id:200+i,province:feature.properties.shapeName,provider_id:i%3+1};
        });
        await page.setViewportSize({width:stressWidth,height:1000});
        await page.goto(admin?'http://badges.test/wp-admin/admin.php?page=tapin-locator&stress='+stressWidth+'#dashboard':pageUrl+'&stress='+stressWidth);
        await expect(page.locator('.marker-status')).toContainText(fixtureRows.length.toLocaleString('fa-IR'));
        await expect(page.locator('.province-aggregate')).toHaveCount(features.length);
        const markers=await snapshot();
        for(const m of markers)for(const e of m.edge)if(!geometry.features.some(f=>contains({latitude:e.lat,longitude:e.lng},f))){
          await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`all-provinces-failed-${stressWidth}.png`)});
          fs.writeFileSync(path.join(artifacts,'all-provinces-failed.json'),JSON.stringify(markers,null,2));
          throw Error('All-province badge edge outside Iran: '+JSON.stringify({width:stressWidth,marker:m,edge:e}));
        }
        for(let i=0;i<markers.length;i++)for(let j=i+1;j<markers.length;j++)expect(Math.hypot(markers[i].center.x-markers[j].center.x,markers[i].center.y-markers[j].center.y)).toBeGreaterThanOrEqual(22);
        await verifyLayers();await expect(page.locator('.branch-pin,.provider-pin-image')).toHaveCount(0);
        await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`${prefix}-all-provinces-${stressWidth}.png`)});
        evidence.push({page:prefix,width:stressWidth,theme,provider:'all',zoom:'country-all-provinces',markers});
      }
      const finalAssets=await Promise.all(assetResponses);for(const asset of finalAssets){const relative=new URL(asset.url).pathname.slice(1);expect(asset.hash).toBe(crypto.createHash('sha256').update(fs.readFileSync(path.join(root,relative))).digest('hex'));}
      evidence.push({page:prefix,width,theme,loadedAssets:finalAssets,layerCycles:3,errors});
      expect(errors).toEqual([]);console.log(`PASS ${prefix} ${theme} ${width}px: all provider modes, three zooms, click, reset, three repeated cycles, coincident picker and runtime asset parity.`);await page.close();
    }
    fs.writeFileSync(path.join(artifacts,'results'+(process.env.TAPIN_TEST_SURFACE?'-'+process.env.TAPIN_TEST_SURFACE:'')+'.json'),JSON.stringify(evidence,null,2));
    console.log('PASS map-marker-badges: actual admin dashboard and public renderer/assets SHA256, uniform number-free pies/proportions for all provider filters, separated provider-logo location pins at stored coordinates, zoom/click/reset and repeated cycles without duplicate layers, separate province groups, inland collision-free charts, accessible counts; three zooms, desktop/mobile light/dark. Network fixtures, not real WordPress.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
