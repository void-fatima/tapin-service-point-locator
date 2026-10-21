# Price Check boundary visibility

## Audit and result

The supplied error-log and cache screenshots show the same problem in two
places: a 1px border exists, but its color is too close to the white/lavender
surfaces. This is a color problem; extra border width, spacing or shadows are
unnecessary. Current-run screenshots of the PHP-rendered screens confirmed
the issue in the other admin cards, public forms and their inputs as well.

1. **Admin containers — corrected.** Logs, cache status/settings, cache tiles,
   providers/shops tables and forms, bulk settings, security cards, provider
   fields and location settings now use the same indigo boundary family.
   The populated logs fixture exercises the same outer card as the empty
   screenshot. Existing nested guide boxes, passive chips, table dividers and
   icon decoration retain their quieter borders, avoiding repeated outlines.
2. **Controls and disclosures — corrected.** Text/number inputs, selects,
   textareas, searchable combo faces/panels, date-range display/calendar,
   neutral outline buttons, details, the location toolbar/groups/city choices,
   sorting control and advanced provider menu use shared tokens. Hover uses a
   darker indigo; keyboard focus has a distinct solid outline. Dark purple
   form-action areas retain a high-contrast yellow button focus outline.
3. **Public forms/results — corrected.** Provider selection, form/result cards,
   bulk forms, advanced chooser and comparable containers share the update.
   The isolated iframe stylesheet does not enter the WordPress theme or map
   module. Embedded public header/footer behavior remains unchanged.

Representative screenshots, captured and inspected during this work:

| Example | Before | After |
| --- | --- | --- |
| Error-log card | [Desktop](../artifacts/borders/before-logs-1440.png) | [Desktop](../artifacts/borders/after-logs-1440.png) |
| Cache status/settings and colored tiles | [Desktop](../artifacts/borders/before-cache-1440.png) | [Desktop](../artifacts/borders/after-cache-1440.png), [mobile](../artifacts/borders/after-cache-390.png) |
| Public form | [Desktop](../artifacts/borders/before-public-form-1440.png) | [Desktop](../artifacts/borders/after-public-form-1440.png) |

The artifacts are local, ignored verification output. They are not deployed.

## Tokens and scope

`assets/price-check/style.css` defines these tokens inside the existing
Price Check document stylesheet, separate from decorative `--line` /
`--line-soft` and status colors:

| Token | Color | Use |
| --- | --- | --- |
| `--pc-border` | `#818ab7` | Container boundary |
| `--pc-control-border` | `#7883b3` | Control/disclosure boundary |
| `--pc-border-hover` | `#535f99` | Hover boundary |
| `--pc-focus` | `#414aa1` | Focus outline |

The old general `#e6e8ef` border has approximately 1.22:1 contrast against
white and 1.16:1 against `#f8f8ff`. The container token measures 3.36:1 and
3.18:1 respectively, and 3.09:1 against the cache gradient's `#f3f5fd`
endpoint. The control token measures 3.69:1 against white. These are boundary
measurements, not a claim of complete accessibility compliance.

Existing border widths stay unchanged: neutral boundaries are normally 1px;
colored 4px card accents, 2px best-result highlights, validation borders,
destructive buttons and error/warning/success badges keep their existing
meaning and colors. Focus outlines do not participate in element dimensions.
No backgrounds, typography, padding, margins, radii, shadows, layout rules or
runtime JavaScript are changed.

There is no independent dark theme in Price Check: its stylesheet defines one
light palette and has no dark-theme selector, switch or media palette. The
audit also emulates a dark OS preference and confirms the existing light
surfaces remain intact. No unrelated locator dark-mode tokens are changed.

`src/PriceCheck/InterfaceScreen.php` extends the existing SHA-256 stylesheet
version from admin documents to all Price Check iframe documents, including
public embeds, standalone views and previews. Otherwise a visitor's cached
unversioned public stylesheet could hide the correction. Admin-only adapter
imports, nonce handling, navigation and sidebar storage remain untouched.

## Actual verification

`tests/price-check-borders.cjs` runs Chromium against the real PHP templates
and bundled assets with local WordPress identity/REST doubles. It captures
42 screen/states at 1440px and 390px: all six admin sections, provider editor
and fields, locations and blocked cities, log filters/calendar, public
provider selection, form, combo dropdown, validation, bulk, advanced chooser,
results and OS-dark-preference cases. It records visible-element inventory
and computed geometry, borders, backgrounds and typography.

The before/after comparison uses the pre-change CSS with the same renderer,
scripts and deterministic data. Reduced motion is enabled for geometry
comparison so existing hover/entry transforms cannot masquerade as size
changes. All measured positions, dimensions, border widths, radii, spacing,
fonts and surface/text colors match. Semantic border colors and colored
accents also match. No horizontal overflow or browser console/runtime errors
are observed. Eight explicit hover/focus checks confirm distinct states,
readable focus contrast, unchanged dimensions and horizontal iframe clearance.
Normal card boundaries pass the tested 3:1 contrast threshold independently
of shadows. A separately styled parent theme marker remains unchanged.

To reproduce a full comparison, preserve the baseline CSS before editing (or
export it from the parent commit) and then run:

```powershell
# Save the pre-change stylesheet as artifacts/borders/before.css first.
$env:TAPIN_BORDER_AUDIT = 'before'
node tests/price-check-borders.cjs
Remove-Item Env:TAPIN_BORDER_AUDIT
node tests/price-check-borders.cjs
```

`TAPIN_BORDER_BASELINE_CSS` can point at another baseline stylesheet. Without
an existing before.json the runner still checks current contrast, focus,
overflow and isolation, but cannot compare previous geometry. Screenshots
and inventories are written under `artifacts/borders/`.

The existing `price-check-ui.cjs` passes public single/bulk/loading/error,
validation, previews, form saves/import/downloads and all admin screens at
desktop/mobile widths. PHP render tests pass 110 protected-admin and 41
public assertions, including current stylesheet URLs. Existing sidebar and
navigation runners retain their persistence, keyboard, tooltip, motion,
history and no-reload checks. The local release ZIP is checked for portable
paths/exact runtime bytes; `TAPIN_PACKAGE_ROOT` lets the browser runners
render/serve the extracted package rather than the working tree.

A fresh read-only attempt to open the live WordPress logs admin page on
2026-10-21 redirected to `wp-login.php`; no authenticated session was
available. Live WordPress visual checks and non-Chromium testing remain
pending. Fixture results do not establish a live WordPress/MySQL result.
Nothing was uploaded or deployed.
