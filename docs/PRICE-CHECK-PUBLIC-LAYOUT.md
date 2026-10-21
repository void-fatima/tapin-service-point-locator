# Public quotation sizing

## Cause and scope

Read-only inspection of both supplied public WordPress URLs found the same sizing
chain at 1440px and 1920px: Twenty Twenty-Five's constrained `.entry-content`
limits its children to `--wp--style--global--content-size: 645px`. The quotation
wrapper, iframe and `#main` therefore all measured 645px. Inside the frame,
`.wrap-pro` also imposed a separate 1040px maximum. Increasing the iframe width
alone would leave the first constraint in place; removing the theme constraint
alone would leave the internal maximum. The 645px frame also selected the app's
single-column responsive layout.

An unprotected WordPress Page containing only one quotation shortcode now uses
the existing plugin page template, with no theme title/header/footer or constrained
content wrapper. WordPress head/body/footer hooks remain available. Selection
uses shortcode content, never page IDs. Escaped examples, password protection,
map pages, multiple quotations and surrounding site content retain their theme
template. Existing explicit `layout="standalone"` remains supported.

Use `[tapin_price_check layout="embedded"]` or
`[tapin_bulk_quote layout="embedded"]` to explicitly embed a quotation in a site
section. Embedded documents fill that section, preserve surrounding content and
do not impose a viewport-height floor. Neither public layout restores the removed
plugin header, navigation or footer. Admin previews retain their existing layout.

Public iframe CSS removes the internal width maximum, keeps the existing 20px
desktop/14px mobile outer padding and uses the existing form/results grid with
40%/60% tracks above 1024px. Narrow frames use one column. Provider tiles use
responsive tracks; their empty state spans the available width. Typography and
control styling retain the existing responsive rules, without scaling.

The dedicated host shares the existing theme engine and canvas token. Its initial
theme resolves in the head. The iframe grows and shrinks to natural content and
open menus, with a dedicated-page minimum of viewport height minus the WordPress
toolbar (32px desktop, 46px mobile). Scrolling remains in the host document.
Public sizing assets use content hashes to invalidate cached versions.

## Measured verification (2026-10-21)

The live measurements above are **before** the change, anonymously and without
carrier requests. The after measurements below come from the extracted release's
PHP templates/assets in a localhost browser fixture. WordPress hooks, identity
and REST responses are doubles; the host fixture reproduces the observed 645px
theme rule. A separate 57-assertion test uses WordPress's real shortcode parser.

Both single and bulk selected-form screens measured the following (CSS pixels):

| Viewport | Before host / iframe / main | After host / iframe / main | After form / results columns |
| --- | --- | --- | --- |
| 1440 | 645 / 645 / 645 | 1440 / 1440 / 1440 | 552 / 828, side by side |
| 1920 | 645 / 645 / 645 | 1920 / 1920 / 1920 | 744 / 1116, side by side |
| 768 | 645 / 645 / 645 | 768 / 768 / 768 | 728 / 728, stacked |
| 390 | 342 / 342 / 342 | 390 / 390 / 390 | 362 / 362, stacked |

The before fixture captured 72 cases. The after release fixture captured 81:
single/bulk, Light/Dark, empty/provider/form/result states, custom dropdowns,
long forms, explicit embedding and surrounding content at all four widths, plus
anonymous rendering. It also checks System synchronization and reload. Quote
responses are mocked locally. No browser errors, external requests, horizontal
overflow, nested document scrolling or clipped dropdowns were detected. The
screenshots were visually reviewed at desktop and mobile sizes.

Additional source regressions passed: public/admin UI workflows, all six admin
navigation sections, sidebar controls, map location controls, and 248 theme
captures including previews, System changes, blocked storage and frame/tab
synchronization. PHP public/admin rendering checks passed 43/116 assertions.

Generated evidence is under ignored `artifacts/public-layout/`: live-before PNGs
and JSON, `before.json`/`after.json`, and matching `before-*.png`/`after-*.png`.
Representative comparisons are `single-1920-dark-form` and
`bulk-1440-light-results`. Run `node tests/price-check-public-layout.cjs`;
`TAPIN_PACKAGE_ROOT` selects an extracted release, and `TAPIN_PHP` selects PHP.
For a baseline, set `TAPIN_PUBLIC_LAYOUT_AUDIT=before` and point the package root
at the unchanged source archive.

The fixed version has not been deployed. Live WordPress verification of the
after state remains pending; the fixture is not a full installed WordPress/theme
or database integration test.
