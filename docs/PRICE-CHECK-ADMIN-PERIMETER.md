# Price Check admin perimeter

## Diagnosis

The visible light strips belong to the host WordPress canvas. They are not
an iframe border or margins inside the application. In the reproduced page:

- The actual `Admin::render()` callback adds `.wrap.tapin-price-admin` around
  the frame. WordPress `common.css` gives `.wrap` a margin of
  `10px 20px 0 2px`; `#wpcontent` adds horizontal padding, and
  `#wpbody-content` adds bottom padding.
- Those wrappers are transparent, exposing WordPress `body`'s `#f0f0f1`.
  Browser hit testing identified `#wpcontent` or `#wpbody-content` at each
  edge, with `body` supplying the painted background.
- Before the correction, all four sampled perimeter pixels were
  `(240,240,241)` even though the child document and iframe were already
  Dark `(11,14,25)`.

The older theme fixture rendered `InterfaceScreen::frame()` directly in a
zero-margin body. It tested the isolated app and frame canvas, but omitted
the actual admin callback's wrapper and WordPress spacing. It could not
expose this host-background failure.

The live admin URL redirects to the login form; no authenticated session is
available. Public `wp-admin/css/common.css` and `common-rtl.css` were readable
and downloaded from the installed site. The reproduction uses those actual
stylesheets, the real packaged PHP admin registration/rendering and assets,
and a WordPress shell fixture. Hooks, identity, menu/toolbar and REST data
are doubles. This is not a live WordPress interaction test.

## Correction and files

- `src/PriceCheck/Admin.php` registers a head initializer only for its
  registered admin hooks and authorized users.
- `src/PriceCheck/InterfaceScreen.php` marks the host root and runs the
  existing theme controller in `admin_head`, before body parsing. It also
  loads the shared canvas stylesheet in parent and iframe documents.
- `assets/theme.js` recognizes that marked host root. Existing storage,
  System tracking, cross-document notifications and idempotence are reused.
- `assets/price-check/surfaces.css` contains the existing `--paper` Light
  and Dark values once for host, frame wrapper and child. Its URL/version
  uses a content hash; `style.css` no longer duplicates those two values.
- `assets/price-check/frame.css` paints the frame/wrapper and the marked
  Price Check host's canvas layers using `var(--paper)`. No geometry,
  overflow or animation rules were added or changed. Unrelated admin pages
  never receive the marker or head initializer.
- `tests/price-check-admin-perimeter.cjs`, its PHP adapter
  `tests/price-check-admin-perimeter-render.php` and the existing public/admin
  renderer tests cover the host wrapper and stylesheet wiring.

## Verification

The original package was captured before editing production files. Its 96
states reproduced the light host perimeter in all six admin sections at
1440px and 390px, expanded/collapsed, with Light, Dark and both resolutions
of System. The corrected runtime compares every existing host/child
bounding box, padding, margin, border width, overflow and child typography
against that baseline. All original 96 layouts are identical.

The corrected matrix includes 16 reload cases preserving each theme and
sidebar state, plus an RTL host case: **113 captures, 452 perimeter pixel
checks**. Dark edges are `(11,14,25)`; Light edges are `(248,248,255)`.
System resolves to the corresponding color. Browser errors and new
horizontal overflow are absent. Tab changes update both the receiving
host and iframe. An unrelated admin screen retains `#f0f0f1`, and the
WordPress toolbar/menu styling is preserved.

A deliberately held iframe stylesheet leaves the child body unparsed:
the host and iframe roots are already Dark and the host is painted Dark.
A Chromium protocol screenshot records this initial paint without waiting
on the blocked frame's fonts. Reload persistence and host synchronization
therefore do not depend on the iframe finishing initialization.

Also passed: the 248-capture existing theme matrix (all public/admin screens
and previews, both System resolutions), full public/admin browser behavior
including forms and previews, and PHP rendering/authorization checks
(41 public and 116 admin assertions). The final ZIP is extracted for the
perimeter run using `TAPIN_PACKAGE_ROOT`; inventory validation compares
packaged runtime bytes with source. Results and screenshots remain under
ignored `artifacts/perimeter/`.

To reproduce, download the two installed core stylesheets to
`artifacts/perimeter/wp-admin-common.css` and `wp-admin-common-rtl.css`,
then run `node tests/price-check-admin-perimeter.cjs`. Optionally set
`TAPIN_PACKAGE_ROOT` to an extracted plugin package. To record the original
package, also set `TAPIN_PERIMETER_AUDIT=before`; `before.json` is used for
the subsequent geometry comparison.

Live authenticated WordPress and non-Chromium verification remain pending.
No deployment was performed.
