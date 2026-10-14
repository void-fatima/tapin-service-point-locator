import { request, routeLocation, navigate, pageURL } from './wordpress.js';
// Admin page. The editor works on a plain JSON record and PUTs it to
// /api/admin/providers/{id}, so the record shape stays the single source of
// truth all the way down to MySQL.

import { api, h, clear, show, hide, fieldWrapper, pretty, toFaDigits, toast, spinner, skeleton, isLogoImage, readFileAsDataURL, icon, captureScroll, preserveScroll, preserveScrollAsync } from './common.js';

const INPUT_KINDS = ['text', 'number', 'select', 'list', 'dict', 'map'];
const INPUT_LABELS = { text: 'متنی', number: 'عددی', select: 'لیست انتخابی', list: 'لیست چند ردیفی', dict: 'شیء تودرتو', map: 'نقشه' };
const VALUE_TYPES = ['str', 'int', 'float'];
const ROW_INPUT_KINDS = ['text', 'number', 'select'];
const PARAM_KINDS = ['city', 'province'];

// Origin selectors are system defaults: the backend adds them to every
// provider automatically, so the editor never shows them and never saves them.
// records.normalise re-attaches them on every save.
const ORIGIN_PARAM_NAMES = ['origin_province_id', 'origin_city_id'];
const isOriginParam = (name) => ORIGIN_PARAM_NAMES.includes(name);

const listView = document.getElementById('list-view');
const editorView = document.getElementById('editor-view');
const bulkView = document.getElementById('bulk-view');
const rowsHost = document.getElementById('provider-rows');
const shopsView = document.getElementById('shops-view');
const shopsHost = document.getElementById('shops-rows');
const logsView = document.getElementById('logs-view');
const logsHost = document.getElementById('logs-rows');
const securityView = document.getElementById('security-view');
const securityHost = document.getElementById('security-rows');
const cacheView = document.getElementById('cache-view');
const cacheHost = document.getElementById('cache-rows');
const tabs = [...document.querySelectorAll('.tabs button')];

function auth() { return {}; }

// ---- live preview sync ----
// The preview window (a public page in preview mode) renders the UNSAVED
// draft: every change here is mirrored to localStorage (same origin, so the
// other tab picks it up instantly via storage events) and re-rendered there.
// Polling with change-detection catches every draft mutation without wiring
// into dozens of editors; only one sync runs at a time and it stops as soon
// as the editor is left.
const PREVIEW_PROVIDER_KEY = 'tapin.preview.provider';
const PREVIEW_BULK_KEY = 'tapin.preview.bulk';
let previewSyncTimer = null;
let previewSyncLast = { provider: null, bulk: null };

function writePreviewDraft(kind, text) {
  if (previewSyncLast[kind] === text) return;
  previewSyncLast[kind] = text;
  try {
    window.localStorage.setItem(kind === 'provider' ? PREVIEW_PROVIDER_KEY : PREVIEW_BULK_KEY, text);
  } catch {
    /* storage blocked: preview just won't update live */
  }
}

function startPreviewSync(kind, buildDraft) {
  stopPreviewSync();
  const tick = () => {
    let text = null;
    try {
      text = JSON.stringify(buildDraft());
    } catch {
      return;
    }
    if (typeof text === 'string') writePreviewDraft(kind, text);
  };
  tick();
  previewSyncTimer = window.setInterval(tick, 700);
}

function stopPreviewSync() {
  if (previewSyncTimer !== null) {
    window.clearInterval(previewSyncTimer);
    previewSyncTimer = null;
  }
}

let previewProviderBuilder = null;

function openPreviewWindow(kind) {
  // Flush synchronously first so the new window never reads a stale draft,
  // then reuse the named window on repeat clicks.
  try {
    const text = kind === 'provider'
      ? (previewProviderBuilder ? JSON.stringify(previewProviderBuilder()) : null)
      : JSON.stringify(bulkRequestBody());
    if (typeof text === 'string') {
      previewSyncLast[kind] = null;
      writePreviewDraft(kind, text);
    }
  } catch { /* preview opens with whatever draft is stored */ }
  window.open(pageURL(kind === 'provider' ? '/preview/provider' : '/preview/bulk'), `tapin-preview-${kind}`);
}

function blankParam(input = 'text') {
  return {
    name: '',
    label: '',
    input,
    type: 'str',
    visible: true,
    description: '',
    required: false,
    default: input === 'list' || input === 'dict' ? null : '',
    ...(input === 'select' ? { options: [] } : {}),
    ...(input === 'list' ? { min_items: 1, fields: [] } : {}),
    ...(input === 'dict' ? { fields: [] } : {}),
  };
}

function blankProvider() {
  return { id: null, name: '', display_name: '', logo: '', check_price_url: '', shop_id: '', timeout: '', main_price_key: '', price_currency_in: 'rial', price_currency_out: 'rial', price_input_param: null, weight_unit_in: 'gram', weight_unit_out: 'gram', weight_input_param: null, active: true, price_fields: [], params: [], referral: { enabled: false, title: '', url: '', description: '' }, rules: [] };
}

// Turn one pasted JSON value into a param draft, or null when it has no name.
// Missing keys fall back to the blankParam defaults so the card renders fully.
function paramFromJson(item) {
  if (!item || typeof item !== 'object' || Array.isArray(item)) return null;
  const name = String(item.name ?? '').trim();
  if (!name) return null;
  const input = INPUT_KINDS.includes(item.input) ? item.input : 'text';
  const type = VALUE_TYPES.includes(item.type) ? item.type : 'str';
  const param = { ...blankParam(input), name, type, input };
  if (typeof item.required === 'boolean') param.required = item.required;
  if (item.label !== undefined) param.label = String(item.label);
  if (item.visible !== undefined) param.visible = !!item.visible;
  if (item.kind !== undefined) param.kind = PARAM_KINDS.includes(item.kind) ? item.kind : null;
  if (item.description !== undefined) param.description = String(item.description);
  if (item.map !== undefined && item.map && typeof item.map === 'object' && !Array.isArray(item.map)) {
    param.map = {
      mode: item.map.mode === 'combined' ? 'combined' : 'split',
      lat_param: String(item.map.lat_param ?? ''),
      long_param: String(item.map.long_param ?? ''),
      param: String(item.map.param ?? ''),
    };
  }
  if (item.default !== undefined) param.default = item.default;
  if (Array.isArray(item.options)) {
    param.options = item.options
      .map((option) => {
        if (typeof option === 'string') return { value: option.trim(), label: option.trim() };
        if (option && typeof option === 'object') {
          const value = String(option.value ?? '').trim();
          return { value, label: String(option.label ?? value) };
        }
        return null;
      })
      .filter((option) => option && option.value);
  }
  if (typeof item.min_items === 'number' && item.min_items > 0) param.min_items = item.min_items;
  if (Array.isArray(item.fields)) {
    param.fields = item.fields
      .map((field) => {
        if (!field || typeof field !== 'object' || Array.isArray(field)) return null;
        const fieldName = String(field.name ?? '').trim();
        if (!fieldName) return null;
        const fieldInput = ROW_INPUT_KINDS.includes(field.input) ? field.input : 'text';
        const out = {
          name: fieldName,
          label: String(field.label ?? fieldName),
          input: fieldInput,
          type: VALUE_TYPES.includes(field.type) ? field.type : 'str',
          visible: !!field.visible,
          default: field.default ?? '',
          kind: PARAM_KINDS.includes(field.kind) ? field.kind : null,
          required: !!field.required,
        };
        if (fieldInput === 'select' && Array.isArray(field.options)) {
          out.options = field.options
            .map((option) => {
              if (typeof option === 'string') return { value: option.trim(), label: option.trim() };
              if (option && typeof option === 'object') {
                const value = String(option.value ?? '').trim();
                return { value, label: String(option.label ?? value) };
              }
              return null;
            })
            .filter((option) => option && option.value);
        } else if (fieldInput === 'select') {
          out.options = [];
        }
        return out;
      })
      .filter(Boolean);
  }
  return param;
}

function isPlainObject(value) {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

function rowFieldFromSample(name, value) {
  if (typeof value === 'number') {
    return {
      name, label: name, input: 'number',
      type: Number.isInteger(value) ? 'int' : 'float',
      visible: true, default: '', kind: null,
    };
  }
  return { name, label: name, input: 'text', type: 'str', visible: true, default: '', kind: null };
}

function paramFromPayloadSample(name, value) {
  // Build a param draft from one key of a sample request body: numbers
  // become numeric params, objects become dicts, arrays of objects become
  // lists, everything else becomes text.
  if (!name) return null;
  if (Array.isArray(value)) {
    const firstObject = value.find((item) => isPlainObject(item));
    const fields = firstObject
      ? Object.keys(firstObject).map((key) => rowFieldFromSample(key, firstObject[key]))
      : [rowFieldFromSample('value', value.find((item) => item !== null && item !== undefined))];
    if (!fields.length || !fields[0]) return null;
    return {
      ...blankParam('list'), name, label: name, type: 'str',
      min_items: 1, fields: fields.filter(Boolean),
    };
  }
  if (isPlainObject(value)) {
    const fields = Object.keys(value).map((key) => rowFieldFromSample(key, value[key]));
    if (!fields.length) return null;
    return { ...blankParam('dict'), name, label: name, type: 'str', fields };
  }
  if (typeof value === 'number') {
    return {
      ...blankParam('number'), name, label: name,
      type: Number.isInteger(value) ? 'int' : 'float',
      kind: null,
    };
  }
  return {
    ...blankParam('text'), name, label: name, type: 'str',
    kind: null,
  };
}

// One guide box at the top of each tab's main card, instead of scattered
// per-field hints. Items are [term, description] pairs.
function guideBox(items) {
  return h(
    'div',
    { class: 'guide-box' },
    h('div', { class: 'guide-title', text: 'راهنما' }),
    h('ul', {}, items.map(([term, desc]) => h('li', {}, h('b', { text: term }), desc ? ` — ${desc}` : '')))
  );
}

// --------------------------------------------------------------------------- list


function showList() {
  previewProviderBuilder = null;
  stopPreviewSync();
  hide(editorView);
  hide(shopsView);
  hide(bulkView);
  hide(logsView);
  hide(securityView);
  if (typeof cacheView !== 'undefined' && cacheView) hide(cacheView);
  show(listView);
  markTab('providers');
  goAdmin('/admin/providers');
  loadList();
}

async function loadList() {
  const restoreScroll = captureScroll();
  clear(rowsHost).append(skeleton(2));
  restoreScroll();
  let providers;
  try {
    ({ providers } = await api('/api/admin/providers', auth()));
  } catch (error) {
    clear(rowsHost).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
    restoreScroll();
    return;
  }

  const searchInput = h('input', { type: 'text', placeholder: 'جستجوی شرکت با نام نمایشی یا فنی…', autocomplete: 'off' });
  const countNote = h('p', { class: 'muted', style: 'margin:6px 0 0' });
  clear(rowsHost).append(
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h(
          'div',
          { style: 'flex:1' },
          h('h2', {}, `شرکت‌ها (${toFaDigits(String(providers.length))})`),
          h('div', { class: 'stat-row' },
            h('span', { class: 'stat-mini' }, 'شرکت‌ها ', h('b', { text: toFaDigits(String(providers.length)) })),
            h('span', { class: 'stat-mini gold' }, 'فیلد نمایشی ', h('b', { text: toFaDigits(String(providers.reduce((sum, p) => sum + (p.visible_field_count || 0), 0))) })),
            h('span', { class: 'stat-mini' }, 'فیلد قیمت ', h('b', { text: toFaDigits(String(providers.reduce((sum, p) => sum + ((p.price_fields || []).length), 0))) }))
          )
        ),
        h('button', { class: 'btn', onClick: () => openEditor(null) }, '＋ افزودن شرکت')
      ),
      providers.length
        ? h('div', {}, h('div', { class: 'search-row' }, searchInput), countNote)
        : null,
      providers.length
        ? providerTable(providers)
        : h('div', { class: 'empty', style: 'text-align:center' },
          h('div', { class: 'empty-illust' }, icon('building')),
          h('p', { style: 'margin:0', text: 'هنوز شرکتی ثبت نشده است. از دکمه بالا اولین شرکت را بسازید.' }))
    )
  );
  const table = rowsHost.querySelector('.table-scroll table');
  if (table && providers.length) {
    const paintCount = () => {
      const visible = [...table.querySelectorAll('tbody tr')].filter((row) => !row.hidden).length;
      countNote.textContent = queryOf(searchInput) ? `نمایش ${toFaDigits(String(visible))} از ${toFaDigits(String(providers.length))} شرکت` : '';
    };
    searchInput.addEventListener('input', () => {
      const query = queryOf(searchInput);
      table.querySelectorAll('tbody tr').forEach((row) => {
        row.hidden = query !== '' && !(row.dataset.search || '').includes(query);
      });
      paintCount();
    });
  }
  restoreScroll();
}

function queryOf(input) {
  return (input.value || '').trim().toLowerCase();
}

function providerTable(providers) {
    const header = ['شناسه', 'نام نمایشی', 'نام ارسالی در وب‌سرویس', 'وضعیت', 'پارامترها', 'عملیات'];
  return h(
    'div',
    { class: 'table-scroll' },
    h(
      'table',
      {},
      h('thead', {}, h('tr', {}, header.map((title) => h('th', {}, title)))),
      h(
        'tbody',
        {},
        providers.map((provider) =>
          h(
            'tr',
            { 'data-search': `${provider.display_name || ''} ${provider.name || ''} ${provider.id || ''}`.toLowerCase() },
            h('td', { class: 'code' }, provider.id),
            h('td',
              { class: 'stat-cell' },
              isLogoImage(provider.logo) ? h('img', { class: 'logo-thumb', src: provider.logo, alt: '' }) : null,
              ' ',
              provider.display_name),
            h('td', { class: 'code' }, provider.name),
            h('td', {}, h('span', { class: provider.active === false ? 'pill pill-dim' : 'pill pill-ok', text: provider.active === false ? 'غیرفعال' : 'فعال' })),
            h(
              'td',
              { class: 'muted stat-cell' },
              `${provider.param_count} پارامتر • ${provider.visible_field_count} نمایشی • ${(provider.price_fields || []).length} قیمت`
            ),
            h(
              'td',
              { class: 'actions' },
              h('button', { class: 'btn btn-sm', onClick: () => openEditor(provider.id) }, 'ویرایش'),
              ' ',
              h(
                'button',
                {
                  class: 'btn btn-sm btn-remove',
                  onClick: () => {
                    if (!window.confirm(`شرکت «${provider.display_name}» و همه پارامترهایش حذف شود؟`)) return;
                    remove(provider.id);
                  },
                },
                'حذف'
              )
            ),
          )
        )
      )
    )
  );
}

async function remove(id) {
  try {
    await api(`/api/admin/providers/${id}`, { method: 'DELETE', ...auth() });
    toast('حذف شد.', 'ok');
    loadList();
  } catch (error) {
    toast(error.message);
  }
}

// --------------------------------------------------------------------------- editor


async function openEditor(id) {
  let provider = blankProvider();
  if (id !== null) {
    try {
      provider = await api(`/api/admin/providers/${encodeURIComponent(id)}`, auth());
    } catch (error) {
      toast(error.message);
      return;
    }
  }
  renderEditor(provider);
  hide(listView);
  show(editorView);
  markTab('providers');
  goAdmin(`/admin/providers/${id === null ? 'new' : encodeURIComponent(id)}`);
  window.scrollTo({ top: 0 });
}

function renderEditor(provider) {
  const record = {
    active: provider.active !== false,
    ...provider,
    active: provider.active !== false,
    params: (provider.params || []).filter((param) => !isOriginParam(param.name)).map((param) => ({ ...param, options: Array.isArray(param.options) ? param.options.map((o) => ({ ...o })) : param.options, fields: Array.isArray(param.fields) ? param.fields.map((f) => ({ ...f, options: Array.isArray(f.options) ? f.options.map((o) => ({ ...o })) : f.options })) : param.fields })),
    referral: { enabled: false, title: '', url: '', description: '', ...((provider.referral && typeof provider.referral === 'object') ? provider.referral : {}) },
    location_config: normaliseLocationDraft(provider.location_config),
    rules: Array.isArray(provider.rules)
      ? provider.rules.filter((rule) => rule && typeof rule === 'object').map((rule) => ({ name: '', if_field: '', if_op: 'eq', if_value: '', if_value2: '', then_field: '', then_op: 'eq', then_value: '', then_value2: '', message: '', ...rule }))
      : [],
  };
  const paramsHost = h('div');
  const errorHost = h('div');
  const paramSearch = h('input', { type: 'text', placeholder: 'جستجوی پارامتر با نام ارسالی یا عنوان…' });
  const paramVisibility = h(
    'select',
    {},
    h('option', { value: 'all' }, 'همه پارامترها'),
    h('option', { value: 'visible' }, 'فقط نمایشی‌ها'),
    h('option', { value: 'hidden' }, 'فقط مخفی‌ها')
  );
  const paramCount = h('p', { class: 'muted', style: 'margin:0' });
  let paramCards = [];
  const fieldsCount = h('b', { text: '۰' });
  const pricesCount = h('b', { text: '۰' });
  const rulesCount = h('b', { text: '۰' });
  const refreshTabCounts = () => {
    fieldsCount.textContent = toFaDigits(String(record.params.length));
    pricesCount.textContent = toFaDigits(String((record.price_fields || []).length));
    rulesCount.textContent = toFaDigits(String((record.rules || []).length));
  };

  const applyParamFilter = () => {
    const query = paramSearch.value.trim().toLowerCase();
    const visibility = paramVisibility.value;
    let shown = 0;
    paramCards.forEach(({ param, card }) => {
      const haystack = `${param.name || ''} ${param.label || ''}`.toLowerCase();
      const matchesQuery = query === '' || haystack.includes(query);
      const matchesVisibility = visibility === 'all'
        || (visibility === 'visible' && param.visible)
        || (visibility === 'hidden' && !param.visible);
      const match = matchesQuery && matchesVisibility;
      card.hidden = !match;
      if (match) shown += 1;
    });
    paramCount.textContent = (query === '' && visibility === 'all') ? '' : `نمایش ${shown} از ${paramCards.length} پارامتر`;
  };
  paramSearch.addEventListener('input', applyParamFilter);
  paramVisibility.addEventListener('change', applyParamFilter);

  const suggestMapName = () => {
    const taken = new Set(record.params.map((item) => item.name));
    let counter = record.params.filter((item) => item.input === 'map').length + 1;
    while (taken.has(`map_${counter}`)) counter += 1;
    return `map_${counter}`;
  };

  // Rebuilding every card collapses the view; keep the scroll where the
  // user was (adding/removing a param must not jump back to the top).
  const renderParams = (openIndex = -1) => preserveScroll(() => {
    clear(paramsHost);
    paramCards = record.params.map((param, index) => {
      const card = paramCard(param, (e) => {
        if (e) e.stopPropagation();
        record.params.splice(index, 1);
        renderParams();
      }, applyParamFilter, suggestMapName, index === openIndex);
      paramsHost.append(card);
      return { param, card };
    });
    applyParamFilter();
    refreshTabCounts();
  });
  renderParams();

  const addParam = (input = 'text') => {
    const param = blankParam(input);
    if (input === 'map') param.name = suggestMapName();
    record.params.push(param);
    paramSearch.value = '';
    renderParams(record.params.length - 1);
  };

  const jsonText = h('textarea', {
    rows: 6,
    placeholder: '[{"name": "weight", "label": "وزن", "input": "number", "type": "int", "visible": true}]',
  });
  const jsonHint = h('p', { class: 'hint' },
    'دو حالت پشتیبانی می‌شود: لیست تعریف پارامترها (مثل بالا) یا یک نمونه بدنه ارسالی مثل {"weight": 1200، "products": [...]} که پارامترها به‌صورت خودکار از روی آن ساخته می‌شوند.');
  const jsonError = h('div');

  // Preview only: parsed params are added to the draft and shown as cards.
  // Nothing is sent to the server until the user clicks "ذخیره".
  const previewJsonParams = () => {
    clear(jsonError);
    let parsed;
    try {
      parsed = JSON.parse(jsonText.value);
    } catch {
      jsonError.append(h('p', { class: 'error-text', text: 'JSON معتبر نیست.' }));
      return;
    }
    if (parsed && !Array.isArray(parsed)) parsed = [parsed];
    if (!Array.isArray(parsed) || !parsed.length) {
      jsonError.append(h('p', { class: 'error-text', text: 'یک شیء یا لیستی از پارامترها وارد کنید.' }));
      return;
    }
    const isDefinition = (item) => isPlainObject(item)
      && typeof item.name === 'string' && item.name.trim() !== '';
    let added = 0;
    let skipped = 0;
    if (parsed.every(isDefinition)) {
      parsed.forEach((item) => {
        const param = paramFromJson(item);
        if (!param) {
          skipped += 1;
          return;
        }
        record.params.push(param);
        added += 1;
      });
    } else {
      const merged = {};
      parsed.forEach((item) => {
        if (!isPlainObject(item)) {
          skipped += 1;
          return;
        }
        Object.keys(item).forEach((key) => {
          if (!(key in merged)) merged[key] = item[key];
        });
      });
      Object.keys(merged).forEach((key) => {
        const param = paramFromPayloadSample(key, merged[key]);
        if (!param) {
          skipped += 1;
          return;
        }
        record.params.push(param);
        added += 1;
      });
    }
    renderParams(added ? record.params.length - added : -1);
    if (added) {
      toast(`${added} پارامتر اضافه شد؛ برای ذخیره دکمه «ذخیره» را بزنید.`, 'ok');
    }
    if (skipped) {
      jsonError.append(h('p', { class: 'error-text', text: `${skipped} مورد بدون نام معتبر نادیده گرفته شد.` }));
    }
    if (!added && !skipped) {
      jsonError.append(h('p', { class: 'error-text', text: 'پارامتری برای افزودن پیدا نشد.' }));
    }
  };

  const logoPreview = h('div', { class: 'logo-preview' });
  const logoInput = h('input', { type: 'file', accept: 'image/*' });
  const logoError = h('div');
  if (!isLogoImage(record.logo)) record.logo = '';
  const paintLogo = () => {
    clear(logoPreview).append(
      isLogoImage(record.logo)
        ? h('img', { src: record.logo, alt: record.display_name || record.name || '' })
        : h('p', { class: 'empty', text: 'لوگویی انتخاب نشده است؛ در صورت خالی بودن، حرف اول نام نمایش داده می‌شود.' }),
      isLogoImage(record.logo)
        ? h('button', {
          class: 'btn btn-sm btn-remove', type: 'button', style: 'margin-top:8px',
          onClick: () => { record.logo = ''; logoInput.value = ''; paintLogo(); },
        }, 'حذف لوگو')
        : null
    );
  };
  paintLogo();
  logoInput.addEventListener('change', async () => {
    clear(logoError);
    const file = logoInput.files && logoInput.files[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      logoError.append(h('p', { class: 'error-text', text: 'فقط فایل تصویری انتخاب کنید.' }));
      logoInput.value = '';
      return;
    }
    if (file.size > 1024 * 1024) {
      logoError.append(h('p', { class: 'error-text', text: 'حجم لوگو نباید بیشتر از ۱ مگابایت باشد.' }));
      logoInput.value = '';
      return;
    }
    try {
      record.logo = await readFileAsDataURL(file);
      paintLogo();
    } catch (error) {
      logoError.append(h('p', { class: 'error-text', text: 'خواندن فایل ناموفق بود.' }));
      logoInput.value = '';
    }
  });

  const settingsCard = h(
    'div',
    { class: 'card' },
    h('h2', {}, 'مشخصات شرکت'),
    guideBox([
      ['نام ارسالی در وب‌سرویس', 'همین نام در درخواست وب‌سرویس ارسال می‌شود.'],
      ['آدرس کامل سرویس قیمت', 'نشانی کامل http یا https سرویس استعلام قیمت همین شرکت.'],
      ['شناسه شعبه پیش‌فرض', 'برای استعلام زنده قیمت استفاده می‌شود؛ توکن این شعبه در بخش «شعب» تنظیم می‌شود.'],
      ['مهلت پاسخ‌گویی', 'خالی یعنی ۱۵ ثانیه.'],
      ['لوگو', 'در پایگاه داده به‌صورت base64 ذخیره می‌شود (حداکثر ۱ مگابایت).'],
    ]),
    h(
      'div',
      { class: 'grid' },
      fieldWrapper(
        'شناسه',
        h('input', { type: 'text', value: provider.id ?? 'خودکار', disabled: true })
      ),
      h(
        'div',
        { class: 'field' },
        h('label', {}, 'وضعیت شرکت'),
        h('label', { class: 'checkline' },
          h('input', {
            type: 'checkbox', checked: record.active !== false,
            onChange: (e) => { record.active = e.target.checked; },
          }),
          h('span', {}, 'فعال (غیرفعال شود در استعلام تکی نمایش داده نمی‌شود)'))
      ),
      fieldWrapper('نام ارسالی در وب‌سرویس', h('input', { type: 'text', required: true, value: provider.name || '', onInput: (e) => { record.name = e.target.value; } })),
      fieldWrapper('نام نمایشی', h('input', { type: 'text', value: provider.display_name || '', onInput: (e) => { record.display_name = e.target.value; } })),
      fieldWrapper('آدرس کامل سرویس قیمت', h('input', { type: 'text', value: provider.check_price_url || '', onInput: (e) => { record.check_price_url = e.target.value; } })),
      fieldWrapper(
        'شناسه شعبه پیش‌فرض',
        h('input', { type: 'text', value: provider.shop_id || '', onInput: (e) => { record.shop_id = e.target.value; } })
      ),
      fieldWrapper(
        'مهلت پاسخ‌گویی سرویس (ثانیه)',
        h('input', { type: 'number', min: 1, max: 300, value: provider.timeout ?? '', onInput: (e) => { record.timeout = e.target.value; } })
      ),
      fieldWrapper('لوگو - انتخاب فایل تصویری', h('div', {}, logoPreview, logoInput, logoError))
    )
  );

  const fieldsCard = h(
    'div',
    { class: 'card' },
    h(
      'div',
      { class: 'card-head', style: 'margin-bottom:12px' },
      h('h2', {}, 'فیلدها'),
      h('span', { class: 'hint', style: 'margin:0', text: 'تیک «نمایش» یعنی در فرم عمومی دیده می‌شود.' })
    ),
    guideBox([
      ['نام ارسالی', 'به‌عنوان کلید همین پارامتر در درخواست وب‌سرویس ارسال می‌شود.'],
      ['نوع ورودی', 'شکل فیلد در فرم استعلام: متنی، عددی، لیست انتخابی، لیست چندردیفی، شیء یا نقشه.'],
      ['داده شهر و استان', 'از تب «تنظیمات شهر و استان» مشخص می‌شود: فیلدهای استان/شهر مقصد آنجا انتخاب می‌شوند.'],
      ['نوع مقدار', 'تعیین می‌کند مقدار به‌صورت متن، عدد صحیح یا اعشاری اعتبارسنجی و ارسال شود.'],
      ['مقدار پیش‌فرض', 'برای فیلدهای مخفی مستقیم ارسال می‌شود.'],
      ['توضیح نمایشی', 'زیر همین فیلد در فرم استعلام نمایش داده می‌شود.'],
    ]),
    h(
      'div',
      { class: 'fields-toolbar' },
      h('button', { class: 'btn btn-sm', type: 'button', onClick: () => addParam() }, '＋ متنی'),
      h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => addParam('select') }, '＋ انتخابی'),
      h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => addParam('list') }, '＋ لیست'),
      h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => addParam('dict') }, '＋ شیء'),
      h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => addParam('map') }, '＋ نقشه')
    ),
    h(
      'div',
      { class: 'grid' },
      fieldWrapper('جستجو', paramSearch, 'با نام ارسالی یا عنوان فارسی فیلتر کنید.'),
      fieldWrapper('نمایش', paramVisibility, 'فقط پارامترهای نمایشی یا مخفی را ببینید.')
    ),
    paramCount,
    paramsHost
  );

  const jsonCard = h(
    'div',
    { class: 'card' },
    h('h2', {}, 'افزودن پارامتر با JSON'),
    h('p', { class: 'muted', style: 'margin-top:0' }, 'JSON پارامترها را بچسبانید و «پیش‌نمایش» را بزنید. پارامترها فقط به پیش‌نویس اضافه و نمایش داده می‌شوند؛ ذخیره‌سازی فقط با دکمه «ذخیره» انجام می‌شود.'),
    fieldWrapper('JSON پارامترها', jsonText),
    jsonHint,
    h('div', { class: 'row' }, h('button', { class: 'btn btn-ghost', type: 'button', onClick: previewJsonParams }, 'پیش‌نمایش و افزودن')),
    jsonError
  );

  const settingsTabBtn = h('button', { class: 'btn', type: 'button', onClick: () => showEditorTab('settings') }, 'تنظیمات شرکت');
  const fieldsTabBtn = h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => showEditorTab('fields') }, 'فیلدها (', fieldsCount, ')');
  const pricesTabBtn = h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => showEditorTab('prices') }, 'قیمت و وزن (', pricesCount, ')');
  const locationTabBtn = h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => showEditorTab('location') }, 'تنظیمات شهر و استان');
  const referralTabBtn = h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => showEditorTab('referral') }, 'لینک ارجاع');
  const rulesTabBtn = h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => showEditorTab('rules') }, 'شرط‌ها (', rulesCount, ')');
  const settingsPane = h('div', { class: 'tab-panel' }, settingsCard);
  const fieldsPane = h('div', { class: 'tab-panel' }, fieldsCard, jsonCard);
  fieldsPane.hidden = true;
  const pricesPane = h('div', { class: 'tab-panel' }, priceFieldsEditor(record, refreshTabCounts), weightUnitEditor(record));
  pricesPane.hidden = true;
  const locationPane = h('div', { class: 'tab-panel' }, locationEditor(record));
  locationPane.hidden = true;
  const referralPane = h('div', { class: 'tab-panel' }, referralEditor(record));
  referralPane.hidden = true;
  const rulesPane = h('div', { class: 'tab-panel' }, rulesEditor(record, refreshTabCounts));
  rulesPane.hidden = true;

  function showEditorTab(name) {
    settingsPane.hidden = name !== 'settings';
    fieldsPane.hidden = name !== 'fields';
    pricesPane.hidden = name !== 'prices';
    locationPane.hidden = name !== 'location';
    referralPane.hidden = name !== 'referral';
    rulesPane.hidden = name !== 'rules';
    settingsTabBtn.classList.toggle('btn-ghost', name !== 'settings');
    fieldsTabBtn.classList.toggle('btn-ghost', name !== 'fields');
    pricesTabBtn.classList.toggle('btn-ghost', name !== 'prices');
    locationTabBtn.classList.toggle('btn-ghost', name !== 'location');
    referralTabBtn.classList.toggle('btn-ghost', name !== 'referral');
    rulesTabBtn.classList.toggle('btn-ghost', name !== 'rules');
  }

  const form = h(
    'form',
    { onSubmit: (event) => { event.preventDefault(); save(record, errorHost); } },
    h('div', { class: 'tabs subtabs', role: 'tablist' }, settingsTabBtn, fieldsTabBtn, pricesTabBtn, locationTabBtn, referralTabBtn, rulesTabBtn),
    settingsPane,
    fieldsPane,
    pricesPane,
    locationPane,
    referralPane,
    rulesPane,
    h(
      'div',
      { class: 'card row actions-sticky' },
      h('button', { class: 'btn', type: 'submit' }, 'ذخیره'),
      h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => openPreviewWindow('provider') }, 'پیش‌نمایش'),
      h('button', { class: 'btn btn-ghost', type: 'button', onClick: showList }, 'بازگشت')
    ),
    errorHost
  );
  refreshTabCounts();
  // Live preview follows this very draft (saved siblings stay saved).
  previewProviderBuilder = () => ({ ...serialise(record), id: record.id ?? null });
  startPreviewSync('provider', previewProviderBuilder);

  const visibleCount = record.params.filter((item) => item.visible).length;
  clear(editorView).append(
    h(
      'div',
      { class: 'admin-hero' },
      h('span', { class: 'admin-hero-icon' }, icon(provider.id ? 'pencil' : 'plus')),
      h(
        'div',
        { class: 'admin-hero-text' },
        h('h1', {}, provider.id ? `ویرایش ${provider.display_name || provider.name || ''}` : 'افزودن شرکت'),
        h('p', { class: 'muted', text: 'فیلدهای مخفی در فرم عمومی دیده نمی‌شوند و مقدار پیش‌فرضشان مستقیم ارسال می‌شود. استان/شهر مبدا خودکار اضافه می‌شود.' }),
        h('div', { class: 'stat-row' },
          provider.id != null ? h('span', { class: 'stat-mini' }, 'شناسه ', h('b', { text: toFaDigits(String(provider.id)) })) : null,
          provider.name ? h('span', { class: 'stat-mini' }, h('span', { class: 'code', text: provider.name })) : null,
          h('span', { class: 'stat-mini gold' }, 'پارامتر ', h('b', { text: toFaDigits(String(record.params.length)) })),
          h('span', { class: 'stat-mini ok' }, 'نمایشی ', h('b', { text: toFaDigits(String(visibleCount)) }))
        )
      ),
      h('div', { class: 'admin-hero-actions' },
        h('button', { class: 'btn btn-ghost', type: 'button', onClick: showList }, 'بازگشت به لیست'))
    ),
    form
  );
}

// Every plain text/number scalar ("param" or "param.sub") an amount/weight
// input target can point at. Options rebuild on focus so params added in
// the fields tab are listed without reopening.
function buildScalarTargetOptions(record) {
  const opts = [];
  (record.params || [])
    .filter((p) => p && p.name && (p.input === 'text' || p.input === 'number') && !isOriginParam(p.name))
    .forEach((p) => opts.push({ value: p.name, label: `${p.label || p.name} (${p.name})` }));
  (record.params || [])
    .filter((p) => p && p.name && (p.input === 'list' || p.input === 'dict') && !isOriginParam(p.name))
    .forEach((p) => (p.fields || [])
      .filter((f) => f && f.name && (f.input === 'text' || f.input === 'number'))
      .forEach((f) => opts.push({ value: `${p.name}.${f.name}`, label: `${p.label || p.name} — ${f.label || f.name} (${p.name}.${f.name})` })));
  return opts;
}

