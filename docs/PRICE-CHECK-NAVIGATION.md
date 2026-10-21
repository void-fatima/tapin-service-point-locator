# Stable Price Check admin navigation

## Root cause and evidence

The six sidebar buttons call `goAdmin()` and `routeAdmin()`. Previously,
`wordpress.js::navigate()` treated a change to WordPress's `page` query
parameter as a different document and called `window.parent.location.assign()`.
Switching from shops to security therefore fetched a new WordPress document,
which created a new `iframe srcdoc`, sidebar, shell and module graph.
The shell disappeared before the destination document was ready.

The initial Chromium regression recorded one additional document request for
`admin.php?page=tapin-price-security&tapin_price_route=%2Fadmin%2Fsecurity`.
Markers placed on the iframe window and shell disappeared. No JavaScript
exception occurred. This substantiates a full parent-page/iframe reload,
rather than an animation or sidebar CSS conflict.

With this change, the same click records **zero additional document requests**
and preserves both markers. Playwright still reports frame/history URL events
for `pushState` and Back/Forward; those are not counted as document reloads.
The test separately observes network document requests and actual DOM/window
identity so same-document history events cannot hide a reload.

## Implementation

- `assets/price-check/wordpress.js`: use parent History API only between the
  known six same-origin WordPress Price Check admin URLs. Public quotation,
  preview and other WordPress module navigation retain their existing
  behavior. Route fallback derives the screen from the current dedicated
  WordPress URL instead of the immutable initial iframe configuration.
- `assets/price-check/admin.js`: one view-selection function keeps the
  sidebar/shell mounted and immediately updates the active item. Exactly one
  main section is visible and outgoing views use native `hidden`. A 180ms
  content-only opacity animation goes from 0.94 to 1, with no blank/fade-out
  phase, movement, delayed routing or overlapping interactive views. Reduced
  motion skips it. Reselecting the current section leaves its form intact.
- Loading cards remain inside the selected section and have accessible status
  labels. Per-host tickets and a navigation generation reject old success and
  error results, including a slow provider editor that previously could
  resurrect itself after another section was selected. Old loaders cannot
  restore obsolete scroll positions. Provider saves still complete normally;
  their completion cannot navigate away from a newer section choice.
- Sidebar activation keeps focus on the navigation button. History navigation
  focuses the incoming section with `preventScroll`, avoiding focus trapped
  in a hidden outgoing form. Section changes start content at the top without
  animating the shell. Existing form validation, save/import handlers and
  draft-preview mechanisms remain on their current paths; no new leave-page
  confirmation policy or unsaved-change prompt is introduced.
- `src/PriceCheck/InterfaceScreen.php`: an admin-only import map versions
  `wordpress.js` by content hash for **both** `admin.js` and `common.js`.
  Without this, an installed browser could reuse the old unversioned adapter
  even though the entry module updated. Both importers share one module and
  one parent `popstate` bridge. Public/preview documents have no new import map.

All six dedicated URLs and PHP submenu/document authorization checks remain.
A reload or direct request still passes through WordPress and renders the
chosen section. History navigation does not grant access to a new capability;
REST endpoints keep their nonce and capability checks.

## Verification

```powershell
node tests/price-check-navigation.cjs
node tests/price-check-ui-adapter.cjs
node tests/price-check-ui.cjs
node tests/price-check-sidebar.cjs
artifacts/php-runtime/php.exe tests/price-check-ui-admin.php
artifacts/php-runtime/php.exe tests/price-check-ui.php
```

`tests/price-check-navigation.cjs` uses Chromium, the real PHP renderer and
runtime assets with WordPress identity and REST doubles. It covers all directed
pairs between the six sections at 1440px and 390px in both sidebar states.
Animation-frame samples check for blank/overlapping content, hidden focus,
sidebar/content positioning changes and shell/sidebar animations. All these
violation counts are zero. Window/shell identities remain unchanged and no
internal/history click requests a new document.

The runner also holds response bodies to exercise stable loading and
out-of-order success/error delivery, a late editor response, current-request
failure and recovery, multiple clicks in one task, Enter activation, history
focus, Back/Forward, all six direct URLs and reloads at desktop/mobile widths,
security subtab deep links, reduced motion and the 180ms animation scope.
Two deliberately simulated HTTP 503 responses generate expected browser
network-error messages; there are no unexpected console errors or JavaScript
runtime exceptions.

The existing UI runner retains public quotation and all admin form/preview,
import/download and save checks. The sidebar runner retains real-cache
upgrade, all seven tooltips, storage failures, persistence and five widths.
The adapter test checks history versus real navigation boundaries and nonce
headers. PHP rendering checks pass 110 protected-admin and 39 public
assertions. Syntax and `git diff --check` pass.

The same browser suites can run against an extracted local release by setting
`TAPIN_PACKAGE_ROOT` to its plugin directory. They then render its PHP classes
and serve its actual CSS/JavaScript. ZIP inventory verification compares all
runtime bytes with the working tree. This remains a local packaged runtime
with mocked REST, not an installed WordPress/MySQL verification.

The documented live WordPress admin URL requires an authenticated login,
which is unavailable in this session. Its previous read-only inspection is
recorded in `PRICE-CHECK-SIDEBAR.md`. Live admin interaction, actual WordPress
history/chrome integrations and non-Chromium verification remain pending.
Screenshots are written under ignored `artifacts/navigation/`. No deployment
or upload is performed.
