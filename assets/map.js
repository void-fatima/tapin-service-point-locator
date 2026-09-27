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
  const provinceNames = {'Mazandaran':'مازندران','North Khorasan':'خراسان شمالی','Kerman':'کرمان','Ilam':'ایلام','Lorestan':'لرستان','Markazi':'مرکزی','Chaharmahal and Bakhtiari':'چهارمحال و بختیاری','Kermanshah':'کرمانشاه','Hamadan':'همدان','Qazvin':'قزوین','Gilan':'گیلان','Zanjan':'زنجان','Semnan':'سمنان','Isfahan':'اصفهان','Kohgiluyeh and Boyer-Ahmad':'کهگیلویه و بویراحمد','Kurdistan':'کردستان','West Azerbaijan':'آذربایجان غربی','Fars':'فارس','Bushehr':'بوشهر','Ardabil':'اردبیل','Golestan':'گلستان','Razavi Khorasan':'خراسان رضوی','South Khorasan':'خراسان جنوبی','Sistan and Baluchestan':'سیستان و بلوچستان','Qom':'قم','Alborz':'البرز','East Azerbaijan':'آذربایجان شرقی','Yazd':'یزد','Hormozgan':'هرمزگان','Khuzestan':'خوزستان','Tehran':'تهران'};
  const normalize = value => String(value).replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s‌]/g,'');
  function mapWidget(container, providers, locations, admin = false) {
    container.innerHTML = `<div class="map-tools"><div class="provider-tabs" role="group" aria-label="ارائه‌دهنده"><button type="button" class="selected" data-provider="">همه</button>${providers.map(p => `<button type="button" data-provider="${Number(p.id)}">${badge(p)}</button>`).join('')}</div><div class="map-selects"><label><span class="sr-only">استان</span><select data-province><option value="">همه استان‌ها</option>${[...new Set(locations.map(l=>l.province))].map(p=>`<option>${esc(p)}</option>`).join('')}</select></label><label><span class="sr-only">شهر</span><select data-city><option value="">همه شهرها</option></select></label><button type="button" data-reset title="نمایش سراسر ایران" aria-label="نمایش سراسر ایران">◎</button></div></div><form class="locator-search"><label><span class="sr-only">جستجوی شعبه یا نشانی</span><input name="search" type="search" placeholder="نام شعبه، نشانی یا تلفن…"></label><button type="submit">جستجو</button></form><div class="tapin-map" role="region" aria-label="نقشه نقاط خدماتی"></div><div class="map-status" role="status"></div><div class="map-actions"><button type="button" data-retry hidden>تلاش دوباره</button><button type="button" data-more hidden>نمایش نقاط بیشتر</button><button type="button" data-list>فهرست قابل دسترس نقاط</button></div><div class="map-list" hidden></div>`;
    const map = L.map(container.querySelector('.tapin-map'), {preferCanvas:true, scrollWheelZoom:false, zoomControl:false, zoomSnap:0.1, minZoom:3, maxZoom:19});
    const iran = [[24.6,43.5],[40.2,63.5]];
    map.fitBounds(iran, {padding:[12,12]});
    L.control.zoom({position:'bottomleft'}).addTo(map);
    L.control.scale({imperial:false,position:'bottomleft'}).addTo(map);
    const tile = L.tileLayer(TapinConfig.tiles, {attribution:TapinConfig.attribution,maxZoom:19}).addTo(map);
    let tileFailed = false;
    tile.on('tileerror', () => { if (!tileFailed) { tileFailed = true; const note = document.createElement('p'); note.className='tile-warning'; note.textContent='تصاویر زمینه نقشه بارگذاری نشد؛ اتصال اینترنت را بررسی کنید. فهرست نقاط در دسترس است.'; container.append(note); } });
    let geoCancelled = false;
    const provinceLayers = new Map();
    map.getPane('tilePane').style.filter='invert(1) hue-rotate(185deg) brightness(.6) saturate(.35)';
    const countries=[['ترکیه',39,35],['عراق',33,43],['سوریه',35,38],['عربستان',24,44],['کویت',29.4,47.5],['امارات',24,54],['عمان',21,57],['پاکستان',29,68],['افغانستان',34,66],['ترکمنستان',40,59],['آذربایجان',40.5,48],['ارمنستان',40.3,44.8]];
    countries.forEach(([name,lat,lng])=>L.marker([lat,lng],{interactive:false,keyboard:false,icon:L.divIcon({className:'country-label',html:esc(name),iconSize:[100,20]})}).addTo(map));
    map.createPane('boundaries');map.getPane('boundaries').style.zIndex='350';
    fetch(TapinConfig.assets+'iran-provinces.geojson').then(r=>r.ok?r.json():null).then(data=>{if(data && !geoCancelled) {
      const holes=[];
      data.features.forEach(feature=>{
        const polygons=feature.geometry.type==='MultiPolygon'?feature.geometry.coordinates:[feature.geometry.coordinates];
        polygons.forEach(polygon=>holes.push(polygon[0].map(([lng,lat])=>[lat,lng])));
      });
      // Opaque exterior prevents foreign cities/capitals baked into raster tiles leaking through.
      L.polygon([[[-85,-180],[-85,180],[85,180],[85,-180]],...holes],{pane:'boundaries',interactive:false,stroke:false,fillColor:'#111622',fillOpacity:1,fillRule:'evenodd'}).addTo(map);
      L.geoJSON(data,{pane:'boundaries',interactive:true,style:{color:'#a294e7',weight:1,fillColor:'#7866bd',fillOpacity:0.35},onEachFeature:(feature, polygon)=>{
        const name=provinceNames[feature.properties.shapeName];
        if(name){provinceLayers.set(normalize(name),polygon);polygon.bindTooltip(name,{direction:'center'});polygon.on('click',()=>{
          const option=[...province.options].find(o=>normalize(o.value)===normalize(name));
          if(!option){province.add(new Option(name,name));}
          province.value=option?option.value:name;province.onchange();
        });}
      }}).addTo(map);
      map.attributionControl.addAttribution('<a href="https://www.geoboundaries.org/">geoBoundaries</a> / OSM');
    }}).catch(()=>{});
    const layer = L.layerGroup().addTo(map);
    const status = container.querySelector('.map-status');
    const more = container.querySelector('[data-more]');
    const retry = container.querySelector('[data-retry]');
    const province = container.querySelector('[data-province]');
    const city = container.querySelector('[data-city]');
    city.innerHTML='<option value="">همه شهرها</option>'+[...new Set(locations.map(l=>l.city))].map(c=>`<option>${esc(c)}</option>`).join('');
    let selected = '', search = '', page = 1, generation = 0, controller, items = [];
    const markers = new Map();
    function updateLocations(){
      const rows=locations.filter(l=>!selected||Number(l.provider_id)===Number(selected));
      const oldProvince=province.value, oldCity=city.value;
      province.innerHTML='<option value="">همه استان‌ها</option>'+[...new Set([...Object.values(provinceNames),...rows.map(l=>l.province)])].map(p=>'<option>'+esc(p)+'</option>').join('');
      province.value=[...province.options].some(o=>o.value===oldProvince)?oldProvince:'';
      city.innerHTML='<option value="">همه شهرها</option>'+[...new Set(rows.filter(l=>!province.value||l.province===province.value).map(l=>l.city))].map(c=>'<option>'+esc(c)+'</option>').join('');
      city.value=[...city.options].some(o=>o.value===oldCity)?oldCity:'';
    }
    const details = p => {
      const contacts=[['تلفن همراه',p.mobile_phone],['تلفن ثابت',p.landline_phone],['تلفن',p.phone]].filter(([,value])=>value);
      return '<p>'+esc(p.address)+'</p>'+(p.postal_code?'<p>کد پستی: <bdi>'+esc(p.postal_code)+'</bdi></p>':'')+contacts.map(([label,value])=>'<p>'+label+': <a href="tel:'+esc(String(value).replace(/[^+0-9]/g,''))+'"><bdi>'+esc(value)+'</bdi></a></p>').join('');
    };
    updateLocations();
    container.querySelector('.map-list').hidden=admin;
    container.querySelector('[data-list]').setAttribute('aria-expanded',String(!admin));
    async function load(append=false) {
      controller?.abort(); controller = new AbortController();
      const token = ++generation;
      if(!append){items=[];container.querySelector('.map-list').innerHTML='';more.hidden=true;}
      status.textContent='در حال دریافت نقاط خدماتی…'; more.disabled=true; retry.hidden=true;
      const params = new URLSearchParams({search,provider_id:selected,province:province.value,city:city.value,per_page:'500',page:String(append?page+1:1),has_coordinates:'1',status:'any'});
      try {
        const data = await api((admin?'points':'public/directory')+'?'+params, {signal:controller.signal});
        if (token !== generation) return;
        if (!append) { items=[]; page=1; } else page++;
        const valid = data.items.filter(p=>p.latitude!==null && p.longitude!==null && Number.isFinite(Number(p.latitude)) && Number.isFinite(Number(p.longitude)) && Math.abs(Number(p.latitude))<=90 && Math.abs(Number(p.longitude))<=180);
        items.push(...(admin?valid:data.items));
        status.textContent = data.total ? `نمایش ${num(items.length)} از ${num(data.total)} شعبه${admin?' دارای مختصات · شامل نقاط غیرفعال':' · '+'فهرست نشانی‌ها'}` : 'شعبه‌ای با این فیلترها پیدا نشد.';
        more.hidden = page >= data.total_pages; more.disabled=false;
        container.querySelector('.map-list').innerHTML = items.map(p=>`<article class="branch-card">${badge(providers.find(pr=>Number(pr.id)===Number(p.provider_id)))}<strong>${esc(p.name)}</strong><small>${esc(p.province)}، ${esc(p.city)}</small>${details(p)}${p.has_coordinates?`<button type="button" data-point="${p.id}">نمایش روی نقشه</button>`:'<span class="coordinate-note">بدون مختصات · نشانی متنی</span>'}</article>`).join('') || '<p>شعبه‌ای برای نمایش وجود ندارد.</p>';
      } catch(e) { if(e.name!=='AbortError') { status.textContent=e.message; retry.hidden=false; more.disabled=false; } }
    }
    let markerController, markerTimer, markerGeneration=0;
    const markerStatus=document.createElement('p');markerStatus.className='marker-status';markerStatus.setAttribute('role','status');container.querySelector('.tapin-map').after(markerStatus);
    function drawMarkers(points){
      layer.clearLayers();markers.clear();
      const groups=new Map();
      points.forEach(p=>{const xy=map.latLngToContainerPoint([p.latitude,p.longitude]);const key=Math.floor(xy.x/44)+':'+Math.floor(xy.y/44);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(p);});
      groups.forEach(group=>{
        const p=group[0], provider=providers.find(pr=>Number(pr.id)===Number(p.provider_id));
        const content=document.createElement('div');content.className='tapin-popup';content.dir='rtl';
        content.innerHTML='<strong>'+esc(p.name)+'</strong>'+badge(provider)+'<p>'+esc(p.province)+'، '+esc(p.city)+'</p>'+details(p);
        const logo=selected&&provider?.logo?safeUrl(provider.logo):'';
        const html=(logo?'<img src="'+logo+'" alt="">':selected?'<span>▣</span>':'<span>●</span>')+(group.length>1?'<b>'+num(group.length)+'</b>':'');
        const marker=L.marker([p.latitude,p.longitude],{title:group.length>1?num(group.length)+' شعبه':p.name,icon:L.divIcon({className:'tapin-pin '+(selected?'provider-pin':'all-pin'),html,iconSize:[34,40],iconAnchor:[17,40]})}).addTo(layer);
        if(group.length>1){marker.on('click',()=>{if(map.getZoom()<18){map.fitBounds(group.map(x=>[x.latitude,x.longitude]),{maxZoom:map.getZoom()+2,padding:[30,30]});}else{content.innerHTML=group.map(x=>'<strong>'+esc(x.name)+'</strong>'+details(x)).join('<hr>');marker.bindPopup(content).openPopup();}});}else{marker.bindPopup(content);}
        group.forEach(x=>markers.set(x.id,marker));
      });
    }
    async function loadMarkers(){
      if(geoCancelled)return;
      markerController?.abort();markerController=new AbortController();const token=++markerGeneration;
      layer.clearLayers();markers.clear();markerStatus.textContent='در حال دریافت نشانگرها…';
      const bounds=map.getBounds();
      const params=new URLSearchParams({search,provider_id:selected,province:province.value,city:city.value,per_page:'500',north:String(Math.min(90,bounds.getNorth())),south:String(Math.max(-90,bounds.getSouth())),east:String(Math.min(180,bounds.getEast())),west:String(Math.max(-180,bounds.getWest())),has_coordinates:'1'});
      const points=[];
      try{let next=1,totalPages=1;do{params.set('page',String(next));const data=await api((admin?'points':'public/points')+'?'+params,{signal:markerController.signal});if(token!==markerGeneration)return;points.push(...data.items.filter(p=>p.has_coordinates&&p.latitude!==null&&p.longitude!==null));totalPages=data.total_pages;next++;}while(next<=totalPages);drawMarkers(points);markerStatus.textContent=num(points.length)+' نقطه دارای مختصات در محدوده نقشه';}
      catch(e){if(e.name!=='AbortError'){markerStatus.textContent=e.message;retry.hidden=false;}}
    }
    function scheduleMarkers(){if(geoCancelled)return;clearTimeout(markerTimer);markerController?.abort();markerGeneration++;markerTimer=setTimeout(loadMarkers,180);}
    map.on('moveend',scheduleMarkers);
    container.querySelectorAll('[data-provider]').forEach(button=>button.onclick=()=>{ selected=button.dataset.provider; container.querySelectorAll('[data-provider]').forEach(b=>{ b.classList.toggle('selected',b===button); b.setAttribute('aria-pressed',String(b===button)); }); updateLocations(); load(); scheduleMarkers(); });
    province.onchange=()=>{city.value='';updateLocations();const polygon=provinceLayers.get(normalize(province.value));if(polygon)map.fitBounds(polygon.getBounds());load();scheduleMarkers();};
    container.querySelector('.locator-search').onsubmit=e=>{e.preventDefault();search=new FormData(e.currentTarget).get('search').trim();load();scheduleMarkers();};
    city.onchange=()=>{load();scheduleMarkers();};
    container.querySelector('[data-reset]').onclick=()=>{province.value='';city.value='';updateLocations();map.fitBounds(iran);load();scheduleMarkers();};
    more.onclick=()=>load(true); retry.onclick=()=>{load();loadMarkers();};
    container.querySelector('[data-list]').onclick=()=>{ const list=container.querySelector('.map-list'); list.hidden=!list.hidden;container.querySelector('[data-list]').setAttribute('aria-expanded',String(!list.hidden)); };
    container.querySelector('.map-list').onclick=e=>{ const b=e.target.closest('[data-point]'); if(b){const p=items.find(x=>Number(x.id)===Number(b.dataset.point));if(!p)return;map.setView([p.latitude,p.longitude],15);const content=document.createElement('div');content.className='tapin-popup';content.dir='rtl';content.innerHTML='<strong>'+esc(p.name)+'</strong>'+badge(providers.find(pr=>Number(pr.id)===Number(p.provider_id)))+details(p);L.popup().setLatLng([p.latitude,p.longitude]).setContent(content).openOn(map);} };
    load();loadMarkers();
    return () => {geoCancelled=true;map.off('moveend',scheduleMarkers);clearTimeout(markerTimer);markerController?.abort();controller?.abort();map.remove();};
  }
  window.Tapin = {api,esc,num,badge,providerOptions,mapWidget,color,safeUrl};
  document.querySelectorAll('.tapin-public-root').forEach(async root=>{
    try { const data=await api('public/filters'); root.innerHTML='<h2>نقاط خدماتی تاپین</h2><p>استان خود را روی نقشه یا از فهرست انتخاب کنید؛ سپس شهر و ارائه‌دهنده را مشخص کنید. شعب بدون مختصات فقط در فهرست نشانی‌ها نمایش داده می‌شوند.</p><div class="map-panel"></div>'; mapWidget(root.querySelector('.map-panel'),data.providers,data.locations); }
    catch(e){root.innerHTML=`<p role="alert">${esc(e.message)}</p><button type="button" onclick="location.reload()">تلاش دوباره</button>`;}
  });
})();