function withMissingTargetOption(opts, current) {
  if (current && !opts.some((o) => o.value === current)) {
    opts.unshift({ value: current, label: `${current} (پارامتر یافت نشد)` });
  }
  return opts;
}

function priceFieldsEditor(record, onChange) {  record.price_fields = record.price_fields || [];
  if (record.price_currency_in !== 'toman') record.price_currency_in = 'rial';
  if (record.price_currency_out !== 'toman') record.price_currency_out = 'rial';
  const body = h('tbody');
  const currencySelect = (key) => h('select', {
    onChange: (e) => { record[key] = e.target.value === 'toman' ? 'toman' : 'rial'; },
  },
    h('option', { value: 'rial', selected: record[key] !== 'toman' }, 'ریال'),
    h('option', { value: 'toman', selected: record[key] === 'toman' }, 'تومان'));

  if (typeof record.price_input_param !== 'string') record.price_input_param = record.price_input_param || null;
  // The user-price target ("param" or "param.sub") gets the currency
  // conversion together with money-ticked fields. Options rebuild on focus
  // so params added in the fields tab are listed without reopening.
  const buildPriceInputOptions = () => withMissingTargetOption(
    buildScalarTargetOptions(record), record.price_input_param || '');
  const priceInputSelect = h('select', {
    onChange: (e) => { record.price_input_param = e.target.value || null; },
  });
  const paintPriceInputOptions = () => {
    clear(priceInputSelect).append(
      h('option', { value: '' }, 'هیچکدام'),
      ...buildPriceInputOptions().map((o) => h('option', {
        value: o.value, selected: (record.price_input_param || '') === o.value,
      }, o.label))
    );
    priceInputSelect.value = record.price_input_param || '';
  };
  priceInputSelect.addEventListener('focus', paintPriceInputOptions);
  paintPriceInputOptions();

  const mainSelect = h('select', { onChange: (e) => { record.main_price_key = e.target.value; } });
  const renderMain = () => {
    const current = String(record.main_price_key || '').trim();
    if (current && !record.price_fields.some((item) => String(item.key || '').trim() === current)) {
      record.main_price_key = '';
    }
    clear(mainSelect).append(
      h('option', { value: '' }, 'خودکار (اولین قیمت)'),
      ...record.price_fields
        .filter((item) => String(item.key || '').trim())
        .map((item) => h(
          'option',
          { value: item.key.trim(), selected: String(record.main_price_key || '').trim() === item.key.trim() },
          `${item.label || item.key} (${item.key.trim()})`
        ))
    );
    if (![...mainSelect.options].some((option) => option.value === String(record.main_price_key || ''))) {
      mainSelect.value = '';
    }
  };

  const renderRows = () => {
    clear(body).append(
      ...record.price_fields.map((item, index) =>
        h(
          'tr',
          {},
          h('td', { class: 'row-number', text: String(index + 1) }),
          h('td', {}, h('input', { type: 'text', value: item.key || '', placeholder: 'entries.send_price', onInput: (e) => { item.key = e.target.value; renderMain(); } })),
          h('td', {}, h('input', { type: 'text', value: item.label || '', placeholder: 'هزینه ارسال', onInput: (e) => { item.label = e.target.value; renderMain(); } })),
          h('td', {}, h('button', {
            class: 'btn btn-sm btn-remove',
            type: 'button',
            onClick: () => {
              if (record.price_fields.length <= 1) {
                item.key = '';
                item.label = '';
              } else {
                record.price_fields.splice(index, 1);
              }
              renderRows();
              if (typeof onChange === 'function') onChange();
            },
          }, 'حذف'))
        )
      )
    );
    renderMain();
  };

  if (!record.price_fields.length) record.price_fields.push({ key: '', label: '' });
  renderRows();

  return h(
    'div',
    { class: 'card' },
    h(
      'div',
      { class: 'card-head', style: 'margin-bottom:12px' },
      h('h2', {}, 'فیلدهای قیمت'),
      h('button', { class: 'btn btn-sm', type: 'button', onClick: () => { record.price_fields.push({ key: '', label: '' }); renderRows(); renderMain(); if (typeof onChange === 'function') onChange(); } }, 'افزودن فیلد')
    ),
    guideBox([
      ['مسیر فیلد', 'مسیر کامل در پاسخ سرویس (مثل entries.send_price)؛ همان مقدار عددی به‌عنوان قیمت نمایش داده می‌شود.'],
      ['قیمت اصلی', 'به‌عنوان مبلغ کل هر شرکت نمایش داده می‌شود (خودکار یعنی اولین قیمت).'],
      ['واحد ارز', 'وب‌سرویس با کدام واحد مبلغ می‌دهد و کاربر با کدام واحد می‌بیند و وارد می‌کند؛ تبدیل خودکار است (ریال به تومان: تقسیم بر ۱۰ با رند).'],
      ['مبلغ پولی', 'فیلدهایی که در تب «فیلدها» تیک مبلغ پولی دارند با واحد نمایشی از کاربر گرفته و با واحد وب‌سرویس ارسال می‌شوند (مثل ارزش کالا).'],
      ['مبلغ ورودی', 'پارامتر مبلغ ورودی کاربر (مثل products.price) حتی بدون تیک هم تبدیل می‌شود.'],
      ['خطا', 'اگر هیچ مسیر در پاسخ پیدا نشود، کاربر «سرویس در حال حاضر پاسخگو نیست» را می‌بیند.'],
    ]),
    h('div', { class: 'grid' },
      fieldWrapper('واحد مبلغ وب‌سرویس', currencySelect('price_currency_in'), 'واحدی که این شرکت در پاسخ برمی‌گرداند.'),
      fieldWrapper('واحد نمایش به کاربر', currencySelect('price_currency_out'), 'واحدی که کاربر می‌بیند و وارد می‌کند.')),
    fieldWrapper('پارامتر مبلغ ورودی کاربر', priceInputSelect, 'کدام فیلد مبلغ را از کاربر می‌گیرد؛ تبدیل واحد روی همین و فیلدهای تیک‌خورده «مبلغ پولی» اعمال می‌شود.'),
    fieldWrapper('قیمت اصلی', mainSelect),
    h(
      'table',
      {},
      h('thead', {}, h('tr', {}, h('th', { style: 'width:40px' }, '#'), h('th', {}, 'نام فیلد در پاسخ'), h('th', {}, 'عنوان نمایشی'), h('th', { style: 'width:70px' }, 'عملیات'))),
      body
    )
  );
}

function weightUnitEditor(record) {
  if (record.weight_unit_in !== 'kilogram') record.weight_unit_in = 'gram';
  if (record.weight_unit_out !== 'kilogram') record.weight_unit_out = 'gram';
  const unitSelect = (key) => h('select', {
    onChange: (e) => { record[key] = e.target.value === 'kilogram' ? 'kilogram' : 'gram'; },
  },
    h('option', { value: 'gram', selected: record[key] !== 'kilogram' }, 'گرم'),
    h('option', { value: 'kilogram', selected: record[key] === 'kilogram' }, 'کیلوگرم'));

  if (typeof record.weight_input_param !== 'string') record.weight_input_param = record.weight_input_param || null;
  // The user-weight target ("param" or "param.sub") gets the weight
  // conversion together with weight-ticked fields. Options rebuild on focus
  // so params added in the fields tab are listed without reopening.
  const buildWeightInputOptions = () => withMissingTargetOption(
    buildScalarTargetOptions(record), record.weight_input_param || '');
  const weightInputSelect = h('select', {
    onChange: (e) => { record.weight_input_param = e.target.value || null; },
  });
  const paintWeightInputOptions = () => {
    clear(weightInputSelect).append(
      h('option', { value: '' }, 'هیچکدام'),
      ...buildWeightInputOptions().map((o) => h('option', {
        value: o.value, selected: (record.weight_input_param || '') === o.value,
      }, o.label))
    );
    weightInputSelect.value = record.weight_input_param || '';
  };
  weightInputSelect.addEventListener('focus', paintWeightInputOptions);
  paintWeightInputOptions();

  return h(
    'div',
    { class: 'card' },
    h(
      'div',
      { class: 'card-head', style: 'margin-bottom:12px' },
      h('h2', {}, 'واحد وزن')
    ),
    guideBox([
      ['واحد وزن', 'وب‌سرویس با کدام واحد وزن می‌گیرد و کاربر با کدام واحد می‌بیند و وارد می‌کند؛ تبدیل خودکار است (هر کیلوگرم ۱۰۰۰ گرم، بدون گرد کردن).'],
      ['وزن', 'فیلدهایی که در تب «فیلدها» تیک وزن دارند با واحد نمایشی از کاربر گرفته و با واحد وب‌سرویس ارسال می‌شوند (مثل وزن بسته).'],
      ['وزن ورودی', 'پارامتر وزن ورودی کاربر حتی بدون تیک هم تبدیل می‌شود.'],
    ]),
    h('div', { class: 'grid' },
      fieldWrapper('واحد وزن وب‌سرویس', unitSelect('weight_unit_in'), 'واحدی که این شرکت برای وزن انتظار دارد (پیش‌فرض گرم).'),
      fieldWrapper('واحد نمایش به کاربر', unitSelect('weight_unit_out'), 'واحدی که کاربر می‌بیند و وارد می‌کند.')),
    fieldWrapper('پارامتر وزن ورودی کاربر', weightInputSelect, 'کدام فیلد وزن را از کاربر می‌گیرد؛ تبدیل واحد روی همین و فیلدهای تیک‌خورده «وزن» اعمال می‌شود.')
  );
}

const RULE_OPS = [
  ['eq', 'برابر باشد'],
  ['neq', 'نابرابر باشد'],
  ['gt', 'بزرگ‌تر از'],
  ['gte', 'بزرگ‌تر یا مساوی'],
  ['lt', 'کوچک‌تر از'],
  ['lte', 'کوچک‌تر یا مساوی'],
  ['between', 'بین دو عدد'],
  ['in', 'یکی از (با کاما جدا شود)'],
];

function ruleFieldOptions(record) {
  // Scalar params plus visible sub-fields, like the bulk mapping options.
  // Origin and map params can never be rule targets.
  const options = [];
  (record.params || []).forEach((param) => {
    if (isOriginParam(param.name)) return;
    if (param.input === 'map') return;
    if (param.input === 'list' || param.input === 'dict') {
      (param.fields || []).forEach((sub) => {
        if (!sub.visible) return;
        options.push({
          value: `${param.name}.${sub.name}`,
          label: `${param.label} - ${sub.label} (${param.name}.${sub.name})`,
        });
      });
    } else {
      options.push({ value: param.name, label: `${param.label} (${param.name})` });
    }
  });
  return options;
}

function blankRule() {
  return {
    name: '',
    if_field: '', if_op: 'eq', if_value: '', if_value2: '',
    then_field: '', then_op: 'eq', then_value: '', then_value2: '', message: '',
  };
}

function rulesEditor(record, refreshTabCounts) {
  record.rules = Array.isArray(record.rules) ? record.rules : [];
  const host = h('div');
  const fieldSelect = (rule, slot, after) => h(
    'select',
    {
      onChange: (e) => {
        rule[slot] = e.target.value;
        if (typeof after === 'function') after();
      },
    },
    h('option', { value: '' }, 'انتخاب فیلد'),
    ruleFieldOptions(record).map((option) => h(
      'option', { value: option.value, selected: rule[slot] === option.value }, option.label
    ))
  );
  const opSelect = (rule, slot, after) => h(
    'select',
    {
      onChange: (e) => {
        rule[slot] = e.target.value;
        if (typeof after === 'function') after();
      },
    },
    RULE_OPS.map(([value, label]) => h('option', { value, selected: rule[slot] === value }, label))
  );
  const valueInput = (rule, slot, placeholder) => h('input', {
    type: 'text', dir: 'ltr', value: rule[slot] || '', placeholder,
    onInput: (e) => { rule[slot] = e.target.value; },
  });
  const selectParamOf = (target) => {
    const base = String(target || '').split('.')[0];
    if (!base || String(target || '').includes('.')) return null;
    const param = (record.params || []).find((p) => p && p.name === base);
    if (!param || param.input !== 'select' || !Array.isArray(param.options)) return null;
    return param;
  };
  const validOptionsOf = (param) => (param.options || [])
    .filter((option) => option && (option.value ?? '') !== '');
  // Dropdown multiselect for the `in` operator on select params: a face
  // button with a popup checkbox list (plus search), instead of a tall
  // inline box. The rule value stays a comma-joined string in option order.
  const multiSelectDropdown = (options, getSelected, onToggle) => {
    const faceLabel = h('span', { class: 'combo-label', text: 'انتخاب کنید…' });
    const face = h('button', { type: 'button', class: 'combo-face' },
      faceLabel, h('span', { class: 'combo-chevron' }));
    const search = h('input', {
      type: 'text', class: 'combo-search', placeholder: 'جستجو…', autocomplete: 'off',
    });
    const list = h('div', { class: 'combo-list', role: 'listbox' });
    const panel = h('div', { class: 'combo-panel' }, search, list);
    const wrap = h('div', { class: 'search-select combo multi-combo' }, face, panel);
    const paintFace = () => {
      const picked = getSelected();
      if (!picked.length) {
        faceLabel.textContent = 'انتخاب کنید…';
      } else if (picked.length <= 2) {
        faceLabel.textContent = picked
          .map((value) => (options.find((option) => option.value === value) || {}).label || value)
          .join('، ');
      } else {
        faceLabel.textContent = `${toFaDigits(String(picked.length))} مورد انتخاب شده`;
      }
    };
    const paintChecks = () => {
      const picked = getSelected();
      [...list.querySelectorAll('input[type=checkbox]')].forEach((box) => {
        const on = picked.includes(box.value);
        if (box.checked !== on) box.checked = on;
        const row = box.closest('label');
        if (row) row.classList.toggle('picked', on);
      });
      paintFace();
    };
    const buildList = () => {
      clear(list);
      options.forEach((option) => {
        const box = h('input', {
          type: 'checkbox',
          value: option.value,
          checked: getSelected().includes(option.value),
          onChange: (e) => {
            onToggle(option.value, e.target.checked);
            paintChecks();
          },
        });
        const row = h('label', { class: 'combo-check' },
          box, h('span', {}, `${option.label} (${option.value})`));
        if (box.checked) row.classList.add('picked');
        list.append(row);
      });
      filterList();
      paintFace();
    };
    const filterList = () => {
      const query = search.value.trim();
      [...list.children].forEach((row) => {
        row.hidden = !(query === '' || row.textContent.includes(query));
      });
    };
    const close = () => wrap.classList.remove('open');
    const open = () => {
      document.querySelectorAll('.search-select.combo.open').forEach((other) => {
        if (other !== wrap) other.classList.remove('open');
      });
      buildList();
      search.value = '';
      filterList();
      wrap.classList.add('open');
    };
    face.addEventListener('click', (event) => {
      event.stopPropagation();
      if (wrap.classList.contains('open')) close();
      else open();
    });
    face.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
        event.preventDefault();
        open();
      }
    });
    search.addEventListener('input', filterList);
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        face.focus();
      }
    });
    document.addEventListener('click', (event) => {
      if (!wrap.contains(event.target)) close();
    });
    paintFace();
    return wrap;
  };
  const valueControl = (rule, valueSlot, opSlot, targetSlot, placeholder) => {
    const param = selectParamOf(rule[targetSlot]);
    const op = rule[opSlot];
    if (param && op === 'in' && validOptionsOf(param).length) {
      const opts = validOptionsOf(param).map((option) => ({
        value: String(option.value ?? ''),
        label: String(option.label ?? option.value ?? ''),
      }));
      return multiSelectDropdown(
        opts,
        () => String(rule[valueSlot] ?? '').split(',').map((item) => item.trim()).filter(Boolean),
        (value, checked) => {
          const next = new Set(
            String(rule[valueSlot] ?? '').split(',').map((item) => item.trim()).filter(Boolean)
          );
          if (checked) next.add(value);
          else next.delete(value);
          rule[valueSlot] = opts.map((option) => option.value).filter((v) => next.has(v)).join(', ');
        }
      );
    }
    if (param && (op === 'eq' || op === 'neq')) {
      return h(
        'select',
        { onChange: (e) => { rule[valueSlot] = e.target.value; } },
        h('option', { value: '' }, 'انتخاب مقدار'),
        param.options
          .filter((option) => option && (option.value ?? '') !== '')
          .map((option) => h(
            'option',
            {
              value: String(option.value ?? ''),
              selected: String(rule[valueSlot] ?? '') === String(option.value ?? ''),
            },
            `${option.label ?? option.value} (${option.value ?? ''})`
          ))
      );
    }
    return valueInput(rule, valueSlot, placeholder);
  };
    const ruleCard = (rule, index) => {
    let cross = !!rule.then_field;
    let open = !rule.if_field;
    const titleNode = h('h2', {}, (rule.name || '').trim() || `شرط ${index + 1}`);
    const summaryNode = h('span', { class: 'rule-summary' });
    const bodyNode = h('div', { class: 'rule-body', hidden: !open });
    const toggleBtn = h('button', {
      class: 'btn btn-sm btn-ghost', type: 'button', title: 'باز / بسته کردن',
      text: open ? '▾' : '▸',
      onClick: () => {
        open = !open;
        bodyNode.hidden = !open;
        toggleBtn.textContent = open ? '▾' : '▸';
        if (!open) paintSummary();
      },
    });
    // Value cells repaint in place: rebuilding the whole card on every
    // pick destroys the focused control, collapses the page and throws
    // the scroll back to the top.
    const ifValueCell = h('div', { style: 'display:contents' });
    const ifExtraCell = h('div', { style: 'display:contents' });
    const thenValueCell = h('div', { style: 'display:contents' });
    const thenExtraCell = h('div', { style: 'display:contents' });
    const thenSection = h('div');
    const paintIfValues = () => {
      clear(ifValueCell).append(
        fieldWrapper('مقدار', valueControl(rule, 'if_value', 'if_op', 'if_field', 'مقدار'))
      );
      clear(ifExtraCell);
      if (rule.if_op === 'between') {
        ifExtraCell.append(fieldWrapper('مقدار دوم (بازه)', valueInput(rule, 'if_value2', 'مقدار دوم')));
      }
    };
    const paintThenValues = () => {
      clear(thenValueCell).append(
        fieldWrapper('مقدار', valueControl(rule, 'then_value', 'then_op', 'then_field', 'مقدار'))
      );
      clear(thenExtraCell);
      if (rule.then_op === 'between') {
        thenExtraCell.append(fieldWrapper('مقدار دوم (بازه)', valueInput(rule, 'then_value2', 'مقدار دوم')));
      }
    };
    const paintThenSection = () => {
      clear(thenSection);
      if (!cross) return;
      thenSection.append(
        h('h3', {}, 'آنگاه (الزام)'),
        h(
          'div',
          { class: 'grid' },
          fieldWrapper('فیلد', fieldSelect(rule, 'then_field', paintThenValues)),
          fieldWrapper('عملگر', opSelect(rule, 'then_op', paintThenValues)),
          thenValueCell,
          thenExtraCell
        )
      );
      paintThenValues();
    };
    const modeSelect = h(
      'select',
      {
        onChange: (e) => {
          if (e.target.value === 'single') {
            cross = false;
            rule.then_field = '';
            rule.then_op = 'eq';
            rule.then_value = '';
            rule.then_value2 = '';
          } else {
            cross = true;
            if (!rule.then_field) {
              const first = ruleFieldOptions(record);
              rule.then_field = rule.if_field || (first.length ? first[0].value : '');
            }
          }
          paintThenSection();
        },
      },
      h('option', { value: 'single', selected: !cross }, 'شرط ساده (تک‌فیلدی)'),
      h('option', { value: 'cross', selected: cross }, 'شرط ترکیبی (اگر… آنگاه…)')
    );
    const targetLabel = (target) => {
      const at = String(target || '').indexOf('.');
      const base = at === -1 ? String(target || '') : String(target).slice(0, at);
      const sub = at === -1 ? '' : String(target).slice(at + 1);
      const param = (record.params || []).find((p) => p && p.name === base);
      if (!param) return target || '…';
      if (!sub) return param.label || base;
      const field = ((param.fields || []).find((f) => f && f.name === sub));
      return (field && field.label) || sub;
    };
    const clauseText = (field, op, first, second) => {
      const label = targetLabel(field);
      if (!field) return '…';
      if (op === 'eq') return `${label} = ${first || '…'}`;
      if (op === 'neq') return `${label} ≠ ${first || '…'}`;
      if (op === 'gt') return `${label} > ${first || '…'}`;
      if (op === 'gte') return `${label} ≥ ${first || '…'}`;
      if (op === 'lt') return `${label} < ${first || '…'}`;
      if (op === 'lte') return `${label} ≤ ${first || '…'}`;
      if (op === 'between') return `${label} بین ${first || '…'} تا ${second || '…'}`;
      if (op === 'in') {
        const count = String(first || '').split(',').map((item) => item.trim()).filter(Boolean).length;
        return `${label} یکی از ${toFaDigits(String(count))} مورد`;
      }
      return label;
    };
    const paintSummary = () => {
      const main = clauseText(rule.if_field, rule.if_op, rule.if_value, rule.if_value2);
      summaryNode.textContent = rule.then_field
        ? `اگر ${main} آنگاه ${clauseText(rule.then_field, rule.then_op, rule.then_value, rule.then_value2)}`
        : main;
    };
    paintIfValues();
    paintThenSection();
    paintSummary();
    bodyNode.append(
      h(
        'div',
        { class: 'grid' },
        fieldWrapper(
          'نام شرط (فقط نمایشی در تنظیمات)',
          h('input', {
            type: 'text', value: rule.name || '', maxlength: 120, placeholder: 'مثلا سقف وزن',
            onInput: (e) => {
              rule.name = e.target.value;
              titleNode.textContent = (rule.name || '').trim() || `شرط ${index + 1}`;
            },
          })
        ),
        fieldWrapper(
          'نوع شرط',
          modeSelect,
          'ساده: خود همین شرط همیشه باید برقرار باشد. ترکیبی: فقط وقتی «اگر» برقرار بود، «آنگاه» هم باید برقرار باشد.'
        )
      ),
      h('h3', {}, 'اگر (شرط)'),
      h(
        'div',
        { class: 'grid' },
        fieldWrapper('فیلد', fieldSelect(rule, 'if_field', paintIfValues)),
        fieldWrapper('عملگر', opSelect(rule, 'if_op', paintIfValues)),
        ifValueCell,
        ifExtraCell
      ),
      thenSection,
      fieldWrapper(
        'متن خطا',
        h('textarea', {
          rows: 2, value: rule.message || '', maxlength: 500,
          onInput: (e) => { rule.message = e.target.value; },
        }),
        'وقتی شرط برقرار نباشد به کاربر نمایش داده می‌شود؛ خالی بماند پیام پیش‌فرض می‌آید.'
      )
    );
    return h(
      'div',
      { class: 'card param rule-card' },
      h(
        'div',
        { class: 'card-head', style: 'margin-bottom:8px' },
        h('div', { class: 'param-title' }, toggleBtn, titleNode, summaryNode),
        h('button', {
          class: 'btn btn-sm btn-remove', type: 'button',
          onClick: () => { record.rules.splice(index, 1); render(); },
        }, 'حذف شرط')
      ),
      bodyNode
    );
  };const render = () => {
    // Only add/remove rebuild the list; keep the scroll where it was so
    // the page does not jump back to the top.
    const top = (typeof window !== 'undefined' && (window.scrollY || document.documentElement.scrollTop)) || 0;
    clear(host);
    if (!record.rules.length) {
      host.append(h('p', { class: 'empty', text: 'هنوز شرطی تعریف نشده است. با دکمه زیر اولین شرط را بسازید.' }));
    }
    record.rules.forEach((rule, index) => host.append(ruleCard(rule, index)));
    if (typeof refreshTabCounts === 'function') refreshTabCounts();
    try {
      window.scrollTo({ top });
    } catch (error) {
      /* headless test DOM without scrolling */
    }
  };
  render();
  return h(
    'div',
    { class: 'card' },
    h('h2', {}, 'شرط‌های اعتبارسنجی'),
    h('p', { class: 'muted', style: 'margin-top:0' },
      'قبل از ارسال استعلام بررسی می‌شوند: اگر مقادیر واردشده با شرطی نسازند، درخواست به سرویس ارسال نمی‌شود و همین‌جا متن خطای شرط نمایش داده می‌شود. فیلد خالی نادیده گرفته می‌شود.'),
    host,
    h('div', { class: 'row', style: 'margin-top:12px' },
      h('button', {
        class: 'btn btn-ghost', type: 'button',
        onClick: () => { record.rules.push(blankRule()); render(); },
      }, 'افزودن شرط'))
  );
}

function normaliseCustomLocationsDraft(raw) {
  const out = { provinces: [], cities: [] };
  if (!raw || typeof raw !== 'object') return out;
  const seenProvinces = new Set();
  (raw.provinces || []).forEach((item) => {
    if (!item || typeof item !== 'object') return;
    const code = String(item.code ?? '').trim();
    if (!code || seenProvinces.has(code)) return;
    seenProvinces.add(code);
    out.provinces.push({ code, title: String(item.title ?? code).trim() || code });
  });
  const seenCities = new Set();
  (raw.cities || []).forEach((item) => {
    if (!item || typeof item !== 'object') return;
    const code = String(item.code ?? '').trim();
    const province_code = String(item.province_code ?? '').trim();
    if (!code || !province_code || seenCities.has(code)) return;
    seenCities.add(code);
    out.cities.push({ code, title: String(item.title ?? code).trim() || code, province_code });
  });
  return out;
}

function normaliseLocationDraft(raw) {
  const cfg = {
    origin: { enabled: true, display: 'separate', source: 'service', custom_mode: 'merge' },
    destination: { enabled: false, display: 'separate', province_param: null, city_param: null, source: 'service', custom_mode: 'merge' },
    custom_locations: { provinces: [], cities: [] },
    blocked_locations: { provinces: [], cities: [] },
  };
  if (!raw || typeof raw !== 'object') return cfg;
  const origin = raw.origin && typeof raw.origin === 'object' ? raw.origin : {};
  if (typeof origin.enabled === 'boolean') cfg.origin.enabled = origin.enabled;
  if (origin.display === 'combined' || origin.display === 'separate') cfg.origin.display = origin.display;
  if (origin.source === 'custom' || origin.source === 'service') cfg.origin.source = origin.source;
  if (origin.custom_mode === 'only' || origin.custom_mode === 'merge') cfg.origin.custom_mode = origin.custom_mode;
  const dest = raw.destination && typeof raw.destination === 'object' ? raw.destination : {};
  if (typeof dest.enabled === 'boolean') cfg.destination.enabled = dest.enabled;
  if (dest.display === 'combined' || dest.display === 'separate') cfg.destination.display = dest.display;
  cfg.destination.province_param = dest.province_param || null;
  cfg.destination.city_param = dest.city_param || null;
  if (dest.source === 'custom' || dest.source === 'service') cfg.destination.source = dest.source;
  if (dest.custom_mode === 'only' || dest.custom_mode === 'merge') cfg.destination.custom_mode = dest.custom_mode;
  cfg.custom_locations = normaliseCustomLocationsDraft(raw.custom_locations);
  cfg.blocked_locations = normaliseCustomLocationsDraft(raw.blocked_locations);
  return cfg;
}

