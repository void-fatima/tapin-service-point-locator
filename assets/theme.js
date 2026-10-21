(() => {
  'use strict';

  // Several plugin instances in one document share the same controller.
  if (window.TapinTheme?.refresh) { window.TapinTheme.refresh(); return; }

  const key = 'tapin-color-scheme';
  const allowed = new Set(['dark', 'light', 'system']);
  const system = window.matchMedia('(prefers-color-scheme: light)');
  let preference = 'dark';
  const roots = '.tapin-app, .tapin-price-document, .tapin-price-admin-host, .tapin-price-public-page, [data-tapin-theme-frame]';
  // Inherit session state for new same-origin frames/previews when storage is blocked.
  try {
    for (const context of [window.parent, window.opener, ...Array.from(window.parent.frames)]) {
      if (context && context !== window && allowed.has(context.TapinTheme?.get())) {
        preference = context.TapinTheme.get(); break;
      }
    }
  } catch (_) {}

  try {
    const saved = window.localStorage.getItem(key);
    if (allowed.has(saved)) preference = saved;
  } catch (_) {}

  const apply = root => {
    if (!(root instanceof Element) || !root.matches(roots)) return;
    root.dataset.theme = preference;
    root.dataset.themeMode = preference === 'system' ? (system.matches ? 'light' : 'dark') : preference;
    if (root.matches('.tapin-price-document')) root.style.colorScheme = root.dataset.themeMode;
  };

  const applyAll = () => {
    document.querySelectorAll(roots).forEach(apply);
    document.querySelectorAll('[data-tapin-theme-choice], #tapin-theme-select').forEach(select => {
      select.value = preference;
      if (select.hasAttribute('data-tapin-theme-choice')) select.title = 'تم سایت: ' + select.selectedOptions[0]?.textContent;
    });
    window.dispatchEvent(new CustomEvent('tapin-theme-change', { detail: { preference, mode: preference === 'system' ? (system.matches ? 'light' : 'dark') : preference } }));
  };
  let channel;
  try { channel = new BroadcastChannel(key); } catch (_) {}
  const receive = value => {
    if (!allowed.has(value) || value === preference) return;
    preference = value; applyAll();
  };

  window.TapinTheme = {
    get: () => preference,
    set(value) {
      if (!allowed.has(value)) return;
      preference = value;
      try { window.localStorage.setItem(key, value); } catch (_) {}
      applyAll();
      try { channel?.postMessage(value); } catch (_) {}
      // Update source-document siblings immediately; storage events cover other tabs.
      try {
        const contexts = new Set([window.parent, window.opener, ...Array.from(window.frames), ...Array.from(window.parent.frames)]);
        contexts.forEach(context => {
          // Access itself enforces same-origin (srcdoc's URL origin can be "null").
          try { if (context && context !== window) context.TapinTheme?.receive(value); } catch (_) {}
        });
      } catch (_) {}
    },
    refresh: applyAll,
    receive
  };

  if (channel) channel.onmessage = event => receive(event.data);
  document.addEventListener('change', event => {
    if (event.target.matches('[data-tapin-theme-choice]')) window.TapinTheme.set(event.target.value);
  });

  applyAll();
  system.addEventListener?.('change', () => {
    if (preference === 'system') applyAll();
  });
  window.addEventListener('storage', event => {
    if (event.key !== key && event.key !== null) return;
    if (allowed.has(event.newValue)) preference = event.newValue;
    else preference = 'dark';
    applyAll();
  });
  new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (!(node instanceof Element)) return;
      apply(node);
      node.querySelectorAll?.(roots).forEach(apply);
      if (node.matches('[data-tapin-theme-choice], #tapin-theme-select') || node.querySelector('[data-tapin-theme-choice], #tapin-theme-select')) applyAll();
    }));
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
