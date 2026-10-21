# Price Check admin sidebar repair

The admin sidebar must collapse from the Tapin branding button into a 72px
right rail and restore its 190px expanded layout. Mobile keeps the existing
horizontal navigation and collapsed icon grid. This change does not deploy or
alter the locator, public quotation UI, embedded chrome, forms or pricing.

## Diagnosis

`PriceCheck\Admin::render()` calls `InterfaceScreen::frame()`, which puts
`views/admin.php` into an isolated same-origin `iframe srcdoc`. That document
loads `assets/price-check/style.css` and the module `admin.js`; the latter
imports `common.js` and `wordpress.js`. WordPress/theme button selectors in
the parent cannot style the iframe's branding button.

The original sidebar feature changed markup from branding into a native
button and added its reset/collapse rules at the end of `style.css`. Only the
entry JavaScript URL was versioned. The stylesheet URL stayed unchanged.
An already visited installation could consequently combine the new button
and working click listener with pre-feature CSS.

The real-cache regression in `tests/price-check-sidebar.cjs` demonstrated
both reported symptoms before the fix:

- Branding computed background: `rgb(240, 240, 240)`, native border: `2px`.
- Pointer click changed `aria-expanded` to `false` and added
  `pc-sidebar-collapsed` to the correct shell, but width remained `248px`.
- No JavaScript runtime errors occurred. The click reached the intended
  button; neither an overlay nor a missing handler caused this reproduction.

The previous `price-check-ui.cjs` fixture always used `context.route()`.
Playwright disables HTTP cache when routing is enabled. That fixture fetched
current CSS every visit and therefore passed despite the update defect.
The new regression warms a browser's genuine HTTP cache with the pre-sidebar
portion of the stylesheet, then serves the upgraded PHP-rendered interface
at the same origin without request interception.

Read-only live inspection on 2026-10-21 found the repository's documented
WordPress admin URL redirected to `wp-login.php`. There was no authenticated
admin session available. Publicly served `style.css`, `admin.js`, `common.js`
and `wordpress.js` returned 200 and matched baseline commit `c39e84a` after
newline normalization. CSS was served with `Cache-Control: public,
max-age=604800`. This corroborates the cache failure path but does not prove
the cache contents of the reported user's browser or the authenticated
page's active srcdoc. Live admin interaction remains pending.

## Repair

- `InterfaceScreen.php` gives the admin stylesheet a SHA-256 content version
  so a changed build requests a new URL, even when a deploy preserves mtimes.
  Public/preview rendering keeps its existing asset path.
- The existing sidebar toggle remains the only click handler and the shell
  class remains its single state source. Its synchronized ARIA attributes,
  native Enter/Space behavior, and guarded Price Check-specific storage key
  `tapin.price-check.admin-sidebar-collapsed` are retained.
- The local button reset keeps branding transparent, removes native border
  and shadow, restores the 30px logo and bold brand typography/alignment,
  and retains a visible keyboard focus indicator. Purple/yellow gradients
  and navigation SVGs stay intact.
- One real tooltip replaces the duplicated pseudo-element rules. Hover and
  focus associate it using `aria-describedby`; Escape dismisses it. Fixed
  positioning faces left into desktop content and clamps to the iframe
  viewport, including mobile edge items and the bottom public link. Resize,
  scroll and toggle changes dismiss stale tooltips.
- Existing mobile breakpoints, reduced-motion handling, navigation URLs and
  label-independent accessible names remain in place.

## Verification

Run from the repository, with the existing local PHP/Chromium runtimes:

```powershell
node tests/price-check-sidebar.cjs
node tests/price-check-ui.cjs
artifacts/php-runtime/php.exe tests/price-check-ui-admin.php
artifacts/php-runtime/php.exe tests/price-check-ui.php
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/build.ps1 -OutputDirectory artifacts/sidebar/release
Expand-Archive -LiteralPath artifacts/sidebar/release/tapin-service-point-locator-1.4.7.zip -DestinationPath artifacts/sidebar/extracted -Force
$env:TAPIN_PACKAGE_ROOT = (Resolve-Path artifacts/sidebar/extracted/tapin-service-point-locator).Path
node tests/price-check-sidebar.cjs
node tests/price-check-ui.cjs
```

The browser runners use the extracted package's PHP classes/templates and
serve its actual CSS/JS when `TAPIN_PACKAGE_ROOT` is set. WordPress identity,
REST responses and writes are doubles; this is packaged runtime verification,
not a real WordPress/MySQL installation or live carrier test.

Coverage: warm-cache upgrade; transparent branding; 190px/72px desktop state;
1440/800/700/390/320px layouts; all seven icon labels and accessible names;
all hover/focus tooltip rectangles inside the iframe and toward content;
yellow active item and purple sidebar; pointer/Enter/Space; eleven immediate
clicks; both reload states; storage access denied at initialization/write;
reduced motion; main-content resizing; all six actual collapsed menu clicks
and parent navigation through `صفحه عمومی` at desktop/mobile widths.
The wider runner also covers existing quotation, form, preview, import and
admin actions. Targeted PHP render suites pass 108 admin and 39 public
assertions. No JavaScript runtime errors or new horizontal overflow were
observed. The existing mobile shops table remains internally scrollable.

Screenshots are generated under ignored `artifacts/sidebar/`. The portable
release ZIP is built locally only, checked against repository runtime bytes,
and exercised after extraction. No files were uploaded or deployed. Live
WordPress admin verification and non-Chromium testing remain pending.
