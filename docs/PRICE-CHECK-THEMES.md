# Price Check themes

Price Check previously bypassed the map module's theme controller: its
isolated documents loaded their own stylesheet, with light surfaces and
hardcoded text, gradients and dropdown colors. Setting a map preference
therefore could not theme the quotation or administration documents.

The existing `assets/theme.js` controller now also recognizes Price Check
documents and iframe wrappers. It keeps the existing `TapinTheme` API,
`tapin-color-scheme` storage key and default Dark preference. The choices are
Light (`روشن`), Dark (`تیره`) and System (`پیش‌فرض سیستم`). System observes
media-query changes; explicit preferences do not follow system changes.
Same-origin documents synchronize through storage events, BroadcastChannel
and immediate parent/sibling notifications. Blocked storage leaves the
current session operational, including newly added iframe documents.

The same controller is inlined before blocking stylesheets in each isolated
document. Its resolved `color-scheme` is set before body parsing. Parent
wrappers receive a matching iframe background while assets load; WordPress
root styles and unrelated content are untouched. Repeated plugin instances
reuse one controller and one delegated Price Check selector handler.

The new native selector follows the map control pattern, in the admin
sidebar and inside public/preview content. The narrow rail retains an
accessible compact select. Embedded quotations still omit their plugin
header/footer. No business settings, quotation logic, routing, motion,
typography or existing component dimensions were changed.

## Files and coverage

- `assets/theme.js`: extend the shared controller and synchronization.
- `src/PriceCheck/InterfaceScreen.php`: before-render initialization in
  parent/child documents; existing asset versioning remains in effect.
- `src/PriceCheck/views/{admin,public,theme-choice}.php`: scoped document
  root and reusable selector, including both admin previews.
- `assets/price-check/style.css`: semantic surfaces, text, borders, controls,
  sidebar/tooltips, calendars, tables, disclosures, code panels, loading,
  empty/result states and status colors. Leaflet chrome inside quotation
  coordinate fields is scoped here; map tiles and the map module are intact.
- `assets/price-check/index.js`: dynamic loading spinner uses theme tokens.
- `assets/price-check/frame.css`: initial dark iframe canvas.
- `tests/price-check-theme.cjs`: runtime theme and visual matrix; existing
  public/sidebar/border fixtures explicitly recognize the new theme support.

## Verification

Chromium fixture tests render the actual PHP views and serve the runtime's
actual CSS/JavaScript. WordPress identity/enqueue functions and REST responses
are doubles, so these results are not a live WordPress installation test.
Coordinate-picker tiles use a local image double; geographic tile imagery
is preserved rather than recolored by the theme.

The theme matrix checks all six admin screens, provider editor/fields,
location settings and blocked locations, log filters/calendar, cache,
both public quotations, standalone public rendering, both admin previews
and the coordinate picker, at 1440px and 390px. Light, Dark and System
resolving to both Light and Dark produce 248 captures. Geometry is compared
between modes; visible neutral light surfaces are rejected in Dark.
Loading, disabled, populated, empty, validation, error and focus states are
included. Screenshots and the machine report are in ignored
`artifacts/themes/`.

Behavior checks cover reload persistence, system changes and explicit-mode
independence, rapid preference changes, synchronization with the shared map
controller and other tabs/iframes, blocked storage and BroadcastChannel,
new iframe inheritance, sidebar state through navigation/reload and a
deliberately blocked stylesheet proving initialization before body parsing.

Other checks: public/admin PHP render and authorization tests (41 and 110
assertions); the complete public/admin browser flow including previews and
form actions; sidebar behavior at 1440/800/700/390/320px; navigation in both
sidebar states at desktop/mobile with no internal document reload or blank
frames; 42 boundary states and eight hover/focus checks; the existing map
province/city-selector regression in Light and Dark. The release ZIP is
unpacked and tested using `TAPIN_PACKAGE_ROOT`; archive validation compares
runtime bytes and checks required assets.

A read-only request to the documented live admin URL redirects to
`wp-login.php` with a login form. No authenticated live session is available.
Live WordPress visual/interaction verification and non-Chromium checks
remain pending. Nothing was deployed or uploaded.
