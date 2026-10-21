import { routeLocation, navigate } from './wordpress.js';
// Public price check page — pro layout.
// Bulk is the default view; single-provider lives under "استعلام پیشرفته".
// This file only talks to /api/* and never hardcodes provider fields.

import { api, h, clear, show, hide, fieldWrapper, pretty, formatNumber, toFaDigits, isBlank, toast, selectControl, searchableSelect, spinner, skeleton, providerLogo, isLogoImage, numericInput, stripNumericCommas, icon, preserveScroll, clearSelection } from './common.js';

const listHost = document.getElementById('provider-list');
const searchHost = document.getElementById('provider-search');
const chooseSection = document.getElementById('choose');
const formPanel = document.getElementById('form-panel');
const formHost = document.getElementById('price-form');
const resultHost = document.getElementById('result');
const titleHost = document.getElementById('form-title');
const backBtn = document.getElementById('back-to-list');

let currentProvider = null;
let allProviders = [];
let fieldSeqLocal = 1000;

/* ---------------- generic pro helpers ---------------- */

function proField(label, control, { hint = '', required = false, fieldKey = '' } = {}) {
  const wrap = fieldWrapper(label, control, hint || null);
  if (fieldKey) wrap.dataset.field = fieldKey;
  if (required) {
    const lab = wrap.querySelector('label');
    if (lab && !lab.querySelector('.req')) lab.append(h('span', { class: 'req', text: ' *' }));
  }
  const err = h('div', { class: 'field-error' });
  if (fieldKey) err.dataset.errorFor = fieldKey;
  wrap.append(err);
  return wrap;
}

// Money/weight inputs show the provider display unit (ریال/تومان/گرم/کیلوگرم)
// as a small badge pinned to the inner corner of the field itself, instead
// of carrying it in the label text. The badge is pointer-events:none so it
// never blocks typing, and the input keeps extra inline-end padding so
// digits never slide under it.
// Wrapping never clones the node, so names, values and listeners stay intact.
function unitWrap(control, unit) {
  const label = String(unit || '').trim();
  if (!control || !label) return control;
  return h('span', { class: 'input-unit' },
    control,
    h('span', { class: 'input-unit-badge', text: label }));
}

function paramIsRequired(param) {
  return !!(param && param.required);
}

function fieldIsRequired(field) {
  return !!(field && field.required);
}

function requiredMessage(label) {
  return `«${label}» اجباری است؛ لطفاً تکمیل کنید.`;
}

function setFieldError(form, key, message) {
  if (!form || !key) return false;
  const esc = (window.CSS && window.CSS.escape) ? window.CSS.escape(key) : key.replace(/"/g, '');
  const slot = form.querySelector(`[data-error-for="${esc}"]`);
  if (!slot) return false;
  slot.textContent = message || '';
  const field = slot.closest('.field');
  if (field) field.classList.toggle('has-error', Boolean(message));
  return true;
}

function clearFormErrors(form) {
  if (!form) return;
  form.querySelectorAll('.field.has-error').forEach((el) => el.classList.remove('has-error'));
  form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
}

function sectionCard(iconName, title, count, sub, gridNode, iconClass = '') {
  return h(
    'div',
    { class: 'card section-card' },
    h(
      'div',
      { class: 'section-head' },
      h('span', { class: `section-icon ${iconClass}` }, iconName),
      h('h3', { text: title }),
      count != null ? h('span', { class: 'section-count', text: count }) : null
    ),
    sub ? h('p', { class: 'section-sub', text: sub }) : null,
    gridNode
  );
}

function setBtnLoading(btn, loading, busyText, idleText) {
  if (!btn) return;
  if (loading) {
    btn.disabled = true;
    btn.dataset.idle = idleText ?? btn.textContent;
    clear(btn).append(h('span', { class: 'btn-spin' }), h('span', { text: busyText || 'در حال استعلام…' }));
  } else {
    btn.disabled = false;
    btn.textContent = btn.dataset.idle || idleText || btn.textContent;
  }
}

function copyText(text, label = 'کپی شد.') {
  const done = () => toast(label, 'ok');
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
      return;
    }
  } catch { /* fallback below */ }
  fallbackCopy(text, done);
}

