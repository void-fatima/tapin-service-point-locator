# Verification record — 2026-09-26

Environment: Windows, WordPress 7.1.2, PHP 8.2.29, MySQL 8.4.0, Chromium 140 through Playwright 1.55.1. The existing LocalWP site and repository junction were used. After LocalWP stopped during the session, its database and PHP runtime were restarted for remaining tests. No real service-point dataset was supplied; test fixtures were isolated and removed.

## Automated checks

- `tests/run-checks.php`: **30 passed**, normalization, validation, malformed/nonfinite/partial coordinates, length limits, duplicate signals, column mapping.
- `tests/integration.php`: **32 passed** against real WordPress/MySQL. Create/clear coordinates, unknown providers, private staging and mapping, invalid/misaligned rows, repeated request safety, multi-request resume, cancellation, exact-code update, ambiguous-code protection, shared-phone protection, XLSX shared strings/blank fields, formula/XXE/truncated XML rejection, checkpoint failure injection with rollback and safe retry, dashboard totals and duplicate counts, anonymous/subscriber denial, inactive-provider filtering and public metadata exclusion.
- `tests/browser.cjs`: passed desktop and mobile admin navigation, point create/edit/delete, Persian-digit coordinates, CSV upload/preview/mapping/results, provider create/delete, invalid REST nonce rejection, anonymous public shortcode/map/list/popup, and no uncaught JavaScript errors. Mobile overflow was found and fixed; shortcode style timing and tile-template URL escaping were also fixed.
- PHP syntax lint and JavaScript syntax checks passed.
- Release packaging verifies an explicit runtime allowlist, including Leaflet/font/brand assets, and rejects test files, local config, sessions and dependency folders.
- `tests/package-check.php` verifies the extracted ZIP loads through WordPress, activates on disposable fresh tables, activates idempotently, saves an address-only point and registers the shortcode. It does not replace the installed working-tree plugin.

## 10,000-row development benchmark

`tests/benchmark.php` staged a CSV and inserted **10,000 records in 200 requests/batches**, with no lost rows. Local measured values:

| Measurement | Result |
| --- | ---: |
| Staging | 0.242 seconds |
| All import batches | 32.522 seconds |
| Longest batch | 0.281 seconds |
| Dashboard aggregation with 10,000 points | 0.231 seconds |
| Peak CLI PHP memory | 12 MiB |
| Retained diagnostic rows | 1,000 (bounded) |

These are direct PHP calls using real database transactions; HTTP/browser latency is additional. Shared hosting may be slower. The maximum 100,000-row limit has not been benchmarked here. All benchmark records were removed.

## Visual review

Desktop screenshots use 1666×1100 (about 1506 pixels of plugin area after WordPress navigation), and mobile uses 390×844. Local screenshots in ignored `artifacts/` show empty/populated dashboard, mobile layout, import preview/results, and public map. Populated screenshots show explicitly named browser-test records; these are not production data.

Persian warnings, RTL layout, scoped WordPress styles, keyboard-visible focus, native dialog focus handling, empty states and actionable issue links were reviewed. A native WordPress password reminder remains outside the plugin; the plugin does not hide WordPress notices or alter account preferences.

## Deliberate differences and limits

- Metrics and markers reflect real saved data; the mockup's illustrative counts and arbitrary markers are not copied.
- Official Post logo uses blue; the official Tipax header logo is white with a green provider accent. No invented brand marks.
- Real OSM background plus bundled historical geoBoundaries geometry replace the illustrative map. Tile availability/latency depends on the external provider. Local geometry and branch list remain usable without tiles.
- Legacy XLS, formulas and multi-sheet Excel selection are excluded; convert to values-only XLSX or UTF-8 CSV. Limits are visible before upload.
- Browser-driven imports pause when the page closes; resume from history. They are not unattended background jobs.
- Minimum supported PHP/WordPress versions and multisite network activation were not runtime-tested. This release is verified on the single-site environment above.
- The Local PHP installation emits a pre-existing missing `php_imagick.dll` startup warning. The plugin does not require Imagick; checks pass without it.

No production deployment, remote push, or merge into `main` was performed.
