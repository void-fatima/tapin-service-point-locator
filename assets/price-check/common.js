import { request } from './wordpress.js';
// Shared helpers for the static frontend. No framework, no build step.

export async function api(path, { method = 'GET', body } = {}) {
  const headers = {};
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await request(path, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const text = await response.text();
  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = { detail: text };
    }
  }
  if (!response.ok) {
    const error = new Error((data && data.detail) || `خطای ${response.status}`);
    error.status = response.status;
    error.errors = (data && data.errors) || [];
    error.data = data;
    throw error;
  }
  return data;
}

const BOOLEAN_PROPS = new Set(['hidden', 'disabled', 'checked', 'readonly', 'selected', 'multiple', 'open']);

export function h(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = value;
    else if (key === 'html') node.innerHTML = value;
    else if (key === 'value' && (tag === 'textarea' || tag === 'select')) node.value = value;
    else if (key.startsWith('on') && typeof value === 'function') {
      node.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (BOOLEAN_PROPS.has(key)) {
      node[key] = value === '' ? true : Boolean(value);
    } else if (value === true) node.setAttribute(key, '');
    else node.setAttribute(key, value);
  }
  if (tag === 'button' && node.getAttribute('type') === null) node.type = 'button';
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child.nodeType ? child : document.createTextNode(String(child)));
  }
  return node;
}

export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

// Rebuilding a host (clear + append) momentarily collapses the page height,
// so the browser clamps the scroll back toward the top. Capture the position
// before the rebuild and restore it right after: the restore runs
// synchronously in the same task, so a deliberate scrollTo issued by the
// caller afterwards still wins.
export function captureScroll() {
  let top = 0;
  let left = 0;
  try {
    top = window.scrollY || document.documentElement.scrollTop || 0;
    left = window.scrollX || document.documentElement.scrollLeft || 0;
  } catch {
    top = 0;
    left = 0;
  }
  return () => {
    try {
      if (typeof window !== 'undefined' && typeof window.scrollTo === 'function') {
        window.scrollTo(left, top);
      }
    } catch {
      /* headless test DOM without scrolling */
    }
  };
}

export function preserveScroll(fn) {
  const restore = captureScroll();
  const out = fn();
  restore();
  return out;
}

// Same idea for async loaders (skeleton first, real rows after the fetch):
// the synchronous prefix restores immediately, and the settled render
// restores again. None of these loaders' callers scrolls deliberately, so
// there is nothing to fight with; a caller that DOES want to move the scroll
// must chain after the returned promise.
export function preserveScrollAsync(fn) {
  const restore = captureScroll();
  let out;
  try {
    out = fn();
  } catch (error) {
    restore();
    throw error;
  }
  restore();
  if (out && typeof out.then === 'function') {
    return out.then(
      (value) => { restore(); return value; },
      (error) => { restore(); throw error; }
    );
  }
  return out;
}

export function show(node) {
  node.hidden = false;
}

export function hide(node) {
  node.hidden = true;
}

export function toast(message, kind = 'error') {
  const host = document.getElementById('toast');
  clear(host);
  host.append(h('div', { class: `toast ${kind}`, text: message }));
  host.hidden = true;
  requestAnimationFrame(() => {
    host.hidden = false;
    const box = host.firstElementChild;
    if (box) box.classList.add('in');
  });
  window.clearTimeout(toast.timer);
  toast.timer = window.setTimeout(() => {
    const box = host.firstElementChild;
    if (box) box.classList.remove('in');
    window.setTimeout(() => {
      if (!host.firstElementChild || host.firstElementChild === box) host.hidden = true;
    }, 250);
  }, 4000);
}

let fieldSeq = 0;

export function fieldWrapper(label, control, hint) {
  if (
    control
    && typeof Element !== 'undefined'
    && control instanceof Element
    && ['INPUT', 'SELECT', 'TEXTAREA'].includes(control.tagName)
    && !control.id
  ) {
    control.id = `field-${++fieldSeq}`;
  }
  const labelNode = h('label', { text: label });
  if (control && control.id) labelNode.htmlFor = control.id;
  return h('div', { class: 'field' }, labelNode, control, hint && h('div', { class: 'hint', text: hint }));
}

const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

export function toFaDigits(text) {
  return String(text).replace(/[0-9]/g, (d) => FA_DIGITS[Number(d)]);
}

export function formatNumber(value) {
  if (typeof value !== 'number' || !Number.isFinite(value)) return null;
  const negative = value < 0;
  const [integers, fraction] = String(Math.abs(value)).split('.');
  const grouped = integers.replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
  const out = fraction === undefined ? grouped : `${grouped}٫${fraction}`;
  return (negative ? '−' : '') + toFaDigits(out);
}

