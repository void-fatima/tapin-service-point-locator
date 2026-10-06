/* Public badge regression with real assets/geometry and isolated network fixtures.
   Run: node tests/public-marker-badges.cjs. No WordPress records are changed. */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs'), path = require('path');
const root = path.resolve(__dirname, '..');
const geometry = JSON.parse(fs.readFileSync(path.join(root, 'assets/iran-provinces.geojson'), 'utf8'));
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
const artifacts = path.join(root,'artifacts/public-marker-badges');
const palette = {1:'#ffbd18',2:'#00ba88',3:'#dc3448'};
const digits = text => Number(text.replace(/[۰-۹]/g,ch=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(ch)));

(async()=>{
  for(const p of points)expect(geometry.features.some(f=>contains(p,f))).toBe(true);
  fs.mkdirSync(artifacts,{recursive:true});
  const chrome=process.env.TAPIN_CHROME_PATH||'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const browser=await chromium.launch({headless:true,...(fs.existsSync(chrome)?{executablePath:chrome}:{})});
  const evidence=[];
  try{
    for(const width of [1440,390])for(const theme of ['dark','light']){
      const page=await browser.newPage({viewport:{width,height:1000}}),errors=[];
      let fixtureRows=rows;
      page.on('pageerror',e=>errors.push(e.message));
      await page.addInitScript(theme=>localStorage.setItem('tapin-color-scheme',theme),theme);
      await page.route('**/*',async route=>{
        const url=new URL(route.request().url()),q=url.searchParams;
        if(url.pathname.startsWith('/api/')){
          const endpoint=url.pathname.slice(5);
          if(endpoint==='public/filters')return route.fulfill({json:{providers,locations:points.map(p=>({province:p.province,city:p.city,provider_id:p.provider_id}))}});
          if(/^public\/points\/\d+$/.test(endpoint))return route.fulfill({json:fixtureRows.find(p=>p.id===Number(endpoint.split('/').pop()))});
          let selected=fixtureRows.filter(p=>!q.get('provider_id')||p.provider_id===Number(q.get('provider_id')));
          if(q.get('has_coordinates')==='1')selected=selected.filter(p=>p.has_coordinates&&p.latitude>=Number(q.get('south')||-90)&&p.latitude<=Number(q.get('north')||90)&&p.longitude>=Number(q.get('west')||-180)&&p.longitude<=Number(q.get('east')||180));
          return route.fulfill({json:{items:selected,total:selected.length,page:1,total_pages:1}});
        }
        if(url.pathname==='/fixture/cargo.svg')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><rect width="32" height="32" fill="#30579a"/><path d="M8 8h16v16H8z" fill="white"/></svg>'});
        if(url.pathname.startsWith('/assets/'))return route.fulfill({body:fs.readFileSync(path.join(root,url.pathname.slice(1))),contentType:({'.js':'application/javascript; charset=utf-8','.css':'text/css','.geojson':'application/json','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2'})[path.extname(url.pathname)]||'application/octet-stream'});
        if(url.pathname.startsWith('/tiles/'))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"></svg>'});
        return route.fulfill({contentType:'text/html; charset=utf-8',body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/leaflet.css"><link rel="stylesheet" href="/assets/app.css"><link rel="stylesheet" href="/assets/theme.css"></head><body class="tapin-locator-page"><div class="tapin-app tapin-public" dir="rtl"><div class="tapin-public-root"></div></div><script>window.TapinConfig={api:"/api/",assets:"/assets/",tiles:"/tiles/{z}/{x}/{y}"};</script><script src="/assets/vendor/leaflet.js"></script><script>window.testMaps=[];window.testMarkers=[];L.Map.addInitHook(function(){window.testMaps.push(this)});L.Marker.addInitHook(function(){window.testMarkers.push(this)});</script><script src="/assets/iran-locations.js"></script><script src="/assets/theme.js"></script><script src="/assets/map.js"></script></body></html>'});
      });
      await page.goto('http://badges.test/?page_id=22');
      await expect(page.locator('.marker-status')).toContainText(points.length.toLocaleString('fa-IR'));
      const frame=await page.evaluate(()=>({center:window.testMaps[0].getCenter(),zoom:window.testMaps[0].getZoom()}));
      const snapshot=()=>page.evaluate(()=>{
        const map=window.testMaps[0],box=map.getContainer().getBoundingClientRect();
        return window.testMarkers.filter(m=>map.hasLayer(m)&&m.getElement()?.classList.contains('public-map-badge')).map(m=>{
          const el=m.getElement(),rect=el.getBoundingClientRect(),chart=el.querySelector('.public-marker-chart'),logo=el.querySelector('.public-marker-logo');
          const center=L.point(rect.x+rect.width/2-box.x,rect.y+rect.height/2-box.y),radius=11;
          return {position:m.getLatLng(),province:el.dataset.markerProvince,label:el.getAttribute('aria-label'),tooltip:m.getTooltip().getContent().textContent,text:el.textContent,chart:chart?.getAttribute('style'),logo:logo?.dataset.providerSlug,images:[...el.querySelectorAll('img')].map(i=>i.getAttribute('src')),size:m.options.icon.options.iconSize,border:getComputedStyle(chart||logo).border,center:{x:center.x,y:center.y},edge:Array.from({length:8},(_,i)=>map.containerPointToLatLng([center.x+radius*Math.cos(i*Math.PI/4),center.y+radius*Math.sin(i*Math.PI/4)]))};
        });
      });
      const verify=async(mode,zoom,expected)=>{
        await expect(page.locator('.marker-status')).toContainText(expected.length.toLocaleString('fa-IR'));
        await expect(page.locator('.public-map-badge')).toHaveCount(zoom==='country'?new Set(expected.map(p=>p.province)).size:1);
        const markers=await snapshot();
        await expect(page.locator('.public-map-badge .cluster-count,.public-map-badge b')).toHaveCount(0);
        for(const marker of markers){
          expect(marker.size).toEqual([20,20]);expect(marker.text).toBe('');
          expect(geometry.features.some(f=>contains({latitude:marker.position.lat,longitude:marker.position.lng},f))).toBe(true);
          for(const edge of marker.edge)if(!geometry.features.some(f=>contains({latitude:edge.lat,longitude:edge.lng},f))){
            await page.locator('.tapin-map').screenshot({path:path.join(artifacts,'failed-layout.png')});
            fs.writeFileSync(path.join(artifacts,'failed-layout.json'),JSON.stringify(await page.evaluate(()=>({size:window.testMaps[0].getSize(),markers:window.testMarkers.filter(m=>window.testMaps[0].hasLayer(m)&&m.getElement()?.classList.contains('public-map-badge')).map(m=>({label:m.getElement().getAttribute('aria-label'),anchor:m.options.icon.options.iconAnchor,style:m.getElement().getAttribute('style'),position:m.getLatLng()}))})),null,2));
            throw Error('Badge edge outside Iran: '+JSON.stringify({width,theme,mode,zoom,marker,edge}));
          }
          const members=zoom==='country'?expected.filter(p=>p.province===marker.province):expected;
          const countMatch=marker.tooltip.match(/([۰-۹]+) شعبه:/);expect(digits(countMatch[1])).toBe(members.length);
          if(mode){
            expect(marker.chart).toBeUndefined();expect(marker.logo).toBe(providers.find(p=>String(p.id)===mode).slug);expect(marker.images.length).toBe(1);
          }else{
            expect(marker.logo).toBeUndefined();expect(marker.images).toEqual([]);let start=0;
            for(const provider of providers){const count=members.filter(p=>p.provider_id===provider.id).length;if(!count)continue;const end=start+count/members.length*100;expect(marker.chart).toContain(palette[provider.id]+' '+start+'% '+end+'%');expect(marker.tooltip).toContain(count.toLocaleString('fa-IR')+' '+(provider.id===3?'سایر':provider.name));start=end;}
          }
        }
        for(let i=0;i<markers.length;i++)for(let j=i+1;j<markers.length;j++)expect(Math.hypot(markers[i].center.x-markers[j].center.x,markers[i].center.y-markers[j].center.y)).toBeGreaterThanOrEqual(22);
        expect(new Set(markers.map(m=>m.border)).size).toBe(1);
        for(const image of await page.locator('.public-map-badge img').all())await expect.poll(()=>image.evaluate(i=>i.naturalWidth)).toBeGreaterThan(0);
        const marker=page.locator('.public-map-badge').first();await marker.focus();await expect(page.locator('.leaflet-tooltip')).toContainText('شعبه');await marker.evaluate(el=>el.blur());
        await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`${theme}-${width}-${mode||'all'}-${zoom}.png`)});
        evidence.push({width,theme,provider:mode||'all',zoom,markers});
      };
      for(const mode of ['','1','2','3']){
        await page.evaluate(frame=>window.testMaps[0].setView(frame.center,frame.zoom,{animate:false}),frame);
        await page.locator(`[data-provider="${mode}"]`).click();
        const matching=points.filter(p=>!mode||p.provider_id===Number(mode));
        await verify(mode,'country',matching);
        await page.evaluate(()=>window.testMaps[0].setView([32.65,51.74],9,{animate:false}));
        await verify(mode,'intermediate',matching.filter(p=>p.province==='اصفهان'));
        if(mode===''){
          const z=await page.evaluate(()=>window.testMaps[0].getZoom());await page.locator('.public-map-badge').press('Enter');await expect.poll(()=>page.evaluate(()=>window.testMaps[0].getZoom())).toBeGreaterThan(z);
        }
        const detail=matching.find(p=>p.province==='اصفهان');
        await page.evaluate(p=>window.testMaps[0].setView([p.latitude,p.longitude],16,{animate:false}),detail);
        await verify(mode,'detailed',[detail]);
        const active=(await snapshot())[0];expect(active.position.lat).toBe(detail.latitude);expect(active.position.lng).toBe(detail.longitude);
        await page.locator('.public-map-badge').click();await expect(page.locator('.detail-body h3')).toHaveText(detail.name);await page.keyboard.press('Escape');
      }
      await page.locator('[data-clear]').click();await expect(page.locator('.branch-card')).toHaveCount(rows.length);
      const address=page.locator('.branch-card').filter({hasText:'Address only'});await expect(address.locator('[data-point]')).toHaveCount(0);
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
        await page.goto('http://badges.test/stress');
        await expect(page.locator('.marker-status')).toContainText(fixtureRows.length.toLocaleString('fa-IR'));
        await expect(page.locator('.province-aggregate')).toHaveCount(features.length);
        const markers=await snapshot();
        for(const m of markers)for(const e of m.edge)if(!geometry.features.some(f=>contains({latitude:e.lat,longitude:e.lng},f))){
          await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`all-provinces-failed-${stressWidth}.png`)});
          fs.writeFileSync(path.join(artifacts,'all-provinces-failed.json'),JSON.stringify(markers,null,2));
          throw Error('All-province badge edge outside Iran: '+JSON.stringify({width:stressWidth,marker:m,edge:e}));
        }
        for(let i=0;i<markers.length;i++)for(let j=i+1;j<markers.length;j++)expect(Math.hypot(markers[i].center.x-markers[j].center.x,markers[i].center.y-markers[j].center.y)).toBeGreaterThanOrEqual(22);
        await page.locator('.tapin-map').screenshot({path:path.join(artifacts,`all-provinces-${stressWidth}.png`)});
        evidence.push({width:stressWidth,theme,provider:'all',zoom:'country-all-provinces',markers});
      }
      expect(errors).toEqual([]);await page.close();
    }
    fs.writeFileSync(path.join(artifacts,'results.json'),JSON.stringify(evidence,null,2));
    console.log('PASS public-marker-badges: fixed circular charts/proportions, no numbers, selected-provider-only logos, separate provincial groups, collision-free in-Iran badge layout, exact tooltips, click/keyboard, stored coordinates and directory preservation at three zoom levels in desktop/mobile dark/light.');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
