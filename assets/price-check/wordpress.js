// WordPress boundary only. Source controls, validation and quote rendering stay in the supplied modules.
const config = () => window.TapinPriceUI;
const host = () => window.parent;

export function restURL(path, settings = config()) {
  if (!path.startsWith('/api/')) throw new Error('Unsupported price-check API path.');
  const source = new URL(path, 'https://price.invalid');
  const suffix = source.pathname.slice(5);
  const url = new URL(settings.restUrl, host().location.href);
  if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + suffix);
  else url.pathname += suffix;
  source.searchParams.forEach((value, key) => url.searchParams.append(key, value));
  return url.href;
}

export function request(path, options = {}) {
  const headers = new Headers(options.headers || {});
  headers.delete('X-Admin-Token');
  if (path.startsWith('/api/admin/')) {
    if (!config().admin || !config().nonce) throw new Error('نشست مدیریت معتبر نیست؛ دوباره وارد وردپرس شوید.');
    if (new URL(restURL(path)).origin !== new URL(host().location.href).origin) throw new Error('نشانی API مدیریت باید هم‌مبدا با وردپرس باشد.');
    headers.set('X-WP-Nonce', config().nonce);
  } else headers.delete('X-WP-Nonce');
  return fetch(restURL(path), { ...options, headers, credentials: 'same-origin' });
}

export function routeLocation() {
  const url = new URL(host().location.href);
  const route = config().route.startsWith('/preview/') ? config().route
    : config().admin ? url.searchParams.get('tapin_price_route') || config().route
      : url.searchParams.get('tapin_price_mode') === 'bulk' ? '/bulk'
        : url.searchParams.get('tapin_price_mode') === 'single' ? '/advanced' : config().route;
  const virtual = new URL(route, 'https://price.invalid');
  for (const key of ['provider', 'sub']) if (url.searchParams.has(key)) virtual.searchParams.set(key, url.searchParams.get(key));
  return { pathname: virtual.pathname, search: virtual.search };
}

export function pageURL(route) {
  const virtual = new URL(route, 'https://price.invalid');
  const settings = config();
  let base;
  if (virtual.pathname.startsWith('/admin/')) {
    if (!settings.admin) throw new Error('Admin navigation is unavailable.');
    base = settings.adminUrls[virtual.pathname.split('/')[2]];
  } else if (virtual.pathname.startsWith('/preview/')) {
    if (!settings.admin) throw new Error('Preview navigation is unavailable.');
    const kind = virtual.pathname.split('/')[2];
    const url = new URL(settings.adminUrls[kind === 'bulk' ? 'bulk' : 'providers']);
    url.searchParams.set('tapin_price_preview', kind);
    return url.href;
  } else base = settings.publicUrls[virtual.pathname === '/advanced' ? 'single' : 'bulk'];
  const url = new URL(base || host().location.href, host().location.href);
  for (const key of ['provider', 'sub', 'tapin_price_route', 'tapin_price_preview', 'tapin_price_mode']) url.searchParams.delete(key);
  if (virtual.pathname.startsWith('/admin/')) url.searchParams.set('tapin_price_route', virtual.pathname);
  else if (!base) url.searchParams.set('tapin_price_mode', virtual.pathname === '/advanced' ? 'single' : 'bulk');
  virtual.searchParams.forEach((value, key) => url.searchParams.set(key, value));
  return url.href;
}

export function navigate(route, replace = false) {
  const url = new URL(pageURL(route));
  const current = new URL(host().location.href);
  // Separate WordPress pages really navigate; provider editors/deep links retain source SPA history.
  if (url.pathname !== current.pathname || url.searchParams.get('page') !== current.searchParams.get('page') || url.searchParams.get('page_id') !== current.searchParams.get('page_id')) {
    host().location.assign(url.href); return;
  }
  if (url.href !== current.href) host().history[replace ? 'replaceState' : 'pushState']({}, '', url.href);
}

if (typeof window !== 'undefined') window.parent.addEventListener('popstate', () => window.dispatchEvent(new Event('popstate')));
