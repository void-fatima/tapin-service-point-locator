/* global L, TapinConfig */
(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const num = value => Number(value || 0).toLocaleString('fa-IR');
  async function api(path, options = {}) {
    const headers = { ...(TapinConfig.nonce ? {'X-WP-Nonce': TapinConfig.nonce} : {}), ...options.headers };
    if (options.body && !(options.body instanceof FormData)) { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
    const response = await fetch(TapinConfig.api + path, {credentials:'same-origin', ...options, headers});
    let data;
    try { data = await response.json(); } catch { throw new Error('پاسخ سرور قابل خواندن نیست. اتصال و تنظیمات وردپرس را بررسی کنید.'); }
    if (!response.ok) throw new Error(data.message || 'ارتباط با سرور ناموفق بود. دوباره تلاش کنید.');
    return data;
  }
  const color = p => /^#[0-9a-f]{6}$/i.test(p?.color) ? p.color : '#b6a4e8';
  const safeUrl = value => { try { const url = new URL(value, location.href); return /https?:/.test(url.protocol) ? esc(url.href) : ''; } catch { return ''; } };
  const badge = p => `<span class="provider-badge"><span class="provider-symbol" style="--provider:${color(p)}">${p?.logo ? `<img src="${safeUrl(p.logo)}" alt="" loading="lazy">` : '<i></i>'}</span>${esc(p?.name || 'سایر')}</span>`;
  const providerOptions = providers => providers.map(p => `<option value="${Number(p.id)}">${esc(p.name)}${Number(p.is_active) ? '' : ' (غیرفعال)'}</option>`).join('');
  function exportControl(container,getFilters){
    const section=document.createElement('div');section.className='export-controls';
    section.innerHTML='<button type="button" data-export><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg><span>خروجی اکسل</span></button><small>همه نتایج فیلترهای اعمال‌شده، نه فقط صفحه فعلی</small><span role="status" aria-live="polite"></span>';
    container.append(section);const button=section.querySelector('button'),message=section.querySelector('[role=status]');
    button.onclick=async()=>{
      button.disabled=true;message.textContent='در حال آماده‌سازی خروجی…';
      const filters={};const current=new URLSearchParams(getFilters());
      ['provider_id','province','city','search','status','issue','has_coordinates'].forEach(key=>{if(current.has(key))filters[key]=current.get(key);});
      try{
        const response=await fetch(TapinConfig.api+'exports/points',{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':TapinConfig.nonce,'Content-Type':'application/json'},body:JSON.stringify(filters)});
        if(!response.ok||!response.headers.get('Content-Type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))throw Error();
        const blob=await response.blob();if(!blob.size)throw Error();
        const filename=response.headers.get('Content-Disposition')?.match(/filename="(tapin-service-points-\d{4}-\d{2}-\d{2}\.xlsx)"/)?.[1];
        const url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=filename||'tapin-service-points.xlsx';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),30000);
        message.textContent='فایل آماده شد و به مرورگر تحویل داده شد.';
      }catch(e){message.textContent='دریافت خروجی اکسل انجام نشد. فیلترها را محدودتر کنید یا صفحه را تازه کنید و دوباره تلاش کنید.';}
      finally{button.disabled=false;}
    };
  }
  const provinceNames = {'Mazandaran':'مازندران','North Khorasan':'خراسان شمالی','Kerman':'کرمان','Ilam':'ایلام','Lorestan':'لرستان','Markazi':'مرکزی','Chaharmahal and Bakhtiari':'چهارمحال و بختیاری','Kermanshah':'کرمانشاه','Hamadan':'همدان','Qazvin':'قزوین','Gilan':'گیلان','Zanjan':'زنجان','Semnan':'سمنان','Isfahan':'اصفهان','Kohgiluyeh and Boyer-Ahmad':'کهگیلویه و بویراحمد','Kurdistan':'کردستان','West Azerbaijan':'آذربایجان غربی','Fars':'فارس','Bushehr':'بوشهر','Ardabil':'اردبیل','Golestan':'گلستان','Razavi Khorasan':'خراسان رضوی','South Khorasan':'خراسان جنوبی','Sistan and Baluchestan':'سیستان و بلوچستان','Qom':'قم','Alborz':'البرز','East Azerbaijan':'آذربایجان شرقی','Yazd':'یزد','Hormozgan':'هرمزگان','Khuzestan':'خوزستان','Tehran':'تهران'};
  // Shared numbered pager: dashboard directory and points table use the same markup.
  function paginationBar(currentPage,totalPages,totalItems,label){
    const aria=label||'صفحه‌بندی';
    if(totalPages<=1)return `<div class="pagination"><div class="pagination-info">کل نتایج: ${num(totalItems)} نقطه</div></div>`;
    const cur=Number(currentPage);
    const delta=2;const range=[];
    for(let i=Math.max(2,cur-delta);i<=Math.min(totalPages-1,cur+delta);i++)range.push(i);
    if(cur-delta>2)range.unshift('...');
    if(cur+delta<totalPages-1)range.push('...');
    range.unshift(1);
    range.push(totalPages);
    const pagesHtml=range.map(p=>{
      if(p==='...')return '<span class="pagination-ellipsis">…</span>';
      const isCur=Number(p)===cur;
      return `<button type="button" class="pagination-num ${isCur?'active':''}" data-page="${p}" ${isCur?'aria-current="page" disabled':''}>${num(p)}</button>`;
    }).join('');
    const arrow=inner=>`<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${inner}</svg>`;
    const one=d=>arrow(`<polyline points="${d}"/>`);
    const double=(a,z)=>arrow(`<polyline points="${a}"/><polyline points="${z}"/>`);
    return `<div class="pagination" role="navigation" aria-label="${esc(aria)}">
      <div class="pagination-controls">
        <button type="button" class="pagination-btn pagination-first" data-page="1" ${cur<=1?'disabled':''} aria-label="صفحه اول" title="صفحه اول">${double('13 17 18 12 13 7','6 17 11 12 6 7')}</button>
        <button type="button" class="pagination-btn pagination-prev" data-page="${cur-1}" ${cur<=1?'disabled':''} aria-label="صفحه قبلی" title="صفحه قبلی">${one('15 18 9 12 15 6')}<span>قبلی</span></button>
        <div class="pagination-pages">${pagesHtml}</div>
        <button type="button" class="pagination-btn pagination-next" data-page="${cur+1}" ${cur>=totalPages?'disabled':''} aria-label="صفحه بعدی" title="صفحه بعدی"><span>بعدی</span>${one('9 18 15 12 9 6')}</button>
        <button type="button" class="pagination-btn pagination-last" data-page="${totalPages}" ${cur>=totalPages?'disabled':''} aria-label="صفحه آخر" title="صفحه آخر">${double('11 17 6 12 11 7','18 17 13 12 18 7')}</button>
      </div>
      <div class="pagination-info">صفحه ${num(cur)} از ${num(totalPages)} · کل ${num(totalItems)} نقطه خدماتی</div>
    </div>`;
  }
  const normalize = value => String(value).replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s‌]/g,'');
  let widgetSequence=0;
  const validCoordinates = p => p.has_coordinates && p.latitude!==null && p.longitude!==null && p.latitude!=='' && p.longitude!=='' && Number.isFinite(Number(p.latitude)) && Number.isFinite(Number(p.longitude)) && Math.abs(Number(p.latitude))<=90 && Math.abs(Number(p.longitude))<=180;
  function insideRing(x,y,ring){
    let inside=false;
    for(let i=0,j=ring.length-1;i<ring.length;j=i++){
      const [xi,yi]=ring[i],[xj,yj]=ring[j];
      if(((yi>y)!==(yj>y))&&(x<(xj-xi)*(y-yi)/(yj-yi)+xi))inside=!inside;
    }
    return inside;
  }
  function insideGeometry(p,geometry){
    const polygons=geometry.type==='MultiPolygon'?geometry.coordinates:[geometry.coordinates];
    return polygons.some(rings=>insideRing(Number(p.longitude),Number(p.latitude),rings[0])&&!rings.slice(1).some(ring=>insideRing(Number(p.longitude),Number(p.latitude),ring)));
  }
  function mapWidget(container, providers, locations, admin = false, onSummary = () => {}) {
    const detailId='tapin-detail-'+(++widgetSequence);
    container.dataset.view='map';
    container.innerHTML = `<div class="map-tools"><div class="provider-tabs" role="group" aria-label="ارائه‌دهنده"><button type="button" class="selected" aria-pressed="true" data-provider="">همه ارائه‌دهندگان</button>${providers.map(p => `<button type="button" aria-pressed="false" data-provider="${Number(p.id)}">${badge(p)}</button>`).join('')}</div><div class="map-selects"><label><span>استان</span><select data-province autocomplete="off"><option value="">همه استان‌ها</option></select></label><label><span>شهر</span><select data-city autocomplete="off"><option value="">همه شهرها</option></select></label><button type="button" data-reset title="نمایش سراسر ایران" aria-label="نمایش سراسر ایران"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="1.5"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/></svg><span>سراسر ایران</span></button></div></div>
      <form class="locator-search" role="search" autocomplete="off"><label><span class="sr-only">جستجوی شعبه، شهر، استان یا ارائه‌دهنده</span><input name="search" type="search" autocomplete="off" placeholder="نام شعبه، شهر یا ارائه‌دهنده…"></label><button type="submit" title="جستجو"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg><span>جستجو</span></button><button type="button" data-clear title="پاک کردن فیلترها"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg><span>پاک کردن فیلترها</span></button></form>
      <div class="locator-view-switch" role="group" aria-label="شیوه نمایش" ${admin?'hidden':''}><button type="button" data-view="map" aria-pressed="true">نقشه</button><button type="button" data-view="list" aria-pressed="false">فهرست نشانی‌ها</button></div>
      <div class="locator-results"><section class="map-viewport" aria-label="نقشه و راهنما"><div class="tapin-map" role="region" aria-label="نقشه نقاط خدماتی؛ با کلیدهای جهت حرکت کنید" tabindex="0"></div><div class="map-legend"></div></section>
      <section class="directory-panel" aria-label="فهرست نقاط خدماتی"><div class="map-status" role="status"></div><p class="directory-hint">فهرست شامل همه نتایج فیلترهاست؛ نقشه فقط نقاط دارای مختصات در محدوده دیده‌شده را نشان می‌دهد.</p><div class="map-list" hidden></div><div class="map-actions"><button type="button" data-retry hidden><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg><span>تلاش دوباره</span></button><button type="button" data-more hidden><span>نمایش نقاط بیشتر</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button><button type="button" data-list ${admin?'':'hidden'}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg><span>فهرست قابل دسترس نقاط</span></button></div></section></div>`;
    if(admin){
      const tools=container.querySelector('.map-tools');
      const title=document.createElement('button');title.type='button';title.className='filter-title';title.innerHTML='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg><span>فیلترها</span>';title.setAttribute('aria-label','اعمال فیلترها');title.setAttribute('title','اعمال فیلترها');tools.prepend(title);
      const select=document.createElement('select');select.dataset.providerSelect='';select.setAttribute('aria-label','ارائه‌دهنده');select.setAttribute('autocomplete','off');select.innerHTML='<option value="">همه ارائه‌دهندگان</option>'+providerOptions(providers);tools.querySelector('.provider-tabs').hidden=true;tools.append(select);
      tools.append(container.querySelector('.map-selects'),container.querySelector('.locator-search'));
      select.onchange=()=>{selected=select.value;refreshFilters();};
      title.onclick=()=>{clearTimeout(searchTimer);const s=container.querySelector('.locator-search input[name=search]');if(s)search=s.value.trim();selected=select.value;refreshFilters();};
      const directoryPanel=container.querySelector('.directory-panel');
      const hero=container.closest('.dashboard-hero');
      if(hero&&directoryPanel){hero.after(directoryPanel);directoryPanel.classList.add('panel','dashboard-directory-panel');}
    }
    const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const map = L.map(container.querySelector('.tapin-map'), {preferCanvas:false, scrollWheelZoom:false, zoomControl:false, zoomSnap:0.1, minZoom:3, maxZoom:19,zoomAnimation:false,fadeAnimation:!reducedMotion,markerZoomAnimation:!reducedMotion});
    const iran = [[24.6,43.5],[40.2,63.5]];
    map.fitBounds(iran, {padding:[12,12]});
    L.control.zoom({position:admin?'topright':'bottomleft',zoomInTitle:'بزرگ‌نمایی',zoomOutTitle:'کوچک‌نمایی'}).addTo(map);
    L.control.scale({imperial:false,position:'bottomleft'}).addTo(map);
    const tile = L.tileLayer(TapinConfig.tiles, {attribution:TapinConfig.attribution,maxZoom:19}).addTo(map);
    map.getPane('tilePane').style.opacity='0';
    let tileFailed = false;
    tile.on('tileerror', () => { if (!tileFailed) { tileFailed = true; const note = document.createElement('p'); note.className='tile-warning'; note.textContent='تصاویر زمینه نقشه بارگذاری نشد؛ اتصال اینترنت را بررسی کنید. فهرست نقاط در دسترس است.'; container.append(note); } });
    let geoCancelled = false;
    const provinceLayers = new Map();
    const countryLabels=[];
    function declutterCountryLabels(){
      if(!map._loaded||geoCancelled)return;
      const boxes=[];
      countryLabels.forEach(label=>{
        const el=label.getElement();
        if(!el)return;
        try{
          const point=map.latLngToContainerPoint(label.getLatLng()),width=Math.max(45,Math.min(150,el.textContent.length*7));
          const box={x:point.x-width/2,y:point.y-10,w:width,h:22};
          const overlap=boxes.some(b=>box.x<b.x+b.w&&box.x+box.w>b.x&&box.y<b.y+b.h&&box.y+box.h>b.y);
          el.style.visibility=overlap?'hidden':'visible';
          if(!overlap)boxes.push(box);
        }catch(e){}
      });
    }
    map.on('moveend',declutterCountryLabels);
    map.getPane('tilePane').style.filter='invert(1) hue-rotate(185deg) brightness(.6) saturate(.35)';
    map.createPane('country-context');map.getPane('country-context').style.zIndex='351';
    fetch(TapinConfig.assets+'neighbor-countries.geojson').then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{
      if(geoCancelled)return;
      L.geoJSON(data,{pane:'country-context',interactive:false,style:{color:'#697283',weight:1,opacity:.4,fill:false}}).addTo(map);
      data.features.forEach(f=>countryLabels.push(L.marker(f.properties.label,{interactive:false,keyboard:false,icon:L.divIcon({className:'country-label',html:esc(f.properties.name),iconSize:[100,20]})}).addTo(map)));
      declutterCountryLabels();
      map.attributionControl.addAttribution('<a href="https://www.naturalearthdata.com/">Natural Earth</a>');
    }).catch(()=>{if(!geoCancelled){const note=document.createElement('p');note.className='tile-warning';note.textContent='مرز کشورهای پیرامون بارگذاری نشد. برای تلاش دوباره صفحه را تازه کنید.';container.append(note);}});
    map.createPane('boundaries');map.getPane('boundaries').style.zIndex='350';
    let iranGeometry=null;
    const geographyReady=fetch(TapinConfig.assets+'iran-provinces.geojson').then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{if(data && !geoCancelled) {
      iranGeometry=data.features;
      const holes=[];
      data.features.forEach(feature=>{
        const polygons=feature.geometry.type==='MultiPolygon'?feature.geometry.coordinates:[feature.geometry.coordinates];
        polygons.forEach(polygon=>holes.push(polygon[0].map(([lng,lat])=>[lat,lng])));
      });
      // Opaque exterior prevents foreign cities/capitals baked into raster tiles leaking through.
      L.polygon([[[-85,-180],[-85,180],[85,180],[85,-180]],...holes],{pane:'boundaries',interactive:false,stroke:false,fillColor:'#08192b',fillOpacity:1,fillRule:'evenodd'}).addTo(map);
      map.getPane('tilePane').style.opacity='1';
      map.createPane('provinces');map.getPane('provinces').style.zIndex='352';
      L.geoJSON(data,{pane:'provinces',interactive:true,style:feature=>({className:'tapin-province-boundary',color:'#67d9ff',opacity:.85,weight:1,fillColor:['#087ac0','#155bd2','#5140c4','#008c9a'][Object.keys(provinceNames).indexOf(feature.properties.shapeName)%4],fillOpacity:.32}),onEachFeature:(feature, polygon)=>{
        const name=provinceNames[feature.properties.shapeName];
        if(name){polygon.on('mouseover',()=>polygon.setStyle({fillOpacity:.4,weight:2}));polygon.on('mouseout',()=>{polygon.setStyle({fillOpacity:.22});updateLegend();});polygon.on('add',()=>{const path=polygon.getElement();if(path){path.setAttribute('tabindex','0');path.setAttribute('role','button');path.setAttribute('aria-label',name);path.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();polygon.fire('click');}});}});provinceLayers.set(normalize(name),polygon);polygon.bindTooltip(name,{direction:'center'});polygon.on('click',()=>{
          const option=[...province.options].find(o=>normalize(o.value)===normalize(name));
          if(!option){province.add(new Option(name,name));}
          province.value=option?option.value:name;province.onchange();
        });}
      }}).addTo(map);
      map.attributionControl.addAttribution('<a href="https://www.geoboundaries.org/">geoBoundaries</a> / OSM');
      if(province.value)map.fitBounds(provinceLayers.get(normalize(province.value))?.getBounds()||iran);
      updateLegend();if(items.length)renderDirectory();
    }}).catch(()=>{if(!geoCancelled){const note=document.createElement('p');note.className='tile-warning';note.setAttribute('role','alert');note.textContent='مرز استان‌ها بارگذاری نشد؛ فهرست نشانی‌ها در دسترس است. برای بازیابی نقشه صفحه را تازه کنید.';container.append(note);}});
    const layer = L.layerGroup().addTo(map);
    const directoryPanel = (container.closest('#tapin-content')||container.parentElement||document).querySelector('.directory-panel')||container.querySelector('.directory-panel');
    const status = directoryPanel.querySelector('.map-status');
    const more = directoryPanel.querySelector('[data-more]');
    const retry = directoryPanel.querySelector('[data-retry]');
    const listToggle = directoryPanel.querySelector('[data-list]');
    const mapList = directoryPanel.querySelector('.map-list');
    retry.className='locator-retry';container.querySelector('.locator-search').after(retry);
    const province = container.querySelector('[data-province]');
    const city = container.querySelector('[data-city]');
    province.value = '';
    city.value = '';
    city.innerHTML='<option value="">همه شهرها</option>'+[...new Set(locations.map(l=>l.city))].map(c=>`<option>${esc(c)}</option>`).join('');
    let selected = '', search = '', page = 1, generation = 0, controller, items = [];
    const markers = new Map();
    const drawer=document.createElement(admin?'div':'dialog');drawer.className='tapin-detail';drawer.dir='rtl';drawer.setAttribute('aria-labelledby',detailId);drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal',String(!admin));
    if(admin)drawer.hidden=true;
    drawer.innerHTML=`<header><h2 id="${detailId}">اطلاعات نقطه خدماتی</h2><button type="button" data-close aria-label="بستن اطلاعات شعبه">×</button></header><div class="detail-body"></div>`;
    container.append(drawer);
    let detailOpener=null,detailController,detailGeneration=0;
    const restoreDetailFocus=()=>{if(detailOpener?.isConnected&&detailOpener.getClientRects().length)detailOpener.focus();else (container.querySelector('[data-provider-select]')||container.querySelector('[data-provider].selected')||container.querySelector('.tapin-map'))?.focus();};
    function closeDetails(){detailGeneration++;detailController?.abort();drawer.removeAttribute('aria-busy');if(drawer.close){drawer.close();}else{drawer.hidden=true;restoreDetailFocus();}}
    drawer.querySelector('[data-close]').onclick=closeDetails;
    drawer.addEventListener('cancel',e=>{e.preventDefault();closeDetails();});
    drawer.addEventListener('keydown',e=>{
      if(e.key==='Escape'){e.preventDefault();e.stopPropagation();closeDetails();return;}
      if(e.key!=='Tab')return;
      const controls=[...drawer.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]')].filter(el=>el.getClientRects().length);
      const first=controls[0],last=controls[controls.length-1];
      if((e.shiftKey&&document.activeElement===first)||(!e.shiftKey&&document.activeElement===last)){e.preventDefault();(e.shiftKey?last:first)?.focus();}
    });
    drawer.addEventListener('click',e=>{if(e.target===drawer){const box=drawer.getBoundingClientRect();if(e.clientX<box.left||e.clientX>box.right||e.clientY<box.top||e.clientY>box.bottom)closeDetails();}});
    drawer.addEventListener('close',restoreDetailFocus);
    function renderDetails(points){
      drawer.querySelector('.detail-body').innerHTML=points.map(p=>{
        const hasCoords=validCoordinates(p);
        const navUrl=hasCoords?`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(p.latitude+','+p.longitude)}`:'';
        return `<article class="tapin-popup">${badge(providers.find(pr=>Number(pr.id)===Number(p.provider_id)))}<h3>${esc(p.name)}</h3>${p.province||p.city?'<p class="detail-location">'+[p.province,p.city].filter(Boolean).map(esc).join('، ')+'</p>':''}${details(p)}${!hasCoords?'<p class="coordinate-note">موقعیت روی نقشه هنوز در دسترس نیست · نشانی متنی</p>':`<div class="drawer-actions"><a class="directions-btn" href="${navUrl}" target="_blank" rel="noopener noreferrer">مسیریابی روی نقشه ↗</a></div>`}</article>`;
      }).join('');
    }
    async function openDetails(points,opener=document.activeElement){
      if(!drawer.contains(opener))detailOpener=opener;
      detailController?.abort();detailController=new AbortController();const token=++detailGeneration;
      drawer.querySelector('.detail-body').innerHTML='<p role="status">در حال دریافت اطلاعات شعبه…</p>';
      drawer.setAttribute('aria-busy','true');
      if(drawer.showModal){if(!drawer.open)drawer.showModal();}else{drawer.hidden=false;}
      drawer.querySelector('[data-close]').focus();
      try{
        // Coincident clusters use a bounded branch picker, not hundreds of detail requests.
        if(points.length>1){
          drawer.querySelector('.detail-body').innerHTML='<p>'+num(points.length)+' شعبه در این محدوده</p>'+points.slice(0,50).map(p=>`<p><button type="button" data-cluster-detail="${Number(p.id)}">${esc(p.name)}</button></p>`).join('')+(points.length>50?'<p>برای نتایج بیشتر از فهرست نشانی‌ها و فیلترها استفاده کنید.</p>':'');
          drawer.querySelectorAll('[data-cluster-detail]').forEach(b=>b.onclick=()=>openDetails([points.find(p=>Number(p.id)===Number(b.dataset.clusterDetail))],detailOpener));
          return;
        }
        const id=Number(points[0].id);
        const point=await api(admin?'points/'+id+'/details':'public/points/'+id,{signal:detailController.signal,cache:'no-store'});
        if(token!==detailGeneration||geoCancelled)return;
        renderDetails([point]);
      }catch(e){if(e.name!=='AbortError'&&token===detailGeneration){drawer.querySelector('.detail-body').innerHTML='<p role="alert">'+esc(e.message)+'</p><button type="button" data-detail-retry>تلاش دوباره</button>';drawer.querySelector('[data-detail-retry]').onclick=()=>openDetails(points,detailOpener);}}
      finally{if(token===detailGeneration)drawer.removeAttribute('aria-busy');}
    }
    function updateLocations(){
      const rows=locations.filter(l=>!selected||Number(l.provider_id)===Number(selected));
      const oldProvince=province.value, oldCity=city.value;
      province.innerHTML='<option value="">همه استان‌ها</option>'+[...new Set(rows.map(l=>l.province).filter(Boolean))].map(p=>'<option>'+esc(p)+'</option>').join('');
      // Keep an explicitly chosen province selectable even when the current
      // filter has no rows for it, so "0 نتیجه" reads as a real state.
      if(oldProvince&&![...province.options].some(o=>o.value===oldProvince))province.add(new Option(oldProvince,oldProvince));
      province.value=oldProvince;
      city.innerHTML='<option value="">همه شهرها</option>'+[...new Set(rows.filter(l=>!province.value||l.province===province.value).map(l=>l.city).filter(Boolean))].map(c=>'<option>'+esc(c)+'</option>').join('');
      city.value=[...city.options].some(o=>o.value===oldCity)?oldCity:'';city.disabled=city.options.length===1;
    }
    function updateLegend(){
      const provider=providers.find(p=>Number(p.id)===Number(selected));
      container.querySelector('.map-legend').innerHTML=(selected?badge(provider):'<span style="color:#ffbd18">● پست</span><span style="color:#00d59b">● تیپاکس</span><span style="color:#9975ff">● سایر</span>')+'<small>عدد روی نشانگر: تعداد شعب نزدیک</small>';
      container.querySelectorAll('[data-provider]').forEach(b=>{const active=b.dataset.provider===selected;b.classList.toggle('selected',active);b.setAttribute('aria-pressed',String(active));});
      provinceLayers.forEach((polygon,name)=>polygon.setStyle({color:name===normalize(province.value)?'#c4f5ff':'#67d9ff',weight:name===normalize(province.value)?2:1,fillOpacity:name===normalize(province.value)?.42:.32}));
    }
    function setView(view){
      container.dataset.view=view;
      container.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));
      if(view==='map')requestAnimationFrame(()=>{if(!geoCancelled)map.invalidateSize();});
    }
    container.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>setView(b.dataset.view));
    const details = p => {
      const contacts=[['تلفن همراه',p.mobile_phone],['تلفن ثابت',p.landline_phone],['تلفن',p.phone]].filter(([,value])=>value).flatMap(([label,value])=>String(value).split(/\s*[/;|]\s*/).map(part=>[label,part]));
      return (p.address?'<p>'+esc(p.address)+'</p>':'')+(p.postal_code?'<p>کد پستی: <bdi>'+esc(p.postal_code)+'</bdi></p>':'')+contacts.map(([label,value])=>{
        const normalized=String(value).replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        // Keep extension notes visible without appending them to the telephone number.
        const main=normalized.match(/^\s*(\+?[0-9][0-9\s().-]{5,})/);
        const dial=main?main[1].replace(/[^+0-9]/g,''):'';
        return '<p>'+label+': '+(dial?'<a aria-label="'+esc('تماس با '+p.name+'، '+label)+'" href="tel:'+esc(dial)+'"><bdi>'+esc(value)+'</bdi></a>':'<bdi>'+esc(value)+'</bdi>')+'</p>';
      }).join('');
    };
    updateLocations();
    updateLegend();
    mapList.hidden=false;
    listToggle.setAttribute('aria-expanded','true');
    if(admin)listToggle.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg><span>بستن فهرست نقاط</span>';
    function renderDirectory(){
      if(admin){
        mapList.innerHTML=items.length?`<div class="table-scroll directory-table" role="region" aria-label="فهرست قابل دسترس نقاط" tabindex="0"><table><caption>نقاط خدماتی مطابق فیلترهای انتخاب‌شده</caption><thead><tr>${['ارائه‌دهنده','نام شعبه','استان','شهر','آدرس','کد پستی','تلفن ثابت','وضعیت موقعیت','جزئیات'].map(label=>`<th scope="col">${label}</th>`).join('')}</tr></thead><tbody>${items.map(p=>`<tr><td>${badge(providers.find(pr=>Number(pr.id)===Number(p.provider_id)))}</td><th scope="row">${esc(p.name)}</th><td>${esc(p.province||'—')}</td><td>${esc(p.city||'—')}</td><td class="directory-address">${esc(p.address||'—')}</td><td><bdi>${esc(p.postal_code||'—')}</bdi></td><td><bdi>${esc(p.landline_phone||'—')}</bdi></td><td><span class="badge ${validCoordinates(p)?'good':'muted'}">${validCoordinates(p)?'● دارای موقعیت':'! بدون مختصات'}</span></td><td><button type="button" data-details="${Number(p.id)}" aria-label="${esc('اطلاعات شعبه '+p.name)}">اطلاعات شعبه</button></td></tr>`).join('')}</tbody></table></div>`:'<p class="locator-empty">هیچ نقطه خدماتی با فیلترهای انتخاب‌شده پیدا نشد.</p>';
        if(!items.length)mapList.querySelector('.locator-empty')?.insertAdjacentHTML('beforeend','<button type="button" data-empty-clear>پاک کردن فیلترها</button>');
        return;
      }
      mapList.innerHTML=items.map(p=>{
        const valid=validCoordinates(p),mapped=valid&&iranGeometry?.some(f=>insideGeometry(p,f.geometry));
        return `<article class="branch-card">${badge(providers.find(pr=>Number(pr.id)===Number(p.provider_id)))}<strong>${esc(p.name)}</strong><small>${esc(p.province)}، ${esc(p.city)}</small>${details(p)}<div class="branch-actions"><button type="button" data-details="${p.id}">اطلاعات شعبه</button>${mapped?`<button type="button" data-point="${p.id}">نمایش روی نقشه</button>`:!valid?'<span class="coordinate-note">بدون مختصات · نشانی متنی</span>':iranGeometry?'<span class="coordinate-note">مختصات خارج از محدوده نقشه</span>':''}</div></article>`;
      }).join('')||'<div class="locator-empty"><strong>شعبه‌ای پیدا نشد</strong><p>عبارت جستجو را تغییر دهید یا فیلترها را پاک کنید. پوشش این فهرست سراسری نیست.</p><button type="button" data-empty-clear>پاک کردن فیلترها</button></div>';
    }
    async function load(append=false,requestedPage=1) {
      controller?.abort(); controller = new AbortController();
      const token = ++generation;
      if(!append){items=[];mapList.innerHTML='';more.hidden=true;}
      status.textContent='در حال دریافت نقاط خدماتی…';mapList.setAttribute('aria-busy','true');more.disabled=true; retry.hidden=true;
      const params = new URLSearchParams({search,provider_id:selected,province:province.value,city:city.value,per_page:admin?'10':'50',page:String(admin?requestedPage:append?page+1:1),include_summary:admin?'1':'0',status:'any'});
      if(admin)params.set('map_view','1');
      try {
        const data = await api((admin?'points':'public/directory')+'?'+params, {signal:controller.signal});
        if (token !== generation || geoCancelled) return;
        if (!append) { items=[]; page=admin?requestedPage:1; } else page++;
        items.push(...data.items);if(admin&&data.summary)onSummary(data.summary);
        status.textContent = data.total ? `نمایش ${num(items.length)} از ${num(data.total)} شعبه${admin?' شامل نقاط بدون مختصات و غیرفعال':' · '+'فهرست نشانی‌ها'}` : 'شعبه‌ای با این فیلترها پیدا نشد.';
        if(admin){
          const totalPages=Math.max(1,data.total_pages||1);
          status.textContent=`${num(data.total)} نتیجه · صفحه ${num(page)} از ${num(totalPages)} · شامل نقاط بدون مختصات و غیرفعال`;
          pagerSlot.innerHTML=paginationBar(page,totalPages,data.total,'فهرست قابل دسترس نقاط');
          pagerSlot.querySelectorAll('[data-page]').forEach(btn=>{btn.onclick=()=>{const target=Number(btn.dataset.page);if(!btn.disabled&&!Number.isNaN(target)&&target!==page)load(false,target);};});
          more.hidden=true;
        } else {
          more.hidden = page >= data.total_pages; more.disabled=false;
        }
        renderDirectory();
      } catch(e) { if(e.name!=='AbortError') { status.textContent=admin?'دریافت فهرست انجام نشد. دوباره تلاش کنید.':e.message; retry.hidden=false; more.disabled=false; } }
      finally{if(token===generation){mapList.setAttribute('aria-busy','false');}}
    }
    let markerController, markerTimer, markerGeneration=0;
    const markerStatus=document.createElement('p');markerStatus.className='marker-status';markerStatus.setAttribute('role','status');container.querySelector('.tapin-map').after(markerStatus);
    function drawMarkers(points){
      layer.clearLayers();markers.clear();
      const groups=new Map();
      const clusters=[];
      points.forEach(p=>{const xy=map.latLngToContainerPoint([p.latitude,p.longitude]),x=Math.floor(xy.x/64),y=Math.floor(xy.y/64);let match;
        for(let dx=-1;dx<=1&&!match;dx++)for(let dy=-1;dy<=1&&!match;dy++)match=(groups.get((x+dx)+':'+(y+dy))||[]).find(g=>Math.hypot(g.xy.x-xy.x,g.xy.y-xy.y)<64);
        if(match)match.points.push(p);else{const group={xy,points:[p]},key=x+':'+y;if(!groups.has(key))groups.set(key,[]);groups.get(key).push(group);clusters.push(group);}
      });
      clusters.forEach(({points:group})=>{
        const p=group[0], provider=group.every(row=>Number(row.provider_id)===Number(p.provider_id))?providers.find(pr=>Number(pr.id)===Number(p.provider_id)):null;
        const logo=provider?.logo?safeUrl(provider.logo):'';
        const html=(logo?'<img src="'+logo+'" alt="">':'<svg width="22" height="26" viewBox="0 0 24 28" aria-hidden="true" focusable="false"><path d="M12 26S3 16 3 10a9 9 0 0 1 18 0c0 6-9 16-9 16Z" fill="white"/><circle cx="12" cy="10" r="4" fill="#7349ff"/></svg>')+(group.length>1?'<b>'+num(group.length)+'</b>':'');
        const marker=L.marker([p.latitude,p.longitude],{title:group.length>1?num(group.length)+' شعبه':p.name,icon:L.divIcon({className:'tapin-pin '+(selected?'provider-pin':'all-pin'),html,iconSize:[34,40],iconAnchor:[17,40]})}).addTo(layer);
        marker.getElement().style.background=/^#[0-9a-f]{6}$/i.test(provider?.marker_color)?provider.marker_color:'#7349ff';marker.getElement().style.borderColor=color(provider);
        marker.getElement().setAttribute('aria-label',group.length>1?num(group.length)+' شعبه؛ بزرگ‌نمایی یا مشاهده فهرست':p.name+'؛ اطلاعات شعبه');
        marker.bindTooltip(document.createTextNode(group.length>1?num(group.length)+' شعبه':p.name+(provider?.name?' · '+provider.name:'')),{direction:'top',offset:[0,-30]});
        marker.getElement().addEventListener('focus',()=>marker.openTooltip());
        marker.getElement().addEventListener('blur',()=>marker.closeTooltip());
        marker.getElement().addEventListener('keydown',e=>{
          if(e.key==='Enter'||e.key===' '){e.preventDefault();e.stopPropagation();marker.fire('click');}
        });
        if(group.length>1){marker.on('click',()=>{if(map.getZoom()<18){map.fitBounds(group.map(x=>[x.latitude,x.longitude]),{maxZoom:map.getZoom()+2,padding:[30,30]});}else{openDetails(group,marker.getElement());}});}else{marker.on('click',()=>openDetails([p],marker.getElement()));}
        group.forEach(x=>markers.set(x.id,marker));
      });
    }
    async function loadMarkers(){
      if(geoCancelled)return;
      markerController?.abort();markerController=new AbortController();const token=++markerGeneration;
      layer.clearLayers();markers.clear();markerStatus.textContent='در حال دریافت نشانگرها…';
      const bounds=map.getBounds();
      const params=new URLSearchParams({search,provider_id:selected,province:province.value,city:city.value,status:admin?'any':'active',per_page:'500',north:String(Math.min(90,bounds.getNorth())),south:String(Math.max(-90,bounds.getSouth())),east:String(Math.min(180,bounds.getEast())),west:String(Math.max(-180,bounds.getWest())),has_coordinates:'1'});
      if(admin)params.set('map_view','1');
      const points=[];
      try{await geographyReady;if(geoCancelled||token!==markerGeneration)return;if(!iranGeometry)throw Error('نقشه آماده نیست؛ فهرست نشانی‌ها را ببینید.');let next=1,totalPages=1;do{params.set('page',String(next));const data=await api((admin?'points':'public/points')+'?'+params,{signal:markerController.signal});if(token!==markerGeneration)return;points.push(...data.items.filter(p=>validCoordinates(p)&&iranGeometry.some(f=>insideGeometry(p,f.geometry))));totalPages=data.total_pages;next++;}while(next<=totalPages);drawMarkers(points);markerStatus.textContent=num(points.length)+' نقطه دارای مختصات در محدوده نقشه';}
      catch(e){if(e.name!=='AbortError'){markerStatus.textContent=e.message;retry.hidden=false;}}
    }
    function scheduleMarkers(){if(geoCancelled)return;layer.clearLayers();markers.clear();clearTimeout(markerTimer);markerController?.abort();markerGeneration++;markerTimer=setTimeout(loadMarkers,180);}
    map.on('moveend',scheduleMarkers);
    function refreshFilters(){updateLocations();updateLegend();load();scheduleMarkers();}
    container.querySelectorAll('[data-provider]').forEach(button=>button.onclick=()=>{selected=button.dataset.provider;refreshFilters();});
    province.onchange=()=>{city.value='';updateLocations();updateLegend();const polygon=provinceLayers.get(normalize(province.value));map.stop();map.fitBounds(polygon?polygon.getBounds():iran,{animate:false});load();scheduleMarkers();};
    let searchTimer=null;
    const searchInput=container.querySelector('.locator-search input[name=search]');
    if(searchInput){
      searchInput.addEventListener('input',()=>{
        clearTimeout(searchTimer);
        searchTimer=setTimeout(()=>{
          search=searchInput.value.trim();
          load();scheduleMarkers();
        },350);
      });
    }
    container.querySelector('.locator-search').onsubmit=e=>{e.preventDefault();clearTimeout(searchTimer);search=new FormData(e.currentTarget).get('search').trim();load();scheduleMarkers();};
    city.onchange=()=>{load();scheduleMarkers();};
    container.querySelector('[data-reset]').onclick=()=>{clearTimeout(searchTimer);search='';const s=container.querySelector('.locator-search input[name=search]');if(s)s.value='';province.value='';city.value='';updateLocations();updateLegend();map.stop();map.fitBounds(iran,{animate:false});load();scheduleMarkers();};
    function clearFilters(){clearTimeout(searchTimer);selected='';if(admin)container.querySelector('[data-provider-select]').value='';search='';province.value='';city.value='';container.querySelector('[name=search]').value='';refreshFilters();map.stop();map.fitBounds(iran,{animate:false});}
    container.querySelector('[data-clear]').onclick=clearFilters;
    const pagerSlot=document.createElement('div');pagerSlot.className='dashboard-pager';
    if(admin){
      mapList.after(pagerSlot);
      more.hidden=true;
    }
    let pagination;
    if(admin){
      const toggleRow=document.createElement('div');toggleRow.className='map-actions directory-toggle';mapList.before(toggleRow);toggleRow.append(listToggle);
      pagination=document.createElement('div');pagination.className='map-actions directory-pagination';pagination.hidden=mapList.hidden;mapList.after(pagination);pagination.append(pagerSlot,more,status);
      mapList.id=detailId+'-list';pagination.id=detailId+'-pages';listToggle.setAttribute('aria-controls',mapList.id+' '+pagination.id);
    }
    if(admin)exportControl(directoryPanel,()=>({search,provider_id:selected,province:province.value,city:city.value,status:'any'}));
    more.onclick=()=>admin?load(false,page+1):load(true); retry.onclick=()=>{load();loadMarkers();};
    const listIcon='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>';
    listToggle.onclick=()=>{
      mapList.hidden=!mapList.hidden;
      if(admin)pagination.hidden=mapList.hidden;
      listToggle.setAttribute('aria-expanded',String(!mapList.hidden));
      if(admin)listToggle.innerHTML=listIcon+'<span>'+(mapList.hidden?'نمایش فهرست نقاط':'بستن فهرست نقاط')+'</span>';
    };
    mapList.onclick=e=>{if(e.target.closest('[data-empty-clear]')){clearFilters();return;}const b=e.target.closest('[data-point],[data-details]');if(!b)return;const p=items.find(x=>Number(x.id)===Number(b.dataset.point||b.dataset.details));if(!p)return;if(b.dataset.point&&validCoordinates(p)&&iranGeometry?.some(f=>insideGeometry(p,f.geometry))){setView('map');map.setView([p.latitude,p.longitude],15);}openDetails([p],b);};
    load();loadMarkers();
    requestAnimationFrame(()=>{if(!geoCancelled)map.invalidateSize();});
    // Refresh newly enriched points without changing filters, viewport, or an open detail.
    const refreshTimer=setInterval(()=>{const detailOpen=drawer.showModal?drawer.open:!drawer.hidden;if(!geoCancelled&&!document.hidden&&!detailOpen&&page===1&&!container.contains(document.activeElement)){load();scheduleMarkers();}},60000);
    return () => {geoCancelled=true;clearInterval(refreshTimer);detailGeneration++;detailController?.abort();clearTimeout(searchTimer);if(drawer.close&&drawer.open)drawer.close();drawer.remove();if(admin&&directoryPanel&&directoryPanel.parentElement&&directoryPanel.parentElement!==container)directoryPanel.remove();map.off('moveend',scheduleMarkers);map.off('moveend',declutterCountryLabels);clearTimeout(markerTimer);markerController?.abort();controller?.abort();map.remove();};
  }
  window.Tapin = {api,esc,num,badge,providerOptions,mapWidget,color,safeUrl,exportControl,paginationBar};
  document.querySelectorAll('.tapin-public-root').forEach(async root=>{
    try { const data=await api('public/filters'); root.innerHTML='<header class="locator-heading"><img src="'+safeUrl(TapinConfig.assets+'brand/tapin.png')+'" alt="تاپین" width="108"><div><p>نزدیک‌تر به مسیر ارسال شما</p><h2>نقاط خدماتی تاپین</h2></div></header><p class="locator-intro">استان، شهر یا ارائه‌دهنده را انتخاب کنید. نقاط بدون مختصات در فهرست نشانی‌ها در دسترس‌اند.</p><div class="map-panel"></div><footer class="locator-coverage">این فهرست شامل نقاط ثبت‌شده است و پوشش کامل شعب سراسر ایران را نشان نمی‌دهد. <a href="https://tapin.ir/map/" target="_blank" rel="noopener noreferrer">مرجع پستی تاپین</a></footer>'; mapWidget(root.querySelector('.map-panel'),data.providers,data.locations); }
    catch(e){root.innerHTML=`<p role="alert">${esc(e.message)}</p><button type="button" onclick="location.reload()">تلاش دوباره</button>`;}
  });
})();