export function pretty(value) {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'number' && Number.isFinite(value)) return formatNumber(value);
  return String(value);
}

const FA_IN = '۰۱۲۳۴۵۶۷۸۹';
const AR_IN = '٠١٢٣٤٥٦٧٨٩';

function normalizeNumericText(text) {
  // Persian/Arabic digits and separators back to plain Latin for submit/parse.
  return String(text)
    .replace(/[۰-۹]/g, (d) => String(FA_IN.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String(AR_IN.indexOf(d)))
    .replace(/[٬,]/g, '')
    .replace(/٫/g, '.')
    .replace(/−/g, '-');
}

function formatNumericText(text, float) {
  const latin = normalizeNumericText(text);
  const negative = latin.trim().startsWith('-');
  let cleaned = latin.replace(/[^0-9.]/g, '');
  if (!float) {
    cleaned = cleaned.replace(/\./g, '');
  } else {
    const dot = cleaned.indexOf('.');
    if (dot !== -1) cleaned = cleaned.slice(0, dot + 1) + cleaned.slice(dot + 1).replace(/\./g, '');
  }
  if (!cleaned) return '';
  const [head, tail] = cleaned.split('.');
  const grouped = head.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const assembled = (negative && grouped !== '' ? '-' : '') + grouped + (tail === undefined ? '' : `.${tail}`);
  return toFaDigits(assembled).replace(/,/g, '٬').replace(/\./g, '٫').replace(/-/g, '−');
}

const DIGIT_RE = /[0-9۰-۹٠-٩]/;

function reformatNumeric(node, float) {
  const caret = node.selectionStart ?? node.value.length;
  const digitsBefore = node.value.slice(0, caret).replace(new RegExp(`[^${DIGIT_RE.source}]`, 'g'), '').length;
  node.value = formatNumericText(node.value, float);
  let seen = 0;
  let pos = 0;
  while (pos < node.value.length && seen < digitsBefore) {
    if (DIGIT_RE.test(node.value[pos])) seen += 1;
    pos += 1;
  }
  node.selectionStart = node.selectionEnd = pos;
}

export function numericInput(name, { float = false, value = '' } = {}) {
  const text = value === null || value === undefined ? '' : String(value);
  return h('input', {
    type: 'text',
    inputmode: 'numeric',
    autocomplete: 'off',
    spellcheck: 'false',
    name,
    'data-numeric': float ? 'float' : 'int',
    value: text === '' ? '' : formatNumericText(text, float),
    onInput: (event) => reformatNumeric(event.target, float),
  });
}

export function stripNumericCommas(root) {
  const touched = [];
  root.querySelectorAll('input[data-numeric]').forEach((node) => {
    const normalized = normalizeNumericText(node.value);
    if (normalized !== node.value) {
      touched.push([node, node.value]);
      node.value = normalized;
    }
  });
  return () => touched.forEach(([node, text]) => {
    if (document.contains(node)) node.value = text;
  });
}

export function isBlank(value) {
  return value === null || value === undefined || String(value).trim() === '';
}

// --------------------------------------------------------------------------- shared builders

// Public forms never render a placeholder option: without an explicit
// choice the select stays genuinely empty (selectedIndex -1) instead of
// showing the first real option as chosen.
export function clearSelection(select) {
  try {
    select.selectedIndex = -1;
  } catch { /* noop */ }
  if (select && typeof select.reapplySearch === 'function') {
    try {
      select.reapplySearch();
    } catch { /* noop */ }
  }
  return select;
}

export function selectControl(name, items, { selectedValue = '', disabled = false, onChange = null } = {}) {
  const select = h('select', { name, disabled: disabled || undefined });
  let matched = false;
  (items || []).forEach((item) => {
    const node = h('option', { value: item.value }, item.label);
    if (!isBlank(selectedValue) && String(selectedValue) === String(item.value)) {
      node.selected = true;
      matched = true;
    }
    select.append(node);
  });
  if (disabled || !(items || []).length) select.disabled = true;
  if (!matched) clearSelection(select);
  if (typeof onChange === 'function') select.addEventListener('change', onChange);
  return select;
}

export function searchableSelect(select, { placeholder = 'جستجو…' } = {}) {
  // A custom dropdown around the native select: the face shows the current
  // choice, the opened panel starts with a focused search box, and picking
  // an option writes straight back to the native select (so population,
  // change listeners, tests and form submission keep working untouched).
  const face = h('button', { type: 'button', class: 'combo-face' },
    h('span', { class: 'combo-label', text: '' }),
    h('span', { class: 'combo-chevron' }));
  const search = h('input', { type: 'text', class: 'combo-search', placeholder, autocomplete: 'off' });
  const list = h('div', { class: 'combo-list', role: 'listbox' });
  const panel = h('div', { class: 'combo-panel' }, search, list);
  const wrap = h('div', { class: 'search-select combo' }, face, select, panel);
  select.classList.add('combo-native');

  let activeIndex = -1;
  const syncFace = () => {
    const selected = select.selectedOptions && select.selectedOptions[0];
    face.querySelector('.combo-label').textContent = selected ? selected.text : '';
    face.classList.toggle('disabled', select.disabled);
    face.disabled = select.disabled;
  };
  // Combined city/province labels render with a dash ("تهران - تهران");
  // the search ignores dashes and spacing, so "تهران تهران" and
  // "تهران - تهران" both match the same option.
  const normalizeSearchText = (text) => String(text || '')
    .replace(/[‐‑‒–—―−-]/g, '')
    .replace(/\s+/g, '');
  const filterList = () => {
    const query = normalizeSearchText(search.value);
    [...list.children].forEach((item) => {
      item.hidden = !(query === '' || (item.dataset.search || '').includes(query));
    });
  };
  const close = (refocus = false) => {
    wrap.classList.remove('open');
    if (refocus) face.focus();
  };
  const choose = (value) => {
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    syncFace();
    close();
  };
  const buildList = () => {
    clear(list);
    [...select.options].forEach((option) => {
      const item = h('button', {
        type: 'button',
        class: 'combo-opt' + (option.selected ? ' active' : ''),
        onClick: () => choose(option.value),
      }, option.text);
      item.dataset.value = option.value;
      item.dataset.search = normalizeSearchText(option.text);
      list.append(item);
    });
    activeIndex = -1;
    filterList();
  };
  const open = () => {
    if (select.disabled) return;
    buildList();
    search.value = '';
    filterList();
    wrap.classList.add('open');
    search.focus();
  };
  face.addEventListener('click', () => {
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
    const visible = [...list.children].filter((item) => !item.hidden);
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = event.key === 'ArrowDown'
        ? Math.min(activeIndex + 1, visible.length - 1)
        : Math.max(activeIndex - 1, 0);
      visible.forEach((item, index) => item.classList.toggle('focused', index === activeIndex));
      try {
        if (visible[activeIndex]) visible[activeIndex].scrollIntoView({ block: 'nearest' });
      } catch (error) { /* older engines */ }
    } else if (event.key === 'Enter') {
      const picked = (activeIndex >= 0 && visible[activeIndex]) || visible[0];
      if (picked) {
        event.preventDefault();
        choose(picked.dataset.value);
      }
    }
  });
  document.addEventListener('click', (event) => {
    if (!wrap.contains(event.target)) close();
  });
  select.addEventListener('change', syncFace);
  select.reapplySearch = syncFace;
  syncFace();
  return wrap;
}

