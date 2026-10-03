(() => {
  'use strict';

  const key = 'tapin-color-scheme';
  const allowed = new Set(['dark', 'light', 'system']);
  const system = window.matchMedia('(prefers-color-scheme: light)');
  let preference = 'dark';

  try {
    const saved = window.localStorage.getItem(key);
    if (allowed.has(saved)) preference = saved;
  } catch (_) {}

  const apply = root => {
    if (!(root instanceof Element) || !root.matches('.tapin-app')) return;
    root.dataset.theme = preference;
    root.dataset.themeMode = preference === 'system' ? (system.matches ? 'light' : 'dark') : preference;
  };

  const applyAll = () => document.querySelectorAll('.tapin-app').forEach(apply);

  window.TapinTheme = {
    get: () => preference,
    set(value) {
      if (!allowed.has(value)) return;
      preference = value;
      try { window.localStorage.setItem(key, value); } catch (_) {}
      applyAll();
    }
  };

  applyAll();
  system.addEventListener?.('change', () => {
    if (preference === 'system') applyAll();
  });
  window.addEventListener('storage', event => {
    if (event.key !== key) return;
    if (allowed.has(event.newValue)) preference = event.newValue;
    else preference = 'dark';
    applyAll();
  });
  new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (!(node instanceof Element)) return;
      apply(node);
      node.querySelectorAll?.('.tapin-app').forEach(apply);
    }));
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