function locationEditor(record) {
  record.location_config = normaliseLocationDraft(record.location_config);
  const cfg = record.location_config;
  cfg.custom_locations = normaliseCustomLocationsDraft(cfg.custom_locations);
  cfg.blocked_locations = normaliseCustomLocationsDraft(cfg.blocked_locations);
  const root = h('div');

  // Live summary next to the sticky tabs (origin/destination only) plus the
  // manual-list counts right below the tabs; repainted in place so toggling
  // a control never rebuilds (and never scroll-jumps) the page.
  const summaryNode = h('span', { class: 'loc-summary' });
  const countsNode = h('div', { class: 'loc-counts' });
  const paintSummary = () => {
    const o = cfg.origin;
    const d = cfg.destination;
    const oTxt = o.enabled !== false
      ? `مبدا: روشن (${o.display === 'combined' ? 'ترکیبی' : 'جدا'})`
      : 'مبدا: خاموش';
    const dTxt = d.enabled === true
      ? `مقصد: روشن (${d.display === 'combined' ? 'ترکیبی' : 'جدا'})`
      : 'مقصد: خاموش';
    clear(summaryNode).append(
      h('span', { text: oTxt }),
      h('span', { text: '•' }),
      h('span', { text: dTxt })
    );
    clear(countsNode).append(
      h('span', { text: `لیست دستی: ${toFaDigits(String(cfg.custom_locations.provinces.length))} استان، ${toFaDigits(String(cfg.custom_locations.cities.length))} شهر` }),
      h('span', { text: '•' }),
      h('span', { text: `بلاک‌شده: ${toFaDigits(String((cfg.blocked_locations.provinces || []).length))} استان، ${toFaDigits(String((cfg.blocked_locations.cities || []).length))} شهر` })
    );
    const totalBlocked = (cfg.blocked_locations.provinces || []).length + (cfg.blocked_locations.cities || []).length;
    const badge = root.querySelector('.blk-tab-count');
    if (badge) {
      badge.textContent = totalBlocked ? ` ${toFaDigits(String(totalBlocked))}` : '';
      badge.hidden = !totalBlocked;
    }
  };

  const labeledControl = (label, control) => h('div', { class: 'field' }, h('label', {}, label), control);

  // Compact two-option switch. Repaints itself + the summary in place;
  // no full rebuild, so focus and scroll position survive the click.
  const segmented = (options, current, onPick) => {
    const buttons = options.map(([value, label]) => h('button', {
      type: 'button',
      class: value === current ? 'active' : undefined,
      onClick: () => {
        // NOTE: no stale `current` guard here — `current` is fixed at build
        // time, so re-picking must always apply (re-setting the same value
        // is harmless).
        onPick(value);
        buttons.forEach((btn, i) => btn.classList.toggle('active', options[i][0] === value));
        paintSummary();
      },
    }, label));
    return h('div', { class: 'segmented', role: 'group' }, buttons);
  };

  const statusToggle = (label, checked, onChange) => h(
    'div',
    { class: 'field' },
    h('label', {}, 'وضعیت'),
    h('label', { class: 'checkline' },
      h('input', {
        type: 'checkbox', checked,
        onChange: (e) => { onChange(e.target.checked); paintSummary(); },
      }),
      h('span', {}, label))
  );

  const originCard = () => h(
    'div',
    { class: 'card' },
    h('h2', {}, 'شهر و استان مبدا'),
    guideBox([
      ['نوع نمایش', '«در یک فیلد» استان و شهر را به‌صورت «استان — شهر» در یک کنترل نشان می‌دهد.'],
      ['مبدا غیرفعال', 'شناسه شعبه پیش‌فرض را در تنظیمات شرکت وارد کنید تا استعلام زنده قیمت کار کند.'],
    ]),
    h(
      'div',
      { class: 'grid' },
      statusToggle('در استعلام قیمت نمایش داده شود', cfg.origin.enabled !== false, (v) => { cfg.origin.enabled = v; }),
      labeledControl('نوع نمایش', segmented([['separate', 'جدا'], ['combined', 'در یک فیلد']], cfg.origin.display, (v) => { cfg.origin.display = v; }))
    )
  );

  const destCard = () => {
    const fieldOptions = (record.params || [])
      .filter((param) => param && param.name && !isOriginParam(param.name))
      .map((param) => ({ value: param.name, label: `${param.label || param.name} (${param.name})` }));
    const destSelect = (key, label) => fieldWrapper(label, h(
      'select',
      { onChange: (e) => { cfg.destination[key] = e.target.value || null; } },
      h('option', { value: '' }, 'انتخاب کنید'),
      fieldOptions.map((option) => h(
        'option', { value: option.value, selected: cfg.destination[key] === option.value }, option.label
      ))
    ));
    return h(
      'div',
      { class: 'card' },
      h('h2', {}, 'شهر و استان مقصد'),
      guideBox([
        ['نوع نمایش', '«در یک فیلد» استان و شهر را به‌صورت «استان — شهر» در یک کنترل نشان می‌دهد.'],
        ['فیلد استان و شهر', 'از بین فیلدهای تعریف‌شده همین شرکت انتخاب می‌شوند. داده شهر/استان فیلدهای انتخاب‌شده به‌صورت خودکار ست می‌شود؛ فقط نوع ورودی آن‌ها باید لیست انتخابی باشد.'],
      ]),
      h(
        'div',
        { class: 'grid' },
        statusToggle('بخش مقصد در استعلام قیمت نمایش داده شود', cfg.destination.enabled === true, (v) => { cfg.destination.enabled = v; }),
        labeledControl('نوع نمایش', segmented([['separate', 'جدا'], ['combined', 'در یک فیلد']], cfg.destination.display, (v) => { cfg.destination.display = v; }))
      ),
      h(
        'div',
        { class: 'grid', style: 'margin-top:12px' },
        destSelect('province_param', 'فیلد استان'),
        destSelect('city_param', 'فیلد شهر')
      )
    );
  };

  const customCard = () => {
    const custom = cfg.custom_locations;
    const provCount = h('span', { class: 'section-count' });
    const cityCount = h('span', { class: 'section-count' });
    const provTableHost = h('div');
    const cityTableHost = h('div');
    const provListId = `loc-prov-codes-${Date.now()}`;
    const provDatalist = h('datalist', { id: provListId });
    // Enter inside an inline cell commits that cell (blur -> change) instead
    // of implicitly submitting the whole provider form.
    const commitOnEnter = (input) => input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        input.blur();
      }
    });
    // Table cells rebuild on every edit; keep the scroll where it was.
    const paintTables = () => preserveScroll(() => {
      provCount.textContent = `${toFaDigits(String(custom.provinces.length))} استان`;
      cityCount.textContent = `${toFaDigits(String(custom.cities.length))} شهر`;
      paintSummary();
      // NOTE: native append() — the array must be spread, otherwise it
      // stringifies to "[object HTMLOptionElement]".
      clear(provDatalist).append(
        ...custom.provinces.map((item) => h('option', { value: item.code }, item.title))
      );
      clear(provTableHost);
      if (!custom.provinces.length) {
        provTableHost.append(h('p', { class: 'empty', text: 'هنوز استانی ثبت نشده است؛ از فرم زیر یا اکسل اضافه کنید.' }));
      } else {
        provTableHost.append(
          h('div', { class: 'table-scroll', style: 'max-height:300px;overflow:auto' },
            h('table', {},
              h('colgroup', {},
                h('col', { style: 'width:40px' }),
                h('col', { style: 'width:70px' }),
                h('col', { style: 'width:180px' }),
                h('col', { style: 'width:64px' })),
              h('thead', {}, h('tr', {}, h('th', { style: 'width:40px' }, '#'), h('th', {}, 'کد استان'), h('th', {}, 'عنوان استان'), h('th', { style: 'width:64px' }, 'عملیات'))),
              h('tbody', {},
                custom.provinces.map((item, index) => {
                  const codeInput = h('input', { type: 'text', value: item.code, dir: 'ltr', class: 'table-input' });
                  const titleInput = h('input', { type: 'text', value: item.title, class: 'table-input' });
                  commitOnEnter(codeInput);
                  commitOnEnter(titleInput);
                  codeInput.addEventListener('change', () => {
                    const next = codeInput.value.trim();
                    if (!next) {
                      toast('کد استان الزامی است.');
                      codeInput.value = item.code;
                      return;
                    }
                    if (next !== item.code && custom.provinces.some((row, i) => i !== index && row.code === next)) {
                      toast(`کد استان تکراری است: ${next}`);
                      codeInput.value = item.code;
                      return;
                    }
                    if (next !== item.code) {
                      const old = item.code;
                      item.code = next;
                      // Keep cities attached to the renamed province.
                      custom.cities.forEach((city) => { if (city.province_code === old) city.province_code = next; });
                      paintTables();
                    }
                  });
                  titleInput.addEventListener('change', () => {
                    const next = titleInput.value.trim();
                    if (next !== item.title) {
                      item.title = next;
                      paintTables();
                    }
                  });
                  return h(
                    'tr',
                    {},
                    h('td', { class: 'row-number', text: String(index + 1) }),
                    h('td', {}, codeInput),
                    h('td', {}, titleInput),
                    h('td', {}, h('button', {
                      class: 'btn btn-sm btn-remove', type: 'button',
                      onClick: () => { custom.provinces.splice(index, 1); paintTables(); },
                    }, 'حذف'))
                  );
                })))
            ));
      }
      clear(cityTableHost);
      if (!custom.cities.length) {
        cityTableHost.append(h('p', { class: 'empty', text: 'هنوز شهری ثبت نشده است؛ از فرم زیر یا اکسل اضافه کنید.' }));
      } else {
        cityTableHost.append(
          h('div', { class: 'table-scroll', style: 'max-height:320px;overflow:auto' },
            h('table', {},
              h('colgroup', {},
                h('col', { style: 'width:40px' }),
                h('col', { style: 'width:60px' }),
                h('col', {}),
                h('col', { style: 'width:60px' }),
                h('col', { style: 'width:64px' })),
              h('thead', {}, h('tr', {}, h('th', { style: 'width:40px' }, '#'), h('th', {}, 'کد شهر'), h('th', {}, 'عنوان شهر'), h('th', {}, 'استان'), h('th', { style: 'width:64px' }, 'عملیات'))),
              h('tbody', {},
                custom.cities.map((item, index) => {
                  const codeInput = h('input', { type: 'text', value: item.code, dir: 'ltr', class: 'table-input' });
                  const titleInput = h('input', { type: 'text', value: item.title, class: 'table-input' });
                  commitOnEnter(codeInput);
                  commitOnEnter(titleInput);
                  codeInput.addEventListener('change', () => {
                    const next = codeInput.value.trim();
                    if (!next) {
                      toast('کد شهر الزامی است.');
                      codeInput.value = item.code;
                      return;
                    }
                    if (next !== item.code && custom.cities.some((row, i) => i !== index && row.code === next)) {
                      toast(`کد شهر تکراری است: ${next}`);
                      codeInput.value = item.code;
                      return;
                    }
                    if (next !== item.code) {
                      item.code = next;
                      paintTables();
                    }
                  });
                  titleInput.addEventListener('change', () => {
                    const next = titleInput.value.trim();
                    if (next !== item.title) {
                      item.title = next;
                      paintTables();
                    }
                  });
                  // Free entry, not a pick from the manual provinces: any Tapin
                  // province code (or a manual one above) is accepted here and
                  // validated on save. The datalist only suggests manual codes.
                  const provInput = h('input', {
                    type: 'text', value: item.province_code, dir: 'ltr',
                    class: 'table-input', list: provListId,
                  });
                  commitOnEnter(provInput);
                  provInput.addEventListener('change', () => {
                    const next = provInput.value.trim();
                    if (!next) {
                      toast('کد استان شهر الزامی است.');
                      provInput.value = item.province_code;
                      return;
                    }
                    if (next !== item.province_code) {
                      item.province_code = next;
                      paintTables();
                    }
                  });
                  return h(
                    'tr',
                    {},
                    h('td', { class: 'row-number', text: String(index + 1) }),
                    h('td', {}, codeInput),
                    h('td', {}, titleInput),
                    h('td', { class: 'stat-cell' }, provInput),
                    h('td', {}, h('button', {
                      class: 'btn btn-sm btn-remove', type: 'button',
                      onClick: () => { custom.cities.splice(index, 1); paintTables(); },
                    }, 'حذف')));
                }))))
        );
      }
    });

    const provCode = h('input', { type: 'text', placeholder: 'مثل ۱' });
    const provTitle = h('input', { type: 'text', placeholder: 'مثل تهران' });
    const cityCode = h('input', { type: 'text', placeholder: 'مثل ۱۰' });
    const cityTitle = h('input', { type: 'text', placeholder: 'مثل تهران' });
    const cityProv = h('input', { type: 'text', placeholder: 'مثل ۱', list: provListId });
    const excelFile = h('input', { type: 'file', accept: '.xlsx' });
    const excelMsg = h('div');

    // Gap picker: Tapin cities this provider does NOT serve, to fill the
    // manual list with. Loaded lazily; checks live in a Set of "prov:city".
    const GAP_RENDER_LIMIT = 200;
    const gap = { data: null, checked: new Set(), query: '', province: '', loading: false };
    const gapMsg = h('div');
    const gapBody = h('div');
    const gapSearch = h('input', {
      type: 'text', placeholder: 'جستجوی شهر، استان یا کد…', autocomplete: 'off',
      onInput: (e) => { gap.query = e.target.value; paintGaps(); },
    });
    // Search matches names and codes alike; Persian/Arabic digits typed by
    // the admin are normalized so «۱۰» finds code "10" too.
    const gapNormDigits = (text) => String(text ?? '')
      .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
      .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const gapProvSelect = h('select', {
      onChange: (e) => { gap.province = e.target.value; paintGaps(); },
    });
    const gapAddBtn = h('button', { class: 'btn btn-sm', type: 'button', onClick: () => addGaps() }, 'افزودن انتخاب‌شده‌ها');
    const gapControls = h('div', { hidden: true },
      h('div', { class: 'grid', style: 'margin-bottom:8px' },
        fieldWrapper('جستجو', gapSearch),
        fieldWrapper('استان', gapProvSelect)),
      gapBody,
      h('div', { class: 'row', style: 'margin-top:8px' }, gapAddBtn));
    const paintGaps = () => preserveScroll(() => {
      gapControls.hidden = !gap.data;
      clear(gapBody);
      if (!gap.data) return;
      const provinces = gap.data.provinces || [];
      const citiesByParent = gap.data.cities_by_province || {};
      // NOTE: native append() — the array must be spread, otherwise it
      // stringifies to "[object HTMLOptionElement]" and the list stays empty.
      clear(gapProvSelect).append(
        h('option', { value: '' }, 'همه استان‌ها'),
        ...provinces.map((item) => h('option', {
          value: item.value, selected: gap.province === item.value,
        }, item.label))
      );
      const query = gap.query.trim();
      const wantedProvs = gap.province ? [gap.province] : provinces.map((item) => item.value);
      const provLabel = {};
      provinces.forEach((item) => { provLabel[String(item.value)] = item.label; });
      const matches = [];
      const wantedQuery = gapNormDigits(query);
      wantedProvs.forEach((parent) => {
        ((citiesByParent[parent]) || []).forEach((city) => {
          const parentCode = String(parent);
          const cityCode = String(city.value);
          const label = `${city.label} — ${provLabel[parentCode] || parentCode}`;
          if (wantedQuery !== '') {
            const haystack = gapNormDigits(`${city.label} ${provLabel[parentCode] || ''} ${cityCode} ${parentCode}`);
            if (!haystack.includes(wantedQuery)) return;
          }
          matches.push({ parent: parentCode, value: cityCode, label });
        });
      });
      const shown = matches.slice(0, GAP_RENDER_LIMIT);
      const toggleAll = h('button', { class: 'btn btn-sm btn-ghost', type: 'button' }, 'انتخاب همه نتایج');
      toggleAll.addEventListener('click', () => {
        shown.forEach((row) => gap.checked.add(`${row.parent}:${row.value}`));
        paintGaps();
      });
      gapAddBtn.textContent = `افزودن انتخاب‌شده‌ها (${toFaDigits(String(gap.checked.size))})`;
      gapBody.append(
        h('p', { class: 'muted', style: 'margin:0 0 8px' },
          matches.length
            ? `${toFaDigits(String(matches.length))} شهر خارج از سرویس پیدا شد.`
            : 'با این فیلتر شهری پیدا نشد.'),
        ...(matches.length > GAP_RENDER_LIMIT
          ? [h('p', { class: 'hint', text: `فقط ${toFaDigits(String(GAP_RENDER_LIMIT))} نتیجه اول نمایش داده می‌شود؛ جستجو را دقیق‌تر کنید.` })]
          : []),
        h('div', { class: 'row', style: 'margin-bottom:8px' }, toggleAll),
        h('div', { class: 'table-scroll', style: 'max-height:300px;overflow:auto' },
          shown.map((row) => {
            const key = `${row.parent}:${row.value}`;
            const box = h('input', {
              type: 'checkbox',
              checked: gap.checked.has(key),
              onChange: (e) => {
                if (e.target.checked) gap.checked.add(key);
                else gap.checked.delete(key);
                gapAddBtn.textContent = `افزودن انتخاب‌شده‌ها (${toFaDigits(String(gap.checked.size))})`;
              },
            });
            return h('label', { class: 'combo-check' }, box,
              h('span', { style: 'flex:1;min-width:0' }, row.label),
              h('span', { class: 'gap-codes' },
                h('span', { class: 'code', text: `شهر ${row.value}` }),
                h('span', { class: 'code', text: `استان ${row.parent}` })));
          }))
      );
    });
    const loadGaps = async () => {
      clear(gapMsg);
      if (record.id === null || record.id === undefined) {
        toast('اول شرکت را ذخیره کنید تا شناسه داشته باشد.');
        return;
      }
      gap.loading = true;
      paintGaps();
      try {
        gap.data = await api(`/api/admin/providers/${encodeURIComponent(record.id)}/unserved-locations`, auth());
      } catch (error) {
        gap.data = null;
        gapMsg.append(h('p', { class: 'error-text', text: error.message }));
        return;
      } finally {
        gap.loading = false;
      }
      gap.checked.clear();
      gap.query = '';
      gap.province = '';
      gapSearch.value = '';
      if (!(gap.data.cities_by_province && Object.keys(gap.data.cities_by_province).length)) {
        gapMsg.append(h('p', { class: 'hint notice', text: (gap.data && gap.data.message) || 'شهری خارج از سرویس پیدا نشد.' }));
      } else if (gap.data.message) {
        gapMsg.append(h('p', { class: 'hint', text: gap.data.message }));
      }
      paintGaps();
    };
    const addGaps = () => {
      if (!gap.data || !gap.checked.size) {
        toast('اول چند شهر را انتخاب کنید.');
        return;
      }
      const provinces = gap.data.provinces || [];
      const citiesByParent = gap.data.cities_by_province || {};
      const provLabel = {};
      provinces.forEach((item) => { provLabel[String(item.value)] = item.label; });
      let addedProv = 0;
      let addedCity = 0;
      gap.checked.forEach((key) => {
        const pipe = key.indexOf(':');
        const parent = key.slice(0, pipe);
        const value = key.slice(pipe + 1);
        const city = ((citiesByParent[parent]) || []).find((item) => String(item.value) === value);
        if (!city) return;
        if (!custom.provinces.some((row) => row.code === parent)) {
          custom.provinces.push({ code: parent, title: provLabel[parent] || parent });
          addedProv += 1;
        }
        if (!custom.cities.some((row) => row.code === value)) {
          custom.cities.push({ code: value, title: city.label, province_code: parent });
          addedCity += 1;
        }
      });
      gap.checked.clear();
      paintTables();
      paintGaps();
      toast(`${toFaDigits(String(addedProv))} استان و ${toFaDigits(String(addedCity))} شهر به لیست دستی اضافه شد.`, 'ok');
    };

    const addProvince = () => {
      const code = provCode.value.trim();
      if (!code) {
        toast('کد استان را وارد کنید.');
        return;
      }
      if (custom.provinces.some((item) => item.code === code)) {
        toast(`کد استان تکراری است: ${code}`);
        return;
      }
      custom.provinces.push({ code, title: provTitle.value.trim() || code });
      provCode.value = '';
      provTitle.value = '';
      paintTables();
    };
    const addCity = () => {
      const code = cityCode.value.trim();
      const provinceCode = cityProv.value.trim();
      if (!code) {
        toast('کد شهر را وارد کنید.');
        return;
      }
      if (!provinceCode) {
        toast('کد استان شهر را وارد کنید.');
        return;
      }
      if (custom.cities.some((item) => item.code === code)) {
        toast(`کد شهر تکراری است: ${code}`);
        return;
      }
      custom.cities.push({ code, title: cityTitle.value.trim() || code, province_code: provinceCode });
      cityCode.value = '';
      cityTitle.value = '';
      cityProv.value = '';
      paintTables();
    };
    const importExcel = async () => {
      clear(excelMsg);
      if (!excelFile.files || !excelFile.files.length) {
        toast('اول یک فایل اکسل انتخاب کنید.');
        return;
      }
      const payload = new FormData();
      payload.append('file', excelFile.files[0]);
      const headers = {};
      let result;
      try {
        const response = await request('/api/admin/providers/locations/import', { method: 'POST', headers, body: payload });
        result = JSON.parse(await response.text());
        if (!response.ok) throw new Error(result.detail || `خطای ${response.status}`);
      } catch (error) {
        excelMsg.append(h('p', { class: 'error-text', text: error.message }));
        return;
      }
      let addedProv = 0;
      let addedCity = 0;
      (result.provinces || []).forEach((item) => {
        if (!item || !item.code || custom.provinces.some((row) => row.code === String(item.code))) return;
        custom.provinces.push({ code: String(item.code), title: String(item.title || item.code) });
        addedProv += 1;
      });
      (result.cities || []).forEach((item) => {
        if (!item || !item.code || custom.cities.some((row) => row.code === String(item.code))) return;
        custom.cities.push({
          code: String(item.code),
          title: String(item.title || item.code),
          province_code: String(item.province_code || ''),
        });
        addedCity += 1;
      });
      (result.errors || []).forEach((item) => {
        excelMsg.append(h('p', { class: 'error-text', text: `${item.field}: ${item.message}` }));
      });
      excelFile.value = '';
      paintTables();
      toast(`${toFaDigits(String(addedProv))} استان و ${toFaDigits(String(addedCity))} شهر اضافه شد.`, 'ok');
    };
    const downloadSample = async () => {
      try {
        const headers = {};
        const response = await request('/api/admin/providers/locations/sample', { headers });
        if (!response.ok) throw new Error(`خطای ${response.status}`);
        const blob = await response.blob();
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'locations-sample.xlsx';
        document.body.append(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(link.href), 5000);
      } catch (error) {
        toast(error.message);
      }
    };

    paintTables();
    const modeSlot = locTab === 'destination' ? cfg.destination : cfg.origin;
    const modeLabel = `حالت لیست دستی (${locTab === 'destination' ? 'مقصد' : 'مبدا'})`;
    return h(
      'div',
      { class: 'card' },
      h('h2', {}, 'لیست دستی شهر و استان'),
      guideBox([
        ['لیست دستی', 'اگر لیست دستی وارد شود، همین لیست (به‌تنهایی یا ترکیب با سرویس) نمایش داده می‌شود؛ کد انتخاب‌شده در وب‌سرویس ارسال می‌شود.'],
        ['کد', 'همان مقداری است که انتخاب شود در وب‌سرویس ارسال می‌شود.'],
        ['کد استان شهر', 'مشخص می‌کند هر شهر زیر کدام استان نمایش داده شود. می‌تواند کد استان تاپین یا یکی از کدهای دستی بالا باشد؛ موقع ذخیره بررسی می‌شود.'],
        ['مبدا', 'برای پیدا شدن شعبه، کدها باید با کد استان/شهر شعبه‌ها در بخش شعب یکی باشند.'],
      ]),
      labeledControl(modeLabel, segmented([['merge', 'ترکیب با سرویس'], ['only', 'فقط دستی']], modeSlot.custom_mode, (v) => { modeSlot.custom_mode = v; })),
      h('details',
        { class: 'loc-details' },
        h('summary', {}, 'افزودن گروهی با اکسل'),
        h('p', { class: 'hint', text: 'شیت provinces با ستون‌های code و title برای استان‌ها، شیت cities با ستون‌های code و title و province_code برای شهرها. ردیف‌های تکراری و خراب نادیده گرفته و گزارش می‌شوند.' }),
        h('div', { class: 'row' },
          h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onClick: downloadSample }, 'دانلود نمونه اکسل'),
          excelFile,
          h('button', { class: 'btn btn-sm', type: 'button', onClick: importExcel }, 'آپلود و افزودن')),
        excelMsg
      ),
      h('div', { class: 'loc-two-col' },
      h(
        'div',
        { class: 'sub-block' },
        h(
          'div',
          { class: 'sub-head' },
          h('span', { class: 'section-icon' }, icon('pin')),
          h('h4', { text: 'استان‌ها' }),
          provCount
        ),
        h('div', { class: 'loc-add-prov' },
          fieldWrapper('عنوان استان', provTitle),
          fieldWrapper('کد استان', provCode)),
        h('div', { class: 'row', style: 'margin:8px 0 12px' },
          h('button', { class: 'btn btn-sm', type: 'button', onClick: addProvince }, '＋ افزودن استان')),
        provTableHost
      ),
      h(
        'div',
        { class: 'sub-block' },
        h(
          'div',
          { class: 'sub-head' },
          h('span', { class: 'section-icon green' }, icon('pin')),
          h('h4', { text: 'شهرها' }),
          cityCount
        ),
        h('div', { class: 'loc-add-city' },
          fieldWrapper('عنوان شهر', cityTitle),
          fieldWrapper('کد شهر', cityCode),
          fieldWrapper('کد استان', h('div', {}, cityProv, provDatalist))),
        h('div', { class: 'row', style: 'margin:8px 0 12px' },
          h('button', { class: 'btn btn-sm', type: 'button', onClick: addCity }, '＋ افزودن شهر')),
        cityTableHost
      )),
      h('details',
        { class: 'loc-details' },
        h('summary', {}, 'افزودن از لیست سرویس‌دهنده (شهرهای خارج از سرویس)'),
        h('p', { class: 'hint', text: 'شهرهایی که تاپین برای این شرکت سرویس نمی‌دهد؛ انتخاب کنید کدام به لیست دستی اضافه شود. برای شرکت ذخیره‌نشده، اول شرکت را ذخیره کنید.' }),
        h('div', { class: 'row', style: 'margin-bottom:8px' },
          h('button', { class: 'btn btn-sm', type: 'button', onClick: loadGaps }, 'بارگذاری شهرهای خارج از سرویس')),
        gapMsg,
        gapControls
      )
    );
  };

  const blockedCard = () => {
    const blocked = cfg.blocked_locations;
    if (!blocked.provinces) blocked.provinces = [];
    if (!blocked.cities) blocked.cities = [];
    const state = { query: '', province: '', onlyBlocked: false, showAll: false, collapsed: new Set() };
    const MAX_GROUPS = 80;

    // Full candidate list = currently visible (record.locations bindings,
    // already filtered) + currently blocked + manual-list draft rows.
    // Built once per card so unblocking never loses the row until save/reload.
    const fullProvs = new Map();
    const fullCities = new Map();
    const bindings = (record.locations && record.locations.bindings) || {};
    Object.values(bindings).forEach((binding) => {
      if (!binding || typeof binding !== 'object') return;
      if (binding.kind === 'province' && Array.isArray(binding.items)) {
        binding.items.forEach((item) => {
          if (!item || String(item.value ?? '').trim() === '') return;
          const code = String(item.value);
          if (!fullProvs.has(code)) fullProvs.set(code, String(item.label || code));
        });
      }
      if (binding.kind === 'city' && binding.items_by_parent && typeof binding.items_by_parent === 'object') {
        Object.entries(binding.items_by_parent).forEach(([parent, group]) => {
          if (!Array.isArray(group)) return;
          group.forEach((item) => {
            if (!item || String(item.value ?? '').trim() === '') return;
            const key = `${String(parent)}:${String(item.value)}`;
            if (!fullCities.has(key)) {
              fullCities.set(key, {
                parent: String(parent),
                code: String(item.value),
                title: String(item.label || item.value),
              });
            }
          });
        });
      }
    });
    const absorbRow = (code, title, parent) => {
      if (code && !fullProvs.has(code)) fullProvs.set(code, title || code);
      if (parent && !fullProvs.has(parent)) fullProvs.set(parent, parent);
    };
    blocked.provinces.forEach((item) => {
      if (!item || !String(item.code ?? '').trim()) return;
      absorbRow(String(item.code), String(item.title || item.code));
    });
    blocked.cities.forEach((item) => {
      if (!item || !String(item.code ?? '').trim()) return;
      const parent = String(item.province_code || '');
      const key = `${parent}:${String(item.code)}`;
      if (!fullCities.has(key)) {
        fullCities.set(key, { parent, code: String(item.code), title: String(item.title || item.code) });
      }
      if (parent && !fullProvs.has(parent)) fullProvs.set(parent, parent);
    });
    (cfg.custom_locations.provinces || []).forEach((item) => {
      if (!item || !String(item.code ?? '').trim()) return;
      absorbRow(String(item.code), String(item.title || item.code));
    });
    (cfg.custom_locations.cities || []).forEach((item) => {
      if (!item || !String(item.code ?? '').trim()) return;
      const parent = String(item.province_code || '');
      const key = `${parent}:${String(item.code)}`;
      if (!fullCities.has(key)) {
        fullCities.set(key, { parent, code: String(item.code), title: String(item.title || item.code) });
      }
      if (parent && !fullProvs.has(parent)) fullProvs.set(parent, parent);
    });

    const citiesByProv = new Map();
    fullCities.forEach((city) => {
      if (!citiesByProv.has(city.parent)) citiesByProv.set(city.parent, []);
      citiesByProv.get(city.parent).push(city);
    });
    citiesByProv.forEach((list) => list.sort((a, b) => String(a.title).localeCompare(String(b.title), 'fa')));
    const provList = [...fullProvs.entries()]
      .map(([value, label]) => ({ value, label }))
      .sort((a, b) => a.label.localeCompare(b.label, 'fa'));

    const normDigits = (text) => String(text ?? '')
      .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
      .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));

    const blockedProvSet = () => new Set(blocked.provinces.map((item) => String(item.code)));
    const blockedCitySet = () => new Set(blocked.cities.map((item) => String(item.code)));

    const hiddenCityCount = () => {
      const pSet = blockedProvSet();
      const cSet = blockedCitySet();
      let n = 0;
      fullCities.forEach((city) => {
        if (pSet.has(city.parent) || cSet.has(city.code)) n += 1;
      });
      return n;
    };

    const toggleProvince = (code, title, checked) => {
      const idx = blocked.provinces.findIndex((row) => String(row.code) === String(code));
      if (checked && idx === -1) blocked.provinces.push({ code: String(code), title: title || String(code) });
      if (!checked && idx !== -1) blocked.provinces.splice(idx, 1);
      refresh();
    };
    const toggleCity = (parent, code, title, checked) => {
      const idx = blocked.cities.findIndex((row) => String(row.code) === String(code));
      if (checked && idx === -1) {
        blocked.cities.push({ code: String(code), title: title || String(code), province_code: String(parent) });
      }
      if (!checked && idx !== -1) blocked.cities.splice(idx, 1);
      refresh();
    };
    const setGroupCities = (parent, checked) => {
      const list = citiesByProv.get(parent) || [];
      if (checked) {
        list.forEach((city) => {
          if (!blocked.cities.some((row) => String(row.code) === city.code)) {
            blocked.cities.push({ code: city.code, title: city.title, province_code: city.parent });
          }
        });
      } else {
        for (let i = blocked.cities.length - 1; i >= 0; i -= 1) {
          const row = blocked.cities[i];
          if (list.some((city) => city.code === String(row.code))) blocked.cities.splice(i, 1);
        }
      }
      refresh();
    };

    // ---- static shells (built once, so search focus never jumps) ----
    const statsHost = h('div', { class: 'blk-stats' });
    const groupsHost = h('div', { class: 'blk-groups' });
    const searchInput = h('input', {
      type: 'text', placeholder: 'جستجوی شهر، استان یا کد…', autocomplete: 'off',
      onInput: (e) => { state.query = e.target.value; state.showAll = false; paint(); },
    });
    const clearSearchBtn = h('button', {
      class: 'btn btn-sm btn-ghost', type: 'button', title: 'پاک‌کردن جستجو',
      onClick: () => { searchInput.value = ''; state.query = ''; paint(); searchInput.focus(); },
    }, '✕');
    const provSelect = h('select', {
      onChange: (e) => { state.province = e.target.value; state.showAll = false; paint(); },
    });
    clear(provSelect).append(
      h('option', { value: '' }, 'همه استان‌ها'),
      ...provList.map((item) => h('option', { value: item.value }, `${item.label} (${item.value})`))
    );
    const onlyBlockedBox = h('input', {
      type: 'checkbox',
      onChange: (e) => { state.onlyBlocked = e.target.checked; state.showAll = false; paint(); },
    });
    const expandBtn = h('button', { class: 'btn btn-sm btn-ghost', type: 'button' }, 'بازکردن همه');
    expandBtn.addEventListener('click', () => {
      state.collapsed.clear();
      state.showAll = true;
      paint();
    });
    const collapseBtn = h('button', { class: 'btn btn-sm btn-ghost', type: 'button' }, 'بستن همه');
    collapseBtn.addEventListener('click', () => {
      provList.forEach((item) => state.collapsed.add(item.value));
      paint();
    });
    const clearFilterBtn = h('button', { class: 'btn btn-sm btn-ghost', type: 'button' }, 'پاک‌کردن فیلتر');
    clearFilterBtn.addEventListener('click', () => {
      searchInput.value = '';
      state.query = '';
      state.province = '';
      provSelect.value = '';
      state.onlyBlocked = false;
      onlyBlockedBox.checked = false;
      state.showAll = false;
      paint();
    });
    const unblockAllBtn = h('button', { class: 'btn btn-sm btn-remove', type: 'button' }, 'حذف همه بلاک‌ها');
    unblockAllBtn.addEventListener('click', () => {
      if (!blocked.provinces.length && !blocked.cities.length) {
        toast('چیزی بلاک نشده است.', 'ok');
        return;
      }
      if (!window.confirm('همه بلاک‌های این شرکت حذف شود؟')) return;
      blocked.provinces.splice(0, blocked.provinces.length);
      blocked.cities.splice(0, blocked.cities.length);
      refresh();
    });

    const headCount = h('span', { class: 'section-count blk-head-count' });
    const paintStats = () => {
      const pCount = blocked.provinces.length;
      const cCount = blocked.cities.length;
      const hidden = hiddenCityCount();
      const total = fullCities.size;
      const visible = Math.max(0, total - hidden);
      headCount.textContent = `${toFaDigits(String(pCount))} استان • ${toFaDigits(String(cCount))} شهر`;
      clear(statsHost).append(
        h('span', { class: 'stat-mini err' }, 'استان بلاک ', h('b', { text: toFaDigits(String(pCount)) })),
        h('span', { class: 'stat-mini err' }, 'شهر بلاک ', h('b', { text: toFaDigits(String(cCount)) })),
        h('span', { class: 'stat-mini gold' }, 'شهر مخفی‌شده ', h('b', { text: toFaDigits(String(hidden)) })),
        h('span', { class: 'stat-mini ok' }, 'شهر نمایشی ', h('b', { text: toFaDigits(String(visible)) })),
        h('span', { style: 'flex:1' }),
        unblockAllBtn
      );
    };

    // Every keystroke/toggle rebuilds the group list; never move the scroll.
    const paint = () => preserveScroll(() => {
      paintStats();
      paintSummary();
      const pSet = blockedProvSet();
      const cSet = blockedCitySet();
      const q = normDigits(state.query.trim());
      const hasFilter = q !== '' || state.province !== '' || state.onlyBlocked;

      // Auto-collapse untouched provinces on first paint so the list opens tidy.
      if (!paint._seeded) {
        paint._seeded = true;
        provList.forEach((item) => {
          const group = citiesByProv.get(item.value) || [];
          const touched = pSet.has(item.value) || group.some((city) => cSet.has(city.code));
          if (!touched) state.collapsed.add(item.value);
        });
      }

      let groups = provList.filter((prov) => {
        if (state.province && prov.value !== state.province) return false;
        const group = citiesByProv.get(prov.value) || [];
        const provHit = q === '' || normDigits(`${prov.label} ${prov.value}`).includes(q);
        let cities = group;
        if (q !== '') {
          cities = group.filter((city) => normDigits(`${city.title} ${city.code}`).includes(q));
          if (!provHit && !cities.length) return false;
        }
        if (state.onlyBlocked) {
          const provBlocked = pSet.has(prov.value);
          const anyBlocked = group.some((city) => cSet.has(city.code));
          if (!provBlocked && !anyBlocked) return false;
        }
        return true;
      });
      // Blocked-first ordering, alphabetical inside.
      const rank = (prov) => {
        if (pSet.has(prov.value)) return 0;
        const group = citiesByProv.get(prov.value) || [];
        if (group.some((city) => cSet.has(city.code))) return 1;
        return 2;
      };
      groups = [...groups].sort((a, b) => (rank(a) - rank(b)) || a.label.localeCompare(b.label, 'fa'));

      const totalGroups = groups.length;
      const shownGroups = state.showAll ? groups : groups.slice(0, MAX_GROUPS);
      clear(groupsHost);

      const matchCityTotal = (() => {
        let n = 0;
        groups.forEach((prov) => {
          const group = citiesByProv.get(prov.value) || [];
          if (q === '') n += group.length;
          else n += group.filter((city) => normDigits(`${city.title} ${city.code} ${prov.label} ${prov.value}`).includes(q)).length;
        });
        return n;
      })();
      groupsHost.append(
        h('p', { class: 'blk-result-line' },
          totalGroups
            ? `${toFaDigits(String(totalGroups))} استان • ${toFaDigits(String(matchCityTotal))} شهر`
            : 'نتیجه‌ای با این فیلتر پیدا نشد. ',
          (!totalGroups && hasFilter) ? h('button', {
            class: 'btn btn-sm btn-ghost', type: 'button',
            onClick: () => clearFilterBtn.click(),
          }, 'پاک‌کردن فیلتر') : null)
      );
      if (!provList.length) {
        groupsHost.append(h('div', { class: 'blk-empty' },
          h('div', { class: 'empty-illust', text: '📍' }),
          h('p', { text: 'هنوز لیست شهر/استانی برای این شرکت بارگذاری نشده است.' }),
          h('p', { class: 'muted', text: 'برای شرکت تازه، اول ذخیره کنید تا لیست سرویس تاپین خوانده شود؛ موارد بلاک‌شده قبلی همچنان قابل ویرایش‌اند.' })));
      }

      shownGroups.forEach((prov) => {
        const group = citiesByProv.get(prov.value) || [];
        let cities = group;
        if (q !== '') {
          const provHit = normDigits(`${prov.label} ${prov.value}`).includes(q);
          const hits = group.filter((city) => normDigits(`${city.title} ${city.code}`).includes(q));
          cities = provHit && !hits.length ? group : hits;
        }
        if (state.onlyBlocked) {
          if (!pSet.has(prov.value)) cities = cities.filter((city) => cSet.has(city.code));
          if (!pSet.has(prov.value) && !cities.length) return;
        }
        const provBlocked = pSet.has(prov.value);
        const blockedInGroup = cities.filter((city) => cSet.has(city.code)).length;
        const allBlocked = provBlocked || (cities.length > 0 && cities.every((city) => cSet.has(city.code)));
        const collapsed = !hasFilter && state.collapsed.has(prov.value);

        const box = h('input', {
          type: 'checkbox', checked: provBlocked,
          title: 'بلاک کل استان',
          onChange: (e) => toggleProvince(prov.value, prov.label, e.target.checked),
        });
        if (!provBlocked && blockedInGroup > 0 && blockedInGroup < cities.length) box.indeterminate = true;
        const chev = h('button', {
          class: 'blk-chev' + (collapsed ? '' : ' open'), type: 'button',
          title: collapsed ? 'بازکردن' : 'بستن',
          onClick: () => {
            if (collapsed) state.collapsed.delete(prov.value);
            else state.collapsed.add(prov.value);
            paint();
          },
        }, '▾');
        const groupBtn = h('button', { class: 'btn btn-sm btn-ghost', type: 'button' },
          allBlocked ? 'رفع بلاک گروه' : 'بلاک همه گروه');
        groupBtn.addEventListener('click', () => {
          if (provBlocked) {
            toggleProvince(prov.value, prov.label, false);
            return;
          }
          setGroupCities(prov.value, !allBlocked);
        });

        const head = h('div', { class: 'blk-group-head' + (provBlocked ? ' is-blocked' : '') },
          box,
          h('span', { class: 'blk-prov-name' }, prov.label),
          h('span', { class: 'code', text: `استان ${prov.value}` }),
          h('span', { class: 'blk-count' },
            provBlocked
              ? `کل استان بلاک است (${toFaDigits(String(group.length))} شهر)`
              : blockedInGroup
                ? `${toFaDigits(String(blockedInGroup))} از ${toFaDigits(String(group.length))} شهر بلاک`
                : `${toFaDigits(String(group.length))} شهر`),
          h('span', { style: 'flex:1' }),
          groupBtn,
          chev);

        const body = h('div', { class: 'blk-city-grid', hidden: collapsed || undefined });
        if (!collapsed) {
          if (!cities.length) {
            body.append(h('p', { class: 'empty', text: 'شهری در این استان نیست.' }));
          } else {
            cities.forEach((city) => {
              const implied = provBlocked;
              const checked = implied || cSet.has(city.code);
              const input = h('input', {
                type: 'checkbox', checked, disabled: implied || undefined,
                onChange: (e) => toggleCity(city.parent, city.code, city.title, e.target.checked),
              });
              body.append(
                h('label', { class: 'blk-city' + (checked ? ' is-blocked' : '') },
                  input,
                  h('span', { class: 'blk-city-name' }, city.title),
                  h('span', { class: 'code', text: city.code }),
                  implied ? h('span', { class: 'blk-via', text: 'از استان' }) : null)
              );
            });
          }
        }
        groupsHost.append(h('div', { class: 'blk-group' + (provBlocked ? ' is-blocked' : '') }, head, body));
      });

      if (totalGroups > shownGroups.length) {
        groupsHost.append(
          h('div', { class: 'blk-more' },
            h('p', { class: 'hint', text: `فقط ${toFaDigits(String(shownGroups.length))} استان از ${toFaDigits(String(totalGroups))} استان نمایش داده شد؛ جستجو یا فیلتر را دقیق‌تر کنید.` }),
            h('button', {
              class: 'btn btn-sm', type: 'button',
              onClick: () => { state.showAll = true; paint(); },
            }, `نمایش همه ${toFaDigits(String(totalGroups))} استان`))
        );
      }
      clearSearchBtn.hidden = state.query === '';
    });

    const refresh = () => {
      paintStats();
      paintSummary();
      paint();
    };

    paintStats();
    const card = h(
      'div',
      { class: 'card blk-card' },
      h('div', { class: 'blk-head' },
        h('div', {},
          h('h2', {}, 'شهرهای بلاک‌شده'),
          h('p', { class: 'muted', style: 'margin:4px 0 0' },
            'استان یا شهری که بلاک شود در فرم استعلام همین شرکت نمایش داده نمی‌شود و در استعلام گروهی هم این شرکت برای آن مقصد قیمت نمی‌دهد.')),
        headCount),
      guideBox([
        ['بلاک استان', 'یعنی همه شهرهایش مخفی می‌شود؛ لازم نیست تک‌تک شهرها را تیک بزنید.'],
        ['تیک نیمه‌پر', 'یعنی بعضی شهرهای آن استان بلاک است.'],
        ['یادآوری', 'بعد از تغییر، دکمه «ذخیره» پایین صفحه را بزنید.'],
      ]),
      (record.locations && record.locations.bindings && Object.keys(record.locations.bindings).length)
        ? h('p', { class: 'hint', text: 'لیست زیر از سرویس تاپین همین شرکت + لیست دستی + موارد بلاک‌شده فعلی ساخته شده است.' })
        : h('p', { class: 'hint notice', text: 'لیست سرویس برای این شرکت بارگذاری نشده است (شرکت تازه است یا سرویس در دسترس نیست)؛ فقط موارد بلاک‌شده قبلی و لیست دستی نمایش داده می‌شود. برای دیدن همه شهرها اول شرکت را ذخیره کنید.' }),
      statsHost,
      h('div', { class: 'blk-toolbar' },
        h('div', { class: 'blk-search' }, searchInput, clearSearchBtn),
        fieldWrapper('استان', provSelect),
        h('label', { class: 'checkline blk-only' }, onlyBlockedBox, h('span', {}, 'فقط بلاک‌شده‌ها')),
        h('div', { class: 'row' }, expandBtn, collapseBtn, clearFilterBtn)),
      groupsHost
    );
    paint();
    return card;
  };


  let locTab = 'origin';
  const locTabs = () => h(
    'div',
    {},
    h(
      'div',
      { class: 'tabs subtabs loc-sticky', role: 'tablist' },
      h('button', {
        class: locTab === 'origin' ? 'btn btn-sm' : 'btn btn-sm btn-ghost',
        type: 'button',
        onClick: () => { locTab = 'origin'; paint(); },
      }, 'مبدا'),
      h('button', {
        class: locTab === 'destination' ? 'btn btn-sm' : 'btn btn-sm btn-ghost',
        type: 'button',
        onClick: () => { locTab = 'destination'; paint(); },
      }, 'مقصد'),
      h('button', {
        class: locTab === 'blocked' ? 'btn btn-sm' : 'btn btn-sm btn-ghost',
        type: 'button',
        onClick: () => { locTab = 'blocked'; paint(); },
      }, 'بلاک‌شده‌ها', h('span', {
        class: 'blk-tab-count',
        hidden: !(((cfg.blocked_locations.provinces || []).length + (cfg.blocked_locations.cities || []).length)),
        text: (((cfg.blocked_locations.provinces || []).length + (cfg.blocked_locations.cities || []).length))
          ? ` ${toFaDigits(String((cfg.blocked_locations.provinces || []).length + (cfg.blocked_locations.cities || []).length))}` : '',
      })),
      summaryNode
    ),
    countsNode
  );

  // Tab switches rebuild the whole location section; stay where the user was.
  function paint() {
    preserveScroll(() => {
      clear(root);
      root.append(locTabs());
      if (locTab === 'blocked') {
        root.append(blockedCard());
      } else {
        root.append(locTab === 'origin' ? originCard() : destCard());
        root.append(customCard());
      }
      paintSummary();
    });
  }
  paint();
  return root;
}