function fallbackCopy(text, done) {
  try {
    const ta = h('textarea', { value: text });
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.append(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    done();
  } catch { toast('کپی ناموفق بود.'); }
}

function scrollToResults(node) {
  try {
    if (window.innerWidth < 1025 && node) node.scrollIntoView({ behavior: 'smooth', block: 'start' });
    else window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch { /* noop */ }
}

/* ---------------- single-provider: choose ---------------- */

async function loadProviders() {
  clear(listHost).append(skeleton(3));
  try {
    const { providers } = await api('/api/providers');
    allProviders = providers || [];
    paintProviderGrid('');
  } catch (error) {
    clear(listHost).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
    throw error;
  }
}

// Lazy: the providers list is fetched only when the user actually opens
// the single-inquiry tab, so the bulk page never calls /api/providers.
let providersPromise = null;
function ensureProviders() {
  if (!providersPromise) {
    providersPromise = loadProviders().catch(() => { providersPromise = null; });
  }
  return providersPromise;
}

// Rebuilds on every search keystroke; keep the scroll where the user was.
function paintProviderGrid(query) {
  preserveScroll(() => {
    clear(listHost);
    const q = (query || '').trim();
    const items = !q
      ? allProviders
      : allProviders.filter((p) => `${p.display_name || ''} ${p.name || ''}`.includes(q));
    if (!items.length) {
      listHost.append(h('div', { class: 'card' }, h('p', { class: 'empty', text: q ? 'شرکتی با این نام پیدا نشد.' : 'هیچ شرکت پستی تعریف نشده است.' })));
      return;
    }
    listHost.classList.add('provider-grid');
    items.forEach((provider) => listHost.append(providerCard(provider)));
  });
}

if (searchHost) {
  searchHost.addEventListener('input', () => paintProviderGrid(searchHost.value));
}

function providerCard(provider) {
  const visible = provider.visible_fields || [];
  const shown = visible.slice(0, 4).map((field) => h('span', { class: 'tag', text: field.label }));
  if (visible.length > shown.length) {
    shown.push(h('span', { class: 'tag tag-more', text: `+${visible.length - shown.length} مورد دیگر` }));
  }
  return h(
    'div',
    { class: 'card tile' },
    h(
      'div',
      { class: 'card-head' },
      providerLogo(provider, 50),
      h(
        'div',
        { style: 'flex:1' },
        h('h2', { text: provider.display_name }),
        h('p', { class: 'muted', style: 'margin:0', text: `${visible.length} فیلد قابل تنظیم` })
      )
    ),
    shown.length
      ? h('div', { class: 'tags' }, shown)
      : h('p', { class: 'empty', text: 'هیچ فیلد قابل نمایشی برای این شرکت تعریف نشده است.' }),
    h('button', { class: 'btn', style: 'margin-top:14px;width:100%', onClick: () => openProvider(provider.id) }, 'انتخاب و ادامه')
  );
}

async function openProvider(identifier) {
  try {
    currentProvider = await api(`/api/providers/${encodeURIComponent(identifier)}`);
  } catch (error) {
    toast(error.message);
    return;
  }
  clear(resultHost);
  resultHost.append(singleEmptyState());
  titleHost.textContent = currentProvider.display_name;
  clear(document.getElementById('form-logo')).append(providerLogo(currentProvider, 44));
  renderForm();
  hide(chooseSection);
  show(formPanel);
  go(`/advanced?provider=${encodeURIComponent(currentProvider.name)}`);
  window.scrollTo({ top: 0 });
}

function backToList() {
  currentProvider = null;
  show(chooseSection);
  hide(formPanel);
  go('/advanced');
  window.scrollTo({ top: 0 });
}
if (backBtn) backBtn.addEventListener('click', backToList);

function singleEmptyState() {
  return h(
    'div',
    { class: 'card results-empty' },
    h('div', { class: 'empty-icon' }, icon('coins')),
    h('h3', { text: 'هنوز استعلامی ثبت نشده' }),
    h('p', { text: 'فرم سمت راست را تکمیل و «ثبت و بررسی قیمت» را بزنید تا نتیجه اینجا نمایش داده شود.' })
  );
}

/* ---------------- single-provider: form ---------------- */

function isOriginParam(param) {
  return param.name === 'origin_province_id' || param.name === 'origin_city_id';
}

function describeParam(param, extra = []) {
  const head = isBlank(param.description) ? '' : param.description;
  return [head, ...extra].filter(Boolean).join(' — ');
}

function orderedSimpleParams(params) {
  const originOrder = { origin_province_id: 0, origin_city_id: 1 };
  const origin = params
    .filter(isOriginParam)
    .sort((a, b) => originOrder[a.name] - originOrder[b.name]);
  const rest = params.filter((param) => !isOriginParam(param));
  return [...origin, ...rest];
}

function defaultPairValues(provName, cityName) {
  const all = currentProvider.params || [];
  const a = all.find((item) => item.name === provName);
  const b = all.find((item) => item.name === cityName);
  const av = a && !isBlank(a.default) ? String(a.default) : null;
  const bv = b && !isBlank(b.default) ? String(b.default) : null;
  return av && bv ? { a: av, b: bv } : null;
}

function combinedPairField(provName, cityName, label, required, byName) {
  const bindings = (currentProvider.locations && currentProvider.locations.bindings) || {};
  if (locationStatus() !== 'fresh' && locationStatus() !== 'stale') return null;
  const provBinding = bindings[provName];
  const cityBinding = bindings[cityName];
  if (!provBinding || !cityBinding) return null;
  if (!Array.isArray(provBinding.items) || !provBinding.items.length) return null;
  if (!cityBinding.items_by_parent) return null;
  const provParam = byName[provName];
  return combinedLocationField({
    label,
    nameA: provName,
    nameB: cityName,
    provinces: provBinding.items,
    citiesByParent: cityBinding.items_by_parent,
    hint: provParam ? describeParam(provParam) : '',
    required,
    fieldKey: provName,
    defaults: defaultPairValues(provName, cityName),
  });
}

function renderForm() {
  clear(formHost);
  clearFormErrors(formHost);
  const locCfg = currentProvider.location_config || {};
  const originCfg = (locCfg.origin && typeof locCfg.origin === 'object') ? locCfg.origin : {};
  const destCfg = (locCfg.destination && typeof locCfg.destination === 'object') ? locCfg.destination : {};
  const originEnabled = originCfg.enabled !== false;
  const originCombined = originEnabled && originCfg.display === 'combined';
  const byName = {};
  (currentProvider.params || []).forEach((param) => { if (param && param.name) byName[param.name] = param; });
  const destProv = destCfg.enabled === true ? String(destCfg.province_param || '') : '';
  const destCity = destCfg.enabled === true ? String(destCfg.city_param || '') : '';
  const destActive = !!(destProv && destCity && byName[destProv] && byName[destCity] && destProv !== destCity);
  const destNames = destActive ? [destProv, destCity] : [];
  const simple = orderedSimpleParams(
    currentProvider.params.filter((param) => param.visible && param.input !== 'list' && param.input !== 'dict')
  );
  const lists = currentProvider.params.filter((param) => param.visible && param.input === 'list');
  const dicts = currentProvider.params.filter((param) => param.visible && param.input === 'dict');

  const gridChildren = [];
  const originParams = simple.filter(isOriginParam);
  if (originEnabled && originParams.length) {
    const combo = originCombined
      ? combinedPairField('origin_province_id', 'origin_city_id', 'مبدا', true, byName)
      : null;
    if (combo) gridChildren.push(combo);
    else originParams.forEach((param) => gridChildren.push(fieldFor(param, { required: true })));
  }
  const destinationNodes = () => {
    const provParam = byName[destProv];
    const cityParam = byName[destCity];
    if (destCfg.display === 'combined') {
      const combo = combinedPairField(
        destProv, destCity, 'مقصد',
        isBlank(provParam.default) || isBlank(cityParam.default),
        byName
      );
      if (combo) return [combo];
    }
    return [
      fieldFor(provParam, { required: isBlank(provParam.default) }),
      fieldFor(cityParam, { required: isBlank(cityParam.default) }),
    ];
  };
  let destInserted = !destActive;
  simple
    .filter((param) => !isOriginParam(param))
    .forEach((param) => {
      if (!destInserted && destNames.includes(param.name)) {
        destInserted = true;
        destinationNodes().forEach((node) => gridChildren.push(node));
        return;
      }
      if (destNames.includes(param.name)) return;
      gridChildren.push(fieldFor(param, { required: paramIsRequired(param) }));
    });
  lists.forEach((param) => {
    const rows = Math.max(param.min_items || 1, 1);
    for (let i = 0; i < rows; i += 1) {
      inlineListFields(param, i, rows).forEach((node) => gridChildren.push(node));
    }
  });
  const blocks = [];
  if (gridChildren.length) blocks.push(h('div', { class: 'section-grid' }, gridChildren));
  dicts.forEach((param) => blocks.push(dictCard(param)));
  if (blocks.length) {
    formHost.append(sectionCard(
      icon('box'), 'مشخصات استعلام', null, null,
      h('div', {}, blocks),
      ''
    ));
  }

  const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, 'ثبت و بررسی قیمت');
  const resetBtn = h('button', { class: 'btn btn-ghost', type: 'button' }, 'پاک کردن فرم');
  resetBtn.addEventListener('click', () => openProvider(currentProvider.id));
  formHost.append(
    h(
      'div',
      { class: 'form-actions glow-beam' },
      h('div', { class: 'form-actions-row' }, submitBtn, resetBtn),
      h('p', { class: 'form-actions-hint', text: 'نتیجه استعلام در ستون کناری نمایش داده می‌شود.' })
    )
  );
  formHost.dataset.submitId = 'single-submit';
  formHost.querySelector('button[type="submit"]').id = 'single-submit-btn';
}

/* ----- location + controls (logic unchanged, wrapped in proField) ----- */

function locationBinding(param) {
  const bindings = currentProvider && currentProvider.locations && currentProvider.locations.bindings;
  return (bindings && bindings[param.name]) || null;
}

function locationStatus() {
  return (currentProvider && currentProvider.locations && currentProvider.locations.status) || 'unused';
}

function populateCityOptions(select, binding, provinceValue, preserve = true) {
  const current = preserve ? select.value : '';
  clear(select);
  const itemsByParent = binding.items_by_parent || {};
  let items = [];
  if (!binding.paired_param || binding.paired_visible === false) {
    Object.values(itemsByParent).forEach((group) => { items = items.concat(group); });
  } else if (provinceValue) {
    items = itemsByParent[provinceValue] || [];
  }
  let matched = false;
  items.forEach((item) => {
    const node = h('option', { value: item.value }, item.label);
    if (current && String(current) === String(item.value)) {
      node.selected = true;
      matched = true;
    }
    select.append(node);
  });
  const needsProvince = binding.paired_param && binding.paired_visible !== false && !provinceValue;
  select.disabled = needsProvince || !items.length;
  if (matched) {
    if (typeof select.reapplySearch === 'function') select.reapplySearch();
  } else {
    clearSelection(select);
  }
}

function pairedProvinceValue(binding) {
  if (!binding.paired_param) return '';
  const provinceParam = (currentProvider.params || []).find((item) => item.name === binding.paired_param);
  if (!provinceParam) return '';
  if (binding.paired_visible === false) return '';
  return isBlank(provinceParam.default) ? '' : String(provinceParam.default);
}

function syncPairedCities(provinceName) {
  const locations = currentProvider.locations || {};
  const provinceBinding = (locations.bindings || {})[provinceName];
  if (!provinceBinding || !provinceBinding.paired_param) return;
  const cityBinding = (locations.bindings || {})[provinceBinding.paired_param];
  if (!cityBinding) return;
  const provinceSelect = formHost.querySelector(`select[name="param:${provinceName}"]`);
  const provinceValue = provinceSelect ? provinceSelect.value : '';
  formHost
    .querySelectorAll(`select[data-location-city="${provinceBinding.paired_param}"]`)
    .forEach((citySelect) => populateCityOptions(citySelect, cityBinding, provinceValue, true));
}

function locationProvinceSelect(param, binding, staleMessage, opts = {}) {
  const select = selectControl(`param:${param.name}`, binding.items || [], {
    selectedValue: isBlank(param.default) ? '' : param.default,
    onChange: () => syncPairedCities(param.name),
  });
  select.setAttribute('data-location-province', param.name);
  return proField(param.label, searchableSelect(select), { hint: describeParam(param), required: opts.required, fieldKey: param.name });
}

function locationCitySelect(param, binding, staleMessage, opts = {}) {
  const select = h('select', { name: `param:${param.name}` });
  select.setAttribute('data-location-city', param.name);
  if (binding.paired_param) select.setAttribute('data-paired-province', binding.paired_param);
  populateCityOptions(select, binding, pairedProvinceValue(binding), false);
  if (!isBlank(param.default)) {
    const wanted = String(param.default);
    const hasDefault = [...select.options].some((option) => option.value === wanted);
    if (hasDefault) select.value = wanted;
  }
  return proField(param.label, searchableSelect(select), { hint: describeParam(param), required: opts.required, fieldKey: param.name });
}

function locationUnavailableField(param, opts = {}) {
  const message = (currentProvider.locations && currentProvider.locations.message)
    || 'لیست شهر/استان در دسترس نیست.';
  if (param.options && param.options.length) {
    return proField(param.label, controlFor(param), { hint: describeParam(param), required: opts.required, fieldKey: param.name });
  }
  return proField(
    param.label,
    param.type === 'int' || param.type === 'float'
      ? numericInput(`param:${param.name}`, {
        float: param.type === 'float',
        value: isBlank(param.default) ? '' : param.default,
      })
      : h('input', {
        type: 'text',
        name: `param:${param.name}`,
        value: isBlank(param.default) ? '' : param.default,
      }),
    { hint: describeParam(param), required: opts.required, fieldKey: param.name }
  );
}

function fieldFor(param, opts = {}) {
  const isLocationParam = (param.kind === 'province' || param.kind === 'city') && param.input === 'select';
  // Money inputs carry the provider display unit as a badge inside the field.
  const label = param.label;
  if (!isLocationParam) {
    if (param.input === 'map') return mapField(param, opts);
    return proField(label, controlFor(param), { hint: describeParam(param), required: opts.required, fieldKey: param.name });
  }
  const binding = locationBinding(param);
  const status = locationStatus();
  if (binding && (status === 'fresh' || status === 'stale')) {
    const staleMessage = status === 'stale' && currentProvider.locations.message
      ? currentProvider.locations.message
      : '';
    if (param.kind === 'province') return locationProvinceSelect(param, binding, staleMessage, opts);
    return locationCitySelect(param, binding, staleMessage, opts);
  }
  if (currentProvider.locations) return locationUnavailableField(param, opts);
  return proField(label, controlFor(param), { hint: describeParam(param), required: opts.required, fieldKey: param.name });
}

function mapField(param, opts = {}) {
  const latInput = h('input', { type: 'number', name: `map:${param.name}:lat`, step: 'any', placeholder: 'lat' });
  const longInput = h('input', { type: 'number', name: `map:${param.name}:long`, step: 'any', placeholder: 'long' });
  const mapDiv = h('div', { class: 'map-canvas' });
  const wrap = h(
    'div',
    { class: 'map-picker' },
    mapDiv,
    h('div', { class: 'section-grid' },
      proField('عرض جغرافیایی (lat)', latInput, { fieldKey: `${param.name}.lat` }),
      proField('طول جغرافیایی (long)', longInput, { fieldKey: `${param.name}.long` }))
  );
  const field = proField(param.label, wrap, { hint: describeParam(param), required: opts.required, fieldKey: param.name });
  if (window.L) {
    requestAnimationFrame(() => initMiniMap(mapDiv, latInput, longInput));
  } else {
    wrap.append(h('p', { class: 'hint', text: 'کتابخانه نقشه بارگذاری نشد؛ مختصات را دستی وارد کنید.' }));
  }
  return field;
}

function initMiniMap(node, latInput, longInput) {
  try {
    const start = [35.6892, 51.3890];
    const map = window.L.map(node).setView(start, 11);
    window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    let marker = null;
    const place = (lat, long) => {
      if (!Number.isFinite(lat) || !Number.isFinite(long)) return;
      if (marker) marker.setLatLng([lat, long]);
      else marker = window.L.marker([lat, long]).addTo(map);
      map.setView([lat, long], Math.max(map.getZoom(), 13));
    };
    map.on('click', (event) => {
      const lat = Math.round(event.latlng.lat * 1e6) / 1e6;
      const long = Math.round(event.latlng.lng * 1e6) / 1e6;
      latInput.value = String(lat);
      longInput.value = String(long);
      latInput.dispatchEvent(new Event('input', { bubbles: true }));
      longInput.dispatchEvent(new Event('input', { bubbles: true }));
      place(lat, long);
    });
    const sync = () => {
      const lat = parseFloat(latInput.value);
      const long = parseFloat(longInput.value);
      if (Number.isFinite(lat) && Number.isFinite(long)) place(lat, long);
    };
    latInput.addEventListener('change', sync);
    longInput.addEventListener('change', sync);
    window.setTimeout(() => map.invalidateSize(), 80);
  } catch { /* numeric inputs keep working */ }
}

function formatMapValue(value) {
  if (!value || (value.lat == null && value.long == null)) return null;
  return `${value.lat ?? '—'}، ${value.long ?? '—'}`;
}

function mapDisplayValue(param, values) {
  const submitted = (values && typeof values === 'object' ? values : {});
  const direct = submitted[param.name];
  if (direct && typeof direct === 'object') return formatMapValue(direct);
  const wiring = param.map || {};
  const lat = wiring.lat_param ? submitted[wiring.lat_param] : null;
  const long = wiring.long_param ? submitted[wiring.long_param] : null;
  if (lat != null || long != null) return `${lat ?? '—'}، ${long ?? '—'}`;
  if (typeof direct === 'string' && direct.trim() !== '') return direct;
  return null;
}

function locationDisplayValue(param, value) {
  if (isBlank(value)) return value;
  if (param.kind !== 'province' && param.kind !== 'city') return value;
  const binding = locationBinding(param);
  if (param.kind === 'province' && binding && Array.isArray(binding.items)) {
    const match = binding.items.find((item) => String(item.value) === String(value));
    if (match) return match.label;
  }
  if (param.kind === 'city' && binding && binding.items_by_parent) {
    const groups = Object.values(binding.items_by_parent).flat();
    const match = groups.find((item) => String(item.value) === String(value));
    if (match) return match.label;
  }
  const manual = (param.options || []).find((option) => String(option.value) === String(value));
  return manual ? manual.label : value;
}

function nestedBindingByKey(key) {
  const bindings = currentProvider && currentProvider.locations && currentProvider.locations.bindings;
  return (bindings && bindings[key]) || null;
}

function nestedBinding(paramName, fieldName) {
  return nestedBindingByKey(`${paramName}.${fieldName}`);
}

function nestedBindingUsable(binding) {
  return binding && (locationStatus() === 'fresh' || locationStatus() === 'stale');
}

function populateNestedCityOptions(select, binding, provinceValue) {
  const current = select.value;
  clear(select);
  const itemsByParent = binding.items_by_parent || {};
  let items = [];
  if (!binding.paired_param) {
    Object.values(itemsByParent).forEach((group) => { items = items.concat(group); });
  } else if (provinceValue) {
    items = itemsByParent[provinceValue] || [];
  }
  let matched = false;
  items.forEach((item) => {
    const node = h('option', { value: item.value }, item.label);
    if (current && String(current) === String(item.value)) {
      node.selected = true;
      matched = true;
    }
    select.append(node);
  });
  const needsProvince = binding.paired_param && !provinceValue;
  select.disabled = needsProvince || !items.length;
  if (matched) {
    if (typeof select.reapplySearch === 'function') select.reapplySearch();
  } else {
    clearSelection(select);
  }
}

function nestedProvinceSelect(name, binding, onProvinceChange) {
  return searchableSelect(selectControl(name, binding.items || [], {
    onChange: onProvinceChange || undefined,
  }));
}

function nestedCitySelect(name, binding, provinceValue, cityFor = null) {
  const select = h('select', { name });
  select.setAttribute('data-nested-city', name);
  if (cityFor) select.setAttribute('data-nested-city-for', cityFor);
  populateNestedCityOptions(select, binding, provinceValue);
  return searchableSelect(select);
}

function nestedDisplayValue(paramName, field, value) {
  if (isBlank(value)) return value;
  const withUnit = (text) => (isMoneyField(paramName, field) ? `${text} ${providerDisplayCurrencyLabel()}` : text);
  const withWeight = (text) => (isWeightField(paramName, field) ? `${text} ${providerDisplayWeightLabel()}` : text);
  if (field.kind === 'province' || field.kind === 'city') {
    const binding = nestedBinding(paramName, field.name);
    let items = [];
    if (binding && binding.kind === 'province' && Array.isArray(binding.items)) items = binding.items;
    else if (binding && binding.items_by_parent) items = Object.values(binding.items_by_parent).flat();
    const match = items.find((item) => String(item.value) === String(value));
    return match ? match.label : value;
  }
  if (field.type === 'int' || field.type === 'float') {
    const numeric = Number(String(value).replace(/,/g, '').trim());
    if (Number.isFinite(numeric)) return withWeight(withUnit(formatNumber(numeric)));
  }
  return withWeight(withUnit(value));
}

function syncNestedDictCity(param, provinceFieldName) {
  const locations = currentProvider.locations || {};
  const provinceKey = `${param.name}.${provinceFieldName}`;
  const provinceBinding = (locations.bindings || {})[provinceKey];
  if (!provinceBinding || !provinceBinding.paired_param) return;
  const cityBinding = (locations.bindings || {})[provinceBinding.paired_param];
  if (!cityBinding) return;
  const provinceSelect = formHost.querySelector(`select[name="dict:${param.name}:${provinceFieldName}"]`);
  const citySelect = formHost.querySelector(`select[data-nested-city-for="${provinceBinding.paired_param}"]`);
  if (provinceSelect && citySelect) populateNestedCityOptions(citySelect, cityBinding, provinceSelect.value);
}

function controlFor(param) {
  const name = `param:${param.name}`;
  if (param.input === 'select') {
    if (!param.options || !param.options.length) {
      return h('div', {}, h('div', { class: 'empty', text: 'گزینه‌ها هنوز تعریف نشده‌اند' }), h('input', { type: 'hidden', name, value: '' }));
    }
    return searchableSelect(selectControl(name, param.options, { selectedValue: isBlank(param.default) ? '' : param.default }));
  }
  if (param.input === 'number') {
    const input = numericInput(name, {
      float: param.type === 'float',
      value: isBlank(param.default) ? '' : param.default,
    });
    const amountUnit = amountUnitForParam(param);
    return amountUnit ? unitWrap(input, amountUnit) : input;
  }
  const input = h('input', { type: 'text', name, value: isBlank(param.default) ? '' : param.default });
  const amountUnit = amountUnitForParam(param);
  return amountUnit ? unitWrap(input, amountUnit) : input;
}

function listCard(param) {
  const body = h('tbody');
  const table = h(
    'table',
    {},
    h(
      'thead',
      {},
      h(
        'tr',
        {},
        h('th', { style: 'width:40px' }, '#'),
        param.fields.filter((field) => field.visible).map((field) => h('th', {}, field.label)),
        h('th', { style: 'width:90px' }, 'عملیات')
      )
    ),
    body
  );

  const addRow = () => {
    body.append(productRow(param, body.rows.length));
  };
  addRow();

  return h(
    'div',
    { class: 'sub-block' },
    h(
      'div',
      { class: 'sub-head' },
      h('span', { class: 'section-icon green' }, icon('receipt')),
      h('h4', { text: param.label }),
      h('span', { class: 'section-count', text: `حداقل ${param.min_items || 1} ردیف` })
    ),
    h('div', { class: 'table-scroll' }, table),
    h('button', { class: 'btn btn-sm btn-ghost', type: 'button', style: 'margin-top:12px', onClick: addRow }, '＋ افزودن ردیف')
  );
}

function productRow(param, index) {
  const cells = param.fields.filter((field) => field.visible).map((field) =>
    h('td', {}, rowInput(param.name, index, field))
  );
  return h(
    'tr',
    {},
    h('td', { class: 'row-number', text: String(index + 1) }),
    cells,
    h(
      'td',
      {},
      h(
        'button',
        {
          class: 'btn btn-sm btn-remove',
          type: 'button',
          onClick: (event) => {
            const body = event.target.closest('tbody');
            if (body.rows.length <= 1) {
              body.querySelectorAll('input').forEach((input) => { input.value = ''; });
              return;
            }
            event.target.closest('tr').remove();
            [...body.rows].forEach((row, i) => { row.querySelector('.row-number').textContent = String(i + 1); });
          },
        },
        'حذف'
      )
    )
  );
}

function rowInput(paramName, index, field) {
  const name = `row:${paramName}:${index}:${field.name}`;
  const binding = (field.kind === 'province' || field.kind === 'city')
    ? nestedBinding(paramName, field.name)
    : null;
  if (nestedBindingUsable(binding)) {
    if (binding.kind === 'province') {
      return nestedProvinceSelect(name, binding, (event) => {
        const row = event.target.closest('tr');
        if (!row || !binding.paired_param) return;
        const citySelect = row.querySelector(`select[data-nested-city-for="${binding.paired_param}"]`);
        const cityBinding = citySelect && nestedBindingByKey(citySelect.getAttribute('data-nested-city-for'));
        if (citySelect && cityBinding) populateNestedCityOptions(citySelect, cityBinding, event.target.value);
      });
    }
    const control = nestedCitySelect(name, binding, '', `${paramName}.${field.name}`);
    return control;
  }
  if (field.input === 'number') {
    const input = numericInput(name, { float: field.type === 'float' });
    const amountUnit = amountUnitForField(paramName, field);
    return amountUnit ? unitWrap(input, amountUnit) : input;
  }
  const input = h('input', { type: 'text', name });
  const amountUnit = amountUnitForField(paramName, field);
  return amountUnit ? unitWrap(input, amountUnit) : input;
}

function inlineListFields(param, index, total) {
  // A list param renders as ordinary inline fields (one group per required
  // row) instead of a separate table, and collects back into an array.
  const suffix = total > 1 ? ` (ردیف ${toFaDigits(String(index + 1))})` : '';
  return param.fields.filter((field) => field.visible).map((field) => {
    const name = `row:${param.name}:${index}:${field.name}`;
    const binding = (field.kind === 'province' || field.kind === 'city')
      ? nestedBinding(param.name, field.name)
      : null;
    let control;
    if (nestedBindingUsable(binding) && binding.kind === 'province') {
      const cityField = String(binding.paired_param || '').split('.').pop();
      control = nestedProvinceSelect(name, binding, (event) => {
        const citySelect = formHost.querySelector(`select[name="row:${param.name}:${index}:${cityField}"]`);
        const cityBinding = nestedBindingByKey(binding.paired_param);
        if (citySelect && cityBinding) populateNestedCityOptions(citySelect, cityBinding, event.target.value);
      });
    } else if (nestedBindingUsable(binding)) {
      control = nestedCitySelect(name, binding, '', `${param.name}.${field.name}`);
    } else if (field.input === 'number') {
      control = numericInput(name, { float: field.type === 'float' });
    } else {
      control = h('input', { type: 'text', name });
    }
    const inlineUnit = amountUnitForField(param.name, field);
    const inlineControl = inlineUnit ? unitWrap(control, inlineUnit) : control;
    return proField(`${field.label}${suffix}`, inlineControl, { required: fieldIsRequired(field), fieldKey: `${param.name}.${field.name}` });
  });
}

function dictCard(param) {
  const fields = (param.fields || []).filter((field) => field.visible);
  const built = fields.map((field) => {
    const name = `dict:${param.name}:${field.name}`;
    const binding = (field.kind === 'province' || field.kind === 'city')
      ? nestedBinding(param.name, field.name)
      : null;
    let control;
    if (nestedBindingUsable(binding) && binding.kind === 'province') {
      control = nestedProvinceSelect(name, binding, () => syncNestedDictCity(param, field.name));
    } else if (nestedBindingUsable(binding)) {
      control = nestedCitySelect(name, binding, '', `${param.name}.${field.name}`);
    } else if (field.input === 'number') {
      control = numericInput(name, { float: field.type === 'float' });
    } else {
      control = h('input', { type: 'text', name });
    }
    return { field, control };
  });
  built.forEach(({ field, control }) => {
    const select = control.tagName === 'SELECT' ? control : control.querySelector('select');
    if (field.kind !== 'province' || !select || isBlank(field.default)) return;
    const binding = nestedBinding(param.name, field.name);
    if (!binding || !binding.paired_param) return;
    const wanted = String(field.default);
    if (![...select.options].some((option) => option.value === wanted)) return;
    select.value = wanted;
    const cityBinding = nestedBindingByKey(binding.paired_param);
    const cityControl = built.find((item) => `${param.name}.${item.field.name}` === binding.paired_param);
    const citySelect = cityControl && (cityControl.control.tagName === 'SELECT'
      ? cityControl.control
      : cityControl.control.querySelector('select'));
    if (cityBinding && citySelect) {
      populateNestedCityOptions(citySelect, cityBinding, wanted);
    }
  });
  const controls = built.map(({ field, control }) => {
    const dictUnit = amountUnitForField(param.name, field);
    const dictControl = dictUnit ? unitWrap(control, dictUnit) : control;
    return proField(field.label, dictControl, { required: fieldIsRequired(field), fieldKey: `${param.name}.${field.name}` });
  });
  return h(
    'div',
    { class: 'sub-block' },
    h(
      'div',
      { class: 'sub-head' },
      h('span', { class: 'section-icon green' }, icon('archive')),
      h('h4', { text: param.label }),
      h('span', { class: 'section-count', text: `${controls.length} فیلد` })
    ),
    h('div', { class: 'section-grid' }, controls)
  );
}

function collect() {
  const restore = stripNumericCommas(formHost);
  const data = new FormData(formHost);
  restore();
  const values = {};

  data.forEach((raw, key) => {
    if (key.startsWith('param:')) {
      const name = key.slice('param:'.length);
      if (name) values[name] = raw === '' ? null : raw;
      return;
    }
    if (key.startsWith('combo:')) {
      const [nameA, nameB] = splitComboKey(key);
      if (nameA || nameB) {
        const [a, b] = splitComboValue(raw);
        if (nameA) values[nameA] = a;
        if (nameB) values[nameB] = b;
      }
      return;
    }
    if (key.startsWith('map:')) {
      const [, paramName, coord] = key.split(':');
      if (paramName && (coord === 'lat' || coord === 'long')) {
        if (!values[paramName] || typeof values[paramName] !== 'object') values[paramName] = {};
        values[paramName][coord] = raw === '' ? null : raw;
      }
      return;
    }
    if (key.startsWith('dict:')) {
      const [, paramName, fieldName] = key.split(':');
      if (paramName && fieldName) {
        if (!values[paramName] || typeof values[paramName] !== 'object') values[paramName] = {};
        values[paramName][fieldName] = raw === '' ? null : raw;
      }
      return;
    }
    if (!key.startsWith('row:')) return;
    const [, paramName, index, fieldName] = key.split(':');
    if (!values[paramName]) values[paramName] = [];
    if (!values[paramName][Number(index)]) values[paramName][Number(index)] = {};
    values[paramName][Number(index)][fieldName] = raw === '' ? null : raw;
  });

  Object.keys(values).forEach((name) => {
    if (!Array.isArray(values[name])) return;
    values[name] = values[name].filter((row) => row && Object.values(row).some((value) => !isBlank(value)));
  });

  return values;
}

function browserSignals() {
  const safe = (value) => {
    try {
      return typeof value === 'function' ? value() : value;
    } catch { return null; }
  };
  const nav = typeof navigator !== 'undefined' ? navigator : {};
  const screen = typeof window !== 'undefined' && window.screen ? window.screen : {};
  let timezone = null;
  try {
    timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || null;
  } catch { timezone = null; }
  return {
    userAgent: safe(() => nav.userAgent) || null,
    language: safe(() => nav.language) || null,
    languages: Array.isArray(nav.languages) ? [...nav.languages] : null,
    platform: safe(() => nav.platform) || null,
    screenWidth: typeof screen.width === 'number' ? screen.width : null,
    screenHeight: typeof screen.height === 'number' ? screen.height : null,
    colorDepth: typeof screen.colorDepth === 'number' ? screen.colorDepth : null,
    devicePixelRatio: typeof window !== 'undefined' && typeof window.devicePixelRatio === 'number'
      ? window.devicePixelRatio
      : null,
    timezone,
    hardwareConcurrency: typeof nav.hardwareConcurrency === 'number' ? nav.hardwareConcurrency : null,
    deviceMemory: typeof nav.deviceMemory === 'number' ? nav.deviceMemory : null,
    maxTouchPoints: typeof nav.maxTouchPoints === 'number' ? nav.maxTouchPoints : null,
  };
}

// --- Client-side rule checks (mirror the server engine) ---
// Runs before any submit so invalid values never reach the carrier call.
// Semantics match providers.check_rules: empty values are skipped, list
// rows are all checked, and each rule reports its own message with a
// generic fallback naming the offending field.
function ruleTargetValues(values, target) {
  const at = String(target || '').indexOf('.');
  const base = at === -1 ? String(target || '') : String(target).slice(0, at);
  const sub = at === -1 ? '' : String(target).slice(at + 1);
  if (!base || !values || typeof values !== 'object') return [];
  const node = values[base];
  if (!sub) return [node];
  if (node && typeof node === 'object' && !Array.isArray(node)) return [node[sub]];
  if (Array.isArray(node)) {
    return node.map((row) => (row && typeof row === 'object' ? row[sub] : undefined));
  }
  return [undefined];
}

function isRuleBlank(value) {
  return value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
}

function ruleNumber(value) {
  const num = Number(String(value).trim());
  return Number.isFinite(num) ? num : null;
}

function ruleClauseHolds(op, actual, expected, expected2) {
  if (isRuleBlank(actual)) return null;
  const text = String(actual).trim();
  if (op === 'eq') return text === String(expected ?? '').trim();
  if (op === 'neq') return text !== String(expected ?? '').trim();
  if (op === 'in') {
    const options = String(expected ?? '').split(',').map((item) => item.trim());
    return options.includes(text);
  }
  const num = ruleNumber(actual);
  if (num === null) return false;
  const want = ruleNumber(expected);
  if (op === 'between') {
    const want2 = ruleNumber(expected2);
    if (want === null || want2 === null) return false;
    const low = Math.min(want, want2);
    const high = Math.max(want, want2);
    return num >= low && num <= high;
  }
  if (want === null) return false;
  if (op === 'gt') return num > want;
  if (op === 'gte') return num >= want;
  if (op === 'lt') return num < want;
  if (op === 'lte') return num <= want;
  return false;
}

function checkClientRules(rules, values, labelOf) {
  const problems = [];
  (rules || []).forEach((rule) => {
    if (!rule || typeof rule !== 'object') return;
    const ifField = String(rule.if_field || '');
    const ifOp = String(rule.if_op || '');
    if (!ifField || !ifOp) return;
    const present = ruleTargetValues(values, ifField).filter((value) => !isRuleBlank(value));
    if (!present.length) return;
    const fallback = (target) => `مقدار «${(labelOf(target) || target)}» معتبر نیست.`;
    const message = String(rule.message || '').trim();
    const thenField = String(rule.then_field || '');
    if (thenField) {
      const triggered = present.some(
        (value) => ruleClauseHolds(ifOp, value, rule.if_value, rule.if_value2) === true
      );
      if (!triggered) return;
      const thenPresent = ruleTargetValues(values, thenField).filter((value) => !isRuleBlank(value));
      const ok = thenPresent.length > 0 && thenPresent.every(
        (value) => ruleClauseHolds(String(rule.then_op || ''), value, rule.then_value, rule.then_value2) === true
      );
      if (!ok) problems.push({ field: thenField, message: message || fallback(thenField) });
    } else {
      const ok = present.every(
        (value) => ruleClauseHolds(ifOp, value, rule.if_value, rule.if_value2) === true
      );
      if (!ok) problems.push({ field: ifField, message: message || fallback(ifField) });
    }
  });
  return problems;
}

function highlightRuleField(form, field, message) {
  if (setFieldError(form, field, message)) return true;
  const dot = String(field || '').indexOf('.');
  if (dot !== -1 && setFieldError(form, String(field).slice(0, dot), message)) return true;
  return false;
}

function singleRuleLabel(target) {
  const at = String(target || '').indexOf('.');
  const base = at === -1 ? String(target || '') : String(target).slice(0, at);
  const sub = at === -1 ? '' : String(target).slice(at + 1);
  const param = ((currentProvider && currentProvider.params) || []).find((p) => p && p.name === base);
  if (!param) return target;
  if (!sub) return param.label || base;
  const field = ((param.fields || []).find((f) => f && f.name === sub));
  return (field && field.label) || sub;
}

function collectRequiredProblems(values) {
  const problems = [];
  const seen = new Set();
  const push = (field, label) => {
    if (!field || seen.has(field)) return;
    seen.add(field);
    problems.push({ field, message: requiredMessage(label || field) });
  };
  for (const param of (currentProvider && currentProvider.params) || []) {
    if (!param || !param.visible) continue;
    if (param.input === 'list') {
      const rows = Array.isArray(values[param.name]) ? values[param.name] : [];
      for (const field of param.fields || []) {
        if (!field || !field.visible || !fieldIsRequired(field)) continue;
        if (rows.some((row) => row && typeof row === 'object' && isBlank(row[field.name]))) {
          push(`${param.name}.${field.name}`, field.label);
        }
      }
      continue;
    }
    if (param.input === 'dict') {
      const node = values[param.name];
      if (!node || typeof node !== 'object' || Array.isArray(node)) continue;
      for (const field of param.fields || []) {
        if (!field || !field.visible || !fieldIsRequired(field)) continue;
        if (isBlank(node[field.name])) push(`${param.name}.${field.name}`, field.label);
      }
      continue;
    }
    if ((param.input === 'text' || param.input === 'number' || param.input === 'select') && paramIsRequired(param)) {
      if (isBlank(values[param.name])) push(param.name, param.label);
    }
  }
  return problems;
}

async function submitForm(event) {
  event.preventDefault();
  clearFormErrors(formHost);
  const submitBtn = formHost.querySelector('button[type="submit"]');
  const values = collect();
  const requiredProblems = collectRequiredProblems(values);
  if (requiredProblems.length) {
    requiredProblems.forEach((problem) => highlightRuleField(formHost, problem.field, problem.message));
    const messages = requiredProblems.map((problem) => problem.message);
    toast(messages.slice(0, 3).join(' — ') + (messages.length > 3 ? ` — ${messages.length - 3} مورد دیگر` : ''));
    clear(resultHost).append(singleEmptyState());
    return;
  }
  const ruleProblems = checkClientRules(currentProvider.rules || [], values, singleRuleLabel);
  if (ruleProblems.length) {
    clearFormErrors(formHost);
    ruleProblems.forEach((problem) => highlightRuleField(formHost, problem.field, problem.message));
    const messages = ruleProblems.map((problem) => problem.message);
    toast(messages.slice(0, 3).join(' — ') + (messages.length > 3 ? ` — ${messages.length - 3} مورد دیگر` : ''));
    clear(resultHost).append(singleEmptyState());
    return;
  }
  setBtnLoading(submitBtn, true, 'در حال استعلام…');
  clear(resultHost).append(bulkLoadingCard('در حال استعلام از شرکت…'));
  const preview = previewKind === 'provider' ? previewProviderDraft() : null;
  if (previewKind === 'provider' && !preview) {
    toast('پیش‌نویسی از تب ادمین نرسیده است.');
    setBtnLoading(submitBtn, false);
    return;
  }
  try {
    const result = preview
      ? await api('/api/admin/preview/price-check', {
        method: 'POST',
        body: { record: preview, values },
      })
      : await api('/api/price-check', {
        method: 'POST',
        body: { provider: currentProvider.name, values, client: browserSignals() },
      });
    renderResult(result, values);
    scrollToResults(resultHost);
  } catch (error) {
    if (error.data && error.data.provider_response !== undefined && error.data.provider_response !== null) {
      renderQuoteFailure(error);
      scrollToResults(resultHost);
      return;
    }
    if (error.errors && error.errors.length) {
      let mapped = 0;
      error.errors.forEach((item) => {
        if (setFieldError(formHost, item.field, item.message)) mapped += 1;
      });
      const firstBad = formHost.querySelector('.field.has-error input, .field.has-error select');
      if (firstBad) { try { firstBad.focus(); } catch { /* noop */ } }
      toast(mapped ? `${error.message} — ${mapped} فیلد را بررسی کنید.` : [error.message, ...error.errors.map((item) => `${item.field}: ${item.message}`)].join(' — '));
      clear(resultHost).append(singleEmptyState());
    } else {
      toast(error.message);
      clear(resultHost);
      renderQuoteFailure(error);
    }
  } finally {
    setBtnLoading(submitBtn, false);
  }
}

const SERVICE_DOWN_MESSAGE = 'سرویس در حال حاضر پاسخگو نیست.';

function renderQuoteFailure(error) {
  clear(resultHost);
  const data = error.data || {};
  const noPriceFields = (data.missing_price_fields || []).length > 0;
  const message = noPriceFields ? SERVICE_DOWN_MESSAGE : error.message;
  resultHost.append(
    h(
      'div',
      { class: 'card result-card is-fail' },
      h('div', { class: 'rc-top' }, providerLogo(currentProvider || {}, 44),
        h('div', { class: 'rc-name' }, h('h3', { text: (currentProvider || {}).display_name || 'نتیجه استعلام' }),
          h('div', { class: 'rc-badges' }, h('span', { class: 'badge fail', text: 'ناموفق' })))),
      h('p', { class: 'rc-error', text: message }),
      h('p', { class: 'rc-note', text: 'ورودی‌ها را بررسی کنید و دوباره تلاش کنید.' })
    )
  );
  toast(message);
}

function displaySimpleValue(param, value) {
  if (value === null || value === undefined || value === '') return '—';
  if (param.kind === 'province' || param.kind === 'city') return locationDisplayValue(param, value);
  // Money/weight was typed in the display unit: echo it back with the unit shown.
  const withUnit = (text) => (isMoneyParam(param) ? `${text} ${providerDisplayCurrencyLabel()}` : text);
  const withWeight = (text) => (isWeightParam(param) ? `${text} ${providerDisplayWeightLabel()}` : text);
  if (param.type === 'int' || param.type === 'float') {
    const numeric = Number(String(value).replace(/,/g, '').trim());
    if (Number.isFinite(numeric)) return withWeight(withUnit(formatNumber(numeric)));
  }
  return withWeight(withUnit(pretty(value)));
}

function mainPriceOf(result) {
  const prices = result.prices || [];
  return prices.find((p) => p.main) || prices[0] || null;
}

// Every quote carries its provider's display unit (rial/toman); older
// responses without it fall back to rial, the historical display.
function resultCurrencyLabel(item) {
  return (item && item.currency_label) || 'ریال';
}

// Compare money across providers in one unit (rial), whatever each shows.
function resultAmountInRial(value, item) {
  const n = Number(String(value).replace(/,/g, ''));
  if (!Number.isFinite(n)) return Number.POSITIVE_INFINITY;
  return item && item.currency === 'toman' ? n * 10 : n;
}

function providerDisplayCurrencyLabel() {
  return (currentProvider && currentProvider.currency_label) || 'ریال';
}

// A money value: ticked as money, or designated as this provider's
// user-price input (both convert display<->service with the same rule).
function isMoneyParam(param) {
  return param.kind === 'money'
    || (!!currentProvider && !!param.name && param.name === currentProvider.price_input_param);
}

function isMoneyField(paramName, field) {
  return field.kind === 'money'
    || (!!currentProvider && `${paramName}.${field.name}` === currentProvider.price_input_param);
}

function providerDisplayWeightLabel() {
  return (currentProvider && currentProvider.weight_unit_label) || 'گرم';
}

// A weight value: ticked as weight, or designated as this provider's
// user-weight input (both convert display<->service with the same rule).
function isWeightParam(param) {
  return param.kind === 'weight'
    || (!!currentProvider && !!param.name && param.name === currentProvider.weight_input_param);
}

function isWeightField(paramName, field) {
  return field.kind === 'weight'
    || (!!currentProvider && `${paramName}.${field.name}` === currentProvider.weight_input_param);
}

// Badge unit for an amount control (money or weight), or null when plain.
function amountUnitForParam(param) {
  if (isMoneyParam(param)) return providerDisplayCurrencyLabel();
  if (isWeightParam(param)) return providerDisplayWeightLabel();
  return null;
}

function amountUnitForField(paramName, field) {
  if (isMoneyField(paramName, field)) return providerDisplayCurrencyLabel();
  if (isWeightField(paramName, field)) return providerDisplayWeightLabel();
  return null;
}

// A provider's referral strip: visually attached below its own price card
// (outside the main frame). Renders only for a block with a valid http(s)
// URL and a button title; anything else stays hidden.
function renderReferral(referral) {
  if (!referral || typeof referral !== 'object') return null;
  const url = String(referral.url || '').trim();
  if (!/^https?:\/\//i.test(url)) return null;
  const title = String(referral.title || '').trim();
  if (!title) return null;
  const description = String(referral.description || '').trim();
  return h(
    'div',
    { class: 'referral-strip' },
    icon('link'),
    description ? h('span', { class: 'referral-strip-text', text: description }) : null,
    h('a', { class: 'referral-strip-link', href: url, target: '_blank', rel: 'noopener' },
      h('span', { text: title }), icon('external'))
  );
}

function renderResult(result, values) {
  clear(resultHost);

  const hasPrices = Array.isArray(result.prices) && result.prices.length > 0;
  const main = hasPrices ? mainPriceOf(result) : null;

  if (main) {
    const breakdownRows = result.prices.map((p) =>
      h('tr', {}, h('td', {}, `${p.label}${p.main ? ' ★' : ''}`), h('td', { text: `${pretty(p.value)} ${resultCurrencyLabel(result)}` })));
    const card = h(
      'div',
      { class: 'card result-card' },
        h('div', { class: 'rc-top rc-top-inline' },
          h('div', { class: 'rc-provider' },
            providerLogo(currentProvider, 44),
            h('div', { class: 'rc-name' },
              h('h3', { text: currentProvider.display_name }))),
          h('div', { class: 'rc-pricebox' },
            h('span', { class: 'rc-price', text: pretty(main.value) }),
            h('span', { class: 'rc-currency', text: resultCurrencyLabel(result) }))),
        result.prices.length > 1
          ? h('details', { class: 'breakdown' },
            h('summary', {}, `ریز هزینه‌ها (${result.prices.length})`),
            h('table', { class: 'summary' }, h('tbody', {}, breakdownRows)))
          : null
      );
    const referralBelow = renderReferral(result.referral);
    resultHost.append(referralBelow ? h('div', { class: 'price-stack' }, card, referralBelow) : card);
  } else {
    resultHost.append(
      h(
        'div',
        { class: 'card result-card is-fail' },
        h('h3', { text: 'قیمتی برگردانده نشد' }),
        h('p', { class: 'rc-error', text: SERVICE_DOWN_MESSAGE })
      )
    );
  }
}

/* ---------------- bulk (group) ---------------- */

const bulkView = document.getElementById('bulk-view');
const advancedView = document.getElementById('advanced-view');
const navBulk = document.getElementById('nav-bulk');
const navAdvanced = document.getElementById('nav-advanced');
const bulkFormHost = document.getElementById('bulk-form');
const bulkResultHost = document.getElementById('bulk-result');

let bulkSchema = null;
let lastBulkItems = [];
let bulkSort = 'cheap';
let lastBulkSubmit = null;

function markNav(name) {
  if (navBulk) navBulk.classList.toggle('btn-ghost', name !== 'bulk');
  if (navAdvanced) navAdvanced.classList.toggle('btn-ghost', name !== 'advanced');
}

function showBulkView() {
  hide(advancedView);
  show(bulkView);
  markNav('bulk');
  window.scrollTo({ top: 0 });
}

function showAdvancedView() {
  hide(bulkView);
  show(advancedView);
  markNav('advanced');
  window.scrollTo({ top: 0 });
}

navBulk?.addEventListener('click', () => { go('/bulk'); routePublic(); });
navAdvanced?.addEventListener('click', () => { go('/advanced'); routePublic(); });

function go(url) {
  if (routeLocation().pathname + routeLocation().search !== url) {
    navigate(url);
  }
}

// Every view has its own URL: /bulk, /advanced and /advanced?provider=<name>.
// Query deep-links (?provider=) keep working from any public path.
function routePublic() {
  if (previewKind) {
    bootPreview();
    return;
  }
  const path = routeLocation().pathname.replace(/\/+$/, '') || '/';
  const provider = new URLSearchParams(routeLocation().search).get('provider');
  if (provider) {
    showAdvancedView();
    ensureProviders();
    openProvider(provider);
    return;
  }
  if (path === '/advanced') {
    showAdvancedView();
    ensureProviders();
    if (!currentProvider) {
      show(chooseSection);
      hide(formPanel);
    }
    return;
  }
  showBulkView();
}

window.addEventListener('popstate', routePublic);

async function loadBulk() {
  clear(bulkFormHost).append(skeleton(3));
  clear(bulkResultHost).append(bulkIntroCard());
  paintBulkAdvancedShortcut();
  try {
    bulkSchema = await api('/api/bulk');
  } catch (error) {
    clear(bulkFormHost).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
    paintBulkAdvancedShortcut();
    return;
  }
  renderBulkForm();
  paintBulkIntro();
  paintBulkAdvancedShortcut();
}

/* ---------------- bulk hero: advanced (single) shortcut ---------------- */
// Attractive entry point inside the group hero: one tap expands the live
// list of ACTIVE providers (from /api/bulk, already filtered server-side)
// and each card deep-links to that provider's dedicated single form.
let bulkAdvancedOpen = false;
let bulkAdvancedFrame = 0;

function bulkAdvancedProviders() {
  const fromBulk = bulkSchema && Array.isArray(bulkSchema.providers) ? bulkSchema.providers : [];
  return fromBulk.filter((p) => p && p.active !== false);
}

function setBulkAdvancedOpen(open) {
  const toggle = document.getElementById('bulk-advanced-toggle');
  const panel = document.getElementById('bulk-advanced-panel');
  if (!toggle || !panel) return;
  if (bulkAdvancedFrame) cancelAnimationFrame(bulkAdvancedFrame);
  bulkAdvancedFrame = 0;
  bulkAdvancedOpen = Boolean(open);
  toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  toggle.classList.toggle('is-open', open);
  if (open) {
    panel.hidden = false;
    panel.inert = true;
    bulkAdvancedFrame = requestAnimationFrame(() => {
      bulkAdvancedFrame = 0;
      if (bulkAdvancedOpen && panel.isConnected) {
        panel.classList.add('open');
        panel.inert = false;
      }
    });
  } else {
    panel.inert = true;
    const wasOpen = panel.classList.contains('open');
    panel.classList.remove('open');
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const duration = parseFloat(getComputedStyle(panel).transitionDuration) || 0;
    if (motion || duration === 0 || !wasOpen) panel.hidden = true;
  }
}

function paintBulkAdvancedShortcut() {
  const toggle = document.getElementById('bulk-advanced-toggle');
  const panel = document.getElementById('bulk-advanced-panel');
  const grid = document.getElementById('bulk-advanced-grid');
  const count = document.getElementById('bulk-advanced-count');
  const sub = document.getElementById('bulk-advanced-sub');
  if (!toggle || !panel || !grid) return;
  if (!toggle.dataset.bound) {
    toggle.dataset.bound = '1';
    panel.inert = true;
    panel.addEventListener('transitionend', (event) => {
      if (event.target === panel && event.propertyName === 'opacity' && !bulkAdvancedOpen) {
        panel.hidden = true;
      }
    });
    toggle.addEventListener('click', (event) => {
      event.stopPropagation();
      setBulkAdvancedOpen(!bulkAdvancedOpen);
    });
    document.addEventListener('click', (event) => {
      if (!bulkAdvancedOpen) return;
      if (event.target.closest('#bulk-advanced-toggle, #bulk-advanced-panel')) return;
      setBulkAdvancedOpen(false);
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setBulkAdvancedOpen(false);
    });
  }
  const providers = bulkAdvancedProviders();
  if (count) {
    if (providers.length) {
      count.hidden = false;
      count.textContent = toFaDigits(String(providers.length));
    } else {
      count.hidden = true;
    }
  }
  clear(grid);
  if (!bulkSchema) {
    if (sub) sub.textContent = 'در حال بارگذاری…';
    grid.append(h('p', { class: 'adv-empty', text: 'لیست شرکت‌ها در حال بارگذاری است…' }));
    return;
  }
  if (!providers.length) {
    if (sub) sub.textContent = 'شرکت فعالی برای استعلام پیشرفته وجود ندارد.';
    grid.append(h('p', { class: 'adv-empty', text: 'هنوز شرکت فعالی ثبت نشده است.' }));
    return;
  }
  if (sub) sub.textContent = `${toFaDigits(String(providers.length))} شرکت فعال • برای فرم اختصاصی کلیک کنید`;
  providers.forEach((provider, index) => {
    const name = provider.display_name || provider.name || '—';
    const card = h(
      'button',
      {
        type: 'button',
        class: 'adv-card',
        style: `animation-delay:${Math.min(index * 45, 450)}ms`,
        onClick: () => {
          const key = provider.name || provider.id;
          setBulkAdvancedOpen(false);
          go(`/advanced?provider=${encodeURIComponent(key)}`);
          routePublic();
        },
      },
      h('span', { class: 'adv-logo' }, providerLogo(provider, 40)),
      h('span', { class: 'adv-meta' },
        h('strong', { text: name }),
        h('small', { text: 'استعلام پیشرفته • فرم اختصاصی' }))
    );
    grid.append(card);
  });
}

function bulkIntroCard() {
  return h(
    'div',
    { class: 'card results-empty' },
    h('div', { class: 'empty-icon' }, icon('chart')),
    h('h3', { text: 'نتایج مقایسه اینجا نمایش داده می‌شود' }),
    h('p', { text: 'فرم را تکمیل کنید تا همه شرکت‌ها همزمان استعلام و از ارزان به گران مرتب شوند.' })
  );
}

function paintBulkIntro() {
  if (lastBulkItems.length) return;
  preserveScroll(() => {
    clear(bulkResultHost).append(bulkIntroCard());
  });
}

// --- Combined province+city selects ("استان — شهر" in one control) ---
// One visible control is bound to two params. The option value joins both
// pks ("provPk:cityPk"); collect() splits it back into the two keys, so the
// API contract (two separate pks) never changes.

function combinedCityOptions(provinces, citiesByParent) {
  const options = [];
  (provinces || []).forEach((province) => {
    const cities = ((citiesByParent || {})[province.value]) || [];
    cities.forEach((city) => {
      options.push({
        value: `${province.value}:${city.value}`,
        label: `${province.label} — ${city.label}`,
      });
    });
  });
  return options;
}

function combinedLocationField({ label, nameA, nameB, provinces, citiesByParent, hint = '', required = false, fieldKey = '', defaults = null }) {
  const select = h('select', { name: `combo:${nameA}|${nameB}` });
  const options = combinedCityOptions(provinces, citiesByParent);
  options.forEach((item) => select.append(h('option', { value: item.value }, item.label)));
  let matched = false;
  if (defaults && defaults.a != null && defaults.b != null) {
    const wanted = `${defaults.a}:${defaults.b}`;
    if (options.some((item) => item.value === wanted)) {
      select.value = wanted;
      matched = true;
    }
  }
  if (!options.length) select.disabled = true;
  if (!matched) clearSelection(select);
  return proField(label, searchableSelect(select), { hint, required, fieldKey });
}

function splitComboValue(raw) {
  const text = String(raw || '');
  const at = text.indexOf(':');
  if (at === -1) return [null, null];
  const a = text.slice(0, at).trim();
  const b = text.slice(at + 1).trim();
  return [a || null, b || null];
}

function splitComboKey(key) {
  const pair = key.slice('combo:'.length);
  const pipe = pair.indexOf('|');
  if (pipe === -1) return [null, null];
  let nameA = pair.slice(0, pipe);
  let nameB = pair.slice(pipe + 1);
  if (nameA.startsWith('bulk:')) nameA = nameA.slice('bulk:'.length);
  if (nameB.startsWith('bulk:')) nameB = nameB.slice('bulk:'.length);
  return [nameA || null, nameB || null];
}

function bulkOriginSeparate(origin) {
  const provinces = origin.provinces || [];
  const citiesByParent = origin.cities_by_province || {};
  const provinceSelect = h('select', { name: 'bulk:origin_province_id' });
  provinces.forEach((item) => provinceSelect.append(h('option', { value: item.value }, item.label)));
  if (!provinces.length) provinceSelect.disabled = true;
  clearSelection(provinceSelect);

  const citySelect = h('select', { name: 'bulk:origin_city_id' });
  const repaintCities = (preserve = true) => {
    const current = preserve ? citySelect.value : '';
    clear(citySelect);
    const items = provinceSelect.value ? (citiesByParent[provinceSelect.value] || []) : [];
    let matched = false;
    items.forEach((item) => {
      const node = h('option', { value: item.value }, item.label);
      if (current && String(current) === String(item.value)) {
        node.selected = true;
        matched = true;
      }
      citySelect.append(node);
    });
    citySelect.disabled = !provinceSelect.value || !items.length;
    if (matched) {
      if (typeof citySelect.reapplySearch === 'function') citySelect.reapplySearch();
    } else {
      clearSelection(citySelect);
    }
  };
  repaintCities(false);
  provinceSelect.addEventListener('change', () => repaintCities(true));
  return {
    provinceSelect: searchableSelect(provinceSelect),
    citySelect: searchableSelect(citySelect),
  };
}

function bulkLocationDataset(schema) {
  // Tapin-wide dataset for every kind city/province bulk control: the
  // dedicated destination payload first, then the origin merge as fallback.
  // This keeps destination working even when origin is disabled/unavailable.
  const dest = (schema && schema.destination) || {};
  const origin = (schema && schema.origin) || {};
  const destOk = dest.status === 'fresh' || dest.status === 'stale';
  const originOk = origin.status === 'fresh' || origin.status === 'stale';
  if (destOk && ((dest.provinces || []).length || Object.keys(dest.cities_by_province || {}).length)) {
    return {
      provinces: dest.provinces || [],
      cities_by_province: dest.cities_by_province || {},
      available: true,
      status: dest.status,
      message: dest.message || '',
    };
  }
  if (originOk && ((origin.provinces || []).length || Object.keys(origin.cities_by_province || {}).length)) {
    return {
      provinces: origin.provinces || [],
      cities_by_province: origin.cities_by_province || {},
      available: true,
      status: origin.status,
      message: origin.message || '',
    };
  }
  // Last resort: whichever side carries items even without a fresh status.
  const fallbackProvinces = (dest.provinces && dest.provinces.length ? dest.provinces : origin.provinces) || [];
  const fallbackCities = (dest.cities_by_province && Object.keys(dest.cities_by_province).length
    ? dest.cities_by_province : origin.cities_by_province) || {};
  return {
    provinces: fallbackProvinces,
    cities_by_province: fallbackCities,
    available: fallbackProvinces.length > 0,
    status: dest.status || origin.status || 'unavailable',
    message: dest.message || origin.message || '',
  };
}

function bulkKindSeparate(provField, cityField, dataset) {
  const source = dataset || { provinces: [], cities_by_province: {} };
  const provinceSelect = h('select', { name: `bulk:${provField.name}` });
  (source.provinces || []).forEach((item) => provinceSelect.append(h('option', { value: item.value }, item.label)));
  if (!(source.provinces || []).length) provinceSelect.disabled = true;
  clearSelection(provinceSelect);

  const citySelect = h('select', { name: `bulk:${cityField.name}` });
  const repaint = () => {
    const pv = provinceSelect.value;
    clear(citySelect);
    ((source.cities_by_province || {})[pv] || []).forEach((item) => {
      citySelect.append(h('option', { value: item.value }, item.label));
    });
    citySelect.disabled = !pv;
    clearSelection(citySelect);
  };
  provinceSelect.addEventListener('change', repaint);
  repaint();
  return [
    proField(provField.label, searchableSelect(provinceSelect), { required: true, fieldKey: provField.name }),
    proField(cityField.label, searchableSelect(citySelect), { required: true, fieldKey: cityField.name }),
  ];
}

function bulkSingleKindField(field, dataset) {
  // One standalone kind field (not part of the destination pair) renders
  // exactly like its single-provider counterpart: a searchable select fed by
  // the same Tapin-wide dataset. A lone city shows every city flattened with
  // its province for disambiguation (same as single form when its paired
  // province is hidden).
  const source = dataset || { provinces: [], cities_by_province: {} };
  if (field.kind === 'province') {
    const select = h('select', { name: `bulk:${field.name}` });
    (source.provinces || []).forEach((item) => select.append(h('option', { value: item.value }, item.label)));
    if (!(source.provinces || []).length) select.disabled = true;
    clearSelection(select);
    return proField(field.label, searchableSelect(select), { required: !!field.required, fieldKey: field.name });
  }
  const provLabel = {};
  (source.provinces || []).forEach((item) => { provLabel[String(item.value)] = item.label; });
  const flat = [];
  Object.entries(source.cities_by_province || {}).forEach(([parent, group]) => {
    (group || []).forEach((item) => flat.push({
      value: item.value,
      label: provLabel[String(parent)] ? `${item.label} — ${provLabel[String(parent)]}` : item.label,
    }));
  });
  const select = h('select', { name: `bulk:${field.name}` });
  flat.forEach((item) => select.append(h('option', { value: item.value }, `${item.label}`)));
  if (!flat.length) select.disabled = true;
  clearSelection(select);
  return proField(field.label, searchableSelect(select), { required: !!field.required, fieldKey: field.name });
}

function bulkKindPairNodes(provField, cityField, ctx) {
  const { dataset, available, destCombined } = ctx;
  const source = dataset || { provinces: [], cities_by_province: {} };
  if (!available) {
    return [
      proField(provField.label, numericInput(`bulk:${provField.name}`, {}), { required: true, fieldKey: provField.name }),
      proField(cityField.label, numericInput(`bulk:${cityField.name}`, {}), { required: true, fieldKey: cityField.name }),
    ];
  }
  if (destCombined) {
    return [combinedLocationField({
      label: 'مقصد',
      nameA: `bulk:${provField.name}`, nameB: `bulk:${cityField.name}`,
      provinces: source.provinces || [], citiesByParent: source.cities_by_province || {},
      required: true, fieldKey: provField.name,
    })];
  }
  return bulkKindSeparate(provField, cityField, source);
}

function renderBulkForm() {
  return preserveScroll(() => {
  clear(bulkFormHost);
  clear(bulkResultHost);
  lastBulkItems = [];
  const schema = bulkSchema;
  if (!schema.providers || !schema.providers.length) {
    bulkFormHost.append(
      h('div', { class: 'card' }, h('p', { class: 'empty', text: 'استعلام هنوز در بخش مدیریت تنظیم نشده است.' }))
    );
    paintBulkIntro();
    return;
  }
  const origin = schema.origin || {};
  const originWanted = origin.wanted !== false;
  const originAvailable = origin.status === 'fresh' || origin.status === 'stale';
  const bulkByName = {};
  (schema.fields || []).forEach((field) => { if (field && field.name) bulkByName[field.name] = field; });
  const dest = schema.destination || {};
  const destEnabled = dest.enabled !== false;
  const destCombined = (dest.display || 'combined') !== 'separate';
  // Destination pair chosen in admin/bulk gets the same kind city/province
  // logic as the single form (combined one-field or separate two selects).
  // Any other bulk field whose kind is city/province (own kind or inferred
  // from its mapped provider params) renders as a location select too.
  const destPairProv = destEnabled && dest.province_field && dest.city_field
    && bulkByName[dest.province_field] && bulkByName[dest.city_field]
    && dest.province_field !== dest.city_field ? dest.province_field : null;
  const destPairCity = destPairProv ? dest.city_field : null;
  const bulkDataset = bulkLocationDataset(schema);
  const bulkAvailable = bulkDataset.available;
  // One shared input fans out to every provider, each converting from its
  // own display unit: name the unit only when all of them agree on it.
  const bulkCurrencies = new Set(((schema.providers || []).map((p) => (p && p.currency) || 'rial')));
  const sharedCurrencyLabel = bulkCurrencies.size === 1
    ? ((((schema.providers || [])[0] || {}).currency_label) || 'ریال')
    : null;
  // The designated shared amount is typed in the bulk unit (which may
  // differ from every provider's unit); badge it inside the field.
  const bulkPrice = (schema.price && typeof schema.price === 'object') ? schema.price : {};
  const bulkPriceField = bulkPrice.field || null;
  const bulkPriceUnitLabel = bulkPriceField ? (bulkPrice.label || null) : null;
  const bulkWeightUnits = new Set(((schema.providers || []).map((p) => (p && p.weight_unit) || 'gram')));
  const sharedWeightUnitLabel = bulkWeightUnits.size === 1
    ? ((((schema.providers || [])[0] || {}).weight_unit_label) || 'گرم')
    : null;
  const bulkWeight = (schema.weight && typeof schema.weight === 'object') ? schema.weight : {};
  const bulkWeightField = bulkWeight.field || null;
  const bulkWeightUnitLabel = bulkWeightField ? (bulkWeight.label || null) : null;
  const bulkFieldUnit = (f) => ((bulkPriceField && f.name === bulkPriceField && bulkPriceUnitLabel)
    ? bulkPriceUnitLabel
    : ((bulkWeightField && f.name === bulkWeightField && bulkWeightUnitLabel)
      ? bulkWeightUnitLabel
      : (f.kind === 'money' ? sharedCurrencyLabel
        : (f.kind === 'weight' ? sharedWeightUnitLabel : null))));

  // Single combined inputs card: origin + parcel together.
  const combined = h('div', { class: 'section-grid' });
  if (originWanted) {
    const originSeparate = (origin.display || 'combined') === 'separate';
    if (originAvailable && !originSeparate) {
      combined.append(combinedLocationField({
        label: 'مبدا', nameA: 'bulk:origin_province_id', nameB: 'bulk:origin_city_id',
        provinces: origin.provinces || [], citiesByParent: origin.cities_by_province || {},
        required: true, fieldKey: 'origin_province_id',
      }));
    } else if (originAvailable) {
      const { provinceSelect, citySelect } = bulkOriginSeparate(origin);
      combined.append(
        proField('استان مبدا', provinceSelect, { required: true, fieldKey: 'origin_province_id' }),
        proField('شهر مبدا', citySelect, { required: true, fieldKey: 'origin_city_id' })
      );
    } else {
      combined.append(
        proField('استان مبدا', numericInput('bulk:origin_province_id', {}), { required: true, fieldKey: 'origin_province_id' }),
        proField('شهر مبدا', numericInput('bulk:origin_city_id', {}), { required: true, fieldKey: 'origin_city_id' })
      );
    }
  }

  // Parcel fields go into the same card.
  (schema.fields || []).forEach((field) => {
    if (destPairProv && field.name === destPairProv) {
      bulkKindPairNodes(field, bulkByName[destPairCity], {
        dataset: bulkDataset, available: bulkAvailable, destCombined,
      }).forEach((n) => combined.append(n));
      return;
    }
    if (destPairCity && field.name === destPairCity) return;
    if (field.mirror_param && field.mirror_param.options && field.mirror_param.options.length) {
      combined.append(proField(field.label,
        searchableSelect(selectControl(`bulk:${field.name}`, field.mirror_param.options)),
        { required: !!field.required, fieldKey: field.name }));
      return;
    }
    if (field.options && field.options.length) {
      combined.append(proField(field.label,
        searchableSelect(selectControl(`bulk:${field.name}`, field.options)),
        { required: !!field.required, fieldKey: field.name }));
      return;
    }
    // Same kind logic as the single-provider form: province/city bulk fields
    // (own kind or inferred from mappings) become searchable location selects.
    if ((field.kind === 'province' || field.kind === 'city') && bulkAvailable) {
      combined.append(bulkSingleKindField(field, bulkDataset));
      return;
    }
    if (field.kind === 'province' || field.kind === 'city') {
      const control = numericInput(`bulk:${field.name}`, {});
      combined.append(proField(field.label, control, { required: !!field.required, fieldKey: field.name }));
      return;
    }
    const unit = bulkFieldUnit(field);
    const control = field.input === 'number'
      ? numericInput(`bulk:${field.name}`, {})
      : h('input', { type: 'text', name: `bulk:${field.name}`, placeholder: field.label });
    combined.append(proField(field.label, unit ? unitWrap(control, unit) : control, { required: !!field.required, fieldKey: field.name }));
  });
  const inputsCard = sectionCard(icon('box'), 'مشخصات استعلام', null, null, combined, '');
  bulkFormHost.append(inputsCard);
  if (origin.status === 'stale' && origin.message) {
    inputsCard.append(h('p', { class: 'hint notice', text: origin.message }));
  }
  const _bulkDestMsg = (bulkSchema.destination && bulkSchema.destination.message) || '';
  if (bulkSchema.destination && bulkSchema.destination.status === 'stale' && _bulkDestMsg
    && _bulkDestMsg !== (origin.message || '')) {
    inputsCard.append(h('p', { class: 'hint notice', text: _bulkDestMsg }));
  }

  // 4) actions
  const submitBtn = h('button', { class: 'btn btn-primary', type: 'submit' }, 'استعلام همزمان همه شرکت‌ها');
  const resetBtn = h('button', { class: 'btn btn-ghost', type: 'button' }, 'پاک کردن');
  resetBtn.addEventListener('click', () => { renderBulkForm(); paintBulkIntro(); });
  bulkFormHost.append(
    h(
      'div',
      { class: 'form-actions glow-beam' },
      h('div', { class: 'form-actions-row' }, submitBtn, resetBtn),
      h('p', { class: 'form-actions-hint', text: 'نتایج در ستون کناری نمایش داده می‌شود و ارزان‌ترین گزینه مشخص است.' })
    )
  );

  paintBulkIntro();
  });
}

function collectBulk() {
  const restore = stripNumericCommas(bulkFormHost);
  const data = new FormData(bulkFormHost);
  restore();
  const values = {};
  data.forEach((raw, key) => {
    if (key.startsWith('combo:')) {
      const [nameA, nameB] = splitComboKey(key);
      if (nameA || nameB) {
        const [a, b] = splitComboValue(raw);
        if (nameA) values[nameA] = a;
        if (nameB) values[nameB] = b;
      }
      return;
    }
    if (!key.startsWith('bulk:')) return;
    const name = key.slice('bulk:'.length);
    if (name) values[name] = raw === '' ? null : raw;
  });
  return values;
}

function validateBulk(values) {
  clearFormErrors(bulkFormHost);
  let ok = true;
  const need = (key, label) => {
    if (isBlank(values[key])) {
      if (setFieldError(bulkFormHost, key, `${label} را انتخاب کنید.`)) ok = false;
    }
  };
  const originWanted = !(bulkSchema && bulkSchema.origin && bulkSchema.origin.wanted === false);
  if (originWanted) {
    need('origin_province_id', 'استان مبدا');
    need('origin_city_id', 'شهر مبدا');
  }
  (bulkSchema.fields || []).forEach((field) => {
    if (!field || !field.required || !isBlank(values[field.name])) return;
    if (setFieldError(bulkFormHost, field.name, requiredMessage(field.label || field.name))) ok = false;
  });
  const dest = (bulkSchema && bulkSchema.destination) || {};
  if (dest.enabled !== false && dest.province_field && dest.city_field) {
    const bulkByName = {};
    (bulkSchema.fields || []).forEach((field) => { if (field && field.name) bulkByName[field.name] = field; });
    const provField = bulkByName[dest.province_field];
    const cityField = bulkByName[dest.city_field];
    if (provField && cityField) {
      need(dest.province_field, provField.label || 'استان مقصد');
      if (isBlank(values[dest.city_field])) {
        // Separate mode has its own city control; combined single select
        // only exposes the province key, so fall back to it.
        const shown = setFieldError(bulkFormHost, dest.city_field, `${cityField.label || 'شهر مقصد'} را انتخاب کنید.`)
          || setFieldError(bulkFormHost, dest.province_field, `${cityField.label || 'شهر مقصد'} را انتخاب کنید.`);
        if (shown) ok = false;
      }
    }
  }
  if (!ok) {
    toast('فیلدهای مشخص‌شده را بررسی کنید.');
    const firstBad = bulkFormHost.querySelector('.field.has-error select, .field.has-error input');
    if (firstBad) { try { firstBad.focus(); } catch { /* noop */ } }
  }
  return ok;
}

function bulkLoadingCard(text) {
  return h(
    'div',
    { class: 'card skel-card' },
    h('div', { class: 'loading-note' }, h('span', { class: 'btn-spin', style: 'border-color:var(--pc-spinner-track);border-top-color:var(--pc-spinner-ink)' }), h('span', { text })),
    h('div', { class: 'skeleton' }, h('div', { class: 'skeleton-bar' }), h('div', { class: 'skeleton-bar' }))
  );
}

function renderBulkLoading(count) {
  clear(bulkResultHost);
  bulkResultHost.append(bulkLoadingCard('در حال استعلام همزمان از شرکت‌ها… این معمولاً چند ثانیه طول می‌کشد.'));
  for (let i = 0; i < Math.min(count, 4); i += 1) {
    bulkResultHost.append(
      h('div', { class: 'card skel-card' },
        h('div', { class: 'pulse-row' },
          h('div', { class: 'pulse-logo' }),
          h('div', { style: 'flex:1' }, h('div', { class: 'pulse-line', style: 'width:45%' }))),
        h('div', { class: 'pulse-line', style: 'width:70%;height:22px' }))
    );
  }
}

async function submitBulk(event) {
  event.preventDefault();
  const values = collectBulk();
  if (!validateBulk(values)) return;
  // Preflight through the server's own validation (same code path as the
  // quotes): nothing is sent to carriers when any provider rule fails.
  const bulkPreview = previewKind === 'bulk' ? previewBulkDraft() : null;
  if (previewKind === 'bulk' && !bulkPreview) {
    toast('پیش‌نویسی از تب ادمین نرسیده است.');
    return;
  }
  const previewAuth = null;
  let verdict = null;
  try {
    verdict = bulkPreview
      ? await api('/api/admin/preview/bulk-validate', {
        method: 'POST', body: { config: bulkPreview, values }, ...previewAuth,
      })
      : await api('/api/bulk-validate', { method: 'POST', body: { values, client: browserSignals() } });
  } catch (error) {
    toast(error.message);
    return;
  }
  if (verdict && !verdict.valid) {
    clearFormErrors(bulkFormHost);
    (verdict.errors || []).forEach((item) => {
      if (item.field) highlightRuleField(bulkFormHost, item.field, item.message);
    });
    const messages = (verdict.errors || []).map((item) => item.display_name
      ? `${item.display_name}: ${item.message}` : item.message);
    toast(messages.slice(0, 3).join(' — ') + (messages.length > 3 ? ` — ${messages.length - 3} مورد دیگر` : ''));
    return;
  }
  const submitBtn = bulkFormHost.querySelector('button[type="submit"]');
  const idle = submitBtn ? submitBtn.textContent : '';
  setBtnLoading(submitBtn, true, 'در حال استعلام…');
  lastBulkSubmit = values;
  renderBulkLoading((bulkSchema.providers || []).length);
  let data;
  try {
    data = bulkPreview
      ? await api('/api/admin/preview/bulk-check', {
        method: 'POST', body: { config: bulkPreview, values }, ...previewAuth,
      })
      : await api('/api/bulk-check', { method: 'POST', body: { values, client: browserSignals() } });
  } catch (error) {
    clear(bulkResultHost);
    if (error.errors && error.errors.length) {
      error.errors.forEach((item) => setFieldError(bulkFormHost, item.field, item.message));
      toast([error.message, ...error.errors.map((item) => `${item.field}: ${item.message}`)].join(' — '));
    } else {
      toast(error.message);
    }
    bulkResultHost.append(
      h('div', { class: 'card result-card is-fail' },
        h('h3', { text: 'استعلام انجام نشد' }),
        h('p', { class: 'rc-error', text: error.message || 'خطای نامشخص.' }),
        h('div', { style: 'margin-top:12px' }, h('button', { class: 'btn btn-sm', onClick: () => submitBulk(new Event('submit')) }, 'تلاش مجدد')))
    );
    setBtnLoading(submitBtn, false, null, idle);
    return;
  } finally {
    if (submitBtn && !submitBtn.disabled) { /* already restored on error path */ }
  }
  setBtnLoading(submitBtn, false, null, idle);
  lastBulkItems = data.results || [];
  paintBulkResults();
  scrollToResults(bulkResultHost);
}

function bulkMainPrice(item) {
  const prices = item.prices || [];
  return prices.find((price) => price.main) || prices[0] || null;
}

function sortedBulkItems() {
  const items = [...lastBulkItems];
  if (bulkSort === 'name') {
    items.sort((a, b) => String(a.provider?.display_name || a.provider?.name || '').localeCompare(String(b.provider?.display_name || b.provider?.name || ''), 'fa'));
    return items;
  }
  const priceOf = (item) => {
    const main = bulkMainPrice(item);
    if (!item.ok || !main) return Number.POSITIVE_INFINITY;
    return resultAmountInRial(main.value, item);
  };
  items.sort((a, b) => priceOf(a) - priceOf(b));
  return items;
}

function paintBulkResults() {
  clear(bulkResultHost);
  // Frontend-only rule: providers without a price are hidden entirely.
  const priced = (lastBulkItems || []).filter((item) => item.ok && bulkMainPrice(item));
  if (!priced.length) {
    const items = lastBulkItems || [];
    const unservedOnly = items.length > 0
      && items.every((item) => item.error && item.error.unserved);
    bulkResultHost.append(
      h('div', { class: 'card result-card is-fail' },
        h('h3', { text: 'نتیجه‌ای برای نمایش وجود ندارد' }),
        h('p', {
          class: 'rc-error',
          text: unservedOnly
            ? 'هیچ شرکتی به مقصد انتخاب‌شده سرویس نمی‌دهد.'
            : 'خطا در برقراری ارتباط. دقایقی دیگر امتحان کنید.',
        }),
        unservedOnly ? null : h('div', { style: 'margin-top:12px' },
          h('button', { class: 'btn btn-sm', type: 'button', onClick: () => { if (lastBulkSubmit) submitBulk(new Event('submit')); } }, 'تلاش مجدد')))
    );
    return;
  }
  let best = null;
  let bestValue = Number.POSITIVE_INFINITY;
  priced.forEach((item) => {
    const main = bulkMainPrice(item);
    if (!main) return;
    const n = resultAmountInRial(main.value, item);
    if (n < bestValue) { bestValue = n; best = item; }
  });

  const sortToggle = h('div', { class: 'sort-toggle' },
    h('button', { type: 'button', class: bulkSort === 'cheap' ? 'active' : '', onClick: () => { bulkSort = 'cheap'; paintBulkResults(); } }, 'ارزان‌ترین اول'),
    h('button', { type: 'button', class: bulkSort === 'name' ? 'active' : '', onClick: () => { bulkSort = 'name'; paintBulkResults(); } }, 'نام شرکت'));
  const retryBtn = h('button', { class: 'btn btn-ghost btn-sm', type: 'button' }, 'استعلام مجدد');
  retryBtn.addEventListener('click', () => {
    if (lastBulkSubmit) submitBulk(new Event('submit'));
  });

  bulkResultHost.append(
    h(
      'div',
      { class: 'card results-head' },
      h('div', { class: 'results-title-row' },
        h('h2', { text: `نتایج (${toFaDigits(String(priced.length))} نتیجه)` }),
        h('div', { class: 'results-actions' }, sortToggle, retryBtn)),
      h('div', { class: 'stat-pills' },
        best ? h('span', { class: 'stat-pill best', text: `★ بهترین: ${best.provider.display_name} • ${pretty(bulkMainPrice(best).value)} ${resultCurrencyLabel(best)}` }) : null)
    )
  );

  const grid = h('div', { class: 'results-grid' });
  sortedBulkItems()
    .filter((item) => item.ok && bulkMainPrice(item))
    .forEach((item) => {
      const card = bulkResultCard(item, best);
      const referralBelow = renderReferral(item.referral);
      grid.append(referralBelow ? h('div', { class: 'price-stack' }, card, referralBelow) : card);
    });
  bulkResultHost.append(grid);
}

// Variant tags beside the company title: one compact gold chip per chosen
// alternative (payment type, shipping type…). Cards without variants render
// exactly as before (this returns null).
function bulkVariantTags(item) {
  const detail = Array.isArray(item.variant_detail) ? item.variant_detail : [];
  if (!detail.length) return null;
  return h('div', { class: 'variant-tags' },
    detail.map((row) => h('span', { class: 'variant-tag' },
      icon('tag'),
      h('span', { class: 'variant-tag-text', text: `${row.label}: ${row.value_label}` }))));
}

function bulkResultCard(item, best) {
  const provider = item.provider || {};
  const isBest = best && item.ok && item === best;
  const variantTags = bulkVariantTags(item);
  if (!item.ok) {
    const failure = item.error || {};
    return h(
      'div',
      { class: 'card result-card is-fail' },
      h('div', { class: 'rc-top' },
        providerLogo(provider, 44),
        h('div', { class: 'rc-name' },
          h('h3', { text: provider.display_name || provider.name || '—' }),
          variantTags,
          h('div', { class: 'rc-badges' }, h('span', { class: 'badge fail', text: 'ناموفق' })))),
      h('p', { class: 'rc-error', text: failure.detail || 'خطا در استعلام این شرکت.' }),
      (failure.errors || []).length
        ? h('p', { class: 'rc-note', text: failure.errors.map((e) => `${e.field}: ${e.message}`).join(' — ') })
        : h('p', { class: 'rc-note', text: 'ممکن است سرویس شرکت موقتاً پاسخگو نباشد؛ دوباره تلاش کنید.' })
    );
  }
  const main = bulkMainPrice(item);
  const prices = item.prices || [];
  const breakdown = prices.length > 1
    ? h('details', { class: 'breakdown' },
      h('summary', {}, `ریز هزینه‌ها (${prices.length})`),
      h('table', { class: 'summary' }, h('tbody', {},
        prices.map((p) => h('tr', {}, h('td', {}, `${p.label}${p.main ? ' ★' : ''}`), h('td', { text: `${pretty(p.value)} ${resultCurrencyLabel(item)}` }))))))
    : null;
  return h(
    'div',
    { class: `card result-card${isBest ? ' is-best' : ''}` },
    isBest ? h('span', { class: 'ribbon-best', text: '★ بهترین قیمت' }) : null,
    h('div', { class: 'rc-top rc-top-inline' },
      h('div', { class: 'rc-provider' },
        providerLogo(provider, 44),
        h('div', { class: 'rc-name' },
          h('h3', { text: provider.display_name || provider.name || '—' }),
          variantTags)),
      h('div', { class: 'rc-pricebox' },
        h('span', { class: 'rc-price', text: main ? pretty(main.value) : '—' }),
        main ? h('span', { class: 'rc-currency', text: resultCurrencyLabel(item) }) : null)),
    breakdown
  );
}

/* ---------------- admin live preview ---------------- */
// /preview/provider and /preview/bulk render the PUBLIC pages from UNSAVED
// admin drafts: the draft travels via localStorage (same origin, so every
// keystroke in the admin tab lands here through storage events) while shops,
// locations and sibling providers always read live saved data. Quotes go
// through the preview API, never touching the database, cache or logs.
const previewKind = (() => {
  const match = routeLocation().pathname.replace(/\/+$/, '').match(/^\/preview\/(provider|bulk)$/);
  return match ? match[1] : null;
})();
const PREVIEW_STORAGE_KEYS = { provider: 'tapin.preview.provider', bulk: 'tapin.preview.bulk' };

let previewBooted = false;
let previewReloadTimer = null;
let previewSchemaSeq = 0;
let previewStatusNode = null;


function previewDraftEnvelope(kind) {
  try {
    const raw = window.localStorage.getItem(PREVIEW_STORAGE_KEYS[kind]);
    if (!raw) return null;
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function previewProviderDraft() {
  const draft = previewDraftEnvelope('provider');
  return draft && typeof draft === 'object' ? draft : null;
}

function previewBulkDraft() {
  const draft = previewDraftEnvelope('bulk');
  return draft && typeof draft === 'object' ? draft : null;
}

function setPreviewStatus(text, isError) {
  if (!previewStatusNode) return;
  previewStatusNode.textContent = text;
  previewStatusNode.classList.toggle('error-text', !!isError);
}

function showPreviewBanner() {
  if (document.getElementById('preview-banner')) return;
  const main = document.querySelector('main.wrap');
  if (!main) return;
  main.prepend(h('div', { class: 'card', id: 'preview-banner', style: 'border-inline-start:4px solid var(--accent)' },
    h('div', { class: 'row', style: 'justify-content:space-between' },
      h('div', { style: 'flex:1;min-width:200px' },
        h('h2', { style: 'margin:0', text: previewKind === 'provider' ? 'پیش‌نمایش زنده شرکت' : 'پیش‌نمایش زنده گروهی' }),
        h('p', { class: 'muted', style: 'margin:4px 0 0', text: 'این صفحه دقیقاً با پیش‌نویس ذخیره‌نشده ادمین ساخته می‌شود و با هر تغییر خودکار به‌روز می‌شود. استعلام، زنده از وب‌سرویس می‌گیرد.' }),
        (previewStatusNode = h('p', { class: 'hint', text: '' }))))));
}

// Snapshot typed values by control name so a structure refresh (new draft)
// never wipes what the tester already filled in.
function snapshotNamedValues(host) {
  const snap = {};
  if (!host || !host.querySelectorAll) return snap;
  host.querySelectorAll('input[name], select[name], textarea[name]').forEach((el) => {
    const name = el.getAttribute('name');
    if (!name || el.disabled) return;
    if (el.type === 'checkbox' || el.type === 'radio') {
      if (!Array.isArray(snap[name])) snap[name] = [];
      if (el.checked) snap[name].push(el.value);
    } else if (!(name in snap)) {
      snap[name] = el.value;
    }
  });
  return snap;
}

function restoreNamedValues(host, snap) {
  if (!host || !snap) return;
  host.querySelectorAll('input[name], select[name], textarea[name]').forEach((el) => {
    const name = el.getAttribute('name');
    if (!name || !(name in snap)) return;
    const saved = snap[name];
    if (el.type === 'checkbox' || el.type === 'radio') {
      el.checked = Array.isArray(saved) ? saved.includes(el.value) : !!saved;
      return;
    }
    if (typeof saved !== 'string') return;
    el.value = saved;
    if (el.tagName === 'SELECT') {
      if (typeof el.reapplySearch === 'function') {
        try {
          el.reapplySearch();
        } catch { /* noop */ }
      }
      // Cascades (province -> city options) rebuild from change events;
      // dependents sit later in the DOM, so they restore afterwards.
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
}

function previewErrorCard(error) {
  const details = (error.errors || []).map((item) => `${item.field}: ${item.message}`);
  return h('div', { class: 'card' },
    h('h2', { style: 'color:var(--danger)' }, error.message || 'خطا در ساخت پیش‌نمایش'),
    details.length ? h('ul', {}, details.map((line) => h('li', { class: 'error-text' }, line))) : null,
    h('p', { class: 'hint', text: 'با ادامه ویرایش در تب ادمین، این صفحه خودکار به‌روز می‌شود.' }));
}

function previewStamp() {
  try {
    return new Date().toLocaleTimeString('fa-IR');
  } catch {
    return '';
  }
}

async function reloadProviderPreview(clearValues) {
  const draft = previewProviderDraft();
  if (!draft) {
    clear(formHost);
    formHost.append(h('div', { class: 'card' },
      h('h2', {}, 'پیش‌نمایشی یافت نشد'),
      h('p', { class: 'muted', text: 'در تب ادمین ویرایشگر یک شرکت را باز کنید (دکمه «پیش‌نمایش» همین صفحه را باز می‌کند)؛ به‌محض رسیدن پیش‌نویس، فرم اینجا ساخته می‌شود.' })));
    clear(resultHost);
    resultHost.append(singleEmptyState());
    setPreviewStatus('در انتظار پیش‌نویس از تب ادمین…');
    return;
  }
  const seq = ++previewSchemaSeq;
  let data;
  try {
    data = await api('/api/admin/preview/provider-schema', {
      method: 'POST', body: { record: draft },
    });
  } catch (error) {
    if (seq !== previewSchemaSeq) return;
    clear(formHost);
    formHost.append(previewErrorCard(error));
    setPreviewStatus(error.status === 401 || error.status === 403 ? 'نشست مدیریت معتبر نیست؛ دوباره وارد وردپرس شوید.' : 'خطا در ساخت فرم از پیش‌نویس', true);
    return;
  }
  if (seq !== previewSchemaSeq) return;
  const snap = clearValues ? {} : snapshotNamedValues(formHost);
  currentProvider = data;
  clear(resultHost);
  resultHost.append(singleEmptyState());
  titleHost.textContent = data.display_name || data.name || 'پیش‌نمایش';
  clear(document.getElementById('form-logo')).append(providerLogo(data, 44));
  renderForm();
  restoreNamedValues(formHost, snap);
  hide(chooseSection);
  show(formPanel);
  // Reset means "clear my test inputs", not "load the saved company".
  const resetBtn = formHost.querySelector('.form-actions button[type="button"]');
  if (resetBtn) {
    const fresh = resetBtn.cloneNode(true);
    resetBtn.replaceWith(fresh);
    fresh.addEventListener('click', () => reloadProviderPreview(true));
  }
  setPreviewStatus(`به‌روز شد ${previewStamp()} • ${data.display_name || data.name || ''}`);
}

async function reloadBulkPreview() {
  const draft = previewBulkDraft();
  if (!draft) {
    clear(bulkFormHost);
    bulkFormHost.append(h('div', { class: 'card' },
      h('h2', {}, 'پیش‌نمایشی یافت نشد'),
      h('p', { class: 'muted', text: 'در تب ادمین صفحه «استعلام گروهی» را باز کنید؛ به‌محض رسیدن پیش‌نویس، فرم اینجا ساخته می‌شود.' })));
    clear(bulkResultHost);
    bulkResultHost.append(bulkIntroCard());
    setPreviewStatus('در انتظار پیش‌نویس از تب ادمین…');
    return;
  }
  const seq = ++previewSchemaSeq;
  let data;
  try {
    data = await api('/api/admin/preview/bulk-schema', {
      method: 'POST', body: { config: draft },
    });
  } catch (error) {
    if (seq !== previewSchemaSeq) return;
    clear(bulkFormHost);
    bulkFormHost.append(previewErrorCard(error));
    setPreviewStatus(error.status === 401 || error.status === 403 ? 'نشست مدیریت معتبر نیست؛ دوباره وارد وردپرس شوید.' : 'خطا در ساخت فرم از پیش‌نویس', true);
    return;
  }
  if (seq !== previewSchemaSeq) return;
  const snap = snapshotNamedValues(bulkFormHost);
  bulkSchema = data;
  renderBulkForm();
  restoreNamedValues(bulkFormHost, snap);
  paintBulkIntro();
  setPreviewStatus(`به‌روز شد ${previewStamp()} • ${toFaDigits(String((data.providers || []).length))} شرکت`);
}

function reloadPreview(clearValues) {
  if (previewKind === 'provider') reloadProviderPreview(clearValues);
  else if (previewKind === 'bulk') reloadBulkPreview();
}

function onPreviewStorageChanged(event) {
  if (!previewKind || !event || event.key !== PREVIEW_STORAGE_KEYS[previewKind]) return;
  window.clearTimeout(previewReloadTimer);
  previewReloadTimer = window.setTimeout(() => reloadPreview(false), 500);
}

function bootPreview() {
  showPreviewBanner();
  if (navBulk) navBulk.hidden = true;
  if (navAdvanced) navAdvanced.hidden = true;
  if (previewKind === 'provider') {
    showAdvancedView();
    hide(chooseSection);
    show(formPanel);
    // The company switcher belongs to the saved world, not the draft one.
    if (backBtn) backBtn.hidden = true;
    reloadProviderPreview(false);
  } else {
    showBulkView();
    // The advanced shortcut deep-links to SAVED singles; hide it so the
    // preview never suggests leaving the draft context.
    const advToggle = document.getElementById('bulk-advanced-toggle');
    if (advToggle) advToggle.hidden = true;
    reloadBulkPreview();
  }
  if (!previewBooted) {
    previewBooted = true;
    window.addEventListener('storage', onPreviewStorageChanged);
  }
}

/* ---------------- boot ---------------- */

if (!previewKind) loadBulk();

routePublic();

formHost.addEventListener('submit', submitForm);
bulkFormHost.addEventListener('submit', submitBulk);
