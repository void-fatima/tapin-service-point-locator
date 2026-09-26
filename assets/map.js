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
  function mapWidget(container, providers, locations, admin = false) {
    container.innerHTML = `<div class="map-tools"><div class="provider-tabs" role="group" aria-label="ارائه‌دهنده"><button type="button" class="selected" data-provider="">همه</button>${providers.map(p => `<button type="button" data-provider="${Number(p.id)}">${badge(p)}</button>`).join('')}</div><div class="map-selects"><label><span class="sr-only">استان</span><select data-province><option value="">همه استان‌ها</option>${[...new Set(locations.map(l=>l.province))].map(p=>`<option>${esc(p)}</option>`).join('')}</select></label><label><span class="sr-only">شهر</span><select data-city><option value="">همه شهرها</option></select></label><button type="button" data-reset title="نمایش سراسر ایران" aria-label="نمایش سراسر ایران">◎</button></div></div><div class="tapin-map" role="region" aria-label="نقشه نقاط خدماتی"></div><div class="map-status" role="status"></div><div class="map-actions"><button type="button" data-retry hidden>تلاش دوباره</button><button type="button" data-more hidden>نمایش نقاط بیشتر</button><button type="button" data-list>فهرست قابل دسترس نقاط</button></div><div class="map-list" hidden></div>`;
    const map = L.map(container.querySelector('.tapin-map'), {preferCanvas:true, scrollWheelZoom:false, zoomControl:false, zoomSnap:0.1, minZoom:3, maxZoom:19});
    const iran = [[24.6,43.5],[40.2,63.5]];
    map.fitBounds(iran, {padding:[12,12]});
    L.control.zoom({position:'bottomleft'}).addTo(map);
    L.control.scale({imperial:false,position:'bottomleft'}).addTo(map);
    const tile = L.tileLayer(TapinConfig.tiles, {attribution:TapinConfig.attribution,maxZoom:19}).addTo(map);
    let tileFailed = false;
    tile.on('tileerror', () => { if (!tileFailed) { tileFailed = true; const note = document.createElement('p'); note.className='tile-warning'; note.textContent='تصاویر زمینه نقشه بارگذاری نشد؛ اتصال اینترنت را بررسی کنید. فهرست نقاط در دسترس است.'; container.append(note); } });
    let geoCancelled = false;
    map.createPane('boundaries');map.getPane('boundaries').style.zIndex='350';
    fetch(TapinConfig.assets+'iran-provinces.geojson').then(r=>r.ok?r.json():null).then(data=>{if(data && !geoCancelled) {
      L.geoJSON(data,{pane:'boundaries',interactive:false,style:{color:'#a294e7',weight:1,fillColor:'#7866bd',fillOpacity:0.20}}).addTo(map);
      map.attributionControl.addAttribution('<a href="https://www.geoboundaries.org/">geoBoundaries</a> / OSM');
    }}).catch(()=>{});
    const layer = L.layerGroup().addTo(map);
    const status = container.querySelector('.map-status');
    const more = container.querySelector('[data-more]');
    const retry = container.querySelector('[data-retry]');
    const province = container.querySelector('[data-province]');
    const city = container.querySelector('[data-city]');
    city.innerHTML='<option value="">همه شهرها</option>'+[...new Set(locations.map(l=>l.city))].map(c=>`<option>${esc(c)}</option>`).join('');
    let selected = '', page = 1, generation = 0, controller, items = [];
    const markers = new Map();
    async function load(append=false) {
      controller?.abort(); controller = new AbortController();
      const token = ++generation;
      status.textContent='در حال دریافت نقاط خدماتی…'; more.disabled=true; retry.hidden=true;
      const params = new URLSearchParams({provider_id:selected,province:province.value,city:city.value,per_page:'500',page:String(append?page+1:1),has_coordinates:'1',status:'any'});
      try {
        const data = await api((admin?'points':'public/points')+'?'+params, {signal:controller.signal});
        if (token !== generation) return;
        if (!append) { layer.clearLayers(); items=[]; markers.clear(); page=1; } else page++;
        const valid = data.items.filter(p=>p.latitude!==null && p.longitude!==null && Number.isFinite(Number(p.latitude)) && Number.isFinite(Number(p.longitude)) && Math.abs(Number(p.latitude))<=90 && Math.abs(Number(p.longitude))<=180);
        items.push(...valid);
        valid.forEach(p => {
          const provider = providers.find(pr=>Number(pr.id)===p.provider_id);
          const content = document.createElement('div'); content.className='tapin-popup'; content.dir='rtl';
          content.innerHTML = `<strong>${esc(p.name)}</strong>${badge(provider)}<p>${esc(p.province)}، ${esc(p.city)}</p><p>${esc(p.address)}</p>${p.phone?`<p dir="ltr">${esc(p.phone)}</p>`:''}${admin?`<a href="${safeUrl(TapinConfig.adminUrl)}#points?edit=${p.id}">ویرایش نقطه خدماتی</a>`:''}`;
          const marker = L.circleMarker([p.latitude,p.longitude],{radius:6,color:color(provider),fillColor:color(provider),fillOpacity:0.95,weight:2}).bindPopup(content).addTo(layer);
          markers.set(p.id, marker);
        });
        if (!append && (selected || province.value || city.value) && valid.length) map.fitBounds(valid.map(p=>[p.latitude,p.longitude]),{padding:[30,30],maxZoom:11});
        status.textContent = data.total ? `نمایش ${num(items.length)} از ${num(data.total)} نقطه دارای مختصات${admin?' · شامل نقاط غیرفعال':''}` : 'نقطه دارای مختصات با این فیلترها پیدا نشد.';
        more.hidden = page >= data.total_pages; more.disabled=false;
        container.querySelector('.map-list').innerHTML = items.map(p=>`<button type="button" data-point="${p.id}">${esc(p.name)} · ${esc(p.city)}<small>${esc(p.address)}</small></button>`).join('') || '<p>نقطه‌ای برای نمایش وجود ندارد.</p>';
      } catch(e) { if(e.name!=='AbortError') { status.textContent=e.message; retry.hidden=false; more.disabled=false; } }
    }
    container.querySelectorAll('[data-provider]').forEach(button=>button.onclick=()=>{ selected=button.dataset.provider; container.querySelectorAll('[data-provider]').forEach(b=>{ b.classList.toggle('selected',b===button); b.setAttribute('aria-pressed',String(b===button)); }); load(); });
    province.onchange=()=>{ city.innerHTML='<option value="">همه شهرها</option>'+locations.filter(l=>!province.value||l.province===province.value).map(l=>`<option>${esc(l.city)}</option>`).join(''); load(); };
    city.onchange=()=>load();
    container.querySelector('[data-reset]').onclick=()=>map.fitBounds(iran);
    more.onclick=()=>load(true); retry.onclick=()=>load();
    container.querySelector('[data-list]').onclick=()=>{ const list=container.querySelector('.map-list'); list.hidden=!list.hidden; };
    container.querySelector('.map-list').onclick=e=>{ const b=e.target.closest('[data-point]'); if(b){const marker=markers.get(Number(b.dataset.point));map.setView(marker.getLatLng(),12);marker.openPopup();} };
    load();
    return () => {geoCancelled=true; controller?.abort(); map.remove();};
  }
  window.Tapin = {api,esc,num,badge,providerOptions,mapWidget,color,safeUrl};
  document.querySelectorAll('.tapin-public-root').forEach(async root=>{
    try { const data=await api('public/filters'); root.innerHTML='<h2>نقاط خدماتی تاپین</h2><p>ارائه‌دهنده و موقعیت خود را انتخاب کنید.</p><div class="map-panel"></div>'; mapWidget(root.querySelector('.map-panel'),data.providers,data.locations); }
    catch(e){root.innerHTML=`<p role="alert">${esc(e.message)}</p><button type="button" onclick="location.reload()">تلاش دوباره</button>`;}
  });
})();
