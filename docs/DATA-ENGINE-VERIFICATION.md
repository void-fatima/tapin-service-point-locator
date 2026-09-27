# Service-point data engine — 2026-09-27

Branch: `feature/service-point-data-engine`, based on the existing `main` implementation.

## Database and runtime

Schema version 5 adds nullable `mobile_phone`, `landline_phone`, and `source`, plus
`data_quality_status` and a composite province/map/provider index. Legacy `phone`
values and coordinates remain intact. Quality backfill marks existing located
records `needs_review`; address-only records remain `missing_coordinates`.
Schema version is recorded only after the required new columns exist.

`tapin_logs` stores event, actor ID, bounded numeric context and UTC timestamp,
with timestamp/event indexes. Import outcomes and point validation/storage errors
are recorded; raw contacts, uploads and page visits are not. A daily WordPress
cron hook removes events older than three calendar months. Deactivation clears
the hook and preserves data; opt-in uninstall also removes the logs table.
WP-Cron requires traffic or a server cron invoking WordPress cron.

## Map and data behavior

Directory pagination and viewport marker requests are separate. Marker loading
uses server-side bounds, province/provider filters, aborts stale requests, and
pages through all matching results in 500-row batches. Nearby points are grouped
in screen cells to bound rendered marker elements. Group counts expand on zoom;
co-located points remain accessible through their group popup and address list.

All-provider pins are yellow; filtered Post and Tipax pins use existing official
assets; other providers use their configured logo or a neutral parcel symbol.
Province selection fits the province geometry, including provinces with no data.
Foreign raster labels are masked outside Iran; country labels are placed separately.
Street tiles appear only after local boundary geometry loads. Geometry failure
therefore keeps the tile pane hidden instead of revealing foreign city labels.

Public details use the dark palette and separate mobile/landline links. Address-only
records appear in the directory and management screens but never create markers.
Dashboard completeness recognizes all three contact fields; totals, located,
missing, publicly mapped and public directory counts remain distinct.

## Verification

- Logic tests: 38 passed, including Persian contacts, separate multi-number fields,
  source mapping and length validation.
- Existing WordPress/MySQL integration suite: 36 passed.
- New isolated data-engine suite: 19 checks covering legacy migration, repeat activation, source import,
  deduplication, typed-contact batch storage, viewport/province filtering, public
  field allowlist, operational events and retention.
- Existing desktop/mobile Playwright suite passed after fixing an asynchronous
  marker callback surviving map disposal.
- Deterministic browser suite passed 501-marker pagination, grouping, Post/Tipax/
  neutral pins, address-only exclusion and empty-province filtering.
- PHP and JavaScript syntax checks passed.
- Populated-source browser acceptance passed: 230 actual Tipax positions, official
  provider logo filtering, ten postal addresses with no markers, and mobile rendering.
- Version 1.1.0 ZIP passed runtime allowlist validation and extracted-package checks
  for fresh activation, repeat activation, point storage, shortcode and assets.
- 10,000-row benchmark: 200 batches, 56.64 seconds total, 0.478 seconds maximum
  batch, 0.631 seconds dashboard query, 12 MiB peak PHP memory. Test rows removed.

Tests use LocalWP, PHP 8.2.29, MySQL and Chromium. The host's existing missing
Imagick startup warning remains unrelated to this plugin.

## Scope and limitations

The included sources provide regional coverage, not a complete national dataset.
See `DATA-SOURCES.md` for provenance and exclusions. Sources can be stale, and
published coordinates still require operational review. No synthetic coordinates
are supplied for postal PDFs. Additional providers use the existing provider
registry and CSV/XLSX mapping pipeline. There is no unattended remote sync.

The future admin log viewer and Excel log export are intentionally not implemented;
this phase supplies their storage and retention foundation. Minimum PHP/WordPress
versions and multisite remain unverified. No production deployment or Git push
is performed by this phase.

## Changed-file summary

- `src/Database`, repositories and validation: additive migration, contacts, source,
  quality state, geographic queries and separate dashboard counts.
- `src/Import`, normalization, `assets/data`, and collection scripts: validated
  source snapshots, repeatable explicit imports and structured metadata mapping.
- `assets/map.js`, `assets/app.css`, `assets/admin.js`, and REST API: viewport
  loading, province focus, provider pins, contact panels and management fields.
- `src/Service/OperationalLog.php`, plugin lifecycle and `uninstall.php`: operational
  events, scheduled retention and conservative cleanup.
- Tests, release build script and documentation: regression coverage, versioned
  packaging, provenance and verification evidence.

Local imported totals are 241 records, 230 mapped and 11 missing coordinates,
with no detected duplicates. All prior feature commits were preserved; the complete
phase history is available with `git log --reverse main..feature/service-point-data-engine`.