function referralEditor(record) {
  record.referral = record.referral && typeof record.referral === 'object' ? record.referral : {};
  if (typeof record.referral.enabled !== 'boolean') record.referral.enabled = !!record.referral.enabled;
  for (const key of ['title', 'url', 'description']) {
    if (typeof record.referral[key] !== 'string') record.referral[key] = '';
  }
  return h(
    'div',
    { class: 'card' },
    h('h2', {}, 'لینک ارجاع'),
    h('p', { class: 'muted', style: 'margin-top:0' },
      'وقتی فعال باشد، پایین کارت قیمت همین شرکت در استعلام تکی و گروهی یک متن و دکمه نمایش داده می‌شود که با زدن آن، کاربر به همین لینک می‌رود.'),
    h(
      'div',
      { class: 'grid' },
      h(
        'div',
        { class: 'field' },
        h('label', {}, 'وضعیت'),
        h('label', { class: 'checkline' },
          h('input', {
            type: 'checkbox', checked: !!record.referral.enabled,
            onChange: (e) => { record.referral.enabled = e.target.checked; },
          }),
          h('span', {}, 'نمایش لینک ارجاع در صفحه استعلام'))
      ),
      fieldWrapper('عنوان دکمه', h('input', {
        type: 'text', value: record.referral.title || '', maxlength: 120,
        onInput: (e) => { record.referral.title = e.target.value; },
      }), 'متن روی دکمه؛ مثل «ثبت سفارش».'),
      fieldWrapper('لینک ارجاع', h('input', {
        type: 'text', dir: 'ltr', value: record.referral.url || '', maxlength: 500,
        placeholder: 'https://…', onInput: (e) => { record.referral.url = e.target.value; },
      }), 'نشانی کامل http یا https که با زدن دکمه باز می‌شود.'),
      fieldWrapper('توضیحات', h('textarea', {
        rows: 2, value: record.referral.description || '', maxlength: 500,
        onInput: (e) => { record.referral.description = e.target.value; },
      }), 'متن نمایشی بالای دکمه؛ خالی بماند نمایش داده نمی‌شود.')
    )
  );
}

function paramCard(param, onRemove, onVisibleToggle, suggestName, startOpen = false) {
  const onlySelect = h('div', { class: 'only-select' });
  const onlyList = h('div', { class: 'only-list' });
  const onlyMap = h('div', { class: 'only-map' });
  let refreshHead = () => {};
  const nameInput = h('input', { type: 'text', value: param.name || '', onInput: (e) => { param.name = e.target.value; refreshHead(); } });
  const onlyName = h('div', { class: 'only-name' },
    fieldWrapper('نام ارسالی در وب‌سرویس', nameInput));

  const defaultWrap = h('div');
  const paintDefault = () => {
    clear(defaultWrap);
    if (param.input === 'select') {
      const opts = Array.isArray(param.options) ? param.options.filter((o) => o && String(o.value ?? '').trim() !== '') : [];
      // Default is optional: a stale value (e.g. its option was removed, or
      // stored before defaults were validated) is healed back to "no
      // default" instead of failing the save.
      const allowed = new Set(opts.map((o) => String(o.value)));
      if (param.default === '' || (param.default !== null && param.default !== undefined && !allowed.has(String(param.default)))) {
        param.default = null;
      }
      const select = h(
        'select',
        { onChange: (e) => { param.default = e.target.value || null; } },
        h('option', { value: '', selected: !param.default }, 'بدون پیش‌فرض'),
        opts.map((option) => h(
          'option',
          { value: String(option.value), selected: String(param.default ?? '') === String(option.value) },
          `${option.label || option.value} (${option.value})`
        ))
      );
      defaultWrap.append(fieldWrapper(
        'مقدار پیش‌فرض (از گزینه‌ها)',
        select,
        'پیش‌فرض باید یکی از گزینه‌های بالا باشد؛ با تغییر گزینه‌ها این لیست به‌روز می‌شود.'
      ));
    } else if (param.input === 'list' || param.input === 'dict') {
      defaultWrap.append(fieldWrapper('مقدار پیش‌فرض', h('input', { type: 'text', value: '', disabled: true, placeholder: 'برای لیست/شیء پیش‌فرض ندارد' }), 'مقادیر پیش‌فرض هر سطر جداگانه تنظیم می‌شود.'));
    } else {
      defaultWrap.append(fieldWrapper('مقدار پیش‌فرض', h('input', { type: 'text', value: param.default ?? '', onInput: (e) => { param.default = e.target.value; } })));
    }
  };
  const renderKindBlocks = () => {
    if (param.input === 'select') {
      clear(onlySelect);
      if (param.kind === 'city' || param.kind === 'province') {
        onlySelect.append(
          h(
            'p',
            { class: 'muted', style: 'margin-top:0' },
            'این فیلد در فرم عمومی از لیست کش‌شده همان شرکت پر می‌شود. گزینه‌های زیر فقط برای مواقعی است که لیست شهر/استان در دسترس نباشد.'
          )
        );
      }
      onlySelect.append(optionsEditor(param, paintDefault));
      onlySelect.hidden = false;
    } else {
      onlySelect.hidden = true;
    }
    if (param.input === 'list') {
      clear(onlyList).append(listEditor(param));
      onlyList.hidden = false;
    } else if (param.input === 'dict') {
      clear(onlyList).append(listEditor(param, false));
      onlyList.hidden = false;
    } else {
      onlyList.hidden = true;
    }
    if (param.input === 'map') {
      clear(onlyMap).append(mapEditor(param));
      onlyMap.hidden = false;
    } else {
      onlyMap.hidden = true;
    }
    onlyName.hidden = param.input === 'map';
  };

  const titleNode = h('h2', {});
  const paintTitle = () => {
    const label = String(param.label || '').trim();
    const name = String(param.name || '').trim();
    clear(titleNode);
    if (label) {
      titleNode.append(label);
      if (name) titleNode.append(h('span', { class: 'param-code', text: ` (${name})` }));
    } else if (name) {
      titleNode.append(h('span', { class: 'param-code', text: name }));
    } else {
      titleNode.append('پارامتر جدید');
    }
  };
  const typePill = h('span', { class: 'pill pill-info', text: INPUT_LABELS[param.input] || param.input });
  const visPill = h('span', { class: param.visible ? 'pill pill-ok' : 'pill pill-dim', text: param.visible ? 'نمایشی' : 'مخفی' });
  const visBtn = h('button', { class: 'btn btn-sm btn-ghost', type: 'button', title: 'نمایش/مخفی در فرم استعلام' });
  const paintVisBtn = () => {
    clear(visBtn);
    visBtn.append(icon(param.visible ? 'eye' : 'eyeOff'), h('span', { text: param.visible ? 'نمایشی' : 'مخفی' }));
  };
  visBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    param.visible = !param.visible;
    paintVisBtn();
    refreshHead();
    if (typeof onVisibleToggle === 'function') onVisibleToggle();
  });
  paintVisBtn();
  // Money flag: a monetary value is taken from the user in the provider's
  // display unit and sent in the service unit. Only plain text/number
  // inputs qualify; destination city/province kinds win over it on save.
  const moneyBox = h('input', {
    type: 'checkbox', checked: param.kind === 'money',
    disabled: param.input !== 'text' && param.input !== 'number',
    title: 'مبلغ پولی',
    onChange: (e) => { param.kind = e.target.checked ? 'money' : null; if (e.target.checked) weightBox.checked = false; },
  });
  const paintMoney = () => {
    if ((param.input !== 'text' && param.input !== 'number') && param.kind === 'money') param.kind = null;
    moneyBox.checked = param.kind === 'money';
    moneyBox.disabled = param.input !== 'text' && param.input !== 'number';
  };
  // Weight flag: same behavior as money but with gram/kilogram. A field is
  // either money or weight, never both.
  const weightBox = h('input', {
    type: 'checkbox', checked: param.kind === 'weight',
    disabled: param.input !== 'text' && param.input !== 'number',
    title: 'وزن',
    onChange: (e) => { param.kind = e.target.checked ? 'weight' : null; if (e.target.checked) moneyBox.checked = false; },
  });
  const paintWeight = () => {
    if ((param.input !== 'text' && param.input !== 'number') && param.kind === 'weight') param.kind = null;
    weightBox.checked = param.kind === 'weight';
    weightBox.disabled = param.input !== 'text' && param.input !== 'number';
  };
  const gridNode = h(
    'div',
    { class: 'grid' },
      fieldWrapper('عنوان فارسی', h('input', { type: 'text', value: param.label || '', onInput: (e) => { param.label = e.target.value; refreshHead(); } })),
      fieldWrapper(
        'نوع ورودی',
        h('select', { onChange: (e) => {
          param.input = e.target.value;
          if (e.target.value === 'map' && !String(param.name || '').trim() && typeof suggestName === 'function') {
            param.name = suggestName();
            nameInput.value = param.name;
          }
          if (e.target.value === 'select' && !Array.isArray(param.options)) param.options = [];
          if (e.target.value === 'list' || e.target.value === 'dict') param.default = null;
          paintMoney();
          paintWeight();
          renderKindBlocks();
          paintDefault();
          refreshHead();
        } },
          INPUT_KINDS.map((kind) => h('option', { value: kind, selected: param.input === kind }, INPUT_LABELS[kind])))
      ),
      fieldWrapper(
        'نوع مقدار',
        h('select', { onChange: (e) => { param.type = e.target.value; } },
          VALUE_TYPES.map((type) => h('option', { value: type, selected: param.type === type }, type)))
      ),
      h(
        'div',
        { class: 'field' },
        h('label', {}, 'اجباری بودن'),
        h('label', { class: 'checkline' },
          h('input', {
            type: 'checkbox', checked: !!param.required,
            onChange: (e) => { param.required = e.target.checked; },
          }),
          h('span', {}, 'تکمیل این فیلد در پنل کاربر اجباری باشد')),
        h('p', { class: 'hint', text: 'فقط برای ورودی متنی/عددی/لیست انتخابی اعمال می‌شود؛ فیلد اجباری با ستاره قرمز مشخص و خالی ارسال نمی‌شود.' })
      ),
      h(
        'div',
        { class: 'field' },
        h('label', {}, 'مبلغ پولی'),
        h('label', { class: 'checkline' },
          moneyBox,
          h('span', {}, 'مقدار این فیلد مبلغ است')),
        h('p', { class: 'hint', text: 'فقط ورودی متنی/عددی؛ با واحد نمایشی گرفته و با واحد وب‌سرویس ارسال می‌شود.' })
      ),
      h(
        'div',
        { class: 'field' },
        h('label', {}, 'وزن'),
        h('label', { class: 'checkline' },
          weightBox,
          h('span', {}, 'مقدار این فیلد وزن است')),
        h('p', { class: 'hint', text: 'فقط ورودی متنی/عددی؛ با واحد نمایشی (گرم/کیلوگرم) گرفته و با واحد وب‌سرویس ارسال می‌شود.' })
      ),
      defaultWrap,
      onlyName,
      fieldWrapper('توضیح نمایشی', h('textarea', { rows: 2, value: param.description || '', onInput: (e) => { param.description = e.target.value; } }))
  );
  const bodyWrap = h('div', { class: 'param-body' }, gridNode, onlySelect, onlyList, onlyMap);
  const chev = h('span', { class: 'chev', text: '▾' });
  const setOpen = (open) => {
    bodyWrap.hidden = !open;
    chev.classList.toggle('open', open);
  };
  const head = h(
    'div',
    {
      class: 'card-head param-head',
      style: 'margin-bottom:0',
      onClick: (e) => { if (e.target.closest('button')) return; setOpen(bodyWrap.hidden); },
    },
    h('div', { class: 'param-title' }, titleNode, typePill, visPill),
    chev,
    h('div', { class: 'row' },
      visBtn,
      h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: onRemove }, 'حذف پارامتر'))
  );
  const card = h('div', { class: 'card param' }, head, bodyWrap);

  refreshHead = () => {
    paintTitle();
    typePill.textContent = INPUT_LABELS[param.input] || param.input;
    visPill.textContent = param.visible ? 'نمایشی' : 'مخفی';
    visPill.className = param.visible ? 'pill pill-ok' : 'pill pill-dim';
    card.classList.toggle('param-hidden', !param.visible);
  };

  renderKindBlocks();
  paintDefault();
  refreshHead();
  setOpen(!!startOpen);
  return card;
}

function mapEditor(param) {
  param.map = param.map && typeof param.map === 'object' ? param.map : {};
  if (param.map.mode !== 'combined') param.map.mode = 'split';
  const mode = h('select', { onChange: (e) => { param.map.mode = e.target.value; renderMapRows(); } },
    h('option', { value: 'split', selected: param.map.mode !== 'combined' }, 'جداگانه: lat و long در دو پارامتر'),
    h('option', { value: 'combined', selected: param.map.mode === 'combined' }, 'ترکیبی: هر دو در یک پارامتر'));
  const body = h('div');

  const renderMapRows = () => {
    clear(body);
    mode.value = param.map.mode;
    if (param.map.mode === 'combined') {
      body.append(fieldWrapper('کلید پارامتر ترکیبی در وب‌سرویس', h('input', { type: 'text', value: param.map.param || '', onInput: (e) => { param.map.param = e.target.value; } }), 'مقدار "lat,long" در این پارامتر قرار می‌گیرد.'));
    } else {
      body.append(
        fieldWrapper('کلید lat در وب‌سرویس', h('input', { type: 'text', value: param.map.lat_param || '', onInput: (e) => { param.map.lat_param = e.target.value; } })),
        fieldWrapper('کلید long در وب‌سرویس', h('input', { type: 'text', value: param.map.long_param || '', onInput: (e) => { param.map.long_param = e.target.value; } }))
      );
    }
  };
  renderMapRows();

  return h(
    'div',
    { class: 'map-config' },
    fieldWrapper('حالت ارسال مختصات', mode, 'مشخص کنید lat و long با چه نام‌هایی در JSON وب‌سرویس بروند.'),
    body
  );
}

function optionsEditor(param, onChange) {
  param.options = param.options || [];
  const body = h('tbody');
  const notify = () => { if (typeof onChange === 'function') onChange(); };
  const emptyHint = h('p', { class: 'empty' }, 'بدون گزینه، این فیلد در فرم عمومی قابل انتخاب نخواهد بود.');

  const fromLabel = h('input', {
    type: 'checkbox',
    checked: param.options.length > 0 && param.options.every((option) => option.value && option.value === option.label),
  });

  // Typing must not trigger a re-render (it would steal focus), so the hint is
  // refreshed on its own from both the value and the label cell.
  const refreshHint = () => {
    emptyHint.hidden = param.options.some((option) => option.value || option.label);
  };

  // The record is the source of truth, so every edit mutates it and re-renders.
  const renderRows = () => {
    clear(body).append(
      ...param.options.map((option, index) => {
        const valueInput = h('input', { type: 'text', value: option.value, onInput: (e) => { option.value = e.target.value; refreshHint(); notify(); } });
        return h(
          'tr',
          {},
          h('td', { class: 'row-number', text: String(index + 1) }),
          h('td', {}, valueInput),
          h(
            'td',
            {},
            h('input', {
              type: 'text',
              value: option.label,
              onInput: (e) => {
                option.label = e.target.value;
                refreshHint();
                if (fromLabel.checked) {
                  option.value = e.target.value;
                  valueInput.value = e.target.value;
                }
                notify();
              },
            })
          ),
          h('td', {}, h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: () => {
            if (param.options.length <= 1) {
              option.value = '';
              option.label = '';
            } else {
              param.options.splice(index, 1);
            }
            renderRows();
            notify();
          } }, 'حذف'))
        );
      })
    );
    refreshHint();
  };

  if (!param.options.length) param.options.push({ value: '', label: '' });

  fromLabel.addEventListener('change', () => {
    if (!fromLabel.checked) return;
    param.options.forEach((option) => { option.value = option.label; });
    renderRows();
    notify();
  });

  renderRows();

  return h(
    'div',
    { class: 'options' },
    h('label', {}, 'گزینه‌ها'),
    h(
      'table',
      {},
      h('thead', {}, h('tr', {}, h('th', { style: 'width:40px' }, '#'), h('th', { style: 'width:30%' }, 'مقدار ارسالی'), h('th', {}, 'عنوان نمایشی'), h('th', { style: 'width:70px' }, 'عملیات'))),
      body
    ),
    h(
      'div',
      { class: 'opt-actions' },
      h('button', { class: 'btn btn-sm', type: 'button', onClick: () => { param.options.push({ value: '', label: '' }); renderRows(); notify(); } }, 'افزودن گزینه'),
      h('label', { class: 'checkline' }, fromLabel, h('span', {}, 'مقدار برابر عنوان باشد'))
    ),
    emptyHint
  );
}

function listEditor(param, showMinItems = true) {
  param.fields = param.fields || [];
  const body = h('tbody');

  const optionsText = (field) => (Array.isArray(field.options) ? field.options.map((o) => String(o.value ?? '')).filter(Boolean).join(', ') : '');
  const applyOptionsText = (field, text) => {
    const values = String(text || '').split(',').map((s) => s.trim()).filter(Boolean);
    field.options = values.map((value) => {
      const prev = (field.options || []).find((o) => String(o.value) === value);
      return { value, label: (prev && prev.label) || value };
    });
    if (field.default && !values.includes(String(field.default))) field.default = null;
  };
  const defaultCell = (field) => {
    if (field.input === 'select') {
      const opts = Array.isArray(field.options) ? field.options.filter((o) => o && String(o.value ?? '') !== '') : [];
      // Same optional-default healing as top-level select params.
      const allowed = new Set(opts.map((o) => String(o.value)));
      if (field.default === '' || (field.default !== null && field.default !== undefined && !allowed.has(String(field.default)))) {
        field.default = null;
      }
      return h('div', { style: 'min-width:140px' },
        h('select', {
          onChange: (e) => { field.default = e.target.value || null; },
        },
          h('option', { value: '', selected: !field.default }, 'بدون پیش‌فرض'),
          opts.map((option) => h('option', { value: String(option.value), selected: String(field.default ?? '') === String(option.value) }, `${option.label || option.value} (${option.value})`))),
        h('input', {
          type: 'text', value: optionsText(field), placeholder: 'گزینه‌ها با کاما: a, b',
          style: 'margin-top:6px',
          onInput: (e) => { applyOptionsText(field, e.target.value); renderRows(); },
        }));
    }
    return h('input', { type: 'text', value: field.default ?? '', onInput: (e) => { field.default = e.target.value; } });
  };

  const renderRows = () => {
    clear(body).append(
      ...param.fields.map((field, index) => {
        if (!Array.isArray(field.options)) field.options = [];
        return h(
          'tr',
          {},
          h('td', { class: 'row-number', text: String(index + 1) }),
          h('td', {}, h('input', { type: 'text', value: field.name, onInput: (e) => { field.name = e.target.value; } })),
          h('td', {}, h('input', { type: 'text', value: field.label, onInput: (e) => { field.label = e.target.value; } })),
          h('td', {}, h('select', { onChange: (e) => { field.input = e.target.value; if (field.input === 'select' && !Array.isArray(field.options)) field.options = []; if (field.input !== 'text' && field.input !== 'number') field.kind = null; renderRows(); } },
            ROW_INPUT_KINDS.map((kind) => h('option', { value: kind, selected: field.input === kind }, INPUT_LABELS[kind])))),
          h('td', {}, h('select', { onChange: (e) => { field.type = e.target.value; } },
            VALUE_TYPES.map((type) => h('option', { value: type, selected: field.type === type }, type)))),
          h('td', {}, defaultCell(field)),
          h('td', { style: 'text-align:center' }, h('input', { type: 'checkbox', checked: !!field.visible, onChange: (e) => { field.visible = e.target.checked; } })),
          h('td', { style: 'text-align:center' }, h('input', { type: 'checkbox', title: 'اجباری', checked: !!field.required, onChange: (e) => { field.required = e.target.checked; } })),
          h('td', { style: 'text-align:center' }, h('input', {
            type: 'checkbox', title: 'مبلغ پولی: با واحد نمایشی گرفته و با واحد وب‌سرویس ارسال می‌شود',
            checked: field.kind === 'money',
            disabled: field.input !== 'text' && field.input !== 'number',
            onChange: (e) => { field.kind = e.target.checked ? 'money' : null; renderRows(); },
          })),
          h('td', { style: 'text-align:center' }, h('input', {
            type: 'checkbox', title: 'وزن: با واحد نمایشی (گرم/کیلوگرم) گرفته و با واحد وب‌سرویس ارسال می‌شود',
            checked: field.kind === 'weight',
            disabled: field.input !== 'text' && field.input !== 'number',
            onChange: (e) => { field.kind = e.target.checked ? 'weight' : null; renderRows(); },
          })),
          h('td', {}, h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: () => {
            if (param.fields.length <= 1) {
              field.name = '';
              field.label = '';
            } else {
              param.fields.splice(index, 1);
            }
            renderRows();
          } }, 'حذف'))
        );
      })
    );
  };

  param.fields.forEach((field) => {
    field.name = field.name ?? '';
    field.label = field.label ?? '';
    field.kind = field.kind ?? null;
    if (field.input === 'select' && !Array.isArray(field.options)) field.options = [];
  });
  if (!param.fields.length) {
    param.fields.push({ name: '', label: '', input: 'text', type: 'str', visible: false, default: '', kind: null, options: [], required: false });
  }
  renderRows();

  return h(
    'div',
    {},
    showMinItems
      ? h(
        'div',
        { class: 'field', style: 'max-width:220px' },
        h('label', {}, 'حداقل تعداد ردیف (min_items)'),
        h('input', { type: 'number', min: 1, value: param.min_items || 1, onInput: (e) => { param.min_items = Number(e.target.value) || 1; } })
      )
      : null,
    h('h3', {}, showMinItems ? 'ستون‌های لیست' : 'زیرفیلدهای شیء'),
    h('p', { class: 'hint', text: 'برای زیرفیلد «لیست انتخابی»، گزینه‌ها را با کاما بنویسید و پیش‌فرض را از همان گزینه‌ها انتخاب کنید.' }),
    h(
      'table',
      {},
      h('thead', {}, h('tr', {}, ['#', 'نام ارسالی در وب‌سرویس', 'عنوان', 'نوع ورودی', 'نوع مقدار', 'پیش‌فرض / گزینه‌ها', 'نمایش', 'اجباری', 'مبلغ', 'وزن', 'عملیات'].map((title) => h('th', {}, title)))),
      body
    ),
    h('button', {
      class: 'btn btn-sm',
      type: 'button',
      style: 'margin-top:10px',
      onClick: () => {
        param.fields.push({ name: '', label: '', input: 'text', type: 'str', visible: false, default: '', kind: null, options: [], required: false });
        renderRows();
      },
    }, 'افزودن ستون')
  );
}

// --------------------------------------------------------------------------- shops


const shopDraft = { id: '', title: '', province: '', city: '', token: '' };
let editingShopId = null;

function parseShopLines(text) {
  // One shop per line: id, title, province, city, token. Tab or comma
  // separated; province and city may be left off, but every shop needs a token.
  return text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const cells = line.split(/\t|,(?=(?:[^"]*"[^"]*")*[^"]*$)/).map((cell) => cell.trim().replace(/^"|"$/g, ''));
      return {
        id: cells[0] ?? '',
        title: cells[1] ?? '',
        province: cells[2] ?? '',
        city: cells[3] ?? '',
        token: cells[4] ?? '',
      };
    })
    .filter((row) => row.id !== '' && row.title !== '' && row.token !== '');
}

async function startShopEdit(shop) {
  try {
    const response = await api(`/api/admin/shops/${encodeURIComponent(shop.id)}`, auth());
    const full = response.shop || shop;
    editingShopId = String(full.id);
    shopDraft.id = String(full.id);
    shopDraft.title = full.title || '';
    shopDraft.province = full.province ?? '';
    shopDraft.city = full.city ?? '';
    shopDraft.token = full.token || '';
    // The edit form lives at the top of the view: reload first, then jump to
    // it. (Plain loadShops() keeps the scroll; only editing jumps.)
    const goTop = () => window.scrollTo({ top: 0 });
    loadShops().then(goTop, goTop);
  } catch (error) {
    toast(error.message);
  }
}

function cancelShopEdit() {
  editingShopId = null;
  shopDraft.id = '';
  shopDraft.title = '';
  shopDraft.province = '';
  shopDraft.city = '';
  shopDraft.token = '';
  loadShops();
}

function showShops() {
  previewProviderBuilder = null;
  stopPreviewSync();
  hide(listView);
  hide(editorView);
  hide(bulkView);
  hide(logsView);
  hide(securityView);
  if (typeof cacheView !== 'undefined' && cacheView) hide(cacheView);
  show(shopsView);
  markTab('shops');
  goAdmin('/admin/shops');
  loadShops();
}

async function loadShops() {
  const restoreScroll = captureScroll();
  clear(shopsHost).append(skeleton(2));
  restoreScroll();
  let shops;
  try {
    ({ shops } = await api('/api/admin/shops', auth()));
  } catch (error) {
    clear(shopsHost).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
    restoreScroll();
    return;
  }

  const form = h(
    'form',
    {
      onSubmit: async (event) => {
        event.preventDefault();
        const body = { ...shopDraft };
        try {
          if (editingShopId !== null) {
            await api(`/api/admin/shops/${encodeURIComponent(editingShopId)}`, { method: 'PUT', body, ...auth() });
          } else {
            await api('/api/admin/shops', { method: 'POST', body, ...auth() });
          }
          toast(editingShopId !== null ? 'شعبه به‌روزرسانی شد.' : 'شعبه افزوده شد.', 'ok');
          editingShopId = null;
          shopDraft.id = '';
          shopDraft.title = '';
          shopDraft.province = '';
          shopDraft.city = '';
          shopDraft.token = '';
          loadShops();
        } catch (error) {
          toast(error.message);
        }
      },
    },
    h('h3', {}, editingShopId !== null ? `ویرایش شعبه ${editingShopId}` : 'افزودن شعبه'),
    h(
      'div',
      { class: 'grid' },
      fieldWrapper('شناسه شعبه', h('input', { type: 'text', value: shopDraft.id, onInput: (e) => { shopDraft.id = e.target.value; } })),
      fieldWrapper('عنوان', h('input', { type: 'text', value: shopDraft.title, onInput: (e) => { shopDraft.title = e.target.value; } })),
      fieldWrapper('استان', h('input', { type: 'text', value: shopDraft.province, onInput: (e) => { shopDraft.province = e.target.value; } }), 'برای مپ مبدا، کد عددی تاپین را وارد کنید.'),
      fieldWrapper('شهر', h('input', { type: 'text', value: shopDraft.city, onInput: (e) => { shopDraft.city = e.target.value; } }), 'برای مپ مبدا، کد عددی تاپین را وارد کنید.'),
      fieldWrapper(
        'توکن شعبه',
        h('input', { type: 'password', value: shopDraft.token, onInput: (e) => { shopDraft.token = e.target.value; } }),
        'این توکن فقط روی سرور برای تماس با سرویس قیمت استفاده می‌شود.'
      )
    ),
    h(
      'div',
      { class: 'row', style: 'margin-top:12px' },
      h('button', { class: 'btn', type: 'submit' }, editingShopId !== null ? 'ذخیره تغییرات' : 'افزودن شعبه'),
      editingShopId !== null
        ? h('button', { class: 'btn btn-ghost', type: 'button', onClick: cancelShopEdit }, 'انصراف از ویرایش')
        : null
    )
  );

  const bulk = h(
    'form',
    {
      onSubmit: async (event) => {
        event.preventDefault();
        const rows = parseShopLines(bulkText.value);
        if (!rows.length) {
          toast('هیچ ردیفی برای بارگذاری پیدا نشد.');
          return;
        }
        try {
          const result = await api('/api/admin/shops', { method: 'POST', body: { shops: rows }, ...auth() });
          toast(`${result.written} شعبه ذخیره شد.`, 'ok');
          bulkText.value = '';
          loadShops();
        } catch (error) {
          toast(error.message);
        }
      },
    },
    h('h3', {}, 'بارگذاری گروهی'),
    h('p', { class: 'hint', text: 'هر خط یک شعبه: id, title, province, city, token — با Tab یا کاما جدا شود. برای مپ مبدا، استان و شهر باید کد عددی تاپین باشند؛ مثلا 1 و 30. هر شعبه به توکن نیاز دارد.' }),
    bulkText,
    h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { class: 'btn', type: 'submit' }, 'بارگذاری گروهی'))
  );

  const excelFile = h('input', { type: 'file', accept: '.xlsx' });
  const excelPreview = h('div');
  let excelRows = [];

  const downloadSample = async () => {
    try {
      const headers = {};
      const response = await request('/api/admin/shops/sample', { headers });
      if (!response.ok) throw new Error(`خطای ${response.status}`);
      const blob = await response.blob();
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = 'shops-sample.xlsx';
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(link.href), 5000);
    } catch (error) {
      toast(error.message);
    }
  };

  const previewExcel = async () => {
    clear(excelPreview);
    excelRows = [];
    if (!excelFile.files || !excelFile.files.length) {
      toast('اول یک فایل اکسل انتخاب کنید.');
      return;
    }
    const payload = new FormData();
    payload.append('file', excelFile.files[0]);
    const headers = {};
    let result;
    try {
      const response = await request('/api/admin/shops/import', { method: 'POST', headers, body: payload });
      result = JSON.parse(await response.text());
      if (!response.ok) throw new Error(result.detail || `خطای ${response.status}`);
    } catch (error) {
      excelPreview.append(h('p', { class: 'error-text', text: error.message }));
      return;
    }
    excelRows = result.rows || [];
    excelPreview.append(
      h('p', { class: 'muted', text: `${excelRows.length} ردیف معتبر پیدا شد. هنوز چیزی ذخیره نشده است.` }),
      excelRows.length
        ? h(
          'table',
          {},
          h('thead', {}, h('tr', {}, ['شناسه شعبه', 'عنوان', 'استان', 'شهر', 'توکن'].map((title) => h('th', {}, title)))),
          h(
            'tbody',
            {},
            excelRows.map((row) =>
              h('tr', {},
                h('td', { class: 'code' }, String(row.id)),
                h('td', {}, row.title),
                h('td', {}, row.province || '—'),
                h('td', {}, row.city || '—'),
                h('td', {}, row.token ? 'تنظیم شده' : '—'))
            )
          )
        )
        : h('p', { class: 'empty', text: 'ردیف معتبری در فایل نیست.' }),
      (result.errors || []).length
        ? h('ul', {}, result.errors.map((item) => h('li', { class: 'error-text' }, `${item.field}: ${item.message}`)))
        : null,
      excelRows.length
        ? h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { class: 'btn', type: 'button', onClick: confirmExcel }, 'تأیید و ذخیره'))
        : null
    );
  };

  const confirmExcel = async () => {
    if (!excelRows.length) return;
    try {
      const result = await api('/api/admin/shops', { method: 'POST', body: { shops: excelRows }, ...auth() });
      toast(`${result.written} شعبه ذخیره شد.`, 'ok');
      excelFile.value = '';
      loadShops();
    } catch (error) {
      toast(error.message);
    }
  };

  const excel = h(
    'div',
    {},
    h('h3', {}, 'بارگذاری با اکسل'),
    h('p', { class: 'hint' }, 'فایل نمونه را دانلود کنید، پر کنید و همین‌جا آپلود کنید. ستون‌ها به ترتیب: id, title, province, city, token. برای مپ مبدا، استان و شهر باید کد عددی تاپین باشند. '),
    h('p', { style: 'margin:0 0 12px' }, h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onClick: downloadSample }, 'دانلود نمونه اکسل')),
    h('div', { class: 'row' }, excelFile, h('button', { class: 'btn', type: 'button', onClick: previewExcel }, 'پیش‌نمایش')),
    excelPreview
  );

  const shopSearch = h('input', { type: 'text', placeholder: 'جستجوی شعبه با شناسه یا عنوان…', autocomplete: 'off' });
  const shopCount = h('p', { class: 'muted', style: 'margin:6px 0 0' });
  clear(shopsHost).append(
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h(
          'div',
          { style: 'flex:1' },
          h('h2', {}, `شعب (${toFaDigits(String(shops.length))})`),
          h('div', { class: 'stat-row' },
            h('span', { class: 'stat-mini' }, 'شعبه ', h('b', { text: toFaDigits(String(shops.length)) })),
            h('span', { class: 'stat-mini ok' }, 'توکن‌دار ', h('b', { text: toFaDigits(String(shops.filter((shop) => shop.has_token).length)) }))
          )
        )
      ),
      shops.length ? h('div', {}, h('div', { class: 'search-row' }, shopSearch), shopCount) : null,
    ),
    h('div', { class: 'card' }, form),
    h('div', { class: 'card' }, bulk),
    h('div', { class: 'card' }, excel),
    shops.length
      ? h(
          'div',
          { class: 'card' },
          h(
            'table',
            {},
            h('thead', {}, h('tr', {}, ['شناسه شعبه', 'عنوان', 'استان', 'شهر', 'توکن', 'عملیات'].map((title) => h('th', {}, title)))),
            h(
              'tbody',
              {},
              shops.map((shop) =>
                h(
                  'tr',
                  { 'data-search': `${shop.id || ''} ${shop.title || ''}`.toLowerCase() },
                  h('td', { class: 'code' }, String(shop.id)),
                  h('td', {}, shop.title),
                  h('td', {}, shop.province || '—'),
                  h('td', {}, shop.city || '—'),
                  h('td', {}, shop.has_token ? 'تنظیم شده' : '—'),
                  h(
                    'td',
                    { class: 'actions' },
                    h('button', { class: 'btn btn-sm', type: 'button', onClick: () => startShopEdit(shop) }, 'ویرایش'),
                    ' ',
                    h(
                      'button',
                      {
                        class: 'btn btn-sm btn-remove',
                        type: 'button',
                        onClick: () => {
                          if (!window.confirm(`شعبه ${shop.id} حذف شود؟`)) return;
                          removeShop(shop.id);
                        },
                      },
                      'حذف'
                    )
                  )
                )
              )
            )
          )
        )
      : h('div', { class: 'empty', style: 'text-align:center' },
        h('div', { class: 'empty-illust' }, icon('store')),
        h('p', { style: 'margin:0', text: 'هنوز شعبه‌ای ثبت نشده است. از فرم بالا اولین شعبه را بسازید.' }))
  );
  const shopTable = shopsHost.querySelector(':scope > .card:last-child table');
  if (shopTable && shops.length) {
    shopSearch.addEventListener('input', () => {
      const query = (shopSearch.value || '').trim().toLowerCase();
      let visible = 0;
      shopTable.querySelectorAll('tbody tr').forEach((row) => {
        const match = query === '' || (row.dataset.search || '').includes(query);
        row.hidden = !match;
        if (match) visible += 1;
      });
      shopCount.textContent = query ? `نمایش ${toFaDigits(String(visible))} از ${toFaDigits(String(shops.length))} شعبه` : '';
    });
  }
  restoreScroll();
}

