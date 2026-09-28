/* global Tapin, TapinConfig */
(() => {
  'use strict';
  const root=document.getElementById('tapin-admin'); if(!root)return;
  const {api,esc,num,badge,providerOptions,mapWidget,color,safeUrl}=Tapin;
  const labels={name:'نام شعبه',code:'کد شعبه',province:'استان',city:'شهر',address:'نشانی',phone:'تلفن قدیمی',mobile_phone:'تلفن همراه',landline_phone:'تلفن ثابت',source:'منبع',metadata:'اطلاعات تکمیلی',postal_code:'کد پستی',latitude:'عرض جغرافیایی',longitude:'طول جغرافیایی',status:'وضعیت'};
  const statuses={preview:'آماده بررسی',running:'در حال ورود / قابل ادامه',completed:'تکمیل‌شده',cancelled:'لغوشده',expired:'منقضی‌شده'};
  const icons={dashboard:'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',points:'<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',imports:'<path d="M14 2H5v20h14V7Z M14 2v6h5 M8 12h8 M8 16h6"/>',providers:'<rect x="3" y="7" width="12" height="11" rx="2"/><path d="M15 10h4l3 4v4h-7 M7 7V4h7"/><circle cx="7" cy="19" r="2"/><circle cx="18" cy="19" r="2"/>',settings:'<circle cx="12" cy="12" r="4"/><path d="m10 2 4 0 1 3 3 1 3 1 1 4-2 2-1 3 0 3-4 2-2-2-3 0-3 1-3-3 1-3-1-3-2-2 2-4 3 0Z"/>'};
  const icon=name=>`<svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">${icons[name]||icons.points}</svg>`;
  let providers=[],locations=[],cleanup=()=>{},routeToken=0,activeJob=null;
  const provider=id=>providers.find(p=>Number(p.id)===Number(id));
  const go=hash=>{location.hash=hash;};
  const notify=(text,error=false)=>{const box=root.querySelector('#tapin-notice');box.className='tapin-notice'+(error?' error':'');box.textContent=text;box.hidden=false;box.focus();};
  function shell(){root.innerHTML=`<aside class="tapin-sidebar"><a href="#dashboard" class="brand"><img src="${safeUrl(TapinConfig.assets+'brand/tapin.png')}" alt="تاپین"><span>شبکه نقاط خدماتی</span></a><nav aria-label="ناوبری تاپین">${[['dashboard','داشبورد'],['points','نقاط خدماتی'],['imports','ورود فایل‌ها'],['providers','ارائه‌دهندگان'],['settings','راهنما و تنظیمات']].map(([key,title])=>`<a href="#${key}" data-nav="${key}">${icon(key)}${title}</a>`).join('')}</nav><div class="sidebar-foot"><span class="live-dot"></span> مدیریت یکپارچه شبکه <small>Tapin Service Point Locator</small></div></aside><div class="tapin-main"><header class="tapin-header"><div><h1 id="tapin-title">داشبورد تاپین</h1><p>مدیریت و نمایش نقاط خدماتی در سراسر کشور</p></div><div class="header-actions"><a class="text-action" href="#points?new=1">＋ افزودن شعبه جدید</a><a class="primary" href="#imports">↥ افزودن فایل</a></div></header><div id="tapin-notice" class="tapin-notice" role="status" tabindex="-1" hidden></div><main id="tapin-content" tabindex="-1"></main><footer class="tapin-footer">تاپین · اطلاعات واقعی، تصمیم‌های دقیق <span>نسخه ۱٫۰</span></footer></div><dialog class="tapin-dialog" aria-labelledby="dialog-title"></dialog>`;}
  const content=()=>root.querySelector('#tapin-content');
  const empty=(message,action='')=>`<div class="empty">${icon('points')}<p>${message}</p>${action}</div>`;
  const qualityBadge=p=>{
    const geo=p.metadata?.geocoding?.status;
    if(!p.has_coordinates&&['pending','processing','retry'].includes(geo))return '<span class="badge muted">در انتظار موقعیت</span>';
    if(!p.has_coordinates&&['failed','blocked'].includes(geo))return '<span class="badge warning">موقعیت‌یابی نیازمند بررسی</span>';
    if(p.data_quality_status==='needs_review')return '<span class="badge warning">نیازمند بررسی</span>';
    if(!p.has_coordinates||p.data_quality_status==='missing_coordinates')return '<span class="badge muted">بدون مختصات</span>';
    return '<span class="badge good">کامل</span>';
  };
  const pointTable=(items,compact=false)=>items.length?`<div class="table-scroll"><table><thead><tr>${compact?'<th>نام شعبه</th><th>ارائه‌دهنده</th><th>شهر</th><th>مختصات</th>':'<th>ارائه‌دهنده</th><th>نام شعبه و کد</th><th>استان و شهر</th><th>نشانی</th><th>تلفن</th><th>مختصات</th><th>کیفیت</th><th>وضعیت</th><th>عملیات</th>'}</tr></thead><tbody>${items.map(p=>`<tr>${compact?`<td><a href="#points?edit=${p.id}">${esc(p.name)}</a></td><td>${badge(provider(p.provider_id))}</td><td>${esc(p.city)}</td><td><span class="${p.has_coordinates?'good':'bad'}">${p.has_coordinates?'● ثبت‌شده':'! بدون مختصات'}</span></td>`:`<td>${badge(provider(p.provider_id))}</td><td><a href="#points?view=${p.id}" data-point-view="${p.id}"><strong>${esc(p.name)}</strong></a><small>${esc(p.code||'بدون کد')}</small></td><td>${esc(p.province)}، ${esc(p.city)}</td><td><span class="cell-address" title="${esc(p.address)}">${esc(p.address)}</span></td><td><bdi>${esc(p.mobile_phone||p.landline_phone||p.phone||'—')}</bdi></td><td><span class="${p.has_coordinates?'good':'bad'}">${p.has_coordinates?'● ثبت‌شده':'! بدون مختصات'}</span></td><td>${qualityBadge(p)}</td><td><span class="status-dot ${p.status==='active'?'good':'muted'}"></span>${p.status==='active'?'فعال':'غیرفعال'}</td><td><div class="tapin-row-actions"><button type="button" data-view="${p.id}" title="مشاهده جزئیات">مشاهده</button> <button type="button" data-edit="${p.id}" title="ویرایش">ویرایش</button> <button type="button" class="danger" data-delete="${p.id}" title="حذف">حذف</button></div></td>`}</tr>`).join('')}</tbody></table></div>`:empty('هنوز نقطه خدماتی ثبت نشده است.','<a href="#points?new=1">افزودن اولین نقطه خدماتی ←</a>');
  const importTable=jobs=>jobs.length?`<div class="table-scroll"><table><thead><tr><th>نام فایل</th><th>وضعیت</th><th>نتیجه</th></tr></thead><tbody>${jobs.map(j=>`<tr><td><a href="#imports?id=${j.id}"><bdi>${esc(j.filename)}</bdi></a><small>${esc(new Date(j.created_at.replace(' ','T')+'Z').toLocaleString('fa-IR',{dateStyle:'short',timeStyle:'short'}))}</small></td><td><span class="${j.status==='completed'?'good':j.status==='failed'?'bad':''}">${esc(statuses[j.status]||j.status)}</span></td><td><span class="good">${num(j.data.inserted+j.data.updated)} موفق</span><small class="${j.data.failed?'bad':'muted'}">${num(j.data.failed)} ناموفق · ${num(j.data.skipped)} تکراری</small></td></tr>`).join('')}</tbody></table></div>`:empty('هنوز فایلی وارد نشده است.','<a href="#imports">ورود اولین فایل ←</a>');
  async function dashboard(token){
    if(token!==routeToken)return;
    content().innerHTML='<section class="dashboard-hero"><aside class="dashboard-overview"><div class="metric-rail panel"></div><div class="provider-overview panel"></div></aside><div class="map-panel dashboard-map"></div></section><section class="distribution panel"><div class="drawer-title"><h2>نقاط خدماتی</h2><p>بر اساس ارائه‌دهنده</p></div><div class="distribution-items" id="provider-summary"></div><button type="button" class="drawer-toggle" aria-expanded="true" aria-controls="provider-summary" aria-label="بستن خلاصه ارائه‌دهندگان">⌃</button></section>';
    const render=s=>{
      const groups=['post','tipax','other'].map(slug=>{const members=providers.filter(p=>slug==='other'?!['post','tipax'].includes(p.slug):p.slug===slug);return {slug,name:slug==='post'?'شرکت ملی پست':slug==='tipax'?'تیپاکس':'سایر',logo:members[0]?.logo,count:s.distribution.filter(d=>members.some(p=>Number(p.id)===Number(d.provider_id))).reduce((sum,d)=>sum+Number(d.total),0),color:slug==='post'?'#ffbf24':slug==='tipax'?'#00d59b':'#7948ff'};});
      const item=(g,compact=false)=>{
        const pct=s.total?Math.round(g.count/s.total*100):0;
        return '<div class="provider-summary-item" style="--provider:'+g.color+'"><span class="provider-logo" tabindex="0" aria-label="'+esc(g.name)+'" data-tooltip="'+esc(g.name)+'">'+(g.slug!=='other'&&g.logo?'<img src="'+safeUrl(g.logo)+'" alt="'+esc(g.name)+'">':'<span>'+esc(g.name)+'</span>')+'</span><strong>'+num(g.count)+'</strong><div class="distribution-bar"><span style="width:'+pct+'%"></span></div><small>'+num(pct)+'٪</small></div>';
      };
      content().querySelector('.metric-rail').innerHTML='<div class="eyebrow">نمای کلی شبکه نقاط خدماتی</div><strong class="hero-number">'+num(s.total)+'</strong><h2>نقطه خدماتی</h2><div class="coverage"><span style="width:'+(s.total?s.located/s.total*100:0)+'%"></span></div><div class="coordinate-totals"><div><b>'+num(s.located)+'</b>دارای مختصات</div><div class="bad"><b>'+num(s.missing)+'</b>بدون مختصات</div></div>';
      content().querySelector('.provider-overview').innerHTML='<h2>توزیع نقاط خدماتی بر اساس ارائه‌دهنده</h2>'+groups.map(g=>item(g,true)).join('');
      content().querySelector('.distribution-items').innerHTML=groups.map(g=>item(g)).join('');
    };
    render({total:0,located:0,missing:0,distribution:[]});
    cleanup=mapWidget(content().querySelector('.map-panel'),providers,locations,true,render);
    const toggle=content().querySelector('.drawer-toggle');toggle.onclick=()=>{const items=content().querySelector('.distribution-items');items.hidden=!items.hidden;toggle.setAttribute('aria-expanded',String(!items.hidden));toggle.setAttribute('aria-label',items.hidden?'نمایش خلاصه ارائه‌دهندگان':'بستن خلاصه ارائه‌دهندگان');};
  }
  function filtersForm(params){return `<form id="point-filters" class="filters"><label class="search-field"><span class="sr-only">جستجو</span><input name="search" placeholder="جستجوی شعبه، نشانی، کد یا تلفن…" value="${esc(params.get('search')||'')}"></label><label><span class="sr-only">ارائه‌دهنده</span><select name="provider_id"><option value="">همه ارائه‌دهندگان</option>${providerOptions(providers)}</select></label><label><span class="sr-only">استان</span><select name="province"><option value="">همه استان‌ها</option>${[...new Set(locations.map(l=>l.province))].map(p=>`<option>${esc(p)}</option>`).join('')}</select></label><label><span class="sr-only">شهر</span><select name="city"><option value="">همه شهرها</option></select></label><label><span class="sr-only">مختصات</span><select name="has_coordinates"><option value="">همه مختصات</option><option value="1">دارای مختصات</option><option value="0">بدون مختصات</option></select></label><label><span class="sr-only">وضعیت</span><select name="status"><option value="any">همه وضعیت‌ها</option><option value="active">فعال</option><option value="inactive">غیرفعال</option></select></label><label><span class="sr-only">نیازمند بررسی</span><select name="issue"><option value="">همه اطلاعات</option><option value="duplicate">مشکوک به تکرار</option><option value="incomplete">اطلاعات ناقص</option></select></label><button type="submit" class="primary">اعمال فیلتر</button><a href="#points">پاک کردن</a></form>`;}
  function pointViewDialog(point){
    if(!point)return;
    const prov=provider(point.provider_id);
    const valid=Boolean(point.has_coordinates&&point.latitude!==null&&point.longitude!==null&&Number.isFinite(Number(point.latitude))&&Number.isFinite(Number(point.longitude)));
    const coordsText=valid?`${point.latitude} , ${point.longitude}`:'موقعیت مکانی روی نقشه ثبت نشده است (نشانی متنی)';
    const phones=[['تلفن همراه',point.mobile_phone],['تلفن ثابت',point.landline_phone],['تلفن عمومی',point.phone]].filter(([,val])=>val);
    const body=`<div class="point-view-sheet"><div class="sheet-hero">${badge(prov)}<div class="sheet-title-group"><h3>${esc(point.name)}</h3><p><bdi>${esc(point.code||'بدون کد شعبه')}</bdi> · <span class="${point.status==='active'?'good':'muted'}">${point.status==='active'?'● فعال':'○ غیرفعال'}</span></p></div></div><div class="sheet-grid"><div class="sheet-section"><h4>اطلاعات مکانی</h4><div class="kv"><span>استان:</span><b>${esc(point.province)}</b></div><div class="kv"><span>شهر:</span><b>${esc(point.city)}</b></div><div class="kv"><span>نشانی:</span><p class="sheet-address">${esc(point.address)}</p></div>${point.postal_code?`<div class="kv"><span>کد پستی:</span><bdi>${esc(point.postal_code)}</bdi></div>`:''}<div class="kv"><span>مختصات:</span><bdi class="${valid?'good':'bad'}">${coordsText}</bdi></div><div class="kv"><span>کیفیت داده:</span><span>${qualityBadge(point)}</span></div>${point.source?`<div class="kv"><span>منبع داده:</span><bdi class="sheet-source">${esc(point.source)}</bdi></div>`:''}</div><div class="sheet-section"><h4>راه‌های ارتباطی</h4>${phones.length?phones.map(([lbl,val])=>`<div class="kv"><span>${lbl}:</span><a href="tel:${esc(String(val).replace(/[^0-9+]/g,''))}"><bdi>${esc(val)}</bdi></a></div>`).join(''):'<p class="muted">شماره تلفنی ثبت نشده است.</p>'}</div>${point.metadata&&typeof point.metadata==='object'&&Object.keys(point.metadata).length?`<div class="sheet-section full"><h4>اطلاعات تکمیلی (JSON)</h4><pre class="sheet-meta" dir="ltr"><code>${esc(JSON.stringify(point.metadata,null,2))}</code></pre></div>`:''}</div><div class="dialog-actions sheet-actions"><button type="button" class="primary" data-switch-edit="${point.id}">ویرایش این نقطه</button> <button type="button" data-close-sheet>بستن</button></div></div>`;
    modal('مشاهده نقطه خدماتی',body,async()=>{});
    const dialog=root.querySelector('dialog');
    dialog.querySelector('[data-switch-edit]')?.addEventListener('click',()=>{dialog.close();pointDialog(point);});
    dialog.querySelector('[data-close-sheet]')?.addEventListener('click',()=>dialog.close());
  }
  async function pointsPage(params,token){
    const query=new URLSearchParams(params);query.delete('edit');query.delete('new');query.delete('view');query.set('per_page','20');query.set('order','DESC');
    const data=await api('points?'+query);if(token!==routeToken)return;
    content().innerHTML=`<section class="panel"><div class="section-title"><h2>نقاط خدماتی <small>${num(data.total)} نتیجه</small></h2><a href="#points?new=1" class="primary">＋ افزودن نقطه</a></div>${filtersForm(params)}${params.get('issue')==='duplicate'?'<p class="help">کد یکسان یا نام و نشانی یکسان در یک شهر و ارائه‌دهنده، فقط نشانه احتمال تکرار است. پیش از حذف، رکوردها را بررسی کنید.</p>':''}${params.get('issue')==='incomplete'?'<p class="help">رکوردهای فاقد تلفن، نشانی، استان یا شهر.</p>':''}${pointTable(data.items)}<div class="pagination"><button type="button" data-page="${data.page-1}" ${data.page<=1?'disabled':''}>قبلی</button><span>صفحه ${num(data.page)} از ${num(Math.max(1,data.total_pages))}</span><button type="button" data-page="${data.page+1}" ${data.page>=data.total_pages?'disabled':''}>بعدی</button></div></section>`;
    const form=content().querySelector('#point-filters');
    mountGeocoding(form,data.items,token);
    ['provider_id','province','has_coordinates','status','issue'].forEach(key=>{if(params.has(key))form.elements[key].value=params.get(key);});
    const updateCities=()=>{const old=form.elements.city.value;const rows=locations.filter(l=>(!form.elements.province.value||l.province===form.elements.province.value)&&(!form.elements.provider_id.value||Number(l.provider_id)===Number(form.elements.provider_id.value)));const cities=[...new Set(rows.map(l=>l.city).filter(Boolean))];form.elements.city.innerHTML='<option value="">همه شهرها</option>'+cities.map(c=>'<option>'+esc(c)+'</option>').join('');form.elements.city.value=cities.includes(old)?old:'';form.elements.city.disabled=!cities.length;};
    updateCities();if(params.get('city')&&[...form.elements.city.options].some(o=>o.value===params.get('city')))form.elements.city.value=params.get('city');form.elements.province.onchange=updateCities;form.elements.provider_id.onchange=updateCities;
    form.onsubmit=e=>{e.preventDefault();const values=new URLSearchParams();new FormData(form).forEach((v,k)=>{if(v)values.set(k,v);});go('points?'+values);};
    content().querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{query.set('page',b.dataset.page);go('points?'+query);});
    content().querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>pointViewDialog(data.items.find(p=>p.id===Number(b.dataset.view))));
    content().querySelectorAll('[data-point-view]').forEach(a=>a.onclick=e=>{e.preventDefault();pointViewDialog(data.items.find(p=>p.id===Number(a.dataset.pointView)));});
    content().querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>pointDialog(data.items.find(p=>p.id===Number(b.dataset.edit))));
    content().querySelectorAll('[data-delete]').forEach(b=>b.onclick=()=>confirmDelete('این نقطه خدماتی حذف شود؟',async()=>{await api('points/'+b.dataset.delete,{method:'DELETE'});await refresh();notify('نقطه خدماتی حذف شد.');}));
    if(params.has('new'))pointDialog();
    if(params.has('view')){const found=data.items.find(p=>p.id===Number(params.get('view')))||await api('points/'+Number(params.get('view')));if(found&&token===routeToken)pointViewDialog(found);}
    if(params.has('edit')){const found=data.items.find(p=>p.id===Number(params.get('edit')))||await api('points/'+Number(params.get('edit')));if(found&&token===routeToken)pointDialog(found);}
  }
  function mountGeocoding(form,points,token){
    const panel=document.createElement('section');panel.className='geocoding-controls';panel.setAttribute('aria-label','موقعیت‌یابی نقاط خدماتی');
    panel.innerHTML='<div class="section-title"><h3>موقعیت‌یابی نشانی‌ها</h3><div class="tapin-row-actions"><button type="button" data-enrich-page>تلاش مجدد برای نقاط بدون مختصات این صفحه</button><button type="button" data-geocoding-refresh>تازه‌سازی وضعیت</button><button type="button" data-points-refresh>تازه‌سازی فهرست</button></div></div><p data-geocoding-summary role="status">در حال دریافت وضعیت…</p><p class="help">مختصات معتبر تغییر نمی‌کند. نشانی‌های مبهم یا ناسازگار تا زمان بررسی بدون نشانگر می‌مانند.</p><p data-geocoding-message role="status"></p>';
    form.after(panel);
    const summary=panel.querySelector('[data-geocoding-summary]'),message=panel.querySelector('[data-geocoding-message]');
    const batch=panel.querySelector('[data-enrich-page]');
    const unresolved=points.filter(p=>!p.has_coordinates).map(p=>Number(p.id));
    batch.disabled=!unresolved.length;
    const labels={pending:'در انتظار موقعیت',processing:'در حال موقعیت‌یابی',retry:'در انتظار تلاش مجدد',succeeded:'مختصات ثبت شد',failed:'موقعیت‌یابی ناموفق',blocked:'نیازمند بررسی نشانی',skipped:'بدون نیاز به پردازش'};
    const reasons={source_conflict:'تطبیق منبع را بررسی کنید.',insufficient_address:'نشانی دقیق و استان لازم است.',ambiguous:'چند موقعیت محتمل پیدا شد.',low_quality:'بخشی از نشانی تطبیق نداشت.',outside_iran:'نتیجه خارج از مرز ایران است.',province_mismatch:'استان نتیجه با شعبه یکسان نیست.',city_mismatch:'شهر نتیجه با شعبه یکسان نیست.',no_match:'موقعیت قابل اعتماد پیدا نشد.',inactive:'شعبه یا ارائه‌دهنده غیرفعال است.',rate_limited:'محدودیت سرویس؛ تلاش بعدی زمان‌بندی شده است.'};
    const rowControls=new Map();let busy=false,loading=false,disposed=false;
    const controller=new AbortController();
    points.filter(p=>!p.has_coordinates).forEach(point=>{
      const actions=content().querySelector('[data-view="'+Number(point.id)+'"]')?.parentElement;if(!actions)return;
      const button=document.createElement('button');button.type='button';button.textContent='یافتن موقعیت';button.setAttribute('aria-label','یافتن موقعیت '+point.name);
      const state=document.createElement('small');state.className='geocoding-row-status';state.textContent='بدون مختصات';
      actions.append(button);actions.parentElement.append(state);rowControls.set(Number(point.id),{button,state});
      button.onclick=()=>retry([Number(point.id)]);
    });
    async function loadStatus(){
      if(disposed||loading)return;loading=true;
      try{
        const data=await api('geocoding?ids='+points.map(p=>Number(p.id)).join(','),{signal:controller.signal,cache:'no-store'});
        if(disposed||token!==routeToken)return;
        const counts=data.counts||{},waiting=(counts.pending||0)+(counts.retry||0)+(counts.processing||0);
        summary.textContent=(data.configured?'صف موقعیت‌یابی: ':'سرویس موقعیت‌یابی تنظیم نشده؛ صف منتظر تنظیم کلید سرور است. ')+num(waiting)+' در انتظار · '+num(counts.succeeded)+' موفق · '+num((counts.failed||0)+(counts.blocked||0))+' نیازمند بررسی';
        data.items.forEach(job=>{const controls=rowControls.get(Number(job.point_id));if(!controls)return;controls.state.textContent=(labels[job.status]||'بدون مختصات')+' · '+num(job.attempts)+' تلاش'+(reasons[job.last_code]?' · '+reasons[job.last_code]:'');controls.button.disabled=busy||['pending','retry','processing','succeeded'].includes(job.status);});
      }catch(e){if(e.name!=='AbortError'&&!disposed)summary.textContent='دریافت وضعیت موقعیت‌یابی انجام نشد. از تازه‌سازی وضعیت استفاده کنید.';}
      finally{loading=false;}
    }
    async function retry(ids){
      if(busy||disposed)return;busy=true;batch.disabled=true;rowControls.forEach(c=>c.button.disabled=true);message.textContent='در حال ثبت درخواست…';
      try{
        const result=await api('geocoding/retry',{method:'POST',body:{ids},signal:controller.signal});
        if(disposed||token!==routeToken)return;
        const queued=result.items.filter(j=>['pending','retry','processing'].includes(j.status)).length;
        const blocked=result.items.filter(j=>['blocked','error','failed'].includes(j.status)).length;
        message.textContent=num(queued)+' نقطه در صف · '+num(blocked)+' نیازمند بررسی.'+(result.configured?'':' برای پردازش، کلید سرویس باید روی سرور تنظیم شود.');
      }catch(e){if(e.name!=='AbortError'&&!disposed)message.textContent=e.message;}
      finally{busy=false;if(!disposed){batch.disabled=!unresolved.length;rowControls.forEach(c=>c.button.disabled=false);await loadStatus();}}
    }
    batch.onclick=()=>retry(unresolved);
    panel.querySelector('[data-geocoding-refresh]').onclick=loadStatus;
    panel.querySelector('[data-points-refresh]').onclick=refresh;
    loadStatus();
    const timer=setInterval(()=>{if(!document.hidden&&!busy)loadStatus();},15000);
    const previousCleanup=cleanup;
    cleanup=()=>{disposed=true;clearInterval(timer);controller.abort();previousCleanup();};
  }
  function modal(title,body,onSubmit){
    const dialog=root.querySelector('dialog');const previous=document.activeElement;
    if(dialog.open)dialog.close();
    dialog.innerHTML=`<form method="dialog"><div class="section-title"><h2 id="dialog-title">${title}</h2><button type="button" class="close-dialog" aria-label="بستن">×</button></div><div class="dialog-error" role="alert" hidden></div>${body}</form>`;
    dialog.querySelector('.close-dialog').onclick=()=>dialog.close();
    dialog.onclose=()=>previous?.focus();
    dialog.querySelector('form').onsubmit=async e=>{e.preventDefault();const submit=e.submitter;if(submit)submit.disabled=true;const error=dialog.querySelector('.dialog-error');error.hidden=true;try{await onSubmit(new FormData(e.target));if(dialog.open)dialog.close();}catch(err){error.textContent=err.message;error.hidden=false;error.scrollIntoView({block:'nearest'});}finally{if(submit)submit.disabled=false;}};
    dialog.showModal();
  }
  function confirmDelete(title,fn){modal(title,'<p>این عمل قابل بازگشت نیست.</p><button type="submit" class="danger">حذف رکورد</button>',fn);}
  function pointDialog(point={}){
    modal(point.id?'ویرایش نقطه خدماتی':'افزودن نقطه خدماتی',`<div class="form-grid"><label>ارائه‌دهنده <select name="provider_id" required>${providerOptions(providers)}</select></label>${Object.entries(labels).filter(([key])=>!['status','address','metadata'].includes(key)).map(([key,label])=>`<label>${label}${['name','province'].includes(key)?' *':''}<input name="${key}" value="${esc(point[key]??'')}" ${['name','province'].includes(key)?'required':''} ${['phone','postal_code','code','latitude','longitude'].includes(key)?'dir="ltr"':''} maxlength="${{name:255,province:100,city:100,code:64,phone:64,mobile_phone:64,landline_phone:64,source:500,postal_code:20}[key]||64}"></label>`).join('')}<label>وضعیت<select name="status"><option value="active">فعال</option><option value="inactive">غیرفعال</option></select></label><label class="full">نشانی *<textarea name="address" required maxlength="10000">${esc(point.address||'')}</textarea></label><p class="help full">مختصات اختیاری است. هر دو را خالی بگذارید تا نقطه با وضعیت «بدون مختصات» ذخیره شود؛ این نقاط روی نقشه نمایش داده نمی‌شوند.</p><label class="full">اطلاعات تکمیلی (JSON)<textarea name="metadata" dir="ltr" placeholder="{}">${esc(point.metadata?JSON.stringify(point.metadata,null,2):'')}</textarea></label></div><div class="dialog-actions"><button type="submit" class="primary">ذخیره نقطه خدماتی</button></div>`,async fd=>{
      const data=Object.fromEntries(fd);try{data.metadata=data.metadata?JSON.parse(data.metadata):null;}catch{throw new Error('اطلاعات تکمیلی باید JSON معتبر باشد.');}
      const saved=await api('points'+(point.id?'/'+point.id:''),{method:'POST',body:data});
      root.querySelector('dialog').close();history.replaceState(null,'','#points');await refresh();notify('نقطه خدماتی ذخیره شد.'+(Object.values(saved.warnings||{}).length?' '+Object.values(saved.warnings).join(' '):''));
    });
    const form=root.querySelector('dialog form');if(point.provider_id)form.elements.provider_id.value=point.provider_id;if(point.status)form.elements.status.value=point.status;
  }
  async function providersPage(){
    const activeCount=providers.filter(p=>Number(p.is_active)).length;
    content().innerHTML=`<section class="panel"><div class="section-title"><div><h2>ارائه‌دهندگان خدمات</h2><span class="step-label">${num(activeCount)} فعال از ${num(providers.length)} ارائه‌دهنده</span></div><button type="button" class="primary" id="new-provider">＋ ارائه‌دهنده جدید</button></div><p class="help">غیرفعال کردن ارائه‌دهنده، نقاط آن را از نقشه عمومی پنهان می‌کند. حذف ارائه‌دهنده دارای نقطه خدماتی امکان‌پذیر نیست.</p><div class="provider-cards">${providers.map(p=>`<article style="--provider:${color(p)}" class="provider-card"><div class="provider-card-head">${badge(p)}<span class="badge ${Number(p.is_active)?'good':'muted'}">${Number(p.is_active)?'فعال':'غیرفعال'}</span></div><p><bdi>${esc(p.slug)}</bdi></p><div class="provider-card-actions"><button type="button" data-provider-edit="${p.id}">ویرایش</button> <button type="button" class="${Number(p.is_active)?'':'good'}" data-provider-toggle="${p.id}">${Number(p.is_active)?'غیرفعال‌سازی':'فعال‌سازی'}</button> <button type="button" class="danger" data-provider-delete="${p.id}">حذف</button></div></article>`).join('')}</div></section>`;
    content().querySelector('#new-provider').onclick=()=>providerDialog();
    content().querySelectorAll('[data-provider-edit]').forEach(b=>b.onclick=()=>providerDialog(provider(b.dataset.providerEdit)));
    content().querySelectorAll('[data-provider-toggle]').forEach(b=>b.onclick=async()=>{
      const id=Number(b.dataset.providerToggle);const pr=provider(id);if(!pr)return;b.disabled=true;
      try{await api('providers',{method:'POST',body:{...pr,id,is_active:Number(pr.is_active)?0:1}});await refresh();notify('وضعیت ارائه‌دهنده تغییر یافت.');}
      catch(err){notify(err.message,true);b.disabled=false;}
    });
    content().querySelectorAll('[data-provider-delete]').forEach(b=>b.onclick=()=>confirmDelete('این ارائه‌دهنده حذف شود؟',async()=>{await api('providers/'+b.dataset.providerDelete,{method:'DELETE'});await refresh();notify('ارائه‌دهنده حذف شد.');}));
  }
  function providerDialog(p={}){
    modal(p.id?'ویرایش ارائه‌دهنده':'ارائه‌دهنده جدید',`<div class="form-grid"><label>نام *<input name="name" value="${esc(p.name||'')}" required maxlength="100"></label><label>شناسه لاتین یکتا *<input name="slug" value="${esc(p.slug||'')}" dir="ltr" required pattern="[a-z0-9_-]+" maxlength="50"></label><label>رنگ نمایش<input name="color" type="color" value="${color(p)}"></label><label>وضعیت<select name="is_active"><option value="1">فعال</option><option value="0">غیرفعال</option></select></label><label class="full">نشانی لوگوی اصلی<input name="logo" type="url" dir="ltr" value="${esc(p.logo||'')}" placeholder="https://…"></label><p class="help full">برای ارائه‌دهنده جدید بدون لوگو، نشان خنثی نمایش داده می‌شود. از نشانی تصویر معتبر استفاده کنید.</p></div><button type="submit" class="primary">ذخیره ارائه‌دهنده</button>`,async fd=>{await api('providers',{method:'POST',body:{...Object.fromEntries(fd),id:p.id||0}});root.querySelector('dialog').close();await refresh();notify('ارائه‌دهنده ذخیره شد.');});
    root.querySelector('dialog select[name=is_active]').value=String(p.is_active??1);
  }
  async function importsPage(params,token){
    activeJob=null;
    if(params.has('id')){const job=await api('imports/'+Number(params.get('id')));if(token===routeToken)renderJob(job);return;}
    const jobs=await api('imports');if(token!==routeToken)return;
    content().innerHTML=`<section class="panel"><div class="section-title"><h2>ورود گروهی نقاط خدماتی</h2><span class="step-label">۱. انتخاب فایل ← ۲. بررسی ستون‌ها ← ۳. نتیجه</span></div><form id="upload-form" class="upload-zone">${icon('imports')}<h3>فایل نقاط خدماتی را انتخاب کنید</h3><p>CSV با کدگذاری UTF-8 یا Excel با پسوند XLSX</p><label class="file-label"><span>انتخاب فایل</span><input name="file" type="file" accept=".csv,.xlsx" required></label><button type="submit" class="primary">بارگذاری و پیش‌نمایش</button><p class="help">CSV: حداکثر ۵۰ مگابایت · XLSX: حداکثر ۱۰ مگابایت · ۱۰۰٬۰۰۰ ردیف<br>اولین برگه Excel خوانده می‌شود. فرمول‌ها را به مقدار تبدیل کنید؛ XLS قدیمی را با فرمت XLSX ذخیره کنید.</p></form><a href="${safeUrl(TapinConfig.assets+'import-template.csv')}" download>↓ دریافت نمونه ستون‌های CSV</a></section><section class="panel import-history"><div class="section-title"><h2>تاریخچه ورود فایل‌ها</h2><span>۵۰ عملیات اخیر</span></div>${importTable(jobs)}</section>`;
    content().querySelector('#upload-form').onsubmit=async e=>{e.preventDefault();const button=e.target.querySelector('button');button.disabled=true;button.textContent='در حال آماده‌سازی…';try{const job=await api('imports',{method:'POST',body:new FormData(e.target)});go('imports?id='+job.id);}catch(err){notify(err.message,true);}finally{button.disabled=false;button.textContent='بارگذاری و پیش‌نمایش';}};
  }
  function renderJob(job){
    activeJob=job;const d=job.data;
    content().innerHTML=`<section class="panel"><div class="section-title"><h2><bdi>${esc(job.filename)}</bdi></h2><a href="#imports">همه فایل‌ها ←</a></div><p>${esc(statuses[job.status])} · ${num(d.total)} ردیف</p><div id="job-detail"></div></section>`;
    const detail=content().querySelector('#job-detail');
    if(job.status==='preview'){
      detail.innerHTML=`<p class="help">پنج ردیف اول را بررسی کنید. هیچ داده‌ای تا تأیید نگاشت ثبت نمی‌شود. ردیف اول فایل، سربرگ است.</p><div class="table-scroll"><table><thead><tr>${d.headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${d.preview.map(row=>`<tr>${row.map(c=>`<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div><form id="mapping-form"><div class="form-grid"><label>ارائه‌دهنده<select name="provider_id" required>${providerOptions(providers)}</select></label><label>رکورد تکراری<select name="duplicate_action"><option value="skip">رد کردن و گزارش</option><option value="update">به‌روزرسانی فقط کد شعبه یکسان</option></select></label>${d.headers.map((h,i)=>`<label><bdi>${esc(h)}</bdi><select name="map_${i}"><option value="">نادیده گرفتن</option>${Object.entries(labels).map(([k,v])=>`<option value="${k}" ${d.mapping[h]===k?'selected':''}>${v}</option>`).join('')}</select></label>`).join('')}</div><p class="help">نام و نشانی الزامی‌اند. استان باید در فایل یا منبع تأییدشده مشخص باشد؛ شهر نامشخص خالی می‌ماند. هر فیلد فقط به یک ستون نگاشت شود. تلفن و کد پستی را در Excel به صورت متن ذخیره کنید تا صفر ابتدایی حفظ شود.</p><button type="submit" class="primary">تأیید و شروع ورود داده</button> <button type="button" data-cancel>لغو</button></form>`;
      detail.querySelector('form').onsubmit=async e=>{e.preventDefault();const button=e.submitter;button.disabled=true;const fd=new FormData(e.target);const mapping={};d.headers.forEach((h,i)=>mapping[h]=fd.get('map_'+i));try{const next=await api(`imports/${job.id}/start`,{method:'POST',body:{provider_id:fd.get('provider_id'),duplicate_action:fd.get('duplicate_action'),mapping}});renderJob(next);runJob(job.id);}catch(err){notify(err.message,true);button.disabled=false;}};
    }else{
      detail.innerHTML=`<progress max="${d.total||1}" value="${d.processed}"></progress><p role="status">${num(d.processed)} از ${num(d.total)} ردیف بررسی شده</p><div class="job-counts"><div><b class="good">${num(d.inserted)}</b>ثبت جدید</div><div><b class="good">${num(d.updated)}</b>به‌روزرسانی</div><div><b class="bad">${num(d.failed)}</b>ناموفق</div><div><b>${num(d.skipped)}</b>تکراری / ردشده</div><div><b>${num(d.warnings)}</b>موفق با هشدار</div></div>${job.status==='running'?'<p class="help">با بستن صفحه، ورود داده متوقف می‌شود. از همین صفحه می‌توانید ادامه دهید. رکوردهای ثبت‌شده باقی می‌مانند.</p><button type="button" class="primary" data-resume>ادامه ورود داده</button> <button type="button" data-cancel>لغو باقی‌مانده</button>':''}<div class="section-title"><h3>گزارش ردیف‌ها</h3><button type="button" data-download>↓ دریافت گزارش JSON</button></div><p class="help">شمارش‌ها کامل است؛ جزئیات حداکثر ۱٬۰۰۰ ردیف دارای خطا، هشدار یا احتمال تکرار نگهداری می‌شود. فایل موقت پس از تکمیل حذف می‌شود و عملیات رهاشده پس از ۲۴ ساعت منقضی می‌شود.</p>${d.issues.length?`<div class="table-scroll"><table><thead><tr><th>ردیف فایل</th><th>نتیجه</th><th>توضیح</th></tr></thead><tbody>${d.issues.map(issue=>`<tr><td>${num(issue.row)}</td><td>${esc({failed:'ناموفق',skipped:'ردشده',inserted:'ثبت با هشدار',updated:'به‌روزرسانی با هشدار'}[issue.result])}</td><td>${esc(issue.messages.join(' '))}${issue.existing_id?` <a href="#points?edit=${Number(issue.existing_id)}">بررسی رکورد موجود ←</a>`:''}</td></tr>`).join('')}</tbody></table></div>`:empty('خطا یا هشداری ثبت نشده است.')}`;
      detail.querySelector('[data-resume]')?.addEventListener('click',()=>runJob(job.id));
      detail.querySelector('[data-download]').onclick=()=>{const blob=new Blob([JSON.stringify(job,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=`tapin-import-${job.id}.json`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
    }
    detail.querySelector('[data-cancel]')?.addEventListener('click',()=>confirmDelete('باقی‌مانده عملیات لغو شود؟',async()=>{const result=await api(`imports/${job.id}/cancel`,{method:'POST'});renderJob(result);}));
  }
  let runningId=null;
  async function runJob(id){
    if(runningId===id)return;runningId=id;const token=routeToken;
    try{
      while(token===routeToken && activeJob?.status==='running' && Number(activeJob.id)===Number(id)){
        const b=content().querySelector('[data-resume]');if(b){b.disabled=true;b.textContent='در حال ورود داده…';}
        const job=await api(`imports/${id}/step`,{method:'POST'});if(token!==routeToken)break;renderJob(job);
      }
    }catch(e){notify(e.message+' برای ادامه دوباره تلاش کنید.',true);const b=content().querySelector('[data-resume]');if(b){b.disabled=false;b.textContent='ادامه ورود داده';}}
    finally{runningId=null;}
  }
  function settingsPage(){content().innerHTML=`<section class="panel guide"><h2>نمایش نقشه در سایت</h2><p>در یک برگه وردپرس، بلوک «کد کوتاه» اضافه کنید و این کد را قرار دهید:</p><code dir="ltr">[tapin_service_points]</code><h3>قواعد نمایش عمومی</h3><p>فقط نقاط فعال با مختصات معتبر و ارائه‌دهنده فعال روی نقشه عمومی نمایش داده می‌شوند. اطلاعات تکمیلی داخلی در نقشه عمومی منتشر نمی‌شود.</p><h3>اطلاعات ناقص و تکراری</h3><p>کارت «اطلاعات ناقص» رکوردهای بدون تلفن، نشانی، استان یا شهر را نشان می‌دهد. احتمال تکرار بر اساس کد شعبه یا نام و نشانی یکسان در یک شهر و ارائه‌دهنده محاسبه می‌شود؛ هیچ رکوردی خودکار ادغام یا حذف نمی‌شود.</p><h3>ارائه‌دهندگان و نشان‌ها</h3><p>نام، وضعیت، رنگ و نشانی لوگوی هر ارائه‌دهنده را در <a href="#providers">مدیریت ارائه‌دهندگان</a> ویرایش کنید.</p><h3>نقشه و حریم خصوصی</h3><p>تصاویر زمینه از OpenStreetMap دریافت می‌شود و مرورگر بازدیدکننده به سرویس نقشه متصل می‌شود. برای تغییر سرویس، فیلترهای <code>tapin_tile_url</code> و <code>tapin_tile_attribution</code> در اختیار توسعه‌دهنده سایت است. نقشه و فونت به صورت محلی بسته‌بندی شده‌اند.</p><h3>نگهداری داده‌ها</h3><p>غیرفعال‌سازی یا حذف معمول افزونه، نقاط خدماتی را پاک نمی‌کند. حذف کامل داده فقط با فعال‌سازی صریح تنظیم توسعه‌دهنده در زمان حذف افزونه انجام می‌شود.</p></section>`;}
  async function refresh(){
    const token=++routeToken;cleanup();cleanup=()=>{};activeJob=null;
    const [name='dashboard',raw='']=(location.hash.slice(1)||'dashboard').split('?');const params=new URLSearchParams(raw);
    root.querySelectorAll('[data-nav]').forEach(a=>{a.classList.toggle('active',a.dataset.nav===name);if(a.dataset.nav===name)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');});
    root.querySelector('#tapin-title').textContent=({dashboard:'داشبورد تاپین',points:'نقاط خدماتی',imports:'مدیریت فایل‌ها',providers:'ارائه‌دهندگان',settings:'راهنما و تنظیمات'})[name]||'داشبورد تاپین';
    content().innerHTML='<div class="loading" role="status"><span></span>در حال دریافت اطلاعات…</div>';
    try{
      [providers,locations]=await Promise.all([api('providers'),api('locations')]);if(token!==routeToken)return;
      if(name==='points')await pointsPage(params,token);else if(name==='imports')await importsPage(params,token);else if(name==='providers')await providersPage();else if(name==='settings'){
        settingsPage();
        content().querySelector('.guide').insertAdjacentHTML('beforeend','<h3>موقعیت‌یابی نشانی‌ها</h3><p>در صفحه نقاط خدماتی می‌توانید موقعیت‌یابی یک شعبه یا نقاط بدون مختصات همان صفحه را درخواست کنید و وضعیت صف را ببینید. پردازش در پس‌زمینه وردپرس انجام می‌شود و به فعال بودن WP-Cron نیاز دارد.</p><p>مدیر سرور باید کلید سرویس نشان را در تنظیمات امن سرور با نام <code>TAPIN_NESHAN_API_KEY</code> قرار دهد. کلید را در اطلاعات شعبه، فایل ورودی یا مرورگر وارد نکنید. تا پیش از تنظیم سرویس، نشانی‌ها در فهرست باقی می‌مانند و نشانگر ساختگی ایجاد نمی‌شود.</p><p>نشانی نرمال‌شده، استان و شهر فقط برای موقعیت‌یابی از سرور به نشان ارسال می‌شود. اطلاعات تماس ارسال نمی‌شود.</p>');
      }else await dashboard(token);
    }catch(e){if(token===routeToken){content().innerHTML=`<div class="panel error" role="alert"><h2>دریافت اطلاعات انجام نشد</h2><p>${esc(e.message)}</p><button type="button" id="retry-page">تلاش دوباره</button></div>`;content().querySelector('#retry-page').onclick=refresh;}}
  }
  shell();window.addEventListener('hashchange',()=>{root.querySelector('dialog').close();refresh();});refresh();
})();
