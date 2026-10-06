/* global L, TapinConfig */
(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const num = value => Number(value || 0).toLocaleString('fa-IR');
  if (typeof TapinConfig !== 'undefined' && TapinConfig) {
    if (location.protocol === 'https:') {
      if (TapinConfig.api && TapinConfig.api.startsWith('http://')) TapinConfig.api = 'https://' + TapinConfig.api.slice(7);
      if (TapinConfig.assets && TapinConfig.assets.startsWith('http://')) TapinConfig.assets = 'https://' + TapinConfig.assets.slice(7);
    }
  }
  function buildApiUrl(path) {
    let base = TapinConfig.api || '';
    if (location.protocol === 'https:' && base.startsWith('http://')) {
      base = 'https://' + base.slice(7);
    }
    const url = new URL(base, location.href);
    const [endpoint, queryStr = ''] = String(path).split('?');
    if (url.searchParams.has('rest_route')) {
      const currentRoute = url.searchParams.get('rest_route').replace(/\/+$/, '');
      const appendRoute = endpoint.replace(/^\/+/, '');
      url.searchParams.set('rest_route', currentRoute + (appendRoute ? '/' + appendRoute : ''));
    } else {
      const currentPath = url.pathname.replace(/\/+$/, '');
      const appendPath = endpoint.replace(/^\/+/, '');
      url.pathname = currentPath + (appendPath ? '/' + appendPath : '');
    }
    if (queryStr) {
      const params = new URLSearchParams(queryStr);
      for (const [k, v] of params.entries()) {
        url.searchParams.append(k, v);
      }
    }
    return url.toString();
  }
  async function api(path, options = {}) {
    const fullUrl = buildApiUrl(path);
    const headers = { ...(TapinConfig.nonce ? {'X-WP-Nonce': TapinConfig.nonce} : {}), ...options.headers };
    if (options.body && !(options.body instanceof FormData)) { headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(options.body); }
    let response;
    try {
      response = await fetch(fullUrl, {credentials:'same-origin', ...options, headers});
    } catch (err) {
      if (err.name === 'AbortError') throw err;
      throw new Error('خطا در برقراری ارتباط با سرور. لطفاً اتصال اینترنت یا تنظیمات سرور را بررسی کنید.');
    }
    let data;
    try { data = await response.json(); } catch {
      if (response.status === 413) throw new Error('حجم فایل بیش از حد مجاز سرور است.');
      throw new Error('پاسخ سرور قابل خواندن نیست. لطفاً مجدداً تلاش کنید.');
    }
    if (!response.ok) throw new Error(data.message || ('خطا در انجام عملیات (کد ' + response.status + ').'));
    return data;
  }
  const color = p => /^#[0-9a-f]{6}$/i.test(p?.color) ? p.color : '#b6a4e8';
  const safeUrl = value => { try { let str = String(value || ''); if (location.protocol === 'https:' && str.startsWith('http://')) str = 'https://' + str.slice(7); const url = new URL(str, location.href); if (location.protocol === 'https:' && url.protocol === 'http:') url.protocol = 'https:'; return /https?:/.test(url.protocol) ? esc(url.href) : ''; } catch { return ''; } };
  const providerSlug = p => /^[a-z0-9_-]+$/.test(String(p?.slug||'')) ? p.slug : 'other';
  const providerFallbackText = p => ({post:'پ',tipax:'ت'}[providerSlug(p)] || String(p?.name||'؟').trim().charAt(0) || '؟');
  const providerLogo = p => {
    const localLogo={post:'post.png',tipax:'tipax.svg'}[providerSlug(p)];
    return localLogo?safeUrl(TapinConfig.assets+'brand/'+localLogo):safeUrl(p?.logo);
  };
  const providerFallbackMarkup = p => `<span class="provider-logo-fallback provider-fallback-${providerSlug(p)}" aria-hidden="true">${esc(providerFallbackText(p))}</span>`;
  const providerLogoMarkup = (p, marker=false) => {
    const logo=providerLogo(p),classes=marker?'provider-logo provider-marker-logo':'provider-logo';
    return logo?`<img class="${classes}" data-provider-slug="${providerSlug(p)}" data-provider-fallback="${esc(providerFallbackText(p))}" src="${logo}" alt="" loading="lazy">`:providerFallbackMarkup(p);
  };
  document.addEventListener('error',event=>{
    const image=event.target;
    if(!(image instanceof HTMLImageElement)||!image.matches('.provider-logo,.provider-summary-item img'))return;
    const label=image.dataset.providerFallback||image.alt||'';
    const slug=providerSlug({slug:image.dataset.providerSlug||(label.includes('پست')?'post':label.includes('تیپاکس')?'tipax':'other')});
    const fallback=document.createElement('span');fallback.className='provider-logo-fallback provider-fallback-'+slug;fallback.setAttribute('aria-hidden','true');fallback.textContent=label?({post:'پ',tipax:'ت'}[slug]||label.trim().charAt(0)):'؟';
    image.replaceWith(fallback);
  },true);
  const badge = p => `<span class="provider-badge"><span class="provider-symbol" style="--provider:${color(p)}">${providerLogo(p)?providerLogoMarkup(p):'<i></i>'}</span>${esc(p?.name || 'سایر')}</span>`;
  const providerOptions = providers => providers.map(p => `<option value="${Number(p.id)}">${esc(p.name)}${Number(p.is_active) ? '' : ' (غیرفعال)'}</option>`).join('');
  // Dashboard groups (post / tipax / everything else) carry real provider ids so
  // every downstream lookup keeps working. "0" is the empty set: the API turns it
  // into provider_id IN (0), which matches nothing rather than matching everything.
  const NAMED_SLUGS = ['post', 'tipax'];
  const groupValue = (key, list) => {
    const ids = (key === 'other'
      ? list.filter(p => !NAMED_SLUGS.includes(p.slug))
      : list.filter(p => p.slug === key)
    ).map(p => Number(p.id));
    return ids.length ? ids.join(',') : '0';
  };
  function exportControl(container,getFilters){
    const section=document.createElement('div');section.className='export-controls';
    section.innerHTML='<button type="button" data-export><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg><span>دریافت خروجی در قالب اکسل</span></button><span role="status" aria-live="polite"></span>';
    container.append(section);const button=section.querySelector('button'),message=section.querySelector('[role=status]');
    button.onclick=async()=>{
      button.disabled=true;message.textContent='در حال آماده‌سازی خروجی…';
      const filters={};const current=new URLSearchParams(getFilters());
      ['provider_id','province','city','search','status','issue','has_coordinates'].forEach(key=>{if(current.has(key))filters[key]=current.get(key);});
      try{
        const response=await fetch(buildApiUrl('exports/points'),{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':TapinConfig.nonce,'Content-Type':'application/json'},body:JSON.stringify(filters)});
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
  const normalize = value => String(value).replace(/\u064a/g,'\u06cc').replace(/\u0643/g,'\u06a9').replace(/[\s\u200c]/g,'');
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
    // Keep geography independent of provider coverage; prefer stored spellings for filters.
    const locationRows=[...locations,...(window.TapinLocationCatalog||[]).flatMap(p=>p.cities.map(city=>({province:p.province,city})))];
    const detailId='tapin-detail-'+(++widgetSequence);
    container.dataset.view='map';
    container.innerHTML = `<div class="map-tools"><div class="provider-tabs" role="group" aria-label="ارائه‌دهنده"><button type="button" class="selected" aria-pressed="true" data-provider="">همه ارائه‌دهندگان</button>${providers.map(p => `<button type="button" aria-pressed="false" data-provider="${Number(p.id)}">${badge(p)}</button>`).join('')}</div><div class="map-selects"><label><span>استان</span><select data-province autocomplete="off"><option value="">همه استان‌ها</option></select></label><label><span>شهر</span><select data-city autocomplete="off"><option value="">همه شهرها</option></select></label><label><span>مختصات</span><select data-coordinates autocomplete="off" aria-label="فیلتر نقاط بر اساس مختصات"><option value="">همه نقاط</option><option value="1">دارای مختصات</option><option value="0">بدون مختصات</option></select></label><button type="button" data-reset title="نمایش سراسر ایران" aria-label="نمایش سراسر ایران"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="1.5"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3"/></svg><span>سراسر ایران</span></button></div></div>
      <form class="locator-search" role="search" autocomplete="off"><label><span class="sr-only">جستجوی شعبه، شهر، استان یا ارائه‌دهنده</span><input name="search" type="search" autocomplete="off" placeholder="نام شعبه، شهر یا ارائه‌دهنده…"></label><button type="submit" class="${admin?'btn-search':''}" title="جستجو"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.35-4.35"/></svg><span>جستجو</span></button><button type="button" data-clear title="پاک کردن فیلترها"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg><span>پاک کردن فیلترها</span></button></form>
      <div class="locator-view-switch" role="group" aria-label="شیوه نمایش" ${admin?'hidden':''}><button type="button" data-view="map" aria-pressed="true">نقشه</button><button type="button" data-view="list" aria-pressed="false">فهرست نشانی‌ها</button></div>
      <div class="locator-results"><section class="map-viewport" aria-label="نقشه و راهنما"><div class="tapin-map" role="region" aria-label="نقشه نقاط خدماتی؛ با کلیدهای جهت حرکت کنید" tabindex="0"></div><div class="map-legend" dir="rtl" role="group" aria-label="راهنمای ارائه‌دهندگان"></div></section>
      <section class="directory-panel" aria-label="فهرست نقاط خدماتی"><div class="map-status" role="status"></div><p class="directory-hint">فهرست شامل همه نتایج فیلترهاست؛ نقشه فقط نقاط دارای مختصات در محدوده دیده‌شده را نشان می‌دهد.</p><div class="map-list" hidden></div><div class="map-actions"><button type="button" data-retry hidden><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg><span>تلاش دوباره</span></button><button type="button" data-more hidden><span>نمایش نقاط بیشتر</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button><button type="button" data-list ${admin?'':'hidden'}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg><span>فهرست قابل دسترس نقاط</span></button></div></section></div>`;
    if(admin){
      const tools=container.querySelector('.map-tools');
      const title=document.createElement('button');title.type='button';title.className='filter-title';title.innerHTML='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg><span>فیلترها</span><svg class="chev" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>';title.setAttribute('aria-expanded','true');title.setAttribute('aria-label','نمایش و پنهان کردن فیلترها');title.setAttribute('title','نمایش / پنهان کردن فیلترها');tools.prepend(title);
      const select=document.createElement('select');select.dataset.providerSelect='';select.setAttribute('aria-label','ارائه‌دهنده');select.setAttribute('autocomplete','off');
      select.innerHTML='<option value="">همه ارائه‌دهندگان</option>'+[['post','شرکت ملی پست'],['tipax','تیپاکس'],['other','سایر']]
        .map(([key,label])=>`<option value="${groupValue(key,providers)}">${esc(label)}</option>`).join('');
      tools.querySelector('.provider-tabs').hidden=true;tools.append(select);
      tools.append(container.querySelector('.map-selects'),container.querySelector('.locator-search'));
      select.onchange=()=>{selected=select.value;refreshFilters();};
      // Fold the whole filter row behind this trigger. Pending search/provider
      // edits are committed first, but a pure toggle must not reload: a reload
      // would silently jump pagination back to page 1.
      title.onclick=()=>{
        clearTimeout(searchTimer);
        const s=container.querySelector('.locator-search input[name=search]');
        const typed=s?s.value.trim():'', provider=select.value;
        if(typed!==search||provider!==selected){search=typed;selected=provider;refreshFilters();}
        const folded=tools.classList.toggle('filters-collapsed');
        title.setAttribute('aria-expanded',String(!folded));
      };
      // The field is folded behind its جستجو trigger so the crowded row only
      // shows buttons. Opening swaps in the short placeholder and lights up the
      // trigger; searching still travels the form (Enter) or the type debounce.
      const searchForm=tools.querySelector('.locator-search');
      const searchField=searchForm.querySelector('input[name=search]');
      const searchTrigger=searchForm.querySelector('.btn-search');
      const searchId='tapin-search-'+(++widgetSequence);
      searchField.id=searchId;searchField.placeholder='نام شعبه';
      searchForm.classList.remove('search-open');
      searchTrigger.type='button';
      searchTrigger.setAttribute('aria-expanded','false');
      searchTrigger.setAttribute('aria-controls',searchId);
      const setSearchOpen=open=>{
        searchForm.classList.toggle('search-open',open);
        searchTrigger.setAttribute('aria-expanded',String(open));
        if(open)searchField.focus();
      };
      searchTrigger.onclick=()=>{
        if(!searchForm.classList.contains('search-open')){setSearchOpen(true);return;}
        searchForm.requestSubmit();
      };
      searchField.addEventListener('keydown',e=>{
        if(e.key==='Escape'){e.preventDefault();setSearchOpen(false);searchTrigger.focus();}
      });
      const directoryPanel=container.querySelector('.directory-panel');
      const hero=container.closest('.dashboard-hero');
      if(hero&&directoryPanel){hero.after(directoryPanel);directoryPanel.classList.add('panel','dashboard-directory-panel');}
    }
    const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const map = L.map(container.querySelector('.tapin-map'), {preferCanvas:false, scrollWheelZoom:false, zoomControl:false, zoomSnap:0.1, minZoom:3, maxZoom:19,zoomAnimation:false,fadeAnimation:!reducedMotion,markerZoomAnimation:!reducedMotion});
    let iran=L.latLngBounds([[24.6,43.5],[40.2,63.5]]);
    const countryPadding=L.point(28,48);
    let countryFrame;
    const fitIran=()=>{map.fitBounds(iran,{animate:false,padding:countryPadding});countryFrame={center:map.getCenter(),zoom:map.getZoom()};};
    fitIran();
    // Refit only an untouched country frame when its viewport changes. A user
    // who has panned or zoomed retains their current map interaction.
    const resizeCountry=()=>{if(countryFrame&&Math.abs(map.getZoom()-countryFrame.zoom)<.01&&map.project(map.getCenter()).distanceTo(map.project(countryFrame.center))<2)fitIran();};
    map.on('resize',resizeCountry);
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
    map.createPane('country-context');map.getPane('country-context').style.zIndex='351';
    fetch(TapinConfig.assets+'neighbor-countries.geojson').then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{
      if(geoCancelled)return;
      L.geoJSON(data,{pane:'country-context',interactive:false,style:{color:'#5c6a80',weight:.8,opacity:.25,fill:false}}).addTo(map);
      data.features.forEach(f=>countryLabels.push(L.marker(f.properties.label,{interactive:false,keyboard:false,icon:L.divIcon({className:'country-label',html:esc(f.properties.name),iconSize:[100,20]})}).addTo(map)));
      declutterCountryLabels();
      map.attributionControl.addAttribution('<a href="https://www.naturalearthdata.com/">Natural Earth</a>');
    }).catch(()=>{if(!geoCancelled){const note=document.createElement('p');note.className='tile-warning';note.textContent='مرز کشورهای پیرامون بارگذاری نشد. برای تلاش دوباره صفحه را تازه کنید.';container.append(note);}});
    map.createPane('boundaries');map.getPane('boundaries').style.zIndex='350';
    let iranGeometry=null;
    const geographyReady=fetch(TapinConfig.assets+'iran-provinces.geojson').then(r=>{if(!r.ok)throw Error();return r.json();}).then(data=>{if(data && !geoCancelled) {
      iranGeometry=data.features;
      // Use every province component (including islands and the northern strip),
      // rather than a manually chosen rectangle, for the country-wide frame.
      iran=L.geoJSON(data).getBounds();
      const holes=[];
      data.features.forEach(feature=>{
        const polygons=feature.geometry.type==='MultiPolygon'?feature.geometry.coordinates:[feature.geometry.coordinates];
        polygons.forEach(polygon=>holes.push(polygon[0].map(([lng,lat])=>[lat,lng])));
      });
      // Opaque exterior prevents foreign cities/capitals baked into raster tiles leaking through.
      L.polygon([[[-85,-180],[-85,180],[85,180],[85,-180]],...holes],{pane:'boundaries',interactive:false,stroke:false,fillColor:'#08192b',fillOpacity:1,fillRule:'evenodd'}).addTo(map);
      map.getPane('tilePane').style.opacity='1';
      map.createPane('provinces');map.getPane('provinces').style.zIndex='352';
      L.geoJSON(data,{pane:'provinces',interactive:true,style:feature=>({className:'tapin-province-boundary',color:'#8fd6ef',opacity:.3,weight:.6,lineCap:'round',lineJoin:'round',smoothFactor:1.8,fillColor:['#087ac0','#155bd2','#5140c4','#008c9a'][Object.keys(provinceNames).indexOf(feature.properties.shapeName)%4],fillOpacity:.14}),onEachFeature:(feature, polygon)=>{
        const name=provinceNames[feature.properties.shapeName];
        if(name){polygon.on('mouseover',()=>polygon.setStyle({fillOpacity:.24,weight:.95}));polygon.on('mouseout',()=>{polygon.setStyle({fillOpacity:.14,weight:.6});updateLegend();});polygon.on('add',()=>{const path=polygon.getElement();if(path){path.setAttribute('tabindex','0');path.setAttribute('role','button');path.setAttribute('aria-label',name);path.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();polygon.fire('click');}});}});const key=normalize(name);if(!provinceLayers.has(key))provinceLayers.set(key,L.featureGroup());provinceLayers.get(key).addLayer(polygon);polygon.bindTooltip(name,{direction:'center'});polygon.on('click',()=>{
          const option=[...province.options].find(o=>normalize(o.value)===normalize(name));
          if(!option){province.add(new Option(name,name));}
          province.value=option?option.value:name;province.onchange();
        });}
      }}).addTo(map);
      map.attributionControl.addAttribution('<a href="https://www.geoboundaries.org/">geoBoundaries</a> / OSM');
      if(province.value)zoomToProvince();else fitIran();
      updateLegend();if(items.length)renderDirectory();
    }}).catch(()=>{if(!geoCancelled){const note=document.createElement('p');note.className='tile-warning';note.setAttribute('role','alert');note.textContent='مرز استان‌ها بارگذاری نشد؛ فهرست نشانی‌ها در دسترس است. برای بازیابی نقشه صفحه را تازه کنید.';container.append(note);}});
    const layer = L.layerGroup().addTo(map);
    const directoryPanel = (container.closest('#tapin-content')||container.parentElement||document).querySelector('.directory-panel')||container.querySelector('.directory-panel');
    const status = directoryPanel.querySelector('.map-status');
    const more = directoryPanel.querySelector('[data-more]');
    const retry = directoryPanel.querySelector('[data-retry]');
    const listToggle = directoryPanel.querySelector('[data-list]');
    const mapList = directoryPanel.querySelector('.map-list');
    if(!admin){retry.className='locator-retry';container.querySelector('.locator-search')?.after(retry);}else{retry.remove();}
    const province = container.querySelector('[data-province]');
    const city = container.querySelector('[data-city]');
    const coordinates = container.querySelector('[data-coordinates]');
    function zoomToProvince(){
      const bounds=provinceLayers.get(normalize(province.value))?.getBounds();
      if(bounds?.isValid())map.fitBounds(bounds,{animate:false,padding:[12,12]});else fitIran();
    }
    province.value = '';
    city.value = '';
    city.innerHTML='<option value="">همه شهرها</option>'+[...new Set(locations.map(l=>l.city))].map(c=>`<option>${esc(c)}</option>`).join('');
    let selected = '', coordinateFilter = '', search = '', page = 1, generation = 0, controller, items = [];
    const markers = new Map();
    const drawer=document.createElement('dialog');drawer.className='tapin-detail';drawer.dir='rtl';drawer.setAttribute('aria-labelledby',detailId);drawer.setAttribute('role','dialog');drawer.setAttribute('aria-modal','true');
    drawer.innerHTML=`<header><h2 id="${detailId}">اطلاعات نقطه خدماتی</h2><button type="button" data-close aria-label="بستن اطلاعات شعبه"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><div class="detail-body"></div>`;
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
        let navigation='';
        if(hasCoords){
          const lat=Number(p.latitude),lng=Number(p.longitude);
          // Neshan's web route requires decimal coordinates; keep the stored
          // numeric precision, including integer coordinates and small fractions.
          const decimal=value=>value.toLocaleString('en-US',{useGrouping:false,minimumFractionDigits:1,maximumFractionDigits:20});
          const routes=[
            {key:'neshan',name:'نشان',url:`https://neshan.org/maps/routing/car/destination/${decimal(lat)},${decimal(lng)}`},
            {key:'google',name:'گوگل‌مپ',url:`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(lat+','+lng)}`},
            // Balad's destination parameter uses longitude first.
            {key:'balad',name:'بلد',url:`https://balad.ir/directions/driving?destination=${encodeURIComponent(lng+','+lat)}`}
          ];
          navigation=`<section class="detail-routing" aria-labelledby="${detailId}-routing-${Number(p.id)}"><h4 id="${detailId}-routing-${Number(p.id)}">مسیریابی به شعبه</h4><p class="detail-routing-hint">مسیریاب دلخواهتان را انتخاب کنید</p><div class="detail-route-options">${routes.map(route=>`<a class="directions-btn detail-route-option" data-route-provider="${route.key}" href="${esc(route.url)}" target="_blank" rel="noopener noreferrer" aria-label="${esc('مسیریابی با '+route.name+' به '+p.name+'؛ باز شدن در پنجرهٔ جدید')}"><span class="detail-route-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m21 3-7 18-4-7-7-4 18-7Z"/><path d="m10 14 5-5"/></svg></span><span>${route.name}</span></a>`).join('')}</div></section>`;
        }
        return `<article class="detail-branch"><div class="detail-identity">${badge(providers.find(pr=>Number(pr.id)===Number(p.provider_id)))}<h3>${esc(p.name)}</h3>${p.province||p.city?'<p class="detail-location">'+[p.province,p.city].filter(Boolean).map(esc).join('، ')+'</p>':''}</div>${details(p,true)}${!hasCoords?'<p class="coordinate-note">موقعیت روی نقشه هنوز در دسترس نیست · نشانی متنی</p>':navigation}</article>`;
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
      const options=values=>{
        const unique=new Map();
        values.filter(Boolean).forEach(value=>{const key=normalize(value);if(!unique.has(key))unique.set(key,value);});
        return [...unique.values()].sort((a,b)=>a.localeCompare(b,'fa')).map(value=>'<option>'+esc(value)+'</option>').join('');
      };
      const oldProvince=province.value, oldCity=city.value;
      province.innerHTML='<option value="">همه استان‌ها</option>'+options(locationRows.map(l=>l.province));
      // Keep an explicitly chosen province selectable even when the current
      // filter has no rows for it, so "0 نتیجه" reads as a real state.
      if(oldProvince&&![...province.options].some(o=>o.value===oldProvince))province.add(new Option(oldProvince,oldProvince));
      province.value=oldProvince;
      city.innerHTML='<option value="">همه شهرها</option>'+options(locationRows.filter(l=>!province.value||normalize(l.province)===normalize(province.value)).map(l=>l.city));
      city.value=[...city.options].some(o=>o.value===oldCity)?oldCity:'';city.disabled=city.options.length===1;
    }
    function updateLegend(){
      container.querySelector('.map-legend').innerHTML='<span class="map-legend-item"><img src="'+safeUrl(TapinConfig.assets+'markers/post.png')+'" alt=""><span>پست</span></span><span class="map-legend-item"><img src="'+safeUrl(TapinConfig.assets+'markers/tipax.png')+'" alt=""><span>تیپاکس</span></span><span class="map-legend-item"><img src="'+safeUrl(TapinConfig.assets+'markers/other.png')+'" alt=""><span>سایر</span></span>';
      container.querySelectorAll('[data-provider]').forEach(b=>{const active=b.dataset.provider===selected;b.classList.toggle('selected',active);b.setAttribute('aria-pressed',String(active));});
      provinceLayers.forEach((polygon,name)=>polygon.setStyle({color:name===normalize(province.value)?'#a5e3ff':'#8fd6ef',weight:name===normalize(province.value)?1.1:.6,fillOpacity:name===normalize(province.value)?.3:.14}));
    }
    function setView(view){
      container.dataset.view=view;
      container.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));
      if(view==='map')requestAnimationFrame(()=>{if(!geoCancelled){map.invalidateSize({pan:false});if(province.value)zoomToProvince();}});
    }
    container.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>setView(b.dataset.view));
    const details = (p,structured=false) => {
      const contacts=[['تلفن همراه',p.mobile_phone],['تلفن ثابت',p.landline_phone],['تلفن',p.phone]].filter(([,value])=>value).flatMap(([label,value])=>String(value).split(/\s*[/;|]\s*/).map(part=>[label,part]));
      const field=(label,value)=>structured?'<div class="detail-field"><dt>'+label+'</dt><dd>'+value+'</dd></div>':'<p>'+label+': '+value+'</p>';
      const contactFields=(p.postal_code?field('کد پستی','<bdi>'+esc(p.postal_code)+'</bdi>'):'')+contacts.map(([label,value])=>{
        const normalized=String(value).replace(/[۰-۹]/g,d=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g,d=>'٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        // Keep extension notes visible without appending them to the telephone number.
        const main=normalized.match(/^\s*(\+?[0-9][0-9\s().-]{5,})/);
        const dial=main?main[1].replace(/[^+0-9]/g,''):'';
        return field(label,dial?'<a aria-label="'+esc('تماس با '+p.name+'، '+label)+'" href="tel:'+esc(dial)+'"><bdi>'+esc(value)+'</bdi></a>':'<bdi>'+esc(value)+'</bdi>');
      }).join('');
      const address=p.address?(structured?'<section class="detail-address" aria-label="نشانی شعبه"><h4>نشانی شعبه</h4><p>'+esc(p.address)+'</p></section>':'<p>'+esc(p.address)+'</p>'):'';
      return address+(structured&&contactFields?'<dl class="detail-contact-grid">'+contactFields+'</dl>':contactFields);
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
      const params = new URLSearchParams({search,provider_id:selected,province:province.value,city:city.value,per_page:admin?'10':'50',page:String(admin?requestedPage:append?page+1:1),include_summary:'1',status:'any'});
      if(coordinateFilter!=='')params.set('has_coordinates',coordinateFilter);
      if(admin)params.set('map_view','1');
      try {
        const data = await api((admin?'points':'public/directory')+'?'+params, {signal:controller.signal});
        if (token !== generation || geoCancelled) return;
        if (!append) { items=[]; page=admin?requestedPage:1; } else page++;
        items.push(...data.items);
        if(data.summary||!admin){
          const width=container.clientWidth;
          onSummary(data.summary);
          if(!admin&&width!==container.clientWidth)map.invalidateSize({pan:false});
        }
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
      } catch(e) { if(e.name!=='AbortError') { status.textContent=admin?'دریافت فهرست انجام نشد.':e.message; if(!admin)retry.hidden=false; more.disabled=false; } }
      finally{if(token===generation){mapList.setAttribute('aria-busy','false');}}
    }
    let markerController, markerTimer, markerGeneration=0;
    const markerStatus=document.createElement('p');markerStatus.className='marker-status';markerStatus.setAttribute('role','status');container.querySelector('.tapin-map').after(markerStatus);
    function drawMarkers(points){
      layer.clearLayers();markers.clear();
      const groups=new Map();
      const clusters=[];
      const countryView=iranGeometry?.length&&map.getZoom()<=map.getBoundsZoom(iran,false,countryPadding.multiplyBy(2))+.1;
      const provinceFor=p=>iranGeometry?.find(f=>normalize(provinceNames[f.properties.shapeName]||'')===normalize(p.province||'')&&insideGeometry(p,f.geometry))||iranGeometry?.find(f=>insideGeometry(p,f.geometry));
      // Keep even small neighboring provinces represented at country scale.
      // Geometry, not the record's province label, determines the marker group.
      const clusterSpacing=100;
      points.forEach(p=>{const feature=provinceFor(p),provinceKey=feature?.properties.shapeName||'',xy=map.latLngToContainerPoint([p.latitude,p.longitude]);
        if(countryView){
          let group=groups.get(provinceKey);
          if(!group){group={points:[],provinceKey};groups.set(provinceKey,group);clusters.push(group);}
          group.points.push(p);return;
        }
        const x=Math.floor(xy.x/clusterSpacing),y=Math.floor(xy.y/clusterSpacing);let match;
        for(let dx=-1;dx<=1&&!match;dx++)for(let dy=-1;dy<=1&&!match;dy++)match=(groups.get(provinceKey+':'+(x+dx)+':'+(y+dy))||[]).find(g=>Math.hypot(g.xy.x-xy.x,g.xy.y-xy.y)<clusterSpacing);
        if(match)match.points.push(p);else{const group={xy,points:[p],provinceKey},key=provinceKey+':'+x+':'+y;if(!groups.has(key))groups.set(key,[]);groups.get(key).push(group);clusters.push(group);}
      });
      const clusterPosition=(group,provinceKey)=>{
        let x=0,y=0;
        group.forEach(point=>{const projected=map.latLngToContainerPoint([point.latitude,point.longitude]);x+=projected.x;y+=projected.y;});
        const center=L.point(x/group.length,y/group.length),position=map.containerPointToLatLng(center);
        const withinProvince=iranGeometry?.some(f=>f.properties.shapeName===provinceKey&&insideGeometry({latitude:position.lat,longitude:position.lng},f.geometry));
        if(!countryView&&(!iranGeometry?.length||withinProvince))return position;
        // A real member nearest the projected center stays on land, including
        // concave coastal provinces and groups spread over separate islands.
        const representative=group.reduce((best,p)=>map.latLngToContainerPoint([p.latitude,p.longitude]).distanceTo(center)<map.latLngToContainerPoint([best.latitude,best.longitude]).distanceTo(center)?p:best);
        return [representative.latitude,representative.longitude];
      };
      const providerById=new Map(providers.map(pr=>[Number(pr.id),pr]));
      const categoryFor=row=>{const slug=providerSlug(providerById.get(Number(row.provider_id)));return NAMED_SLUGS.includes(slug)?slug:'other';};
      const markerBadgeSize=20;
      const positionCluster=cluster=>{
        const group=cluster.points,counts={post:0,tipax:0,other:0};group.forEach(row=>counts[categoryFor(row)]++);
        const brands=['post','tipax','other'].filter(slug=>counts[slug]>0);
        const isCluster=countryView||group.length>1;
        const position=isCluster?clusterPosition(group,cluster.provinceKey):[group[0].latitude,group[0].longitude];
        return {...cluster,counts,brands,isCluster,position,xy:map.latLngToContainerPoint(position)};
      };
      const positioned=clusters.map(positionCluster);
      if(!positioned.length)return;
      const chartGroups=positioned.filter(group=>group.isCluster);
      if(chartGroups.length){
        // Keep provincial groups separate. Only the badge's screen layout moves;
        // a leader marks the unchanged geographic anchor when space is tight.
        const radius=markerBadgeSize/2+3,size=map.getSize();
        let landPixels;
        if(iranGeometry?.length){
          const mask=document.createElement('canvas');mask.width=Math.ceil(size.x);mask.height=Math.ceil(size.y);
          const ctx=mask.getContext('2d');ctx.fillStyle=ctx.strokeStyle='#fff';ctx.lineWidth=1.5;
          iranGeometry.forEach(feature=>{
            ctx.beginPath();
            const polygons=feature.geometry.type==='MultiPolygon'?feature.geometry.coordinates:[feature.geometry.coordinates];
            polygons.forEach(rings=>rings.forEach(ring=>{ring.forEach(([lng,lat],i)=>{const p=map.latLngToContainerPoint([lat,lng]);if(i)ctx.lineTo(p.x,p.y);else ctx.moveTo(p.x,p.y);});ctx.closePath();}));
            // Close subpixel seams between province polygons. The disk's
            // extra margin still keeps its visible edge inside the coastline.
            ctx.fill('evenodd');ctx.stroke();
          });
          landPixels=ctx.getImageData(0,0,mask.width,mask.height).data;
        }
        const circleOnLand=xy=>{
          if(!landPixels)return true;
          // Check the whole disk against the union, preserving internal province
          // borders while rejecting narrow coastal inlets and exterior space.
          for(let y=Math.floor(xy.y-radius);y<=Math.ceil(xy.y+radius);y++)for(let x=Math.floor(xy.x-radius);x<=Math.ceil(xy.x+radius);x++){
            if(Math.hypot(x+.5-xy.x,y+.5-xy.y)<=radius&&landPixels[(y*Math.ceil(size.x)+x)*4+3]<250)return false;
          }
          return true;
        };
        const fitsLand=xy=>xy.x>=radius&&xy.y>=radius&&xy.x<=size.x-radius&&xy.y<=size.y-radius&&circleOnLand(xy);
        const layouts=chartGroups.map(group=>{
          const candidates=[];
          if(fitsLand(group.xy))candidates.push(group.xy);
          for(let distance=16;distance<=160;distance+=16)for(let angle=0;angle<16;angle++){
            const candidate=L.point(group.xy.x+distance*Math.cos(angle*Math.PI/8),group.xy.y+distance*Math.sin(angle*Math.PI/8));
            if(fitsLand(candidate))candidates.push(candidate);
          }
          return {group,candidates};
        });
        // Coastal groups have fewer feasible positions. Place them first and
        // rearrange nearby badges when a dense province has no free position.
        const assignments=new Map(),byGroup=new Map(layouts.map(layout=>[layout.group,layout]));let attempts=0;
        const place=(layout,locked=new Set(),depth=0)=>{
          const nextLocked=new Set(locked);nextLocked.add(layout.group);
          for(const center of layout.candidates){
            if(++attempts>8000)return false;
            const conflicts=[...assignments].filter(([group,p])=>group!==layout.group&&p.distanceTo(center)<markerBadgeSize+3).map(([group])=>group);
            if(!conflicts.length){assignments.set(layout.group,center);return true;}
            if(depth>=5||conflicts.some(group=>nextLocked.has(group)))continue;
            const previous=new Map(assignments);assignments.set(layout.group,center);conflicts.forEach(group=>assignments.delete(group));
            if(conflicts.every(group=>place(byGroup.get(group),nextLocked,depth+1)))return true;
            assignments.clear();previous.forEach((p,group)=>assignments.set(group,p));
          }
          return false;
        };
        let crowded=false;
        layouts.sort((a,b)=>a.candidates.length-b.candidates.length).forEach(layout=>{attempts=0;if(!place(layout))crowded=true;});
        if(crowded){
          // A regular inland lattice guarantees separation even when every
          // province is populated on mobile. Choose the least displacement
          // assignment; geographic anchors and province groups stay intact.
          const spacing=markerBadgeSize+3,rowSpacing=spacing*Math.sqrt(3)/2;
          let slots=[];
          for(const dx of [0,spacing/4,spacing/2,spacing*3/4])for(const dy of [0,rowSpacing/4,rowSpacing/2,rowSpacing*3/4]){
            const candidates=[];
            for(let y=radius+dy,row=0;y<=size.y-radius;y+=rowSpacing,row++)for(let x=radius+dx+(row%2)*spacing/2;x<=size.x-radius;x+=spacing){const p=L.point(x,y);if(fitsLand(p))candidates.push(p);}
            if(candidates.length>slots.length)slots=candidates;
          }
          if(slots.length>=chartGroups.length){
            // Rectangular minimum-cost matching (rows = badges, columns =
            // non-overlapping slots). Potentials keep each augmenting path
            // cheap without exponential layout retries.
            const n=chartGroups.length,m=slots.length,u=Array(n+1).fill(0),v=Array(m+1).fill(0),owner=Array(m+1).fill(0),way=Array(m+1).fill(0);
            for(let i=1;i<=n;i++){
              owner[0]=i;let j0=0;
              const best=Array(m+1).fill(Infinity),used=Array(m+1).fill(false);
              do{
                used[j0]=true;const i0=owner[j0];let delta=Infinity,j1=0;
                for(let j=1;j<=m;j++)if(!used[j]){
                  const cost=chartGroups[i0-1].xy.distanceTo(slots[j-1])**2-u[i0]-v[j];
                  if(cost<best[j]){best[j]=cost;way[j]=j0;}
                  if(best[j]<delta){delta=best[j];j1=j;}
                }
                for(let j=0;j<=m;j++){if(used[j]){u[owner[j]]+=delta;v[j]-=delta;}else best[j]-=delta;}
                j0=j1;
              }while(owner[j0]);
              do{const j1=way[j0];owner[j0]=owner[j1];j0=j1;}while(j0);
            }
            assignments.clear();
            for(let j=1;j<=m;j++)if(owner[j])assignments.set(chartGroups[owner[j]-1],slots[j-1]);
          }
        }
        // An exceptionally small container may have fewer inland slots than
        // badges; retain every geographic marker rather than dropping records.
        chartGroups.forEach(group=>{if(!assignments.has(group))assignments.set(group,group.xy);});
        chartGroups.forEach(group=>{group.badgeOffset=assignments.get(group).subtract(group.xy);});
      }
      positioned.forEach(({points:group,provinceKey,position,counts,brands,isCluster,badgeOffset=L.point(0,0)})=>{
        const p=group[0];
        // Overview/province groups and unresolved clusters always use charts.
        // Only a separated branch uses its existing pin at the stored coordinate.
        const pinProviderClass=' '+(brands.length===1?brands[0]+'-marker ':'mixed-marker ')+(isCluster?'map-badge chart-marker tapin-cluster ':'branch-pin ')+(countryView?'province-aggregate':'');
        const iconSize=isCluster?[markerBadgeSize,markerBadgeSize]:[40,60],iconAnchor=isCluster?[markerBadgeSize/2-badgeOffset.x,markerBadgeSize/2-badgeOffset.y]:[20,60];
        let html;
        if(badgeOffset.x||badgeOffset.y){
          const labelPosition=map.containerPointToLatLng(map.latLngToContainerPoint(position).add(badgeOffset));
          L.polyline([position,labelPosition],{className:'marker-leader',interactive:false,weight:1,opacity:.55}).addTo(layer);
        }
        if(isCluster){
          const palette={post:'#ffbd18',tipax:'#00ba88',other:'#dc3448'};let start=0;
          const stops=brands.map(slug=>{const end=start+counts[slug]/group.length*100,stop=palette[slug]+' '+start+'% '+end+'%';start=end;return stop;});
          html='<span class="marker-chart" style="background:conic-gradient('+stops.join(',')+')" aria-hidden="true"></span>';
        }else{
          const slug=brands[0],tipOffsets={post:11.71875,tipax:9.984375,other:9.890625};
          // Preserve the supplied PNG padding; its visible tip ends at the anchor.
          html='<img class="provider-pin-image" style="top:'+(tipOffsets[slug]*40/48)+'px" src="'+safeUrl(TapinConfig.assets+'markers/'+slug+'.png')+'" alt="" draggable="false">';
        }
        const clusterComposition=[['post','پست'],['tipax','تیپاکس'],['other','سایر']].filter(([slug])=>counts[slug]).map(([slug,label])=>num(counts[slug])+' '+label).join('، ');
        const clusterLabel=num(group.length)+' شعبه: '+clusterComposition+(countryView?' · تجمیع استانی '+(provinceNames[provinceKey]||provinceKey):'');
        const members=[...new Set(group.map(row=>providerById.get(Number(row.provider_id))?.name).filter(Boolean))];
        const marker=L.marker(position,{title:isCluster?num(group.length)+' شعبه':p.name,icon:L.divIcon({className:'tapin-pin '+(selected?'provider-pin':'all-pin')+pinProviderClass,html,iconSize,iconAnchor})}).addTo(layer);
        marker.getElement().setAttribute('aria-label',isCluster?clusterLabel+' ('+members.join('، ')+')؛ بزرگ‌نمایی یا مشاهده فهرست':p.name+'؛ '+(providerById.get(Number(p.provider_id))?.name||'سایر')+'؛ اطلاعات شعبه · '+clusterLabel);
        if(countryView)marker.getElement().dataset.markerProvince=provinceNames[provinceKey]||provinceKey;
        marker.bindTooltip(document.createTextNode(isCluster?clusterLabel+' ('+members.join('، ')+')':p.name+(providerById.get(Number(p.provider_id))?.name?' · '+providerById.get(Number(p.provider_id)).name:'')+' · '+clusterLabel),{direction:'top',offset:isCluster?[badgeOffset.x,badgeOffset.y-markerBadgeSize/2-4]:[0,-54]});
        marker.getElement().addEventListener('focus',()=>marker.openTooltip());
        marker.getElement().addEventListener('blur',()=>marker.closeTooltip());
        marker.getElement().addEventListener('keydown',e=>{
          if(e.key==='Enter'||e.key===' '){e.preventDefault();e.stopPropagation();marker.fire('click');}
        });
        if(isCluster){marker.on('click',()=>{if(map.getZoom()<18){map.fitBounds(group.map(x=>[x.latitude,x.longitude]),{maxZoom:map.getZoom()+2,padding:[30,30]});}else{openDetails(group,marker.getElement());}});}else{marker.on('click',()=>openDetails([p],marker.getElement()));}
        group.forEach(x=>markers.set(x.id,marker));
      });
    }
    async function loadMarkers(){
      if(geoCancelled)return;
      markerController?.abort();markerController=new AbortController();const token=++markerGeneration;
      layer.clearLayers();markers.clear();if(coordinateFilter==='0'){markerStatus.textContent='نقطه‌ای با مختصات برای نمایش روی نقشه نیست؛ نشانی‌ها در فهرست زیر نمایش داده می‌شوند.';return;}markerStatus.textContent='در حال دریافت نشانگرها…';
      const points=[];
      try{await geographyReady;if(geoCancelled||token!==markerGeneration)return;
      const bounds=map.getBounds();
      const params=new URLSearchParams({search,provider_id:selected,province:province.value,city:city.value,status:admin?'any':'active',per_page:'500',north:String(Math.min(90,bounds.getNorth())),south:String(Math.max(-90,bounds.getSouth())),east:String(Math.min(180,bounds.getEast())),west:String(Math.max(-180,bounds.getWest())),has_coordinates:'1'});
      if(admin)params.set('map_view','1');
      const inIran=p=>{if(!validCoordinates(p))return false;if(iranGeometry?.length)return iranGeometry.some(f=>insideGeometry(p,f.geometry));const lat=Number(p.latitude),lng=Number(p.longitude);return lat>=24&&lat<=41&&lng>=43&&lng<=65;};let next=1,totalPages=1;do{params.set('page',String(next));const data=await api((admin?'points':'public/points')+'?'+params,{signal:markerController.signal});if(token!==markerGeneration)return;points.push(...data.items.filter(inIran));totalPages=data.total_pages;next++;}while(next<=totalPages);drawMarkers(points);markerStatus.textContent=num(points.length)+' نقطه دارای مختصات در محدوده نقشه';}
      catch(e){if(e.name!=='AbortError'){markerStatus.textContent=e.message;if(!admin)retry.hidden=false;}}
    }
    function scheduleMarkers(){if(geoCancelled)return;layer.clearLayers();markers.clear();clearTimeout(markerTimer);markerController?.abort();markerGeneration++;markerTimer=setTimeout(loadMarkers,180);}
    map.on('moveend',scheduleMarkers);
    function refreshFilters(){updateLocations();updateLegend();load();scheduleMarkers();}
    coordinates.onchange=()=>{coordinateFilter=coordinates.value;refreshFilters();};
    container.querySelectorAll('[data-provider]').forEach(button=>button.onclick=()=>{selected=button.dataset.provider;refreshFilters();});
    province.onchange=()=>{city.value='';updateLocations();updateLegend();map.stop();zoomToProvince();load();scheduleMarkers();};
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
    // Cities get the same treatment provinces already have. The province layer is
    // drawn from GeoJSON, cities are not, so their bounds come from the branches
    // themselves — one coordinates-only request, cancelled if the pick changes.
    let cityBoundsController, cityBoundsGeneration=0;
    const zoomToCity=()=>{
      cityBoundsController?.abort();
      if(!city.value||geoCancelled||coordinateFilter==='0')return;
      const token=++cityBoundsGeneration;
      const ctrl=cityBoundsController=new AbortController();
      const params=new URLSearchParams({province:province.value,city:city.value,provider_id:selected,status:admin?'any':'active',has_coordinates:'1',per_page:'500'});
      api((admin?'points':'public/points')+'?'+params,{signal:ctrl.signal}).then(data=>{
        if(token!==cityBoundsGeneration||geoCancelled)return;
        const latLngs=(data.items||[]).filter(validCoordinates).map(p=>[Number(p.latitude),Number(p.longitude)]);
        if(!latLngs.length)return;
        map.stop();
        // A single branch would otherwise fitBounds all the way to max zoom.
        if(latLngs.length===1){map.setView(latLngs[0],15,{animate:false});return;}
        const bounds=L.latLngBounds(latLngs);
        if(bounds.isValid())map.fitBounds(bounds,{animate:false,maxZoom:16});
      }).catch(()=>{});
    };
    city.onchange=()=>{load();scheduleMarkers();zoomToCity();};
    container.querySelector('[data-reset]').onclick=()=>{clearTimeout(searchTimer);search='';const s=container.querySelector('.locator-search input[name=search]');if(s)s.value='';province.value='';city.value='';updateLocations();updateLegend();map.stop();fitIran();load();scheduleMarkers();};
    function clearFilters(){clearTimeout(searchTimer);selected='';if(admin)container.querySelector('[data-provider-select]').value='';coordinateFilter='';coordinates.value='';search='';province.value='';city.value='';container.querySelector('[name=search]').value='';refreshFilters();map.stop();fitIran();}
    container.querySelector('[data-clear]').onclick=clearFilters;
    const pagerSlot=document.createElement('div');pagerSlot.className='dashboard-pager';
    if(admin){
      mapList.after(pagerSlot);
      more.hidden=true;
    }
    let pagination,listPanel,listAnimation;
    let listExpanded=!mapList.hidden;
    const listMotion=admin?window.matchMedia('(prefers-reduced-motion: reduce)'):null;
    if(admin){
      const toggleRow=document.createElement('div');toggleRow.className='map-actions directory-toggle';mapList.before(toggleRow);toggleRow.append(listToggle);
      pagination=document.createElement('div');pagination.className='map-actions directory-pagination';pagination.hidden=mapList.hidden;mapList.after(pagination);pagination.append(pagerSlot,more,status);
      mapList.id=detailId+'-list';pagination.id=detailId+'-pages';listToggle.setAttribute('aria-controls',mapList.id+' '+pagination.id);
      listPanel=document.createElement('div');listPanel.className='directory-list-panel';mapList.before(listPanel);listPanel.append(mapList,pagination);
    }
    if(admin)exportControl(directoryPanel,()=>({search,provider_id:selected,province:province.value,city:city.value,has_coordinates:coordinateFilter,status:'any'}));
    more.onclick=()=>admin?load(false,page+1):load(true); retry.onclick=()=>{load();loadMarkers();};
    const listIcon='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>';
    if(admin)listToggle.innerHTML=listIcon+'<span class="directory-toggle-label"><span class="directory-toggle-width" aria-hidden="true">نمایش فهرست نقاط</span><span data-list-label>بستن فهرست نقاط</span></span>';
    const finishListToggle=()=>{
      listAnimation?.cancel();listAnimation=null;
      listPanel.classList.remove('is-animating');
      listPanel.hidden=mapList.hidden=pagination.hidden=!listExpanded;
    };
    const settleReducedMotion=()=>{if(listMotion.matches&&listAnimation)finishListToggle();};
    listMotion?.addEventListener('change',settleReducedMotion);
    listToggle.onclick=()=>{
      if(admin){
        // Capture the current frame before cancelling, so a rapid reversal
        // continues from the visible height rather than snapping to an endpoint.
        const fromHeight=listPanel.getBoundingClientRect().height;
        const fromOpacity=listPanel.hidden?0:Number(getComputedStyle(listPanel).opacity);
        listAnimation?.cancel();listAnimation=null;
        listExpanded=!listExpanded;
        if(!listExpanded&&listPanel.contains(document.activeElement))listToggle.focus({preventScroll:true});
        listPanel.inert=!listExpanded;
        listPanel.setAttribute('aria-hidden',String(!listExpanded));
        listToggle.setAttribute('aria-expanded',String(listExpanded));
        listToggle.querySelector('[data-list-label]').textContent=listExpanded?'بستن فهرست نقاط':'نمایش فهرست نقاط';
        listPanel.hidden=mapList.hidden=pagination.hidden=false;
        if(listMotion.matches||!listPanel.animate){finishListToggle();return;}
        const toHeight=listExpanded?listPanel.getBoundingClientRect().height:0;
        listPanel.classList.add('is-animating');
        const animation=listPanel.animate([
          {height:fromHeight+'px',opacity:fromOpacity},
          {height:toHeight+'px',opacity:listExpanded?1:0}
        ],{duration:260,easing:'cubic-bezier(.25,.1,.25,1)',fill:'both'});
        listAnimation=animation;
        animation.finished.then(()=>{if(listAnimation===animation)finishListToggle();},()=>{});
        return;
      }
      mapList.hidden=!mapList.hidden;
      listToggle.setAttribute('aria-expanded',String(!mapList.hidden));
    };
    mapList.onclick=e=>{if(e.target.closest('[data-empty-clear]')){clearFilters();return;}const b=e.target.closest('[data-point],[data-details]');if(!b)return;const p=items.find(x=>Number(x.id)===Number(b.dataset.point||b.dataset.details));if(!p)return;if(b.dataset.point&&validCoordinates(p)&&iranGeometry?.some(f=>insideGeometry(p,f.geometry))){setView('map');map.setView([p.latitude,p.longitude],15);}openDetails([p],b);};
    load();loadMarkers();
    requestAnimationFrame(()=>{if(!geoCancelled)map.invalidateSize();});
    // Admin enrichment can refresh in the background; public discovery updates
    // only after filter, search, retry, pagination, or viewport actions.
    const refreshTimer=admin?setInterval(()=>{const detailOpen=drawer.showModal?drawer.open:!drawer.hidden;if(!geoCancelled&&!document.hidden&&!detailOpen&&page===1&&!container.contains(document.activeElement)){load();scheduleMarkers();}},60000):null;
    return () => {geoCancelled=true;listAnimation?.cancel();listMotion?.removeEventListener('change',settleReducedMotion);clearInterval(refreshTimer);detailGeneration++;detailController?.abort();cityBoundsController?.abort();clearTimeout(searchTimer);if(drawer.close&&drawer.open)drawer.close();drawer.remove();if(admin&&directoryPanel&&directoryPanel.parentElement&&directoryPanel.parentElement!==container)directoryPanel.remove();map.off('resize',resizeCountry);map.off('moveend',scheduleMarkers);map.off('moveend',declutterCountryLabels);clearTimeout(markerTimer);markerController?.abort();controller?.abort();map.remove();};
  }
  window.Tapin = {api,esc,num,badge,providerOptions,mapWidget,color,safeUrl,providerLogoMarkup,exportControl,paginationBar};
  document.querySelectorAll('.tapin-public-root').forEach(async root=>{
    try { const data=await api('public/filters'); root.innerHTML='<header class="tapin-header public-header"><div><h1>نقاط خدماتی تاپین</h1><p>جست‌وجو و مشاهدهٔ شعب پست، تیپاکس و سایر ارائه‌دهندگان</p></div></header><main class="public-content"><section class="dashboard-hero public-overview-empty"><aside class="dashboard-overview" hidden><div class="metric-rail panel public-metric"></div><div class="provider-overview panel public-providers"></div></aside><div class="map-panel dashboard-map"></div></section><footer class="locator-coverage">این فهرست شامل نقاط ثبت‌شده است و پوشش کامل شعب سراسر ایران را نشان نمی‌دهد. <a href="https://tapin.ir/map/" target="_blank" rel="noopener noreferrer">مرجع پستی تاپین</a></footer></main>'; const overview=root.querySelector('.public-metric'),distribution=root.querySelector('.public-providers'); const render=s=>{const shell=overview.parentElement,hero=shell.parentElement;const hasSummary=s&&['total','located','missing'].every(key=>s[key]!==null&&s[key]!==''&&Number.isFinite(Number(s[key]))&&Number(s[key])>=0)&&Array.isArray(s.distribution);shell.hidden=!hasSummary;hero.classList.toggle('public-overview-empty',!hasSummary);if(!hasSummary){overview.innerHTML='';distribution.innerHTML='';return;}const total=Number(s.total)||0,located=Number(s.located)||0,groups=['post','tipax','other'].map(slug=>{const members=data.providers.filter(p=>slug==='other'?!['post','tipax'].includes(p.slug):p.slug===slug),count=(s.distribution||[]).filter(d=>members.some(p=>Number(p.id)===Number(d.provider_id))).reduce((sum,d)=>sum+Number(d.total),0);return {slug,name:slug==='post'?'شرکت ملی پست':slug==='tipax'?'تیپاکس':'سایر',logo:members[0]?.logo,provider:members[0],count,color:slug==='post'?'#ffbf24':slug==='tipax'?'#00d59b':'#7948ff'};}); const item=g=>{const pct=total?Math.round(g.count/total*100):0,icon=g.slug!=='other'&&g.provider?providerLogoMarkup(g.provider):'<i aria-hidden="true">◈</i>';return '<div class="provider-summary-item" style="--provider:'+g.color+'"><span class="provider-logo">'+icon+'</span><strong>'+num(g.count)+'</strong><div class="distribution-bar"><span style="width:'+pct+'%"></span></div><small>'+num(pct)+'٪ · '+esc(g.name)+'</small></div>';}; overview.innerHTML='<div class="eyebrow">نمای کلی شبکه نقاط خدماتی</div><div class="metric-total"><strong class="hero-number">'+num(total)+'</strong><h2>نقطه خدماتی</h2></div><div class="coordinate-totals"><div><b>'+num(located)+'</b><span>دارای مختصات</span></div><div><b>'+num(Number(s.missing)||0)+'</b><span>بدون مختصات</span></div></div><div class="coverage"><span style="width:'+(total?located/total*100:0)+'%"></span></div>'; distribution.innerHTML='<h2>توزیع نقاط بر اساس ارائه‌دهنده</h2>'+groups.map(item).join('');}; const panel=root.querySelector('.map-panel'); mapWidget(panel,data.providers,data.locations,false,render); const directory=panel.querySelector('.directory-panel'); directory.classList.add('panel','dashboard-directory-panel');root.querySelector('.dashboard-hero').after(directory); }
    catch(e){root.innerHTML=`<p role="alert">${esc(e.message)}</p><button type="button" onclick="location.reload()">تلاش دوباره</button>`;}
  });
})();