async function removeShop(shopId) {
  try {
    await api(`/api/admin/shops/${encodeURIComponent(shopId)}`, { method: 'DELETE', ...auth() });
    toast('حذف شد.', 'ok');
    loadShops();
  } catch (error) {
    toast(error.message);
  }
}

function markTab(name) {
  tabs.forEach((tab) => {
    tab.classList.toggle('btn-ghost', tab.dataset.tab !== name);
  });
}

tabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    goAdmin(ADMIN_TAB_URL[tab.dataset.tab] || '/admin/providers');
    routeAdmin();
  });
});

const bulkText = h('textarea', { rows: 5, placeholder: '101\tشعبه مرکزی\t1\t1\tTOKEN' });

// --------------------------------------------------------------------------- save


function serialise(record) {
  const text = (value) => String(value ?? '').trim();
  // City/province binding ("kind") lives only in "تنظیمات شهر و استان":
  // the destination fields picked there become province/city here on save,
  // and every other param is stored without a kind. The fields table no
  // longer edits kind directly.
  const destCfg = (record.location_config && record.location_config.destination) || {};
  const destProvName = destCfg.enabled === true ? text(destCfg.province_param) : '';
  const destCityName = destCfg.enabled === true ? text(destCfg.city_param) : '';
  return {
    name: text(record.name),
    display_name: text(record.display_name),
    active: record.active !== false,
    logo: text(record.logo),
    check_price_url: text(record.check_price_url),
    shop_id: text(record.shop_id),
    timeout: text(record.timeout) === '' ? null : Number(text(record.timeout)),
    main_price_key: text(record.main_price_key) || null,
    price_currency_in: record.price_currency_in === 'toman' ? 'toman' : 'rial',
    price_currency_out: record.price_currency_out === 'toman' ? 'toman' : 'rial',
    price_input_param: text(record.price_input_param) || null,
    weight_unit_in: record.weight_unit_in === 'kilogram' ? 'kilogram' : 'gram',
    weight_unit_out: record.weight_unit_out === 'kilogram' ? 'kilogram' : 'gram',
    weight_input_param: text(record.weight_input_param) || null,
    price_fields: (record.price_fields || [])
      .map((item) => ({ key: text(item.key), label: text(item.label) }))
      .filter((item) => item.key),
    params: record.params.map((param) => {
      const pname = text(param.name);
      const item = {
        id: param.id ?? null,
        name: pname,
        label: text(param.label),
        input: param.input,
        type: param.type,
        visible: !!param.visible,
        kind: destProvName && pname === destProvName
          ? 'province'
          : destCityName && pname === destCityName
            ? 'city'
            : (param.kind === 'money' && (param.input === 'text' || param.input === 'number') ? 'money'
              : (param.kind === 'weight' && (param.input === 'text' || param.input === 'number') ? 'weight' : null)),
        description: text(param.description),
        default: param.input === 'list' || param.input === 'dict' ? null : param.default,
        required: (param.input === 'text' || param.input === 'number' || param.input === 'select') ? !!param.required : false,
      };
      if (param.input === 'select') {
        item.options = (param.options || [])
          .map((option) => ({ value: text(option.value), label: text(option.label) }))
          .filter((option) => option.value || option.label)
          .map((option) => ({ value: option.value || option.label, label: option.label || option.value }));
      }
      if (param.input === 'list') {
        item.min_items = param.min_items || 1;
      }
      if (param.input === 'map') {
        const map = param.map || {};
        if (map.mode === 'combined') {
          item.map = { mode: 'combined', param: text(map.param) };
        } else {
          item.map = { mode: 'split', lat_param: text(map.lat_param), long_param: text(map.long_param) };
        }
      }
      if (param.input === 'list' || param.input === 'dict') {
        item.fields = (param.fields || [])
          .filter((field) => text(field.name))
          .map((field) => {
            const out = {
              id: field.id ?? null,
              name: text(field.name),
              label: text(field.label),
              input: field.input,
              type: field.type,
              visible: !!field.visible,
              kind: (field.kind === 'money' && (field.input === 'text' || field.input === 'number')) ? 'money'
                : ((field.kind === 'weight' && (field.input === 'text' || field.input === 'number')) ? 'weight' : null),
              default: field.default,
              required: !!field.required,
            };
            if (field.input === 'select') {
              out.options = (field.options || [])
                .map((option) => ({ value: text(option.value), label: text(option.label) }))
                .filter((option) => option.value || option.label)
                .map((option) => ({ value: option.value || option.label, label: option.label || option.value }));
            }
            return out;
          });
      }
      return item;
    }),
    referral: {
      enabled: !!((record.referral || {}).enabled),
      title: text((record.referral || {}).title),
      url: text((record.referral || {}).url),
      description: text((record.referral || {}).description),
    },
    location_config: (() => {
      const loc = record.location_config || {};
      const origin = loc.origin || {};
      const dest = loc.destination || {};
      const seenCodes = new Set();
      const cleanProvinces = (((loc.custom_locations || {}).provinces || []))
        .filter((item) => item && text(item.code) && !seenCodes.has(`p:${text(item.code)}`))
        .map((item) => {
          seenCodes.add(`p:${text(item.code)}`);
          return { code: text(item.code), title: text(item.title) || text(item.code) };
        });
      seenCodes.clear();
      const cleanCities = (((loc.custom_locations || {}).cities || []))
        .filter((item) => item && text(item.code) && text(item.province_code) && !seenCodes.has(`c:${text(item.code)}`))
        .map((item) => {
          seenCodes.add(`c:${text(item.code)}`);
          return { code: text(item.code), title: text(item.title) || text(item.code), province_code: text(item.province_code) };
        });
      seenCodes.clear();
      const cleanBlockedProvinces = (((loc.blocked_locations || {}).provinces || []))
        .filter((item) => item && text(item.code) && !seenCodes.has(`p:${text(item.code)}`))
        .map((item) => {
          seenCodes.add(`p:${text(item.code)}`);
          return { code: text(item.code), title: text(item.title) || text(item.code) };
        });
      seenCodes.clear();
      const cleanBlockedCities = (((loc.blocked_locations || {}).cities || []))
        .filter((item) => item && text(item.code) && text(item.province_code) && !seenCodes.has(`c:${text(item.code)}`))
        .map((item) => {
          seenCodes.add(`c:${text(item.code)}`);
          return { code: text(item.code), title: text(item.title) || text(item.code), province_code: text(item.province_code) };
        });
      // Entering any manual row means the manual list; otherwise the service list.
      const hasCustom = cleanProvinces.length > 0 || cleanCities.length > 0;
      return {
        origin: {
          enabled: origin.enabled !== false,
          display: origin.display === 'combined' ? 'combined' : 'separate',
          source: hasCustom ? 'custom' : 'service',
          custom_mode: origin.custom_mode === 'only' ? 'only' : 'merge',
        },
        destination: {
          enabled: dest.enabled === true,
          display: dest.display === 'combined' ? 'combined' : 'separate',
          province_param: text(dest.province_param) || null,
          city_param: text(dest.city_param) || null,
          source: hasCustom ? 'custom' : 'service',
          custom_mode: dest.custom_mode === 'only' ? 'only' : 'merge',
        },
        custom_locations: { provinces: cleanProvinces, cities: cleanCities },
        blocked_locations: { provinces: cleanBlockedProvinces, cities: cleanBlockedCities },
      };
    })(),
    rules: (record.rules || []).map((rule) => ({
      name: text(rule.name),
      if_field: text(rule.if_field),
      if_op: text(rule.if_op) || 'eq',
      if_value: text(rule.if_value),
      if_value2: text(rule.if_value2),
      then_field: text(rule.then_field),
      then_op: text(rule.then_op) || 'eq',
      then_value: text(rule.then_value),
      then_value2: text(rule.then_value2),
      message: text(rule.message),
    })),
  };
}

async function save(record, errorHost) {
  clear(errorHost);
  const body = serialise(record);
  try {
    if (record.id) {
      await api(`/api/admin/providers/${record.id}`, { method: 'PUT', body, ...auth() });
    } else {
      await api('/api/admin/providers', { method: 'POST', body, ...auth() });
    }
    toast('ذخیره شد.', 'ok');
    showList();
  } catch (error) {
    const details = (error.errors || []).map((item) => `${item.field}: ${item.message}`).join('\n');
    toast(details ? `${error.message}\n${details}` : error.message);
  }
}

// --------------------------------------------------------------------------- bulk


const bulkDraft = { providers: [], fields: [], mappings: {}, valueMappings: {}, variants: {}, location: { origin: { enabled: true, display: 'combined' }, destination: { enabled: true, display: 'combined' } }, price: { field: null, unit: 'rial' }, weight: { field: null, unit: 'gram' } };

function normaliseBulkPriceDraft(saved) {
  const price = saved && typeof saved === 'object' ? saved : {};
  const field = String(price.field || '').trim() || null;
  return { field, unit: price.unit === 'toman' ? 'toman' : 'rial' };
}

function normaliseBulkWeightDraft(saved) {
  const weight = saved && typeof saved === 'object' ? saved : {};
  const field = String(weight.field || '').trim() || null;
  return { field, unit: weight.unit === 'kilogram' ? 'kilogram' : 'gram' };
}

function normaliseBulkLocationDraft(saved) {
  const loc = saved && typeof saved === 'object' ? saved : {};
  const origin = loc.origin && typeof loc.origin === 'object' ? loc.origin : {};
  const dest = loc.destination && typeof loc.destination === 'object' ? loc.destination : {};
  return { origin: {
    enabled: origin.enabled !== false,
    display: origin.display === 'separate' ? 'separate' : 'combined',
  }, destination: {
    enabled: dest.enabled !== false,
    display: dest.display === 'separate' ? 'separate' : 'combined',
    province_field: dest.province_field || null,
    city_field: dest.city_field || null,
  } };
}
let bulkProviders = [];
const bulkErrorHost = h('div');
let bulkStep = 'companies';
let bulkMapPid = null;

function showBulk() {
  previewProviderBuilder = null;
  stopPreviewSync();
  hide(listView);
  hide(editorView);
  hide(shopsView);
  hide(logsView);
  hide(securityView);
  if (typeof cacheView !== 'undefined' && cacheView) hide(cacheView);
  show(bulkView);
  markTab('bulk');
  goAdmin('/admin/bulk');
  loadBulk();
  // Live preview follows the unsaved bulk draft (saved providers stay saved).
  startPreviewSync('bulk', bulkRequestBody);
}

async function loadBulk() {
  clear(bulkView).append(skeleton(3));
  let data;
  try {
    data = await api('/api/admin/bulk', auth());
  } catch (error) {
    clear(bulkView).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
    return;
  }
  bulkProviders = data.providers || [];
  const config = data.config || {};
  bulkDraft.providers = [...(config.providers || [])];
  bulkDraft.fields = (config.fields || []).map((field) => ({ ...field }));
  bulkDraft.mappings = JSON.parse(JSON.stringify(config.mappings || {}));
  bulkDraft.valueMappings = JSON.parse(JSON.stringify(config.value_mappings || config.valueMappings || {}));
  bulkDraft.variants = JSON.parse(JSON.stringify(config.variants || {}));
  bulkDraft.location = normaliseBulkLocationDraft(config.location);
  bulkDraft.price = normaliseBulkPriceDraft(config.price);
  bulkDraft.weight = normaliseBulkWeightDraft(config.weight);
  canonicaliseBulkDraftTargets();
  renderBulk();
}

// Match a stored bulk reference: new configs carry stable numeric ids,
// older ones carry technical names. Numeric strings count as ids first,
// then fall back to a name lookup.
function bulkProviderByRef(ref) {
  if (ref === null || ref === undefined || ref === '') return undefined;
  if (typeof ref === 'number' && Number.isInteger(ref)) {
    return bulkProviders.find((provider) => provider.id === ref);
  }
  const text = String(ref).trim();
  if (text !== '' && /^\d+$/.test(text)) {
    const byId = bulkProviders.find((provider) => provider.id === Number(text));
    if (byId) return byId;
  }
  return bulkProviders.find((provider) => provider.name === text);
}

function bulkRefProviderId(ref) {
  const found = bulkProviderByRef(ref);
  return found ? found.id : null;
}

// ---- stable param relations (bulk targets) ----
// Every param/field has a stable numeric id; bulk relations store the id
// ("12", "12.34") so renaming a param never strands them. Legacy configs
// carry technical names instead — every helper below accepts both, ids
// first, exactly like provider references already do.
function bulkParamByRef(provider, ref) {
  if (ref === null || ref === undefined || ref === '') return undefined;
  const params = (provider && provider.params) || [];
  if (typeof ref === 'number' && Number.isInteger(ref)) {
    const byId = params.find((p) => p && p.id === ref);
    if (byId) return byId;
  }
  const text = String(ref).trim();
  if (text !== '' && /^\d+$/.test(text)) {
    const byId = params.find((p) => p && String(p.id) === text);
    if (byId) return byId;
  }
  return params.find((p) => p && p.name === text);
}

function bulkSubByRef(param, ref) {
  if (ref === null || ref === undefined || ref === '') return undefined;
  const fields = (param && param.fields) || [];
  if (typeof ref === 'number' && Number.isInteger(ref)) {
    const byId = fields.find((f) => f && f.id === ref);
    if (byId) return byId;
  }
  const text = String(ref).trim();
  if (text !== '' && /^\d+$/.test(text)) {
    const byId = fields.find((f) => f && String(f.id) === text);
    if (byId) return byId;
  }
  return fields.find((f) => f && f.name === text);
}

function canonicalBulkTarget(provider, raw) {
  // "12"/"12.34"/legacy names -> canonical id form, or null when the
  // referenced param (or sub-field) no longer exists (renamed away/removed).
  if (raw === null || raw === undefined || raw === '') return null;
  const text = String(raw).trim();
  if (!text) return null;
  const dot = text.indexOf('.');
  if (dot === -1) {
    const param = bulkParamByRef(provider, text);
    if (!param) return null;
    return String(param.id ?? param.name);
  }
  const param = bulkParamByRef(provider, text.slice(0, dot));
  if (!param) return null;
  const sub = bulkSubByRef(param, text.slice(dot + 1));
  if (!sub) return null;
  return `${param.id ?? param.name}.${sub.id ?? sub.name}`;
}

function resolveBulkTargetName(provider, raw) {
  // Canonical-or-legacy target -> live "name"/"name.sub" for display, or
  // null when dangling.
  if (raw === null || raw === undefined || raw === '') return null;
  const text = String(raw).trim();
  if (!text) return null;
  const dot = text.indexOf('.');
  if (dot === -1) {
    const param = bulkParamByRef(provider, text);
    return param ? param.name : null;
  }
  const param = bulkParamByRef(provider, text.slice(0, dot));
  if (!param) return null;
  const sub = bulkSubByRef(param, text.slice(dot + 1));
  return sub ? `${param.name}.${sub.name}` : null;
}

function bulkTargetMatches(provider, stored, optionValue) {
  if (stored === optionValue) return true;
  const canonical = canonicalBulkTarget(provider, stored);
  return canonical !== null && canonical === optionValue;
}

// Normalize a loaded/saved draft in place: legacy name targets become
// canonical id targets wherever they still resolve; dangling ones are kept
// verbatim so the UI can surface (and the save can reject) them.
function canonicaliseBulkDraftTargets() {
  const byId = new Map((bulkProviders || []).map((p) => [p && p.id, p]));
  const upgrade = (provider, raw) => {
    const canonical = provider ? canonicalBulkTarget(provider, raw) : null;
    return canonical !== null ? canonical : raw;
  };
  Object.entries(bulkDraft.mappings || {}).forEach(([pid, row]) => {
    const provider = byId.get(Number(pid)) || bulkProviderByRef(pid);
    if (!provider || !row || typeof row !== 'object') return;
    Object.entries(row).forEach(([shared, target]) => {
      const next = upgrade(provider, target);
      if (next !== target) row[shared] = next;
    });
  });
  (bulkDraft.fields || []).forEach((field) => {
    const src = field && field.source && typeof field.source === 'object' ? field.source : null;
    if (src && src.param !== undefined && src.param !== null && src.param !== '') {
      const provider = bulkProviderByRef(src.provider);
      const next = upgrade(provider, src.param);
      if (next !== src.param) src.param = (typeof next === 'string' && /^\d+$/.test(next)) ? Number(next) : next;
    }
  });
  Object.entries(bulkDraft.variants || {}).forEach(([pid, vrow]) => {
    const provider = byId.get(Number(pid)) || bulkProviderByRef(pid);
    if (!provider || !vrow || typeof vrow !== 'object') return;
    Object.entries(vrow).forEach(([target, choices]) => {
      const next = upgrade(provider, target);
      if (next !== target) {
        vrow[next] = choices;
        delete vrow[target];
      }
    });
  });
}

// Entries kept in the saved bulk settings that match neither a provider id
// nor a legacy technical name (renamed or deleted): they have no checkbox,
// so the tab would silently hide them while the server keeps rejecting every
// save that mentions them.
function bulkStaleNames() {
  return bulkDraft.providers.filter((entry) => !bulkProviderByRef(entry));
}

