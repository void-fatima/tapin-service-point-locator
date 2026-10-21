// Same-origin srcdoc: resize only this module's iframe, without touching the host layout.
const frame = window.frameElement;
if (frame?.parentElement.matches('.tapin-price-embedded, .tapin-price-dedicated')) {
  let scheduled = false;
  const fit = () => {
    scheduled = false;
    let bottom = document.body.getBoundingClientRect().bottom;
    // Open custom menus can extend past normal flow, especially in short configurations.
    for (const menu of document.querySelectorAll('.combo.open .combo-panel, .adv-menu.open')) {
      bottom = Math.max(bottom, menu.getBoundingClientRect().bottom);
    }
    const viewport = frame.parentElement.classList.contains('tapin-price-dedicated')
      ? window.parent.innerHeight - (parseFloat(window.parent.getComputedStyle(window.parent.document.body).getPropertyValue('--pc-toolbar-height')) || 0) : 0;
    const height = Math.max(Math.ceil(bottom + window.scrollY), viewport);
    if (height > 0 && frame.style.height !== `${height}px`) frame.style.height = `${height}px`;
  };
  const schedule = () => {
    if (!scheduled) { scheduled = true; requestAnimationFrame(fit); }
  };
  new ResizeObserver(schedule).observe(document.body);
  new MutationObserver(schedule).observe(document.body, {
    subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden'],
  });
  window.addEventListener('load', schedule);
  window.parent.addEventListener('resize', schedule);
  document.fonts.ready.then(schedule);
  schedule();
}