export function errorList(errors) {
  if (!errors || !errors.length) return null;
  return h('ul', {}, errors.map((item) => h('li', { class: 'error-text' }, `${item.field}: ${item.message}`)));
}

export function errorCard(title, message, errors) {
  return h(
    'div',
    { class: 'card' },
    h('h2', { style: 'color:var(--danger)' }, title),
    h('p', { class: 'error-text', style: 'margin-top:0', text: message }),
    errorList(errors)
  );
}

export function priceTable(prices) {
  return h('table', {}, h('tbody', {}, (prices || []).map((price) => h('tr', {}, h('td', {}, price.label), h('td', {}, pretty(price.value))))));
}

export function candidateTable(candidates) {
  return h('table', {}, h('tbody', {}, (candidates || []).map((row) => h('tr', {}, h('td', {}, row.key), h('td', {}, pretty(row.value))))));
}

export function spinner(text = 'در حال بارگذاری…') {
  return h('p', { class: 'spinner', text });
}

// Brand line-art icons: rounded 2px stroke, neutral by default — the
// container colors them (accent/navy/ok). Single source for both pages.
const ICON_PATHS = {
  box: '<path d="M12 14.9 4.3 11.6 4.3 19.2 12 22.7 19.7 19.2 19.7 11.6Z"/><path d="M12 14.9V22.7"/><path d="M12 5.4 4 8.6 12 11.9 20 8.6Z"/><path d="M4 8.6V11.4L12 14.6 20 11.4V8.6"/><path d="M12 11.9V14.6"/><polygon points="6.2,14.5 8.9,15.6 8.9,16.7 6.2,15.6"/><line x1="16" y1="18.1" x2="18" y2="17.3"/><line x1="16" y1="19.4" x2="18" y2="18.6"/>',
  pin: '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
  receipt: '<path d="M6 3h12v18l-2-1.4-2 1.4-2-1.4L10 21l-2-1.4L6 21V3z"/><path d="M9 8h6M9 12h6"/>',
  archive: '<rect x="3" y="5" width="18" height="5" rx="1.5"/><path d="M5 10v9a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19v-9M10 14h4"/>',
  coins: '<ellipse cx="12" cy="7" rx="7" ry="3"/><path d="M5 7v10c0 1.7 3.1 3 7 3s7-1.3 7-3V7"/><path d="M5 12c0 1.7 3.1 3 7 3s7-1.3 7-3"/>',
  chart: '<path d="M4 4v15a1 1 0 0 0 1 1h15"/><path d="M8 15v-4M12 15V8M16 15v-6"/>',
  pencil: '<path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19l-4 1z"/><path d="M14.5 6.5l3 3"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  building: '<rect x="5" y="3" width="14" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/>',
  store: '<path d="M3 10a2 2 0 0 0 2 2 2 2 0 0 0 2-2 2 2 0 0 0 2 2 2 2 0 0 0 2-2 2 2 0 0 0 2 2 2 2 0 0 0 2-2V6H3v4z"/><path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M10 21v-4h4v4"/>',
  shield: '<path d="M12 3l7 3v5c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6l7-3z"/><path d="M9.5 12l2 2 3.5-4"/>',
  eye: '<path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/>',
  eyeOff: '<path d="M4 4l16 16"/><path d="M10.6 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.6 6.6C4 8.2 2 12 2 12s3.5 7 10 7c1.2 0 2.3-.2 3.3-.6"/>',
  globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.3 3.8 5.2 3.8 8.5s-1.3 6.2-3.8 8.5c-2.5-2.3-3.8-5.2-3.8-8.5s1.3-6.2 3.8-8.5z"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  link: '<path d="M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1.5 1.5"/><path d="M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1.5-1.5"/>',
  tag: '<path d="M3 3h7l11 11-7 7L3 10V3z"/><circle cx="8" cy="8" r="1.6"/>',
  external: '<path d="M14 4h6v6M20 4l-9 9"/><path d="M19 13v6a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19V8a1.5 1.5 0 0 1 1.5-1.5h6"/>',
  database: '<ellipse cx="12" cy="5.5" rx="7.5" ry="3"/><path d="M4.5 5.5v13c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-13"/><path d="M4.5 12c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3"/>',
  clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  refresh: '<path d="M20 11a8 8 0 1 0-.7 4"/><path d="M20 5.5V11h-5.5"/>',
  check: '<path d="M4.5 12.5l5 5 10-11"/>',
  trash: '<path d="M4.5 7h15"/><path d="M9 7V5.2A1.2 1.2 0 0 1 10.2 4h3.6A1.2 1.2 0 0 1 15 5.2V7"/><path d="M6.5 7l.9 12a1.2 1.2 0 0 0 1.2 1.1h6.8a1.2 1.2 0 0 0 1.2-1.1l.9-12"/><path d="M10.2 11v5.5M13.8 11v5.5"/>',
};

export function icon(name) {
  const node = document.createElement('span');
  node.className = 'ico';
  node.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICON_PATHS[name] || ''}</svg>`;
  return node;
}

export function isLogoImage(src) {
  return typeof src === 'string' && (/^data:image\//.test(src) || /^https?:\/\//.test(src));
}

export function providerLogo(provider, size = 50) {
  const name = (provider && (provider.display_name || provider.name)) || '?';
  if (provider && isLogoImage(provider.logo)) {
    const img = h('img', { src: provider.logo, alt: name });
    img.style.width = `${size}px`;
    img.style.height = `${size}px`;
    return img;
  }
  return h('div', { class: 'logo', text: String(name).slice(0, 1) });
}

export function readFileAsDataURL(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = () => reject(reader.error || new Error('read failed'));
    reader.readAsDataURL(file);
  });
}

export function skeleton(bars = 3) {
  return h(
    'div',
    { class: 'card', 'aria-hidden': 'true' },
    h('div', { class: 'skeleton' }, Array.from({ length: bars }, () => h('div', { class: 'skeleton-bar' })))
  );
}