// Every bulk interaction (checking a company, editing a field, mapping…)
// rebuilds this whole view; keep the scroll where the user was.
function renderBulk() {
  return preserveScroll(() => {
  clear(bulkErrorHost);
  const staleNames = bulkStaleNames();
  const providerCards = bulkProviders.length
    ? bulkProviders.map((provider) => {
      const checked = bulkDraft.providers.some((entry) => {
        const found = bulkProviderByRef(entry);
        return !!found && found.id === provider.id;
      });
      const inactive = provider.active === false;
      return h(
        'label',
        { class: inactive ? 'checkline provider-off' : 'checkline' },
        h('input', {
          type: 'checkbox',
          checked,
          disabled: inactive,
          title: inactive ? 'این شرکت غیرفعال است؛ انتخاب آن قابل تغییر نیست.' : '',
          onChange: (e) => {
            if (inactive) return;
            if (e.target.checked) {
              if (!bulkDraft.providers.includes(provider.id)
                && !bulkDraft.providers.includes(String(provider.id))) bulkDraft.providers.push(provider.id);
              if (!bulkDraft.mappings[provider.id]) bulkDraft.mappings[provider.id] = {};
            } else {
              bulkDraft.providers = bulkDraft.providers.filter(
                (entry) => entry !== provider.id && entry !== String(provider.id) && entry !== provider.name
              );
              delete bulkDraft.mappings[provider.id];
              delete bulkDraft.mappings[provider.name];
            }
            renderBulk();
          },
        }),
        h('span', {}, `${provider.display_name} `, h('span', { class: 'code', text: provider.name }),
          inactive ? h('span', { class: 'pill pill-dim', style: 'margin-right:6px', text: 'غیرفعال' }) : null)
      );
    })
    : [h('p', { class: 'empty', text: 'هنوز شرکتی ثبت نشده است.' })];

  const fieldLabel = h('input', { type: 'text', placeholder: 'وزن' });
  const fieldInput = h(
    'select',
    {},
    h('option', { value: 'text' }, 'متنی'),
    h('option', { value: 'number' }, 'عددی')
  );

  const mappingCards = bulkDraft.providers
    .map(bulkProviderByRef)
    .filter(Boolean)
    .map((provider) => {
      if (!bulkDraft.fields.length) {
        return h('div', { class: 'card' }, h('h3', {}, provider.display_name), h('p', { class: 'empty', text: 'اول یک فیلد گروهی تعریف کنید.' }));
      }
      const rows = bulkDraft.fields.map((field) => {
        const fieldSource = field.source && typeof field.source === 'object' ? field.source : null;
        const fieldSourcePid = fieldSource ? bulkRefProviderId(fieldSource.provider) : null;
        const lockedSource = fieldSourcePid !== null && fieldSourcePid === provider.id;
        const readOnly = provider.active === false;
        const disabled = lockedSource || readOnly;
        const options = [];
        (provider.params || []).forEach((param) => {
          if (isOriginParam(param.name)) return;
          if (!param.visible) return;
          if (param.input === 'map') return;
          if (param.input === 'list' || param.input === 'dict') {
            // A shared scalar value can only fill one sub-field, never a
            // whole list or object, so bare list/dict params are not offered.
            // Only visible sub-fields are offered, like the params themselves.
            // Values are stable param/field ids, labels show live names.
            (param.fields || []).forEach((sub) => {
              if (!sub.visible) return;
              options.push({
                value: `${param.id ?? param.name}.${sub.id ?? sub.name}`,
                label: `${param.label} - ${sub.label} (${param.name}.${sub.name})`,
              });
            });
          } else {
            options.push({ value: String(param.id ?? param.name), label: `${param.label} (${param.name})` });
          }
        });
        const stored = (bulkDraft.mappings[provider.id] || {})[field.name];
        const matched = stored ? options.some((option) => bulkTargetMatches(provider, stored, option.value)) : false;
        const select = h(
          'select',
          {
            disabled,
            onChange: (e) => {
              if (!bulkDraft.mappings[provider.id]) bulkDraft.mappings[provider.id] = {};
              bulkDraft.mappings[provider.id][field.name] = e.target.value;
            },
          },
          h('option', { value: '' }, 'بدون نگاشت'),
          options.map((option) => h(
            'option',
            { value: option.value, selected: bulkTargetMatches(provider, stored, option.value) },
            option.label
          )),
          stored && !matched
            ? h('option', { value: stored, selected: true }, `${resolveBulkTargetName(provider, stored) || stored} (یافت نشد)`)
            : null
        );
        return h('tr', {}, h('td', {}, field.label),
          h('td', {}, select,
            stored && !matched
              ? h('div', { class: 'error-text', text: `این نگاشت به پارامتری اشاره می‌کند که دیگر وجود ندارد (${resolveBulkTargetName(provider, stored) || stored})؛ گزینه دیگری انتخاب کنید.` })
              : null,
            lockedSource ? h('div', { class: 'hint', text: 'خودکار: مقدار به پارامتر منبع می‌رسد؛ نگاشت لازم نیست.' }) : null,
            readOnly ? h('div', { class: 'hint', text: 'شرکت غیرفعال است؛ فقط نمایش.' }) : null));
      });
  const valueBlocks = [];
  const ensureValueMap = (fieldName) => {
    if (!bulkDraft.valueMappings[provider.id]) bulkDraft.valueMappings[provider.id] = {};
    let rowMap = bulkDraft.valueMappings[provider.id][fieldName];
    if (!rowMap || typeof rowMap !== 'object') {
      rowMap = {};
      bulkDraft.valueMappings[provider.id][fieldName] = rowMap;
    }
    return rowMap;
  };
  const pruneValueMap = (fieldName) => {
    const pidMap = bulkDraft.valueMappings[provider.id];
    if (!pidMap) return;
    if (pidMap[fieldName] && !Object.keys(pidMap[fieldName]).length) delete pidMap[fieldName];
    if (!Object.keys(pidMap).length) delete bulkDraft.valueMappings[provider.id];
  };
  const readOnlyProvider = provider.active === false;
  const manualRow = (fieldName, keyInit, valInit, tbody) => {
    let currentKey = keyInit;
    const keyInput = h('input', { type: 'text', value: keyInit, placeholder: 'مقدار منبع', disabled: readOnlyProvider });
    const valInput = h('input', { type: 'text', value: valInit, placeholder: 'مقدار ارسالی', disabled: readOnlyProvider });
    const sync = () => {
      const rowMap = ensureValueMap(fieldName);
      const nextKey = keyInput.value.trim();
      if (currentKey && currentKey !== nextKey) delete rowMap[currentKey];
      currentKey = nextKey;
      const val = valInput.value.trim();
      if (currentKey && val) rowMap[currentKey] = val;
      else if (currentKey) delete rowMap[currentKey];
      pruneValueMap(fieldName);
    };
    keyInput.addEventListener('input', sync);
    valInput.addEventListener('input', sync);
    const rowNode = h('tr', {},
      h('td', {}, keyInput),
      h('td', {}, valInput),
      h('td', { style: 'width:70px' },
        h('button', {
          class: 'btn btn-sm btn-remove', type: 'button', disabled: readOnlyProvider, onClick: () => {
            const rowMap = ensureValueMap(fieldName);
            if (currentKey) delete rowMap[currentKey];
            pruneValueMap(fieldName);
            rowNode.remove();
          },
        }, 'حذف')));
    tbody.append(rowNode);
  };
  bulkDraft.fields.forEach((field) => {
    const src = field.source && typeof field.source === 'object' ? field.source : null;
    if (!src) return;
    const srcProvider = bulkProviderByRef(src.provider);
    if (!srcProvider || srcProvider.id === provider.id) return;
    const head = h('h4', { style: 'margin:0 0 4px' }, `نگاشت مقادیر «${field.label}»`);
    const srcParam = bulkParamByRef(srcProvider, src.param);
    if (!srcParam) {
      valueBlocks.push(h('div', { style: 'margin-top:14px' }, head,
        h('p', { class: 'error-text', text: `پارامتر منبع (${resolveBulkTargetName(srcProvider, src.param) || src.param}) در «${srcProvider.display_name}» پیدا نشد؛ منبع این فیلد را اصلاح کنید.` })));
      return;
    }
    const existing = ((bulkDraft.valueMappings[provider.id] || {})[field.name]) || {};
    const srcOptions = Array.isArray(srcParam.options) ? srcParam.options : [];
    const manualBody = h('tbody');
    Object.keys(existing)
      .filter((k) => !srcOptions.some((o) => String(o.value) === k))
      .forEach((k) => manualRow(field.name, k, String(existing[k] ?? ''), manualBody));
    const manualBlock = h('div', { style: 'margin-top:10px' },
      h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'مقدار منبع (دستی)'), h('th', {}, 'مقدار ارسالی'), h('th', { style: 'width:70px' }, 'عملیات'))), manualBody),
      h('div', { class: 'row', style: 'margin-top:8px' },
        h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => manualRow(field.name, '', '', manualBody) }, '＋ سطر دستی')));
    if (!srcOptions.length) {
      valueBlocks.push(h('div', { style: 'margin-top:14px' }, head,
        h('p', { class: 'hint notice', text: 'برای پارامتر منبع گزینه‌ای ثبت نشده؛ مقادیر را دستی وارد کنید (کلید باید دقیقاً همان مقداری باشد که فرم می‌فرستد).' }),
        manualBlock));
      return;
    }
    const optRows = srcOptions.map((option) => {
      const key = String(option.value);
      const current = existing[key];
      const input = h('input', {
        type: 'text',
        disabled: readOnlyProvider,
        value: current === undefined || current === null ? '' : String(current),
        placeholder: 'خالی = همان مقدار',
        onInput: (e) => {
          const rowMap = ensureValueMap(field.name);
          const val = e.target.value.trim();
          if (val) rowMap[key] = val;
          else delete rowMap[key];
          pruneValueMap(field.name);
        },
      });
      return h('tr', {},
        h('td', {}, h('span', { text: `${option.label} ` }), h('span', { class: 'code', text: key })),
        h('td', {}, input));
    });
    valueBlocks.push(h('div', { style: 'margin-top:14px' }, head,
      h('p', { class: 'hint', style: 'margin:0 0 8px' }, `به‌ازای هر گزینه «${srcProvider.display_name}» بنویس برای این شرکت چه مقداری ارسال شود؛ خالی یعنی همان مقدار.`),
      h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'مقدار منبع'), h('th', {}, 'مقدار ارسالی'))), h('tbody', {}, optRows)),
      manualBlock));
  });
  return h(
    'div',
    { class: 'card' },
        h('h3', {}, `نگاشت ${provider.display_name}`),
        h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'فیلد گروهی'), h('th', {}, 'پارامتر این شرکت'))), h('tbody', {}, rows)),
        valueBlocks.length ? valueBlocks : null
      );
    });

  const selectedProviders = bulkDraft.providers.map(bulkProviderByRef).filter(Boolean);
  if (!selectedProviders.some((p) => p.id === bulkMapPid)) bulkMapPid = selectedProviders.length ? selectedProviders[0].id : null;
  mappingCards.forEach((card, i) => { card.hidden = !selectedProviders[i] || selectedProviders[i].id !== bulkMapPid; });

  const stepTab = (key, label) => h('button', {
    class: bulkStep === key ? 'btn btn-sm' : 'btn btn-sm btn-ghost',
    type: 'button',
    onClick: () => { bulkStep = key; renderBulk(); },
  }, label);
  const stepTabs = h('div', { class: 'tabs subtabs bulk-steps', role: 'tablist' },
    stepTab('companies', `۱. شرکت‌ها (${toFaDigits(String(selectedProviders.length))})`),
    stepTab('fields', `۲. فیلدها (${toFaDigits(String(bulkDraft.fields.length))})`),
    stepTab('location', '۳. شهر و استان'),
    stepTab('mapping', '۴. نگاشت و مقادیر'),
    stepTab('variants', '۵. حالت‌های جایگزین'));

  const companiesPane = h('div', { hidden: bulkStep !== 'companies' },
    h(
      'div',
      { class: 'card' },
      h('h2', {}, 'شرکت‌های حاضر در استعلام گروهی'),
      guideBox([['استان و شهر مبدا', 'این دو فیلد همیشه در فرم گروهی هستند و برای هر شرکت با همان منطق استعلام تکی به شعبه تبدیل می‌شوند؛ نیازی به تعریف یا نگاشت ندارند.']]),
      h('div', { class: 'grid' }, providerCards)
    ));

  const destFieldNames = bulkDraft.fields.filter((f) => f && f.name).map((f) => f.name);
  const destLoc = bulkDraft.location.destination;
  // No auto-pick: the admin chooses both fields explicitly. Only stale
  // references (deleted fields) are cleared.
  if (!destFieldNames.includes(destLoc.province_field)) destLoc.province_field = null;
  if (!destFieldNames.includes(destLoc.city_field)) destLoc.city_field = null;
  const destFieldLabel = (name) => {
    const found = bulkDraft.fields.find((f) => f && f.name === name);
    return (found && found.label) || name;
  };

  const locationPane = h('div', { hidden: bulkStep !== 'location' },
    h(
      'div',
      { class: 'card' },
      h('h2', {}, 'مبدا گروهی'),
      h(
        'div',
        { class: 'grid' },
        h(
          'div',
          { class: 'field' },
          h('label', {}, 'وضعیت'),
          h('label', { class: 'checkline' },
            h('input', {
              type: 'checkbox', checked: bulkDraft.location.origin.enabled !== false,
              onChange: (e) => { bulkDraft.location.origin.enabled = e.target.checked; },
            }),
            h('span', {}, 'مبدا در فرم گروهی نمایش داده شود'))
        ),
        fieldWrapper('نوع نمایش', h(
          'select',
          { onChange: (e) => { bulkDraft.location.origin.display = e.target.value; } },
          h('option', { value: 'combined', selected: bulkDraft.location.origin.display !== 'separate' }, 'در یک فیلد'),
          h('option', { value: 'separate', selected: bulkDraft.location.origin.display === 'separate' }, 'جدا')
        ), '«در یک فیلد» استان و شهر را به‌صورت «استان — شهر» در یک کنترل نشان می‌دهد.')
      )
    ),
    h(
      'div',
      { class: 'card' },
      h('h2', {}, 'مقصد گروهی'),
      h(
        'div',
        { class: 'grid' },
        h(
          'div',
          { class: 'field' },
          h('label', {}, 'وضعیت'),
          h('label', { class: 'checkline' },
            h('input', {
              type: 'checkbox', checked: bulkDraft.location.destination.enabled !== false,
              onChange: (e) => { bulkDraft.location.destination.enabled = e.target.checked; },
            }),
            h('span', {}, 'فیلدهای مقصد (استان/شهر) در فرم گروهی نمایش داده شوند'))
        ),
        fieldWrapper('نوع نمایش', h(
          'select',
          { onChange: (e) => { bulkDraft.location.destination.display = e.target.value; } },
          h('option', { value: 'combined', selected: bulkDraft.location.destination.display !== 'separate' }, 'در یک فیلد'),
          h('option', { value: 'separate', selected: bulkDraft.location.destination.display === 'separate' }, 'جدا')
        ), '«در یک فیلد» استان و شهر مقصد را به‌صورت «استان — شهر» در یک کنترل نشان می‌دهد.'),
        fieldWrapper('فیلد استان', destFieldNames.length >= 2 ? h(
          'select',
          { onChange: (e) => { bulkDraft.location.destination.province_field = e.target.value || null; } },
          h('option', { value: '', selected: !bulkDraft.location.destination.province_field }, 'انتخاب کنید'),
          destFieldNames.map((name) => h(
            'option', { value: name, selected: bulkDraft.location.destination.province_field === name }, destFieldLabel(name)
          ))
        ) : h('p', { class: 'hint', text: 'برای جفت مقصد حداقل دو فیلد لازم است.' }),
        'کدام فیلد استان مقصد است. در فرم گروهی با منطق استان (لیست استان‌ها) نمایش داده می‌شود.'),
        fieldWrapper('فیلد شهر', destFieldNames.length >= 2 ? h(
          'select',
          { onChange: (e) => { bulkDraft.location.destination.city_field = e.target.value || null; } },
          h('option', { value: '', selected: !bulkDraft.location.destination.city_field }, 'انتخاب کنید'),
          destFieldNames.map((name) => h(
            'option', { value: name, selected: bulkDraft.location.destination.city_field === name }, destFieldLabel(name)
          ))
        ) : h('p', { class: 'hint', text: 'برای جفت مقصد حداقل دو فیلد لازم است.' }),
        'کدام فیلد شهر مقصد است. در فرم گروهی با منطق شهر (وابسته به استان) نمایش داده می‌شود.')
      )
    ));

  // The shared bulk amount: which field carries it and in which unit it is
  // typed. On submit each provider translates it into its own display
  // unit first, so results keep following each provider's own settings.
  if (!bulkDraft.price || typeof bulkDraft.price !== 'object') {
    bulkDraft.price = { field: null, unit: 'rial' };
  }
  const bulkPriceFieldNames = bulkDraft.fields.filter((f) => f && f.name).map((f) => f.name);
  if (bulkDraft.price.field && !bulkPriceFieldNames.includes(bulkDraft.price.field)) {
    bulkDraft.price.field = null;
  }
  const bulkPriceCard = h(
    'div',
    { class: 'card' },
    h('h2', {}, 'فیلد مبلغ گروهی'),
    h('p', { class: 'muted', style: 'margin-top:0' },
      'کدام فیلد مبلغ را از کاربر می‌گیرد و با چه واحدی؛ موقع استعلام به واحد نمایشی هر شرکت تبدیل و بعد طبق تنظیمات همان شرکت ارسال می‌شود. نمایش مبلغ‌ها طبق تنظیمات هر سرویس‌دهنده است.'),
    h('div', { class: 'grid' },
      fieldWrapper('فیلد مبلغ', h('select', {
        onChange: (e) => { bulkDraft.price.field = e.target.value || null; },
      },
        h('option', { value: '', selected: !bulkDraft.price.field }, 'هیچکدام'),
        bulkPriceFieldNames.map((name) => {
          const found = bulkDraft.fields.find((f) => f && f.name === name) || {};
          return h('option', {
            value: name, selected: bulkDraft.price.field === name,
          }, `${found.label || name} (${name})`);
        })),
        'فیلدی که مبلغ مشترک در آن وارد می‌شود.'),
      fieldWrapper('واحد مبلغ گروهی', h('select', {
        onChange: (e) => { bulkDraft.price.unit = e.target.value === 'toman' ? 'toman' : 'rial'; },
      },
        h('option', { value: 'rial', selected: bulkDraft.price.unit !== 'toman' }, 'ریال'),
        h('option', { value: 'toman', selected: bulkDraft.price.unit === 'toman' }, 'تومان')),
        'واحدی که کاربر مبلغ را با آن وارد می‌کند.'))
  );

  // The shared bulk weight: which field carries it and in which unit it is
  // typed. On submit each provider translates it into its own display
  // weight unit first (1 kg = 1000 g), so results keep following each
  // provider's own settings.
  if (!bulkDraft.weight || typeof bulkDraft.weight !== 'object') {
    bulkDraft.weight = { field: null, unit: 'gram' };
  }
  const bulkWeightFieldNames = bulkDraft.fields.filter((f) => f && f.name).map((f) => f.name);
  if (bulkDraft.weight.field && !bulkWeightFieldNames.includes(bulkDraft.weight.field)) {
    bulkDraft.weight.field = null;
  }
  const bulkWeightCard = h(
    'div',
    { class: 'card' },
    h('h2', {}, 'فیلد وزن گروهی'),
    h('p', { class: 'muted', style: 'margin-top:0' },
      'کدام فیلد وزن را از کاربر می‌گیرد و با چه واحدی؛ موقع استعلام به واحد نمایشی هر شرکت تبدیل و بعد طبق تنظیمات همان شرکت ارسال می‌شود.'),
    h('div', { class: 'grid' },
      fieldWrapper('فیلد وزن', h('select', {
        onChange: (e) => { bulkDraft.weight.field = e.target.value || null; },
      },
        h('option', { value: '', selected: !bulkDraft.weight.field }, 'هیچکدام'),
        bulkWeightFieldNames.map((name) => {
          const found = bulkDraft.fields.find((f) => f && f.name === name) || {};
          return h('option', {
            value: name, selected: bulkDraft.weight.field === name,
          }, `${found.label || name} (${name})`);
        })),
        'فیلدی که وزن مشترک در آن وارد می‌شود.'),
      fieldWrapper('واحد وزن گروهی', h('select', {
        onChange: (e) => { bulkDraft.weight.unit = e.target.value === 'kilogram' ? 'kilogram' : 'gram'; },
      },
        h('option', { value: 'gram', selected: bulkDraft.weight.unit !== 'kilogram' }, 'گرم'),
        h('option', { value: 'kilogram', selected: bulkDraft.weight.unit === 'kilogram' }, 'کیلوگرم')),
        'واحدی که کاربر وزن را با آن وارد می‌کند.'))
  );

  const fieldsPane = h('div', { hidden: bulkStep !== 'fields' },
    bulkPriceCard,
    bulkWeightCard,
    h(
      'div',
      { class: 'card' },
      h(
        'div',
        { class: 'card-head' },
        h('h2', {}, `فیلدهای گروهی (${toFaDigits(String(bulkDraft.fields.length))})`)
      ),
      bulkDraft.fields.length
        ? h(
          'div',
          { class: 'table-scroll' },
          h(
            'table',
            {},
            h('thead', {}, h('tr', {}, h('th', {}, 'عنوان نمایشی'), h('th', {}, 'نوع ورودی'), h('th', {}, 'منبع داده'), h('th', {}, 'اجباری'), h('th', { style: 'width:70px' }, 'عملیات'))),
            h(
              'tbody',
              {},
              bulkDraft.fields.map((field, index) => {
            const sourceRef = field.source && typeof field.source === 'object' ? field.source : null;
            const currentSourcePid = sourceRef ? bulkRefProviderId(sourceRef.provider) : null;
            const currentSourceParam = sourceRef ? sourceRef.param : null;
            const sourceOptions = [h('option', { value: '' }, 'از نگاشت')];
            bulkProviders.forEach((provider) => {
              const scalar = (provider.params || []).filter((param) => !isOriginParam(param.name) && param.visible && param.input !== 'list' && param.input !== 'dict' && param.input !== 'map');
              if (!scalar.length) return;
              sourceOptions.push(h('optgroup', { label: provider.display_name },
                scalar.map((param) => h('option', {
                  value: JSON.stringify({ provider: provider.id, param: param.id ?? param.name }),
                  selected: currentSourcePid === provider.id && currentSourceParam != null
                    && bulkTargetMatches(provider, currentSourceParam, String(param.id ?? param.name)),
                }, `${param.label} (${param.name})`))));
            });
            const sourceSelect = h('select', {
              onChange: (e) => {
                field.source = e.target.value ? JSON.parse(e.target.value) : null;
                if (field.source) {
                  // The source owner routes automatically and configures no
                  // mapping for this field; drop any stale row.
                  const srcProvider = bulkProviderByRef(field.source.provider);
                  if (srcProvider) {
                    [srcProvider.id, srcProvider.name].forEach((key) => {
                      const row = bulkDraft.mappings[key];
                      if (row) delete row[field.name];
                    });
                  }
                }
                renderBulk();
              },
            }, sourceOptions);
            const removeButton = h('button', {
              class: 'btn btn-sm btn-remove',
              type: 'button',
              onClick: () => {
                bulkDraft.fields.splice(index, 1);
                Object.values(bulkDraft.mappings).forEach((row) => { delete row[field.name]; });
                Object.values(bulkDraft.valueMappings).forEach((row) => { delete row[field.name]; });
                if (bulkDraft.price && bulkDraft.price.field === field.name) bulkDraft.price.field = null;
                if (bulkDraft.weight && bulkDraft.weight.field === field.name) bulkDraft.weight.field = null;
                renderBulk();
              },
            }, 'حذف');
            const inputSelect = h('select', {
              onChange: (e) => { field.input = e.target.value; },
            },
              h('option', { value: 'text', selected: field.input !== 'number' }, 'متنی'),
              h('option', { value: 'number', selected: field.input === 'number' }, 'عددی'));
            return h(
              'tr',
              {},
              h('td', {}, field.label),
              h('td', {}, inputSelect),
              h('td', {}, sourceSelect),
              h('td', { style: 'text-align:center' }, h('input', { type: 'checkbox', title: 'اجباری', checked: !!field.required, onChange: (e) => { field.required = e.target.checked; } })),
              h('td', {}, removeButton)
            );
          })
        )
      )
      )
      : h('div', { class: 'empty', style: 'text-align:center' },
        h('div', { class: 'empty-illust' }, icon('pencil')),
        h('p', { style: 'margin:0', text: 'هنوز فیلدی تعریف نشده است؛ از بخش زیر اولین فیلد را بسازید.' })),
      h('details', { class: 'add-field' },
        h('summary', {}, '＋ افزودن فیلد گروهی جدید'),
        h(
          'div',
          { class: 'grid', style: 'margin-top:12px' },
          fieldWrapper('عنوان نمایشی', fieldLabel),
          fieldWrapper('نوع ورودی', fieldInput),
          h(
            'div',
            { class: 'field' },
            h('label', {}, ' '),
            h('button', {
              class: 'btn btn-ghost',
              type: 'button',
              onClick: () => {
                const label = fieldLabel.value.trim();
                if (!label) {
                  toast('عنوان فیلد را وارد کنید.');
                  return;
                }
                const taken = new Set(bulkDraft.fields.map((item) => item.name));
                let counter = bulkDraft.fields.length + 1;
                while (taken.has(`field_${counter}`)) counter += 1;
                bulkDraft.fields.push({ name: `field_${counter}`, label, input: fieldInput.value, source: null, required: false });
                fieldLabel.value = '';
                renderBulk();
              },
            }, 'افزودن فیلد')
          )
        )
      )
    )
  );

  const mapPane = h('div', { hidden: bulkStep !== 'mapping' },
    !selectedProviders.length
      ? h('div', { class: 'card' },
        h('div', { class: 'empty', style: 'text-align:center' },
          h('div', { class: 'empty-illust' }, icon('building')),
          h('p', { style: 'margin:0', text: 'اول در گام «شرکت‌ها» حداقل یک شرکت انتخاب کنید.' })))
      : h('div', {},
        h('div', { class: 'tabs subtabs', role: 'tablist' },
          selectedProviders.map((p) => h('button', {
            class: p.id === bulkMapPid ? 'btn btn-sm' : 'btn btn-sm btn-ghost',
            type: 'button',
            onClick: () => { bulkMapPid = p.id; renderBulk(); },
          }, p.display_name))),
        ...mappingCards)
  );

  const variantAssignedTargets = (provider) => {
    // Canonical id targets: a rename must not free a field for variants
    // that is actually fed by the shared form.
    const assigned = new Set();
    const row = bulkDraft.mappings[provider.id] || bulkDraft.mappings[String(provider.id)] || {};
    Object.values(row).forEach((target) => {
      if (!target && target !== 0) return;
      const canonical = canonicalBulkTarget(provider, target);
      assigned.add(canonical !== null ? canonical : String(target));
    });
    (bulkDraft.fields || []).forEach((field) => {
      if (!field || typeof field !== 'object') return;
      const source = field.source && typeof field.source === 'object' ? field.source : null;
      if (source && bulkRefProviderId(source.provider) === provider.id && (source.param || source.param === 0)) {
        const canonical = canonicalBulkTarget(provider, source.param);
        assigned.add(canonical !== null ? canonical : String(source.param));
      }
      const mirror = field.mirror && typeof field.mirror === 'object' ? field.mirror : null;
      if (mirror && bulkRefProviderId(mirror.provider) === provider.id && (mirror.param || mirror.param === 0)) {
        const canonical = canonicalBulkTarget(provider, mirror.param);
        assigned.add(canonical !== null ? canonical : String(mirror.param));
      }
    });
    return assigned;
  };
  const variantCandidates = (provider) => {
    const assigned = variantAssignedTargets(provider);
    const out = [];
    (provider.params || []).forEach((param) => {
      if (!param || !param.name || isOriginParam(param.name)) return;
      if (param.input === 'map') return;
      if (param.input === 'list' || param.input === 'dict') {
        (param.fields || []).forEach((sub) => {
          if (!sub || !sub.name || sub.input !== 'select') return;
          const options = Array.isArray(sub.options) ? sub.options.filter((o) => o && String(o.value ?? '') !== '') : [];
          if (!options.length) return;
          // Stable id target; the label keeps showing the live names.
          const target = `${param.id ?? param.name}.${sub.id ?? sub.name}`;
          if (assigned.has(target)) return;
          out.push({ target, label: `${param.label || param.name} — ${sub.label || sub.name}`, options });
        });
        return;
      }
      if (param.input !== 'select') return;
      const options = Array.isArray(param.options) ? param.options.filter((o) => o && String(o.value ?? '') !== '') : [];
      if (!options.length) return;
      const scalarTarget = String(param.id ?? param.name);
      if (assigned.has(scalarTarget)) return;
      out.push({ target: scalarTarget, label: `${param.label || param.name} (${param.name})`, options });
    });
    return out;
  };
  const ensureVariantRow = (pid) => {
    if (!bulkDraft.variants[pid] || typeof bulkDraft.variants[pid] !== 'object') bulkDraft.variants[pid] = {};
    return bulkDraft.variants[pid];
  };
  const variantCards = selectedProviders.map((provider) => {
    const readOnly = provider.active === false;
    const candidates = variantCandidates(provider);
    const row = ensureVariantRow(provider.id);
    const comboCount = Object.values(row).reduce((acc, arr) => acc * Math.max(1, (Array.isArray(arr) ? arr.length : 0) || 0), 1);
    const hasAny = Object.values(row).some((arr) => Array.isArray(arr) && arr.length > 0);
    const dropStaleVariant = (candidate, value) => {
      const next = ensureVariantRow(provider.id);
      const current = new Set(Array.isArray(next[candidate.target]) ? next[candidate.target].map(String) : []);
      current.delete(String(value));
      if (current.size) next[candidate.target] = [...current];
      else delete next[candidate.target];
      if (!Object.keys(next).length) delete bulkDraft.variants[provider.id];
      renderBulk();
    };
    const blocks = candidates.map((candidate) => {
      const selected = new Set(Array.isArray(row[candidate.target]) ? row[candidate.target].map(String) : []);
      // Stored values whose option no longer exists (e.g. renamed in the
      // company): no checkbox renders for them, yet they still block every
      // save — surface them here with a remove button.
      const known = new Set(candidate.options.map((option) => String(option.value)));
      const stale = [...selected].filter((value) => !known.has(value));
      const boxes = candidate.options.map((option) => {
        const value = String(option.value);
        const checked = selected.has(value);
        return h('label', { class: readOnly ? 'checkline provider-off' : 'checkline' },
          h('input', {
            type: 'checkbox', checked, disabled: readOnly,
            onChange: (e) => {
              const next = ensureVariantRow(provider.id);
              const current = new Set(Array.isArray(next[candidate.target]) ? next[candidate.target].map(String) : []);
              if (e.target.checked) current.add(value);
              else current.delete(value);
              if (current.size) next[candidate.target] = [...current];
              else delete next[candidate.target];
              if (!Object.keys(next).length) delete bulkDraft.variants[provider.id];
              renderBulk();
            },
          }),
          h('span', {}, `${option.label || value} `, h('span', { class: 'code', text: value })));
      });
      return h('div', { class: 'card', style: 'margin-top:10px' },
        h('h4', { style: 'margin:0 0 8px' }, candidate.label),
        h('div', { class: 'grid' }, boxes),
        stale.length
          ? h('div', { style: 'margin-top:10px' },
            h('p', { class: 'error-text', style: 'margin:0 0 6px', text: 'این مقادیر دیگر در گزینه‌های فیلد نیستند و مانع ذخیره می‌شوند؛ حذفشان کنید:' }),
            stale.map((value) => h('div', { class: 'row', style: 'margin-top:6px' },
              h('span', { class: 'code', text: value }),
              h('button', {
                class: 'btn btn-sm btn-remove', type: 'button', disabled: readOnly || undefined,
                onClick: () => dropStaleVariant(candidate, value),
              }, 'حذف'))))
          : null);
    });
    // Variant rows whose target matches no live candidate anymore (param
    // renamed away or deleted after the save): invisible otherwise, yet
    // blocking every save — surface them with a remove button.
    const coveredTargets = new Set(candidates.map((candidate) => candidate.target));
    const orphanKeys = Object.keys(row).filter((key) => {
      if (!Array.isArray(row[key]) || !row[key].length) return false;
      if (coveredTargets.has(key)) return false;
      return !candidates.some((candidate) => bulkTargetMatches(provider, key, candidate.target));
    });
    const dropVariantKey = (key) => {
      const next = ensureVariantRow(provider.id);
      delete next[key];
      if (!Object.keys(next).length) delete bulkDraft.variants[provider.id];
      renderBulk();
    };
    return h('div', { class: 'card' },
      h('h3', {}, `حالت‌های جایگزین ${provider.display_name}`, readOnly ? h('span', { class: 'pill pill-dim', style: 'margin-right:8px', text: 'غیرفعال — فقط نمایش' }) : null),
      h('p', { class: 'hint', text: 'فقط فیلدهایی که به فرم گروهی نگاشت نشده‌اند اینجا می‌آیند. برای هر فیلد چند گزینه را تیک بزنید تا با همان حالت‌ها (حاصل‌ضرب ترکیب‌ها) استعلام همزمان گرفته شود؛ وقتی فیلدی حالت دارد، مقدار پیش‌فرض آن نادیده گرفته می‌شود.' }),
      candidates.length ? blocks : h('p', { class: 'empty', text: 'فیلد لیستیِ نگاشت‌نشده‌ای برای این شرکت نیست.' }),
      orphanKeys.length
        ? h('div', { class: 'card card-warn', style: 'margin-top:10px' },
          h('p', { class: 'error-text', style: 'margin-top:0', text: 'این حالت‌ها قابل ذخیره نیستند (فیلد حذف شده یا به فرم نگاشت شده)؛ حذفشان کنید:' }),
          orphanKeys.map((key) => h('div', { class: 'row', style: 'margin-top:6px;justify-content:space-between' },
            h('span', { class: 'code', text: resolveBulkTargetName(provider, key) || key }),
            h('button', {
              class: 'btn btn-sm btn-remove', type: 'button', disabled: readOnly || undefined,
              onClick: () => dropVariantKey(key),
            }, 'حذف'))))
        : null,
      hasAny ? h('p', { class: 'hint', text: `با این تنظیم ${toFaDigits(String(comboCount))} استعلام برای این شرکت گرفته می‌شود.` }) : null);
  });
  const variantsPane = h('div', { hidden: bulkStep !== 'variants' },
    !selectedProviders.length
      ? h('div', { class: 'card' }, h('p', { class: 'empty', text: 'اول در گام «شرکت‌ها» حداقل یک شرکت انتخاب کنید.' }))
      : h('div', {},
        h('div', { class: 'tabs subtabs', role: 'tablist' },
          selectedProviders.map((p) => h('button', {
            class: p.id === bulkMapPid ? 'btn btn-sm' : 'btn btn-sm btn-ghost',
            type: 'button',
            onClick: () => { bulkMapPid = p.id; renderBulk(); },
          }, `${p.display_name}${p.active === false ? ' (غیرفعال)' : ''}`))),
        ...variantCards.map((card, i) => { card.hidden = !selectedProviders[i] || selectedProviders[i].id !== bulkMapPid; return card; }),
  ));

  const staleCard = staleNames.length
    ? h(
      'div',
      { class: 'card card-warn' },
      h('h2', {}, 'ارجاع‌های قدیمی'),
      h('p', { class: 'error-text', style: 'margin-top:0' }, 'این نام‌ها در تنظیمات ذخیره شده‌اند ولی چنین شرکتی دیگر وجود ندارد (تغییرنام یا حذف شده). تا حذف نشوند، ذخیره ناموفق می‌ماند.'),
      h('ul', {}, staleNames.map((name) =>
        h('li', { class: 'row', style: 'justify-content:space-between' },
          h('span', { class: 'code', text: name }),
          h('button', {
            class: 'btn btn-sm btn-remove',
            type: 'button',
            onClick: () => {
              bulkDraft.providers = bulkDraft.providers.filter((item) => item !== name);
              delete bulkDraft.mappings[name];
              renderBulk();
            },
          }, 'حذف')))))
    : null;
  clear(bulkView).append(
    h('div', { class: 'admin-hero' },
      h('span', { class: 'admin-hero-icon gold' }, icon('chart')),
      h('div', { class: 'admin-hero-text' },
        h('h1', {}, 'تنظیمات استعلام گروهی'),
        h('p', { class: 'muted', text: 'پنج گام: شرکت‌ها، فیلدهای مشترک، شهر و استان، نگاشت هر شرکت، حالت‌های جایگزین.' }),
        h('div', { class: 'stat-row' },
          h('span', { class: 'stat-mini' }, 'شرکت ', h('b', { text: toFaDigits(`${selectedProviders.length} از ${bulkProviders.length}`) })),
          h('span', { class: 'stat-mini gold' }, 'فیلد ', h('b', { text: toFaDigits(String(bulkDraft.fields.length)) }))))),
    stepTabs,
    companiesPane,
    fieldsPane,
    locationPane,
    mapPane,
    variantsPane,
    h(
      'div',
      { class: 'card row actions-sticky' },
      h('button', { class: 'btn', type: 'button', onClick: saveBulk }, 'ذخیره تنظیمات گروهی'),
      h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => openPreviewWindow('bulk') }, 'پیش‌نمایش')
    ),
    bulkErrorHost
  );
  // Native append() stringifies null to "null", so the warn card is inserted
  // separately only when stale references actually exist.
  if (staleCard) bulkView.insertBefore(staleCard, companiesPane);
  });
}

// The exact save payload, also reused as the live-preview draft body so the
// preview window quotes precisely what ذخیره would persist.
function bulkRequestBody() {
  return {
    providers: [...bulkDraft.providers],
    // kind/mirror are not part of the bulk UI: kind is inferred server-side
    // from each field's mapped provider params, so only the essentials persist.
    fields: bulkDraft.fields.map((field) => ({
      name: field.name,
      label: field.label,
      input: field.input,
      source: field.source && typeof field.source === 'object' ? { ...field.source } : null,
      required: !!field.required,
    })),
    mappings: JSON.parse(JSON.stringify(bulkDraft.mappings)),
    value_mappings: JSON.parse(JSON.stringify(bulkDraft.valueMappings || {})),
    variants: JSON.parse(JSON.stringify(bulkDraft.variants || {})),
    location: JSON.parse(JSON.stringify(bulkDraft.location || {
      origin: { enabled: true, display: 'combined' },
      destination: { enabled: true, display: 'combined' },
    })),
    price: {
      field: (bulkDraft.price && bulkDraft.price.field) || null,
      unit: (bulkDraft.price && bulkDraft.price.unit) === 'toman' ? 'toman' : 'rial',
    },
    weight: {
      field: (bulkDraft.weight && bulkDraft.weight.field) || null,
      unit: (bulkDraft.weight && bulkDraft.weight.unit) === 'kilogram' ? 'kilogram' : 'gram',
    },
  };
}

async function saveBulk() {
  clear(bulkErrorHost);
  const body = bulkRequestBody();
  try {
    const result = await api('/api/admin/bulk', { method: 'PUT', body, ...auth() });
    bulkDraft.providers = [...result.config.providers];
    bulkDraft.fields = result.config.fields.map((field) => ({ ...field }));
    bulkDraft.mappings = JSON.parse(JSON.stringify(result.config.mappings));
    bulkDraft.valueMappings = JSON.parse(JSON.stringify(result.config.value_mappings || {}));
    bulkDraft.variants = JSON.parse(JSON.stringify(result.config.variants || {}));
    bulkDraft.location = normaliseBulkLocationDraft(result.config.location);
    bulkDraft.price = normaliseBulkPriceDraft(result.config.price);
  bulkDraft.weight = normaliseBulkWeightDraft(result.config.weight);
    canonicaliseBulkDraftTargets();
    renderBulk();
    toast('تنظیمات گروهی ذخیره شد.', 'ok');
  } catch (error) {
    const details = (error.errors || []).map((item) => `${item.field}: ${item.message}`).join('\n');
    toast(details ? `${error.message}\n${details}` : error.message);
  }
}

// --------------------------------------------------------------------------- logs


// --- Jalali dates for the logs filter ---
// The API stores Gregorian ISO timestamps; the admin filter speaks Jalali.
// Anchor: 1403/01/01 == 2024-03-20, day arithmetic in UTC.
const JAL_DAY = 86400000;
const JAL_ANCHOR = Date.UTC(2024, 2, 20);
const isJalaliLeap = (jy) => (((jy + 38) * 31) % 128 < 31);
const jalMonthLen = (jy, jm) => (jm <= 6 ? 31 : jm <= 11 ? 30 : isJalaliLeap(jy) ? 30 : 29);
function jalToDayNum(jy, jm, jd) {
  let days = 0;
  if (jy >= 1403) {
    for (let y = 1403; y < jy; y++) days += isJalaliLeap(y) ? 366 : 365;
  } else {
    for (let y = jy; y < 1403; y++) days -= isJalaliLeap(y) ? 366 : 365;
  }
  for (let m = 1; m < jm; m++) days += jalMonthLen(jy, m);
  return days + jd - 1;
}
function dayNumToGreg(days) {
  const date = new Date(JAL_ANCHOR + days * JAL_DAY);
  return { gy: date.getUTCFullYear(), gm: date.getUTCMonth() + 1, gd: date.getUTCDate() };
}
function gregToDayNum(gy, gm, gd) {
  return Math.round((Date.UTC(gy, gm - 1, gd) - JAL_ANCHOR) / JAL_DAY);
}
function dayNumToJalali(days) {
  let jy = 1403;
  let rest = days;
  while (rest < 0) {
    jy -= 1;
    rest += isJalaliLeap(jy) ? 366 : 365;
  }
  while (rest >= (isJalaliLeap(jy) ? 366 : 365)) {
    rest -= isJalaliLeap(jy) ? 366 : 365;
    jy += 1;
  }
  let jm = 1;
  while (rest >= jalMonthLen(jy, jm)) {
    rest -= jalMonthLen(jy, jm);
    jm += 1;
  }
  return { jy, jm, jd: rest + 1 };
}
const jalaliToGregorian = (jy, jm, jd) => dayNumToGreg(jalToDayNum(jy, jm, jd));
const gregorianToJalali = (gy, gm, gd) => dayNumToJalali(gregToDayNum(gy, gm, gd));
const faDigitsToEn = (text) => String(text || '')
  .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
  .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
// Raw Jalali parts without range checks; null when the shape is wrong.
function readJalaliParts(text) {
  const clean = faDigitsToEn(text).trim().replace(/-/g, '/').replace(/\./g, '/');
  const match = /^(\d{4})\/(\d{1,2})\/(\d{1,2})$/.exec(clean);
  if (!match) return null;
  return { jy: Number(match[1]), jm: Number(match[2]), jd: Number(match[3]) };
}
// "1403/07/13" (or with Persian digits, "-" separators) -> "2024-10-04".
// Returns null when the Jalali date is invalid.
function parseJalaliDate(text) {
  const parts = readJalaliParts(text);
  if (!parts) return null;
  const { jy, jm, jd } = parts;
  if (jy < 1300 || jy > 1500 || jm < 1 || jm > 12) return null;
  if (jd < 1 || jd > jalMonthLen(jy, jm)) return null;
  const greg = jalaliToGregorian(jy, jm, jd);
  const pad = (n) => String(n).padStart(2, '0');
  return `${greg.gy}-${pad(greg.gm)}-${pad(greg.gd)}`;
}
// "2024-10-04" -> "1403/07/13" for refilling the filter inputs.
function formatJalaliInput(gregorian) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(gregorian || ''));
  if (!match) return '';
  const jal = gregorianToJalali(Number(match[1]), Number(match[2]), Number(match[3]));
  const pad = (n) => String(n).padStart(2, '0');
  return `${jal.jy}/${pad(jal.jm)}/${pad(jal.jd)}`;
}

const JAL_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
const JAL_WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
const JAL_WEEKDAYS_SHORT = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

let jalaliPop = null;
function closeJalaliCalendar() {
  if (!jalaliPop) return;
  jalaliPop.remove();
  jalaliPop = null;
  document.removeEventListener('mousedown', jalaliOutsideDown, true);
  document.removeEventListener('keydown', jalaliEscapeDown, true);
}
function jalaliOutsideDown(event) {
  if (jalaliPop && !jalaliPop.contains(event.target)
    && !(jalaliPop._anchor && jalaliPop._anchor.contains(event.target))) {
    closeJalaliCalendar();
  }
}
function jalaliEscapeDown(event) {
  if (event.key === 'Escape') closeJalaliCalendar();
}
// Weekday of a Jalali date as 0=Saturday..6=Friday (weeks start on Saturday).
function jalaliWeekday(jy, jm, jd) {
  const greg = jalaliToGregorian(jy, jm, jd);
  return (new Date(Date.UTC(greg.gy, greg.gm - 1, greg.gd)).getUTCDay() + 1) % 7;
}
function jalaliToday() {
  const now = new Date();
  return gregorianToJalali(now.getFullYear(), now.getMonth() + 1, now.getDate());
}
// Shared range calendar for the logs filter: first click picks the start
// date, second click picks the end date. Works on plain backing values;
// onConfirm fires when the user confirms or clears the range.
function openJalaliRangeCalendar(anchor, fromInput, toInput, onConfirm) {
  closeJalaliCalendar();
  const today = jalaliToday();
  const fromParts = readJalaliParts(fromInput.value);
  const validFrom = fromParts && fromParts.jy >= 1300 && fromParts.jy <= 1500
    && fromParts.jm >= 1 && fromParts.jm <= 12
    && fromParts.jd >= 1 && fromParts.jd <= jalMonthLen(fromParts.jy, fromParts.jm);
  let viewJy = validFrom ? fromParts.jy : today.jy;
  let viewJm = validFrom ? fromParts.jm : today.jm;
  // Every opening starts a fresh range pick: first click sets the start.
  let stage = 'from';
  const pop = h('div', { class: 'cal-pop', dir: 'rtl' });
  pop._anchor = anchor;
  const title = h('span', { class: 'cal-title' });
  const hint = h('p', { class: 'cal-hint' });
  const body = h('div', { class: 'cal-grid' });
  const pad = (n) => String(n).padStart(2, '0');
  const key = (jy, jm, jd) => `${jy}/${pad(jm)}/${pad(jd)}`;
  const paintHint = () => {
    hint.textContent = stage === 'from' ? 'تاریخ شروع را انتخاب کنید.' : 'تاریخ پایان را انتخاب کنید.';
  };
  const render = () => {
    title.textContent = `${JAL_MONTHS[viewJm - 1]} ${toFaDigits(String(viewJy))}`;
    paintHint();
    clear(body);
    JAL_WEEKDAYS_SHORT.forEach((short, index) => {
      body.append(h('span', { class: 'cal-dow', title: JAL_WEEKDAYS[index], text: short }));
    });
    const from = readJalaliParts(fromInput.value);
    const to = readJalaliParts(toInput.value);
    const fromKey = from ? key(from.jy, from.jm, from.jd) : null;
    const toKey = to ? key(to.jy, to.jm, to.jd) : null;
    for (let blank = 0; blank < jalaliWeekday(viewJy, viewJm, 1); blank++) {
      body.append(h('span', { class: 'cal-day blank' }));
    }
    for (let day = 1; day <= jalMonthLen(viewJy, viewJm); day++) {
      const dayKey = key(viewJy, viewJm, day);
      const classes = ['cal-day'];
      if (dayKey === fromKey || dayKey === toKey) classes.push('sel');
      else if (fromKey && toKey && dayKey > fromKey && dayKey < toKey) classes.push('in-range');
      if (today.jy === viewJy && today.jm === viewJm && today.jd === day) classes.push('today');
      const pick = day;
      body.append(h('button', {
        type: 'button',
        class: classes.join(' '),
        text: toFaDigits(String(pick)),
        onClick: () => {
          if (stage === 'from' || !fromInput.value.trim()) {
            fromInput.value = key(viewJy, viewJm, pick);
            fromInput.dispatchEvent(new window.Event('change', { bubbles: true }));
            stage = 'to';
          } else {
            const picked = key(viewJy, viewJm, pick);
            if (fromInput.value.trim() && picked < fromInput.value.trim()) {
              fromInput.value = picked;
              fromInput.dispatchEvent(new window.Event('change', { bubbles: true }));
            } else {
              toInput.value = picked;
              toInput.dispatchEvent(new window.Event('change', { bubbles: true }));
              stage = 'from';
            }
          }
          render();
        },
      }));
    }
  };
  const step = (delta) => {
    viewJm += delta;
    if (viewJm < 1) {
      viewJm = 12;
      viewJy -= 1;
    }
    if (viewJm > 12) {
      viewJm = 1;
      viewJy += 1;
    }
    render();
  };
  pop.append(
    h(
      'div',
      { class: 'cal-head' },
      h('button', { class: 'btn btn-sm btn-ghost cal-nav', type: 'button', title: 'ماه قبل', text: '›', onClick: () => step(-1) }),
      title,
      h('button', { class: 'btn btn-sm btn-ghost cal-nav', type: 'button', title: 'ماه بعد', text: '‹', onClick: () => step(1) })
    ),
    hint,
    body,
    h(
      'div',
      { class: 'cal-foot' },
      h('button', {
        class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => {
          viewJy = today.jy;
          viewJm = today.jm;
          render();
        },
      }, 'امروز'),
      h('button', {
        class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => {
          fromInput.value = '';
          toInput.value = '';
          fromInput.dispatchEvent(new window.Event('change', { bubbles: true }));
          if (typeof onConfirm === 'function') onConfirm();
          closeJalaliCalendar();
        },
      }, 'پاک کردن'),
      h('button', {
        class: 'btn btn-sm', type: 'button', onClick: () => {
          if (typeof onConfirm === 'function') onConfirm();
          closeJalaliCalendar();
        },
      }, 'تأیید')
    )
  );
  render();
  document.body.append(pop);
  const rect = anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : { top: 0, bottom: 0, left: 0, right: 0 };
  const width = pop.offsetWidth || 272;
  pop.style.top = `${rect.bottom + 6}px`;
  pop.style.left = `${Math.max(8, rect.right - width)}px`;
  if (rect.bottom + (pop.offsetHeight || 340) + 8 > window.innerHeight
    && rect.top - (pop.offsetHeight || 340) - 8 > 8) {
    pop.style.top = `${rect.top - (pop.offsetHeight || 340) - 8}px`;
  }
  jalaliPop = pop;
  document.addEventListener('mousedown', jalaliOutsideDown, true);
  document.addEventListener('keydown', jalaliEscapeDown, true);
}
// A Jalali date field: plain text input (1403/07/13); picking happens
// through the single shared range calendar below.
function jalaliDateField(gregorian) {
  return h('input', {
    type: 'text', inputmode: 'numeric', dir: 'ltr',
    placeholder: '1403/07/13', value: formatJalaliInput(gregorian),
  });
}

function showLogs() {
  previewProviderBuilder = null;
  stopPreviewSync();
  hide(listView);
  hide(editorView);
  hide(shopsView);
  hide(bulkView);
  hide(securityView);
  if (typeof cacheView !== 'undefined' && cacheView) hide(cacheView);
  show(logsView);
  markTab('logs');
  goAdmin('/admin/logs');
  loadLogs();
}

function formatLogTime(value) {
  if (!value) return '—';
  const date = new Date(typeof value === 'string' && !value.endsWith('Z') && !value.includes('+') ? `${value}Z` : value);
  if (Number.isNaN(date.getTime())) return String(value);
  try {
    return date.toLocaleString('fa-IR', {
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    });
  } catch (error) {
    return date.toLocaleString();
  }
}

function logCard(item) {
  const ok = item.status === 'success';
  const payload = item.payload && typeof item.payload === 'object' ? item.payload : {};
  const rows = Object.entries(payload).map(([key, value]) => h(
    'tr', {}, h('td', {}, h('span', { class: 'code', text: key })), h('td', {}, pretty(value))
  ));
  return h(
    'div',
    { class: ok ? 'card log-ok' : 'card log-err' },
    h(
      'div',
      { class: 'card-head' },
      h(
        'div',
        { style: 'flex:1' },
        h('h2', { text: item.display_name || item.provider_name || '—' }),
        h('p', { class: 'muted', style: 'margin:0' }, formatLogTime(item.created_at)),
        h('p', { style: 'margin:0' }, h('span', { class: 'code', text: item.endpoint || '—' }))
      ),
      h('span', { class: ok ? 'tag tag-ok' : 'tag tag-err', text: ok ? 'موفق' : 'خطا' })
    ),
    item.error_message && h('p', { class: 'error-text', text: item.error_message }),
    h('details', {},
      h('summary', {}, 'پارامترها'),
      rows.length
        ? h('table', { class: 'summary' }, h('tbody', {}, rows))
        : h('p', { class: 'empty', text: 'پارامتری ثبت نشده است.' })),
    h('details', {},
      h('summary', {}, 'درخواست'),
      h('pre', { dir: 'ltr' }, JSON.stringify({ endpoint: item.endpoint || '', body: payload }, null, 2))),
    h('details', {},
      h('summary', {}, 'پاسخ'),
      item.response === null || item.response === undefined
        ? h('p', { class: 'empty', text: 'پاسخی ثبت نشده است.' })
        : h('pre', { dir: 'ltr' }, JSON.stringify(item.response, null, 2)))
  );
}

const logFilter = { provider: '', from: '', to: '' };
let logFilterOpen = false;
let lastLogProviders = [];
let lastLogs = [];
// Client-side search box state (never triggers a rebuild, so typing here
// keeps both focus and scroll exactly where they are).
const logSearch = { text: '' };

async function loadLogs() {
  const restoreScroll = captureScroll();
  closeJalaliCalendar();
  clear(logsHost).append(skeleton(2));
  restoreScroll();
  let providers = [];
  try {
    ({ providers } = await api('/api/admin/providers'));
  } catch (error) {
    providers = [];
  }
  let data;
  const query = new URLSearchParams();
  if (logFilter.provider) query.set('provider', logFilter.provider);
  if (logFilter.from) query.set('from', logFilter.from);
  if (logFilter.to) query.set('to', logFilter.to);
  query.set('limit', '500');
  try {
    data = await api(`/api/admin/logs?${query.toString()}`);
  } catch (error) {
    clear(logsHost).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
    restoreScroll();
    return;
  }
  lastLogProviders = providers || [];
  lastLogs = data.logs || [];
  if (logFilter.provider || logFilter.from || logFilter.to) logFilterOpen = true;
  paintLogs();
  restoreScroll();
}

function paintLogs() {
  closeJalaliCalendar();
  const providers = lastLogProviders;
  const logs = lastLogs;
  const filterActive = Boolean(logFilter.provider || logFilter.from || logFilter.to);
  clear(logsHost);
  const providerSelect = h(
    'select',
    {},
    h('option', { value: '' }, 'همه شرکت‌ها'),
    (providers || []).map((provider) => h(
      'option',
      { value: String(provider.id), selected: logFilter.provider === String(provider.id) },
      `${provider.display_name || provider.name} (${provider.name})`
    ))
  );
  // Date range lives only in the calendar: these backing values are never
  // shown as inputs, the panel only displays the picked range.
  const fromInput = jalaliDateField(logFilter.from);
  const toInput = jalaliDateField(logFilter.to);
  const rangeText = h('div', { class: 'range-value' });
  const paintRange = () => {
    const from = fromInput.value.trim();
    const to = toInput.value.trim();
    rangeText.textContent = from && to
      ? `از ${toFaDigits(from)} تا ${toFaDigits(to)}`
      : from ? `از ${toFaDigits(from)}` : to ? `تا ${toFaDigits(to)}` : 'بازه انتخاب نشده';
    rangeText.classList.toggle('is-empty', !(from || to));
  };
  fromInput.addEventListener('change', paintRange);
  toInput.addEventListener('change', paintRange);
  paintRange();
  const applyRange = () => {
    logFilter.provider = providerSelect.value;
    logFilter.from = parseJalaliDate(fromInput.value.trim()) || '';
    logFilter.to = parseJalaliDate(toInput.value.trim()) || '';
    loadLogs();
  };
  providerSelect.addEventListener('change', () => {
    logFilter.provider = providerSelect.value;
    loadLogs();
  });
  const panel = logFilterOpen
    ? h(
      'div',
      { style: 'margin-top:12px' },
      h(
        'div',
        { class: 'section-grid' },
        fieldWrapper('شرکت', providerSelect, 'فقط خطاهای همین شرکت نمایش داده می‌شود.'),
        fieldWrapper('بازه تاریخ (شمسی)',
          h('div', { class: 'range-box' },
            rangeText,
            h('button', {
              class: 'btn btn-sm btn-ghost', type: 'button', onClick: (event) => {
                openJalaliRangeCalendar(event.currentTarget, fromInput, toInput, applyRange);
              },
            }, icon('calendar'), ' تقویم'))
      )),
      h(
        'div',
        { class: 'row', style: 'margin-top:12px' },
        filterActive
          ? h('button', {
            class: 'btn btn-sm btn-ghost', type: 'button', onClick: () => {
              logFilter.provider = '';
              logFilter.from = '';
              logFilter.to = '';
              loadLogs();
            },
          }, 'حذف فیلتر')
          : null,
        filterActive
          ? h('p', { class: 'muted', style: 'margin:0', text: `فیلتر فعال است؛ ${toFaDigits(String(logs.length))} خطا پیدا شد.` })
          : null
      )
    )
    : null;
  const bar = h(
    'div',
    { class: 'card' },
    h(
      'div',
      { class: 'card-head' },
      h(
        'div',
        { style: 'flex:1' },
        h('h2', {}, `گزارش خطاها (${toFaDigits(String(logs.length))})`),
        h('div', { class: 'stat-row' },
          h('span', { class: 'stat-mini err' }, 'خطا ', h('b', { text: toFaDigits(String(logs.length)) })),
          h('span', { class: 'stat-mini' }, 'حذف خودکار پس از ۷ روز'))
      ),
      h(
        'div',
        { class: 'row' },
        h('button', {
          class: filterActive ? 'btn btn-sm' : 'btn btn-sm btn-ghost', type: 'button',
          onClick: () => { logFilterOpen = !logFilterOpen; preserveScroll(() => paintLogs()); },
        }, 'فیلتر'),
        logs.length ? h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onClick: clearLogs }, 'پاک کردن همه') : null
      )
    ),
    panel,
    logs.length ? logToolbar() : null,
    logs.length ? null : h('div', { class: 'empty', style: 'text-align:center' },
      h('div', { class: 'empty-illust' }, icon('receipt')),
      h('p', { style: 'margin:0', text: filterActive ? 'با این فیلتر خطایی پیدا نشد.' : 'هنوز خطایی ثبت نشده است.' }))
  );
  logsHost.append(bar);
  if (logs.length) logsHost.append(logTable(logs));
}

// Always-visible search box. It filters client-side only (rows hide in
// place), so typing never rebuilds, never steals focus and never moves the
// scroll.
function logToolbar() {
  const searchInput = h('input', {
    type: 'text', placeholder: 'جستجو در شرکت، پیام خطا یا پارامتر…', autocomplete: 'off',
    value: logSearch.text,
    onInput: (e) => { logSearch.text = e.target.value; applyLogView(); },
  });
  return h('div', { class: 'log-toolbar' },
    h('div', { class: 'search-row log-search' }, searchInput),
    h('p', { class: 'muted log-count', style: 'margin:0' }));
}

// One compact row per log; the heavy card (params/request/response) opens
// inline underneath on demand instead of weighing down the whole page.
// Every stored row is a failure, so the table is company + error + exact time.
function logTableRows(logs) {
  return logs.map((item) => {
    const when = h('span', { text: formatLogTime(item.created_at) });
    const message = item.error_message ? String(item.error_message).trim() : '';
    const errorCell = message
      ? h('span', { class: 'log-err-text', title: message, text: message })
      : h('span', { class: 'muted', text: item.endpoint || '—' });
    const haystack = [
      item.display_name, item.provider_name, item.endpoint, item.error_message,
      (() => { try { return JSON.stringify(item.payload); } catch { return ''; } })(),
    ].filter((part) => part !== null && part !== undefined && String(part) !== '').join(' ').toLowerCase();
    const chev = h('span', { class: 'chev', text: '▾' });
    const detailRow = h('tr', { class: 'log-detail', hidden: true },
      h('td', { colspan: 4 }, logCard(item)));
    const toggleBtn = h('button', {
      class: 'btn btn-sm btn-ghost', type: 'button',
      onClick: () => {
        const open = detailRow.hidden;
        detailRow.hidden = !open;
        chev.classList.toggle('open', open);
        toggleBtn.querySelector('.toggle-label').textContent = open ? 'بستن' : 'جزئیات';
      },
    }, chev, ' ', h('span', { class: 'toggle-label', text: 'جزئیات' }));
    const deleteBtn = h('button', {
      class: 'btn btn-sm btn-remove', type: 'button',
      onClick: () => removeLog(item.id),
    }, 'حذف');
    const row = h('tr', { class: 'log-row' },
      h('td', {}, h('span', { text: item.display_name || item.provider_name || '—' }),
        item.provider_name ? h('div', { class: 'muted', style: 'font-size:11.5px' }, h('span', { class: 'code', text: item.provider_name })) : null),
      h('td', { class: 'log-msg-cell' }, errorCell),
      h('td', { class: 'stat-cell' }, when),
      h('td', { class: 'actions' }, toggleBtn, ' ', deleteBtn));
    row.dataset.search = haystack;
    return [row, detailRow];
  });
}

function logTable(logs) {
  const body = h('tbody');
  logTableRows(logs).forEach(([row, detailRow]) => {
    body.append(row, detailRow);
  });
  const emptyRow = h('tr', { class: 'log-empty', hidden: true },
    h('td', { colspan: 4 }, h('p', { class: 'empty', style: 'margin:0', text: 'با این جستجو چیزی پیدا نشد.' })));
  body.append(emptyRow);
  const table = h('div', { class: 'card table-card' },
    h('div', { class: 'table-scroll', style: 'margin:0' },
      h('table', {},
        h('thead', {}, h('tr', {},
          h('th', {}, 'شرکت'),
          h('th', {}, 'پیام خطا'),
          h('th', {}, 'زمان'),
          h('th', {}, 'عملیات'))),
        body)));
  applyLogView(body);
  return table;
}

function applyLogView(scope) {
  const host = scope && scope.querySelectorAll ? scope : document;
  const query = (logSearch.text || '').trim().toLowerCase();
  let visible = 0;
  let total = 0;
  host.querySelectorAll('tr.log-row').forEach((row) => {
    total += 1;
    const show = query === '' || (row.dataset.search || '').includes(query);
    row.hidden = !show;
    const detail = row.nextElementSibling;
    if (detail && detail.classList && detail.classList.contains('log-detail') && !show) {
      detail.hidden = true;
      const chev = row.querySelector('.chev');
      if (chev) chev.classList.remove('open');
      const label = row.querySelector('.toggle-label');
      if (label) label.textContent = 'جزئیات';
    }
    if (show) visible += 1;
  });
  host.querySelectorAll('tr.log-empty').forEach((row) => {
    row.hidden = !(total > 0 && visible === 0);
  });
  document.querySelectorAll('.log-count').forEach((node) => {
    node.textContent = query !== ''
      ? `نمایش ${toFaDigits(String(visible))} از ${toFaDigits(String(total))}`
      : '';
  });
}

async function removeLog(logId) {
  if (!window.confirm('این گزارش حذف شود؟')) return;
  try {
    await api(`/api/admin/logs/${encodeURIComponent(logId)}`, { method: 'DELETE', ...auth() });
  } catch (error) {
    toast(error.message);
    return;
  }
  toast('حذف شد.', 'ok');
  // Reload so header counts stay truthful; search/status/focus survive
  // because the toolbar rebuilds from logSearch and loadLogs keeps scroll.
  loadLogs();
}

async function clearLogs() {
  if (!window.confirm('همه گزارش‌ها حذف شوند؟')) return;
  try {
    await api('/api/admin/logs', { method: 'DELETE', ...auth() });
    toast('گزارش‌ها پاک شدند.', 'ok');
    loadLogs();
  } catch (error) {
    toast(error.message);
  }
}

// --------------------------------------------------------------------------- security


const BLOCK_TYPE_LABELS = { ip: 'آی‌پی', client_id: 'شناسه کاربر', client_fingerprint: 'اثر انگشت' };
const securityFilter = { q: '', type: '', active: '1' };

function showSecurity() {
  previewProviderBuilder = null;
  stopPreviewSync();
  hide(listView);
  hide(editorView);
  hide(shopsView);
  hide(bulkView);
  hide(logsView);
  if (typeof cacheView !== 'undefined' && cacheView) hide(cacheView);
  show(securityView);
  markTab('security');
  goAdmin('/admin/security' + (pendingSecuritySub && pendingSecuritySub !== 'routes' ? `?sub=${encodeURIComponent(pendingSecuritySub)}` : ''));
  loadSecurity();
}

function formatMs(value) {
  if (value === null || value === undefined || value === '') return '—';
  const date = new Date(Number(value));
  if (Number.isNaN(date.getTime())) return String(value);
  try {
    return date.toLocaleString('fa-IR', { dateStyle: 'medium', timeStyle: 'medium' });
  } catch (error) {
    return date.toLocaleString();
  }
}

function splitWindow(totalSeconds) {
  const total = Math.max(1, Math.round(Number(totalSeconds) || 0));
  if (total % 3600 === 0) return { amount: total / 3600, unit: 'hour' };
  if (total % 60 === 0) return { amount: total / 60, unit: 'minute' };
  return { amount: total, unit: 'second' };
}

function toSeconds(amount, unit) {
  const value = Math.max(1, Math.round(Number(amount) || 0));
  if (unit === 'hour') return value * 3600;
  if (unit === 'minute') return value * 60;
  return value;
}

function unitOptions(selected) {
  return [
    h('option', { value: 'second', selected: selected === 'second' }, 'ثانیه'),
    h('option', { value: 'minute', selected: selected === 'minute' }, 'دقیقه'),
    h('option', { value: 'hour', selected: selected === 'hour' }, 'ساعت'),
  ];
}

function windowLabel(totalSeconds) {
  const { amount, unit } = splitWindow(totalSeconds);
  const name = unit === 'hour' ? 'ساعت' : unit === 'minute' ? 'دقیقه' : 'ثانیه';
  return `${amount} ${name}`;
}

const SECURITY_SUBTABS = [
  ['routes', 'محدودیت نرخ'],
  ['limits', 'محدودیت‌های فعال'],
  ['offenders', 'متخلفین تکراری'],
  ['blocks', 'مسدودشده‌ها'],
  ['events', 'رویدادها'],
  ['allowlist', 'لیست سفید'],
];

function showSecuritySub(name) {
  [...document.querySelectorAll('#security-view [data-subtab]')].forEach((tab) => {
    tab.classList.toggle('btn-ghost', tab.dataset.subtab !== name);
  });
  [...document.querySelectorAll('#security-view [data-subsection]')].forEach((section) => {
    section.hidden = section.dataset.subsection !== name;
  });
  const url = name === 'routes' ? '/admin/security' : `/admin/security?sub=${encodeURIComponent(name)}`;
  if (routeLocation().pathname + routeLocation().search !== url) {
    navigate(url, true);
  }
}

function loadSecurity() {
  return preserveScrollAsync(async () => {
  clear(securityHost).append(skeleton(2));
  clear(securityHost);
  const subnav = h(
    'nav',
    { class: 'tabs subtabs' },
    SECURITY_SUBTABS.map(([name, label], index) => h(
      'button',
      {
        class: index === 0 ? 'btn' : 'btn btn-ghost',
        type: 'button',
        'data-subtab': name,
        onClick: () => showSecuritySub(name),
      },
      label
    ))
  );
  const sections = {};
  SECURITY_SUBTABS.forEach(([name]) => {
    sections[name] = h('div', { 'data-subsection': name, hidden: name !== SECURITY_SUBTABS[0][0] });
    securityHost.append(sections[name]);
  });
  securityHost.prepend(subnav);
  sections.routes.append(securityRoutesCard());
  sections.limits.append(securityDenialsCard());
  sections.offenders.append(securityOffendersCard());
  sections.blocks.append(securityBlocksCard());
  sections.events.append(securityEventsCard());
  sections.allowlist.append(securityAllowlistCard());
  loadRouteLimits();
  loadDenials();
  loadOffenders();
  loadBlocks();
  loadSecEvents();
  loadAllowlist();
  showSecuritySub(SECURITY_SUBTABS.some(([sub]) => sub === pendingSecuritySub) ? pendingSecuritySub : 'routes');
  pendingSecuritySub = 'routes';
  });
}

function shortHash(value) {
  const text = String(value || '');
  return text ? `${text.slice(0, 12)}…` : '—';
}

const SECURITY_KIND_LABELS = {
  rate_limit_exceeded: 'محدودیت نرخ', blocked_attempt: 'تلاش مسدودشده',
  invalid_client_signal: 'سیگنال نامعتبر', malformed_request: 'درخواست خراب',
  suspicious_pattern: 'الگوی مشکوک', payload_too_large: 'حجم زیاد',
  admin_block: 'مسدودسازی', admin_unblock: 'رفع مسدودیت',
};

function securityCardHead(iconName, tone, title, desc, action) {
  return h('div', { class: 'card-head sec-head' },
    h('span', { class: `section-icon${tone ? ` ${tone}` : ''}` }, icon(iconName)),
    h('div', { style: 'flex:1;min-width:0' },
      h('h3', { class: 'card-title', style: 'margin:0;border:0;padding:0' }, title),
      h('p', { class: 'muted', style: 'margin:4px 0 0' }, desc)),
    action || null);
}

const ROUTE_SOURCE_LABEL = { custom: 'اختصاصی', off: 'بدون محدودیت' };

function securityRoutesCard() {
  return h(
    'div',
    { class: 'card' },
    securityCardHead('chart', 'blue', 'محدودیت نرخ',
      'سقف هر API جداست و شمارش هر مسیر مستقل است؛ شلوغی یک مسیر، مسیر دیگر را محدود نمی‌کند. با «بدون محدودیت» می‌توانید سقف یک API را کاملاً بردارید و با «بازگشت به پیش‌فرض» حد اختصاصی را حذف کنید.'),
    h('div', { id: 'security-routes' }),
    h('div', { id: 'security-routes-admin' })
  );
}

function securityRouteRow(entry) {
  const guarded = entry.guarded !== false;
  const unlimited = entry.unlimited === true;
  const sourcePill = entry.source === 'custom' ? 'pill-ok'
    : entry.source === 'off' ? 'pill-off' : null;
  const current = unlimited
    ? 'نامحدود'
    : (entry.max_requests == null
      ? 'بدون محدودیت'
      : `${entry.max_requests} در هر ${windowLabel(entry.window_seconds)}`);
  const maxInput = h('input', { type: 'number', min: 1, value: entry.max_requests || '', disabled: !guarded || unlimited, dir: 'ltr', style: 'width:90px' });
  const split = splitWindow(entry.window_seconds || 60);
  const amountInput = h('input', { type: 'number', min: 1, value: split.amount, disabled: !guarded || unlimited, dir: 'ltr', style: 'width:70px' });
  const unitSelect = h('select', { disabled: !guarded || unlimited, style: 'width:95px' }, ...unitOptions(split.unit));
  const offInput = h('input', { type: 'checkbox', checked: unlimited, disabled: !guarded });
  offInput.addEventListener('change', () => {
    const off = offInput.checked;
    maxInput.disabled = !guarded || off;
    amountInput.disabled = !guarded || off;
    unitSelect.disabled = !guarded || off;
  });
  const save = async () => {
    try {
      const off = offInput.checked;
      await api('/api/admin/security/route-limits', {
        method: 'PUT',
        body: {
          route: entry.route,
          max_requests: Number(maxInput.value),
          window_seconds: toSeconds(amountInput.value, unitSelect.value),
          enabled: !off,
        },
        ...auth(),
      });
      toast(off ? `«${entry.title}» بدون محدودیت شد.` : `حد «${entry.title}» ذخیره شد.`, 'ok');
      loadRouteLimits();
    } catch (error) {
      toast(error.message);
    }
  };
  const resetDefault = async () => {
    try {
      await api(`/api/admin/security/route-limits?route=${encodeURIComponent(entry.route)}`, { method: 'DELETE', ...auth() });
      toast(`«${entry.title}» به حد پیش‌فرض برگشت.`, 'ok');
      loadRouteLimits();
    } catch (error) {
      toast(error.message);
    }
  };
  return h(
    'tr',
    {},
    h('td', {},
      h('div', { style: 'font-weight:700' }, entry.title),
      h('div', {},
        h('span', { class: 'code', text: entry.route }),
        ' ',
        ...(entry.methods || []).map((method) => h('span', { class: 'pill pill-dim', style: 'margin-inline-start:4px' }, method))),
      entry.description ? h('div', { class: 'muted', style: 'font-size:12px' }, entry.description) : null),
    h('td', { class: 'stat-cell' },
      h('div', {}, current),
      sourcePill ? h('span', { class: `pill ${sourcePill}` }, ROUTE_SOURCE_LABEL[entry.source]) : null),
    guarded ? h('td', {}, maxInput) : h('td', { class: 'muted' }, '—'),
    guarded
      ? h('td', {}, h('div', { class: 'row', style: 'flex-wrap:nowrap' }, amountInput, unitSelect))
      : h('td', { class: 'muted' }, '—'),
    guarded
      ? h('td', { class: 'actions' },
        h('label', { class: 'checkline route-unlimited' }, offInput, h('span', {}, 'بدون محدودیت')),
        h('div', { class: 'row', style: 'margin-top:6px' },
          h('button', { class: 'btn btn-sm', type: 'button', onClick: save }, 'ذخیره'),
          entry.custom
            ? h('button', { class: 'btn btn-sm btn-ghost', type: 'button', style: 'margin-inline-start:6px', onClick: resetDefault }, 'بازگشت به پیش‌فرض')
            : null))
      : h('td', { class: 'muted' }, '—')
  );
}

function loadRouteLimits() {
  return preserveScrollAsync(async () => {
  const host = securityHost.querySelector('#security-routes');
  const adminHost = securityHost.querySelector('#security-routes-admin');
  if (!host) return;
  clear(host).append(skeleton(1));
  if (adminHost) clear(adminHost);
  let routes = [];
  let adminGroups = [];
  try {
    const data = await api('/api/admin/security/route-limits');
    routes = (data && data.routes) || [];
    adminGroups = (data && data.admin_groups) || [];
  } catch (error) {
    clear(host).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(host);
  const customCount = routes.filter((item) => item.custom && !item.unlimited).length;
  const offCount = routes.filter((item) => item.unlimited).length;
  const parts = [`${routes.length} API عمومی`];
  if (customCount) parts.push(`${customCount} حد اختصاصی`);
  if (offCount) parts.push(`${offCount} بدون محدودیت`);
  host.append(h('p', { class: 'muted', style: 'margin:0 0 10px' },
    routes.length ? parts.join(' · ') : 'مسیری یافت نشد.'));
  if (!routes.length) return;
  host.append(h(
    'div',
    { class: 'table-scroll' },
    h('table', {},
      h('thead', {}, h('tr', {},
        h('th', {}, 'API'), h('th', {}, 'سقف فعلی'),
        h('th', {}, 'تعداد'), h('th', {}, 'بازه'), h('th', {}, 'عملیات'))),
      h('tbody', {}, routes.map((entry) => securityRouteRow(entry))))
  ));
  if (adminHost && adminGroups.length) {
    adminHost.append(h(
      'details',
      { style: 'margin-top:14px' },
      h('summary', {}, `APIهای ادمین (${adminGroups.length} گروه) — محافظت با توکن، بدون محدودیت نرخ`),
      h('div', { class: 'table-scroll' },
        h('table', {},
          h('thead', {}, h('tr', {},
            h('th', {}, 'گروه'), h('th', {}, 'مسیرها'), h('th', {}, 'توضیح'))),
          h('tbody', {}, adminGroups.map((group) => h(
            'tr',
            {},
            h('td', { style: 'font-weight:700;white-space:nowrap' }, group.title),
            h('td', {}, h('span', { class: 'code', text: `${group.prefix}…` })),
            h('td', { class: 'muted' }, group.description))))))));
  }
  });
}

const securityLimitSearch = { q: '' };

const securityAllowlistSearch = { q: '' };

function securityAllowlistCard() {
  const searchInput = h('input', {
    type: 'text', placeholder: 'جستجو با آی‌پی…', value: securityAllowlistSearch.q,
    onInput: (e) => { securityAllowlistSearch.q = e.target.value; },
    onChange: () => loadAllowlist(),
  });
  const ipInput = h('input', { type: 'text', placeholder: '127.0.0.1', dir: 'ltr', style: 'text-align:left' });
  const reasonInput = h('input', { type: 'text', placeholder: 'دلیل (اختیاری)…' });
  const add = async () => {
    try {
      await api('/api/admin/security/allowlist', {
        method: 'POST',
        body: { ip: ipInput.value, reason: reasonInput.value },
        ...auth(),
      });
      toast('به لیست مجاز اضافه شد؛ هیچ محدودیتی برای این آی‌پی اعمال نمی‌شود.', 'ok');
      loadAllowlist();
    } catch (error) {
      toast(error.message);
    }
  };
  return h(
    'div',
    { class: 'card' },
    securityCardHead('check', 'green', 'آی‌پی‌های مجاز (لیست سفید)',
      'این آی‌پی‌ها از همه بررسی‌ها معاف‌اند: نه محدودیت نرخ، نه بلاک. فقط آی‌پی دقیق (IPv4/IPv6) پذیرفته می‌شود.'),
    h('div', { class: 'grid', style: 'margin-bottom:12px' },
      fieldWrapper('جستجو با آی‌پی', searchInput)),
    h('div', { id: 'security-allowlist' }),
    h('div', { class: 'grid', style: 'margin-top:12px' },
      fieldWrapper('آی‌پی', ipInput),
      fieldWrapper('دلیل', reasonInput)),
    h('div', { class: 'row', style: 'margin-top:12px' },
      h('button', { class: 'btn', type: 'button', onClick: add }, 'افزودن به لیست سفید'))
  );
}

function loadAllowlist() {
  return preserveScrollAsync(async () => {
  const host = securityHost.querySelector('#security-allowlist');
  if (!host) return;
  clear(host).append(skeleton(1));
  let entries = [];
  try {
    const params = new URLSearchParams();
    if (securityAllowlistSearch.q.trim()) params.set('q', securityAllowlistSearch.q.trim());
    params.set('limit', '100');
    const data = await api(`/api/admin/security/allowlist?${params.toString()}`);
    entries = data.allowlist || [];
  } catch (error) {
    clear(host).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(host);
  if (!entries.length) {
    host.append(h('p', { class: 'empty', text: 'لیست سفید خالی است.' }));
    return;
  }
  const remove = async (id) => {
    if (!window.confirm('این آی‌پی از لیست سفید حذف شود؟')) return;
    try {
      await api(`/api/admin/security/allowlist/${id}`, { method: 'DELETE', ...auth() });
      toast('حذف شد.', 'ok');
      loadAllowlist();
    } catch (error) {
      toast(error.message);
    }
  };
  host.append(h(
    'div',
    { class: 'table-scroll' },
    h('table', {},
      h('thead', {}, h('tr', {}, h('th', {}, 'آی‌پی'), h('th', {}, 'دلیل'), h('th', {}, 'ایجاد'), h('th', {}, 'عملیات'))),
      h('tbody', {}, entries.map((entry) => h(
        'tr',
        {},
        h('td', {}, h('span', { class: 'code', text: entry.ip })),
        h('td', {}, entry.reason || '—'),
        h('td', { class: 'stat-cell' }, formatMs(entry.created_at_ms)),
        h('td', { class: 'actions' },
          h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: () => remove(entry.id) }, 'حذف'))
      ))))
  ));
  });
}

function securityDenialsCard() {
  const searchInput = h('input', {
    type: 'text', placeholder: 'جستجو با آی‌پی…', value: securityLimitSearch.q,
    onInput: (e) => { securityLimitSearch.q = e.target.value; },
    onChange: () => loadDenials(),
  });
  const resetAll = async () => {
    if (!window.confirm('محدودیت نرخ همه کاربران برداشته شود؟')) return;
    try {
      await api('/api/admin/security/rate-limits?all=1', { method: 'DELETE', ...auth() });
      toast('محدودیت همه برداشته شد.', 'ok');
      loadDenials();
    } catch (error) {
      toast(error.message);
    }
  };
  return h(
    'div',
    { class: 'card' },
    securityCardHead('clock', '', 'محدودیت‌های فعال',
      'چه کسی همین حالا پشت سقف است؛ با پایان پنجره خودکار آزاد می‌شود. «رفع محدودیت» شمارش را صفر می‌کند و «مسدود کردن» به بلاک‌لیست می‌برد.',
      h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onClick: resetAll }, 'رفع محدودیت همه')),
    h('div', { class: 'grid', style: 'margin-bottom:12px' },
      fieldWrapper('جستجو با آی‌پی', searchInput)),
    h('div', { id: 'security-denials' })
  );
}

function loadDenials() {
  return preserveScrollAsync(async () => {
  const host = securityHost.querySelector('#security-denials');
  if (!host) return;
  clear(host).append(skeleton(1));
  const params = new URLSearchParams();
  if (securityLimitSearch.q.trim()) params.set('q', securityLimitSearch.q.trim());
  params.set('limit', '100');
  let limits = [];
  let windowText = '';
  try {
    const data = await api(`/api/admin/security/rate-limits?${params.toString()}`);
    limits = data.limits || [];
    if (data.max_requests && data.window_seconds) {
      windowText = `${data.max_requests} درخواست در هر ${windowLabel(data.window_seconds)}`;
    }
  } catch (error) {
    clear(host).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(host);
  host.append(h('p', { class: 'muted', style: 'margin:0 0 10px' },
    limits.length ? `${limits.length} محدودیت فعال (${windowText})` : 'محدودیتی فعال نیست.'));
  if (!limits.length) return;
  host.append(h(
    'div',
    { class: 'table-scroll' },
    h('table', {},
      h('thead', {}, h('tr', {},
        h('th', {}, 'آی‌پی'), h('th', {}, 'شناسه کاربر'), h('th', {}, 'اثر انگشت'),
        h('th', {}, 'ابعاد'), h('th', {}, 'شروع محدودیت'), h('th', {}, 'اتمام محدودیت'),
        h('th', {}, 'مسیر'), h('th', {}, 'سقف'), h('th', {}, 'عملیات'))),
      h('tbody', {}, limits.map((item) => securityDenialRow(item))))
  ));
  });
}

const DIMENSION_LABELS = { ip: 'آی‌پی', client: 'شناسه', fp: 'اثر', ip_client: 'ترکیبی' };

function securityDenialRow(item) {
  const target = item.client_id
    ? { type: 'client_id', label: 'شناسه کاربر', value: item.client_id }
    : item.fingerprint
      ? { type: 'client_fingerprint', label: 'اثر انگشت', value: item.fingerprint }
      : { type: 'ip', label: 'آی‌پی', value: item.ip };
  const block = async () => {    if (!target.value) {
      toast('شناسه‌ای برای مسدود کردن وجود ندارد.');
      return;
    }
    if (!window.confirm(`این ${target.label} مسدود شود؟\n${target.value}`)) return;
    try {
      await api('/api/admin/security/blocks', {
        method: 'POST',
        body: { type: target.type, value: target.value, reason: 'مسدودسازی از لیست محدودیت' },
        ...auth(),
      });
      toast('مسدود شد.', 'ok');
      loadBlocks();
    } catch (error) {
      toast(error.message);
    }
  };
  const reset = async () => {
    const params = new URLSearchParams();
    if (item.ip) params.set('ip', item.ip);
    if (item.client_id) params.set('client_id', item.client_id);
    if (item.fingerprint) params.set('fingerprint', item.fingerprint);
    if (item.route) params.set('route', item.route);
    try {
      await api(`/api/admin/security/rate-limits?${params.toString()}`, { method: 'DELETE', ...auth() });
      toast('محدودیت این مورد برداشته شد.', 'ok');
      loadDenials();
    } catch (error) {
      toast(error.message);
    }
  };
  return h(
    'tr',
    {},
    h('td', {}, h('span', { class: 'code', text: item.ip || '—' })),
    h('td', {}, h('span', { class: 'code', title: item.client_id || '', text: shortHash(item.client_id) })),
    h('td', {}, h('span', { class: 'code', title: item.fingerprint || '', text: shortHash(item.fingerprint) })),
    h('td', { class: 'stat-cell' },
      h('span', { class: 'tag', text: (item.dimensions || []).map((d) => DIMENSION_LABELS[d] || d).join('، ') || '—' })),
    h('td', { class: 'stat-cell' }, formatMs(item.started_at_ms)),
    h('td', { class: 'stat-cell' }, formatMs(item.ends_at_ms)),
    h('td', {}, h('span', { class: 'code', text: item.route || '—' })),
    h('td', { class: 'stat-cell' }, item.max_requests ? `${item.max_requests} در هر ${windowLabel(item.window_seconds)}` : '—'),
    h('td', { class: 'actions' },
      h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: reset }, 'رفع محدودیت'),
      ' ',
      h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: block }, 'مسدود کردن'))
  );
}

const securityOffenderFilter = { min: 5, q: '' };

function securityOffendersCard() {
  const minInput = h('input', {
    type: 'number', min: 1, value: securityOffenderFilter.min,
    onChange: (e) => {
      securityOffenderFilter.min = Math.max(1, Math.round(Number(e.target.value) || 1));
      loadOffenders();
    },
  });
  const searchInput = h('input', {
    type: 'text', placeholder: 'جستجو با آی‌پی…', value: securityOffenderFilter.q,
    onInput: (e) => { securityOffenderFilter.q = e.target.value; },
    onChange: () => loadOffenders(),
  });
  return h(
    'div',
    { class: 'card' },
    securityCardHead('eye', '', 'متخلفین تکراری',
      'کسانی که در چند بازه جداگانه سقف را رد کرده‌اند — کاندیدای بلاک دائمی. «بلاک دائمی» مستقیم به بلاک‌لیست اضافه‌شان می‌کند.'),
    h('div', { class: 'grid', style: 'margin-bottom:12px' },
      fieldWrapper('حداقل تعداد بازه', minInput),
      fieldWrapper('جستجو با آی‌پی', searchInput)),
    h('div', { id: 'security-offenders' })
  );
}

function loadOffenders() {
  return preserveScrollAsync(async () => {
  const host = securityHost.querySelector('#security-offenders');
  if (!host) return;
  clear(host).append(skeleton(1));
  const params = new URLSearchParams();
  params.set('min', String(securityOffenderFilter.min));
  if (securityOffenderFilter.q.trim()) params.set('q', securityOffenderFilter.q.trim());
  params.set('limit', '100');
  let offenders = [];
  try {
    const data = await api(`/api/admin/security/repeat-offenders?${params.toString()}`);
    offenders = data.offenders || [];
  } catch (error) {
    clear(host).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(host);
  if (!offenders.length) {
    host.append(h('p', { class: 'empty', text: 'متخلف تکراری یافت نشد.' }));
    return;
  }
  host.append(h(
    'div',
    { class: 'table-scroll' },
    h('table', {},
      h('thead', {}, h('tr', {},
        h('th', {}, 'آی‌پی'), h('th', {}, 'شناسه کاربر'), h('th', {}, 'اثر انگشت'),
        h('th', {}, 'بازه‌ها'), h('th', {}, 'تلاش‌ها'), h('th', {}, 'اولین'), h('th', {}, 'آخرین'), h('th', {}, 'عملیات'))),
      h('tbody', {}, offenders.map((item) => securityOffenderRow(item))))
  ));
  });
}

function securityOffenderRow(item) {
  const target = item.client_id
    ? { type: 'client_id', label: 'شناسه کاربر', value: item.client_id }
    : item.fingerprint
      ? { type: 'client_fingerprint', label: 'اثر انگشت', value: item.fingerprint }
      : { type: 'ip', label: 'آی‌پی', value: item.ip };
  const block = async () => {
    if (!target.value) {
      toast('شناسه‌ای برای مسدود کردن وجود ندارد.');
      return;
    }
    if (!window.confirm(`این ${target.label} برای همیشه مسدود شود؟\n${target.value}`)) return;
    try {
      await api('/api/admin/security/blocks', {
        method: 'POST',
        body: { type: target.type, value: target.value, reason: `تکرار محدودیت در ${item.windows} بازه` },
        ...auth(),
      });
      toast('مسدود شد.', 'ok');
      loadBlocks();
    } catch (error) {
      toast(error.message);
    }
  };
  return h(
    'tr',
    {},
    h('td', {}, h('span', { class: 'code', text: item.ip || '—' })),
    h('td', {}, h('span', { class: 'code', title: item.client_id || '', text: shortHash(item.client_id) })),
    h('td', {}, h('span', { class: 'code', title: item.fingerprint || '', text: shortHash(item.fingerprint) })),
    h('td', {}, h('span', { class: 'tag tag-err', text: String(item.windows) })),
    h('td', { class: 'stat-cell' }, String(item.hits)),
    h('td', { class: 'stat-cell' }, formatMs(item.first_at_ms)),
    h('td', { class: 'stat-cell' }, formatMs(item.last_at_ms)),
    h('td', { class: 'actions' },
      h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: block }, 'بلاک دائمی'))
  );
}

const securityEventsFilter = { kind: '', q: '' };

function securityEventsCard() {
  const kindSelect = h('select', { onChange: (e) => { securityEventsFilter.kind = e.target.value; loadSecEvents(); } },
    h('option', { value: '' }, 'همه نوع‌ها'),
    ...Object.entries(SECURITY_KIND_LABELS).map(([value, label]) =>
      h('option', { value, selected: securityEventsFilter.kind === value }, label)));
  const searchInput = h('input', {
    type: 'text', placeholder: 'آی‌پی، شناسه یا اثر دقیق…', value: securityEventsFilter.q,
    onInput: (e) => { securityEventsFilter.q = e.target.value; },
    onChange: () => loadSecEvents(),
  });
  return h(
    'div',
    { class: 'card' },
    securityCardHead('receipt', '', 'رویدادها',
      'تازه‌ترین اتفاقات امنیتی همه APIها: رد سقف، تلاش مسدودشده، الگوی مشکوک و اقدام‌های ادمین. جستجو فقط با مقدار دقیق جواب می‌دهد.'),
    h('div', { class: 'grid', style: 'margin-bottom:12px' },
      fieldWrapper('نوع رویداد', kindSelect),
      fieldWrapper('جستجو', searchInput)),
    h('div', { id: 'security-events' })
  );
}

function loadSecEvents() {
  return preserveScrollAsync(async () => {
  const host = securityHost.querySelector('#security-events');
  if (!host) return;
  clear(host).append(skeleton(1));
  let events = [];
  try {
    const params = new URLSearchParams();
    if (securityEventsFilter.kind) params.set('kind', securityEventsFilter.kind);
    if (securityEventsFilter.q.trim()) params.set('value', securityEventsFilter.q.trim());
    params.set('limit', '100');
    const data = await api(`/api/admin/security/events?${params.toString()}`);
    events = data.events || [];
  } catch (error) {
    clear(host).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(host);
  host.append(h('p', { class: 'muted', style: 'margin:0 0 10px' },
    events.length ? `${events.length} رویداد اخیر` : 'رویدادی با این فیلتر نیست.'));
  if (!events.length) return;
  host.append(h(
    'div',
    { class: 'table-scroll' },
    h('table', {},
      h('thead', {}, h('tr', {},
        h('th', {}, 'نوع'), h('th', {}, 'مسیر'), h('th', {}, 'آی‌پی'),
        h('th', {}, 'شناسه'), h('th', {}, 'زمان'), h('th', {}, 'جزئیات'))),
      h('tbody', {}, events.map((item) => h(
        'tr',
        {},
        h('td', {}, h('span', { class: 'tag', text: SECURITY_KIND_LABELS[item.kind] || item.kind })),
        h('td', {}, h('span', { class: 'code', text: item.route || '—' })),
        h('td', {}, h('span', { class: 'code', text: item.ip || '—' })),
        h('td', {}, h('span', {
          class: 'code',
          title: item.client_id || item.client_fingerprint || '',
          text: shortHash(item.client_id || item.client_fingerprint),
        })),
        h('td', { class: 'stat-cell' }, formatMs(item.created_at_ms)),
        h('td', { class: 'muted' }, item.detail || '—')))))
  ));
  });
}

function securityBlocksCard() {
  const searchInput = h('input', {
    type: 'text', placeholder: 'جستجو در مقدار، دلیل یا سازنده…', value: securityFilter.q,
    onInput: (e) => { securityFilter.q = e.target.value; },
    onChange: () => loadBlocks(),
  });
  const typeSelect = h('select', { onChange: (e) => { securityFilter.type = e.target.value; loadBlocks(); } },
    h('option', { value: '' }, 'همه نوع‌ها'),
    h('option', { value: 'ip', selected: securityFilter.type === 'ip' }, 'آی‌پی'),
    h('option', { value: 'client_id', selected: securityFilter.type === 'client_id' }, 'شناسه کاربر'),
    h('option', { value: 'client_fingerprint', selected: securityFilter.type === 'client_fingerprint' }, 'اثر انگشت'));
  const activeSelect = h('select', { onChange: (e) => { securityFilter.active = e.target.value; loadBlocks(); } },
    h('option', { value: '1', selected: securityFilter.active === '1' }, 'فعال'),
    h('option', { value: '0', selected: securityFilter.active === '0' }, 'غیرفعال/منقضی'),
    h('option', { value: '', selected: securityFilter.active === '' }, 'همه'));
  const newType = h('select', {},
    h('option', { value: 'ip' }, 'آی‌پی'),
    h('option', { value: 'client_id' }, 'شناسه کاربر'),
    h('option', { value: 'client_fingerprint' }, 'اثر انگشت'));
  const newValue = h('input', { type: 'text', placeholder: 'مقدار…' });
  const newReason = h('input', { type: 'text', placeholder: 'دلیل (اختیاری)…' });
  const newAmount = h('input', { type: 'number', min: 1, placeholder: 'مدت…', style: 'flex:2;min-width:0' });
  const newUnit = h('select', { style: 'flex:1;min-width:0' }, ...unitOptions('hour'));
  const add = async () => {
    const amount = newAmount.value === '' ? '' : Number(newAmount.value);
    let expiresAt = null;
    if (amount !== '') expiresAt = Date.now() + toSeconds(amount, newUnit.value) * 1000;
    try {
      await api('/api/admin/security/blocks', {
        method: 'POST',
        body: {
          type: newType.value, value: newValue.value,
          reason: newReason.value, expires_at_ms: expiresAt,
        },
        ...auth(),
      });
      toast('مسدود شد.', 'ok');
      loadBlocks();
    } catch (error) {
      toast(error.message);
    }
  };
  return h(
    'div',
    { class: 'card' },
    securityCardHead('shield', '', 'کاربران مسدودشده',
      'این شناسه‌ها با خطای 403 جواب می‌گیرند؛ ترتیب بررسی: اول آی‌پی، بعد شناسه کاربر، بعد اثر انگشت. «درخواست‌های اخیر» هر ردیف، ایونت‌های همان مقدار را نشان می‌دهد.'),
    h('div', { class: 'grid' },
      fieldWrapper('جستجو', searchInput),
      fieldWrapper('نوع', typeSelect),
      fieldWrapper('وضعیت', activeSelect)),
    h('div', { id: 'security-blocks' }),
    h('h3', { style: 'margin-top:18px' }, 'مسدود کردن جدید'),
    h('p', { class: 'muted', style: 'margin-top:0' }, 'نوع و مقدار دقیق را وارد کنید؛ «مدت» خالی یعنی بلاک دائمی. بلاک فعال بلافاصله با خطای 403 جواب می‌دهد.'),
    h('div', { class: 'grid' },
      fieldWrapper('نوع', newType),
      fieldWrapper('مقدار', newValue),
      fieldWrapper('دلیل', newReason),
      h('div', { class: 'field' },
        h('label', {}, 'مدت مسدودیت'),
        h('div', { class: 'row' }, newAmount, newUnit))),
    h('div', { class: 'row', style: 'margin-top:12px' },
      h('button', { class: 'btn', type: 'button', onClick: add }, 'مسدود کردن'))
  );
}

function loadBlocks() {
  return preserveScrollAsync(async () => {
  const host = securityHost.querySelector('#security-blocks');
  if (!host) return;
  clear(host).append(skeleton(1));
  const params = new URLSearchParams();
  if (securityFilter.q) params.set('q', securityFilter.q);
  if (securityFilter.type) params.set('type', securityFilter.type);
  params.set('limit', '100');
  let blocks = [];
  try {
    const data = await api(`/api/admin/security/blocks?${params.toString()}`);
    blocks = data.blocks || [];
  } catch (error) {
    clear(host).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(host);
  const now = Date.now();
  const isLive = (entry) => entry.is_active
    && (entry.expires_at_ms === null || entry.expires_at_ms === undefined || Number(entry.expires_at_ms) > now);
  const liveCount = blocks.filter(isLive).length;
  const shown = securityFilter.active === '1'
    ? blocks.filter(isLive)
    : securityFilter.active === '0'
      ? blocks.filter((entry) => !isLive(entry))
      : blocks;
  host.append(h(
    'p',
    { class: 'muted', style: 'margin:0 0 10px' },
    `${blocks.length} مورد یافت شد (${liveCount} فعال)`
  ));
  if (!shown.length) {
    host.append(h('p', { class: 'empty', text: blocks.length
      ? 'با این فیلتر موردی نیست؛ فیلتر «وضعیت» را عوض کنید.'
      : 'هنوز کسی مسدود نشده است.' }));
    return;
  }
  host.append(h(
    'div',
    { class: 'table-scroll' },
    h('table', {},
      h('thead', {}, h('tr', {},
        h('th', {}, 'نوع'), h('th', {}, 'مقدار'), h('th', {}, 'دلیل'),
        h('th', {}, 'ایجاد'), h('th', {}, 'انقضا'), h('th', {}, 'وضعیت'), h('th', {}, 'عملیات'))),
      h('tbody', {}, shown.map((entry) => securityBlockRow(entry))))
  ));
  });
}

function securityBlockRow(entry) {
  const expired = entry.expires_at_ms !== null && entry.expires_at_ms !== undefined
    && Number(entry.expires_at_ms) <= Date.now();
  const statusTag = !entry.is_active
    ? h('span', { class: 'tag tag-err', text: 'غیرفعال' })
    : expired
      ? h('span', { class: 'tag', text: 'منقضی‌شده' })
      : h('span', { class: 'tag tag-ok', text: 'فعال' });
  const requestsBox = h('div', {});
  const details = h('details', {},
    h('summary', {}, 'درخواست‌های اخیر'),
    requestsBox);
  details.addEventListener('toggle', () => {
    if (details.open && !requestsBox.dataset.loaded) {
      requestsBox.dataset.loaded = '1';
      loadBlockRequests(entry.value, requestsBox);
    }
  }, { once: false });
  const toggle = async () => {
    try {
      await api(`/api/admin/security/blocks/${entry.id}`, {
        method: 'PUT', body: { is_active: !entry.is_active }, ...auth(),
      });
      toast(entry.is_active ? 'از بلاک خارج شد.' : 'مسدود شد.', 'ok');
      loadBlocks();
    } catch (error) {
      toast(error.message);
    }
  };
  const remove = async () => {
    if (!window.confirm('این رکورد حذف شود؟')) return;
    try {
      await api(`/api/admin/security/blocks/${entry.id}`, { method: 'DELETE', ...auth() });
      toast('حذف شد.', 'ok');
      loadBlocks();
    } catch (error) {
      toast(error.message);
    }
  };
  return [
    h(
      'tr',
      {},
      h('td', {}, BLOCK_TYPE_LABELS[entry.type] || entry.type),
      h('td', {}, h('span', { class: 'code', text: entry.value })),
      h('td', {}, entry.reason || '—'),
      h('td', { class: 'stat-cell' }, formatMs(entry.created_at_ms)),
      h('td', { class: 'stat-cell' }, entry.expires_at_ms ? formatMs(entry.expires_at_ms) : 'دائمی'),
      h('td', {}, statusTag),
      h('td', { class: 'actions' },
        h('button', { class: 'btn btn-sm btn-ghost', type: 'button', onClick: toggle },
          entry.is_active ? 'خارج کردن از بلاک' : 'مسدود کردن'),
        ' ',
        h('button', { class: 'btn btn-sm btn-remove', type: 'button', onClick: remove }, 'حذف'))
    ),
    h('tr', {}, h('td', { colspan: '7' }, details)),
  ];
}

async function loadBlockRequests(value, box) {
  clear(box).append(h('p', { class: 'spinner', text: 'در حال بارگذاری…' }));
  let events = [];
  try {
    const data = await api(`/api/admin/security/events?value=${encodeURIComponent(value)}&limit=20`);
    events = data.events || [];
  } catch (error) {
    clear(box).append(h('p', { class: 'error-text', text: error.message }));
    return;
  }
  clear(box);
  if (!events.length) {
    box.append(h('p', { class: 'empty', text: 'درخواستی برای این مقدار ثبت نشده است.' }));
    return;
  }
  box.append(h('table', { class: 'summary' }, h('tbody', {},
    events.map((item) => h('tr', {},
      h('td', {}, SECURITY_KIND_LABELS[item.kind] || item.kind),
      h('td', {}, h('span', { class: 'code', text: item.route || '—' })),
      h('td', {}, h('span', { class: 'code', text: item.ip || '—' })),
      h('td', { class: 'stat-cell' }, formatMs(item.created_at_ms)),
      h('td', {}, item.detail || '—'))))));
}

// --------------------------------------------------------------------------- cache

function showCache() {
  previewProviderBuilder = null;
  stopPreviewSync();
  hide(listView);
  hide(editorView);
  hide(shopsView);
  hide(bulkView);
  hide(logsView);
  hide(securityView);
  if (typeof cacheView !== 'undefined' && cacheView) show(cacheView);
  markTab('cache');
  goAdmin('/admin/cache');
  loadCache();
}

function formatCacheDuration(totalSeconds) {
  const total = Math.max(0, Math.round(Number(totalSeconds) || 0));
  if (total >= 86400 && total % 86400 === 0) return toFaDigits(`${total / 86400} روز`);
  if (total >= 3600 && total % 3600 === 0) return toFaDigits(`${total / 3600} ساعت`);
  if (total >= 60 && total % 60 === 0) return toFaDigits(`${total / 60} دقیقه`);
  return toFaDigits(`${total} ثانیه`);
}

function loadCache() {
  return preserveScrollAsync(async () => {
    if (!cacheHost) return;
    clear(cacheHost).append(skeleton(3));
    const defaults = { price: 86400, price_retention: 864000, locations: 21600, quote_logs: 604800, security_events: 2592000 };
    let settings = { ...defaults };
    let stats = {};
    let locState = {};
    try {
      const data = await api('/api/admin/cache', auth());
      if (data) {
        if (data.settings) settings = { ...defaults, ...data.settings };
        if (data.defaults) for (const k of Object.keys(defaults)) if (data.defaults[k] != null) defaults[k] = Number(data.defaults[k]) || defaults[k];
        if (data.stats) stats = data.stats;
        if (data.locations) locState = data.locations;
      }
    } catch (error) {
      clear(cacheHost).append(h('div', { class: 'card' }, h('p', { class: 'error-text', text: error.message })));
      return;
    }

    const inputs = {
      price: h('input', { type: 'number', min: 1, step: 1, inputmode: 'numeric', value: settings.price }),
      price_retention: h('input', { type: 'number', min: 1, step: 1, inputmode: 'numeric', value: settings.price_retention }),
      locations: h('input', { type: 'number', min: 1, step: 1, inputmode: 'numeric', value: settings.locations }),
      quote_logs: h('input', { type: 'number', min: 1, step: 1, inputmode: 'numeric', value: settings.quote_logs }),
    };

    const save = async () => {
      const body = {};
      for (const key of Object.keys(inputs)) body[key] = Number(inputs[key].value);
      try {
        await api('/api/admin/cache', { method: 'PUT', body, ...auth() });
        toast('مدت‌زمان کش‌ها ذخیره شد.', 'ok');
        loadCache();
      } catch (error) {
        toast(error.message);
      }
    };

    const clearKind = async (kind, label) => {
      if (!window.confirm(`«${label}» حذف شود؟`)) return;
      try {
        await api(`/api/admin/cache/clear?kind=${encodeURIComponent(kind)}`, { method: 'DELETE', ...auth() });
        toast(`«${label}» پاک شد.`, 'ok');
        loadCache();
      } catch (error) {
        toast(error.message);
      }
    };

    const numField = (label, key) => {
      const input = inputs[key];
      const def = defaults[key];
      const hint = h('div', { class: 'hint cache-hint' });
      const sync = () => {
        const val = Math.round(Number(input.value) || 0);
        if (val < 1) {
          hint.classList.add('is-bad');
          hint.textContent = 'مقدار باید حداقل ۱ ثانیه باشد.';
        } else {
          hint.classList.remove('is-bad');
          const eq = formatCacheDuration(val);
          const df = formatCacheDuration(def);
          hint.textContent = eq === df ? `معادل ${eq} · برابر پیش‌فرض` : `معادل ${eq} · پیش‌فرض ${df}`;
        }
      };
      input.addEventListener('input', sync);
      sync();
      return h('div', { class: 'field' }, h('label', { text: label }), input, hint);
    };

    const statChip = (iconName, label, value, kind) =>
      h('span', { class: `stat-mini${kind ? ` ${kind}` : ''}` },
        icon(iconName),
        h('span', { text: label }),
        h('b', { text: value }));

    const tile = ({ iconName, tone, title, code, desc, badge, badgeKind, fields, help, clearKind: kind, clearLabel }) =>
      h('div', { class: `card cache-tile${tone ? ` ${tone}` : ''}` },
        h('div', { class: 'cache-tile-head' },
          h('span', { class: `cache-tile-icon${tone ? ` ${tone}` : ''}` }, icon(iconName)),
          h('div', { class: 'cache-tile-title' },
            h('h3', {}, title),
            h('span', { class: 'cache-code', text: code })),
          badge != null && h('span', { class: `pill${badgeKind ? ` ${badgeKind}` : ''}` }, badge)),
        h('p', { class: 'cache-tile-desc', text: desc }),
        h('div', { class: 'cache-tile-fields' }, fields),
        help || null,
        h('div', { class: 'cache-tile-foot' },
          h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onClick: () => clearKind(kind, clearLabel) },
            icon('trash'), `پاک‌کردن ${clearLabel}`)));

    const helpBox = (...paragraphs) =>
      h('details', { class: 'cache-help' },
        h('summary', {}, 'این کش چطور کار می‌کند؟'),
        h('div', { class: 'cache-help-body' }, paragraphs.map((text) => h('p', {}, text))));

    const faCount = (value) => (value === '—' ? '—' : toFaDigits(String(value)));
    const priceRows = stats.price_rows ?? stats.price ?? '—';
    const logsRows = stats.quote_logs_rows ?? stats.logs ?? '—';
    const locCached = Boolean(locState && locState.cached);
    const locDesc = locCached
      ? `در حافظه${locState.city_count != null ? ` — ${toFaDigits(String(locState.city_count))} شهر` : ''}`
      : 'خالی است';

    clear(cacheHost).append(
      h('div', { class: 'card cache-hero' },
        h('div', { class: 'cache-hero-top' },
          h('span', { class: 'cache-hero-icon' }, icon('database')),
          h('div', { class: 'cache-hero-text' },
            h('h2', {}, 'وضعیت و تنظیمات'),
            h('p', { class: 'muted' }, 'عدد هر فیلد به ثانیه است؛ پس از تغییر مقادیر، «ذخیره همه» را بزنید. طرز کار دقیق هر بخش را از «این کش چطور کار می‌کند؟» بخوانید.')),
          h('div', { class: 'cache-hero-actions' },
            h('button', { class: 'btn', type: 'button', onClick: save },
              icon('check'), 'ذخیره همه'),
            h('button', { class: 'btn btn-ghost', type: 'button', onClick: () => loadCache() },
              icon('refresh'), 'بازخوانی'),
            h('button', { class: 'btn btn-remove', type: 'button', onClick: () => clearKind('all', 'همه کش‌ها و لاگ‌ها') },
              icon('trash'), 'حذف همه'))),
        h('div', { class: 'stat-row cache-stats' },
          statChip('coins', 'کش قیمت', `${faCount(priceRows)} ردیف`),
          statChip('pin', 'استان و شهر', locDesc, locCached ? 'ok' : 'err'),
          statChip('receipt', 'لاگ استعلام', `${faCount(logsRows)} ردیف`)),
        ),
      h('div', { class: 'cache-grid' },
        tile({
          iconName: 'coins', tone: 'tone-gold', title: 'کش قیمت', code: 'tapin_price_cache',
          desc: 'قیمت‌های محاسبه‌شده در دیتابیس نگه داشته می‌شوند تا استعلام تکراری سریع و بدون کال زنده پاسخ بگیرد.',
          badge: `${faCount(priceRows)} ردیف`,
          fields: [numField('تازه — بدون کال زنده', 'price'), numField('نگهداری — جایگزین هنگام خطا', 'price_retention')],
          help: helpBox(
            'حالت تازه: تا پایان این پنجره، قیمت مستقیم از کش خوانده می‌شود و هیچ درخواستی به تاپین نمی‌رود.',
            'پایان تازه: با استعلام بعدی، قیمت به‌صورت زنده گرفته و جایگزین کش می‌شود.',
            'حالت نگهداری: اگر گرفتن قیمت زنده با خطا مواجه شود (مثل خطای ۵۰۴)، تا پایان این پنجره از آخرین قیمت ذخیره‌شده استفاده می‌شود؛ قدیمی‌تر از آن دور ریخته می‌شود و خطا به کاربر نمایش داده می‌شود.',
            'دکمه پاک‌کردن، کل جدول کش قیمت را خالی می‌کند؛ استعلام بعدی حتماً به‌صورت زنده محاسبه می‌شود.'),
          clearKind: 'price', clearLabel: 'کش قیمت',
        }),
        tile({
          iconName: 'pin', tone: 'tone-blue', title: 'کش استان و شهر', code: 'حافظه RAM',
          desc: 'لیست استان و شهر تاپین در حافظه نگه داشته می‌شود تا فرم‌ها سریع ساخته شوند. حالت استیل این کش دائمی است و تنظیمی ندارد.',
          badge: locDesc, badgeKind: locCached ? 'pill-ok' : 'pill-dim',
          fields: [
            numField('تازه — بدون رفرش', 'locations'),
            h('p', { class: 'hint notice' }, 'استیل دائمی: هر وقت رفرش لیست با خطا مواجه شود — هرچقدر هم از آخرین رفرش موفق گذشته باشد — تا موفقیت رفرش بعدی از همین کش استفاده می‌شود.'),
          ],
          help: helpBox(
            'حالت تازه: تا پایان این پنجره، برای لیست شهر و استان هیچ درخواستی به تاپین نمی‌رود.',
            'پایان تازه: با ساخته‌شدن فرم بعدی، لیست از سرور تاپین رفرش و جایگزین کش می‌شود.',
            'استیل دائمی: اگر رفرش ناموفق باشد (قطعی، خطای سرور، یا نبود اعتبار فروشگاه)، همان آخرین لیست معتبر — بدون هیچ محدودیت زمانی — نمایش داده می‌شود تا وقتی که یک رفرش موفق انجام شود.',
            'دکمه پاک‌کردن، حافظه را خالی می‌کند؛ فرم بعدی تلاش می‌کند لیست را مستقیم از سرور بگیرد.'),
          clearKind: 'locations', clearLabel: 'کش استان و شهر',
        }),
        tile({
          iconName: 'receipt', tone: 'tone-green', title: 'لاگ استعلام‌ها', code: 'tapin_quote_logs',
          desc: 'تاریخچه استعلام‌های ثبت‌شده در دیتابیس؛ برای بررسی و حسابرسی نگه داشته می‌شود و ربطی به سرعت پاسخ ندارد.',
          badge: `${faCount(logsRows)} ردیف`,
          fields: [numField('پنجره نگهداری لاگ', 'quote_logs')],
          help: helpBox(
            'پاک‌سازی خودکار: روی هر خواندن یا نوشتن، ردیف‌های قدیمی‌تر از این پنجره حذف می‌شوند.',
            'دکمه پاک‌کردن، کل تاریخچه را یکجا و بدون بازگشت حذف می‌کند.'),
          clearKind: 'quote_logs', clearLabel: 'لاگ استعلام‌ها',
        }))
    );
  });
}

// --------------------------------------------------------------------------- routing

// Every admin section has its own URL: /admin/providers (list),
// /admin/providers/<id|new> (editor), /admin/shops, /admin/bulk,
// /admin/logs, /admin/cache and /admin/security (with ?sub= for security sub-tabs).
const ADMIN_TAB_URL = {
  providers: '/admin/providers',
  shops: '/admin/shops',
  bulk: '/admin/bulk',
  logs: '/admin/logs',
  cache: '/admin/cache',
  security: '/admin/security',
};

let pendingSecuritySub = 'routes';

function goAdmin(url) {
  if (routeLocation().pathname + routeLocation().search !== url) {
    navigate(url);
  }
}

function routeAdmin() {
  const parts = routeLocation().pathname.replace(/\/+$/, '').split('/').filter(Boolean);
  const rest = parts[0] === 'admin' ? parts.slice(1) : [];
  if (rest[0] === 'providers' && rest[1]) {
    openEditor(rest[1] === 'new' ? null : decodeURIComponent(rest[1]));
    return;
  }
  const view = ADMIN_TAB_URL[rest[0]] ? rest[0] : 'providers';
  pendingSecuritySub = view === 'security'
    ? (new URLSearchParams(routeLocation().search).get('sub') || 'routes')
    : 'routes';
  if (view === 'shops') showShops();
  else if (view === 'bulk') showBulk();
  else if (view === 'logs') showLogs();
  else if (view === 'cache') showCache();
  else if (view === 'security') showSecurity();
  else showList();
}

window.addEventListener('popstate', routeAdmin);
routeAdmin();
