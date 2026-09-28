# Phase 3 implementation handoff — validation deferred

Branch: `feat/service-points-xlsx-history`.
Phase 2 base: `ec37b129b8d70496ded2ef9acb9678d45b644fb9`.

**Phase 3 implementation was completed without running tests at user request.** No PHP/JavaScript syntax checks, automated suites, browser sessions, activation/deactivation checks, source parser, benchmarks, packaging or live Neshan requests were run. Phase 2 results are not evidence of Phase 3 correctness.

Release constants, stable tag and database version remain **1.3.0 / schema 6**. No new validated release, ZIP, PR or main merge was produced. A potential 1.4.0 release requires the separate validation/review session.

## Accessible directory and filters

- Extended the existing admin map's “فهرست قابل دسترس نقاط”, not a competing directory. It remains collapsible and now uses a real RTL table with caption, column/row headers, keyboard-focusable contained scrolling, full addresses, empty/loading/error states and previous/next controls.
- Columns: provider, branch, province, city, address, postal code, landline, location status and branch details. Provider display comes from the registry; no hardcoded provider IDs or demo rows.
- The existing `points?map_view=1` endpoint applies shared repository predicates and the Phase 2 trusted-field allowlist. Table pagination retains at most **50 rows** rather than accumulating all results in browser memory.
- Search, province, city and provider use the existing map filter state; table results include address-only/inactive records and are not restricted to the current map viewport. The management-page export additionally follows its applied coordinate, status and issue filters.
- Public directory cards, marker/detail architecture, clustering, geocoding policies, provider identities and the bottom provider drawer are unchanged. Only admin directory rendering/pagination and export controls were added to the shared map script.

## XLSX export

- `POST /tapin/v1/exports/points` requires `manage_options` plus a valid `X-WP-Nonce` (`wp_rest`). Both the accessible directory and service-point management offer “خروجی اکسل”, explicitly for **all applied filtered results**, not only the visible page. Unsubmitted management-form edits are not applied filters.
- Filters reuse `Api::filters()` and `ServicePointRepository::query()`. Pagination, viewport bounds and caller sorting cannot restrict or broaden the export. The result is ordered by stored ID.
- A read-only repeatable-read transaction provides one consistent database snapshot. Rows are fetched in **500-row batches**; providers are fetched once. Display values reuse `PointEvidence::fields()` without writing records, geocoding or scraping.
- `Export/Workbook.php` writes an OOXML worksheet to private temporary storage and packages it with the existing PHP `ZipArchive` extension. No new package dependency. `DownloadResponse` streams the binary workbook through the REST serving hook; no server path or JSON-encoded workbook is returned.
- Filename: `tapin-service-points-YYYY-MM-DD.xlsx`, using the WordPress site date. Worksheet: `نقاط خدماتی`, RTL, frozen/header-filter row, contrasting header and practical widths with wrapped addresses.
- Persian columns: provider, branch, province, city, address, postal code, landline, mobile, general phone, latitude, longitude, location status. General phone is blank when it duplicates a dedicated phone field.
- Postal codes, phones and all other text use explicit OOXML `inlineStr` cells: leading zeros remain text and formula-like values never become formulas. HTML and invalid XML characters are removed. Valid coordinates are numeric; address-only coordinates stay empty, never zero-filled.
- Limits: **100,000 rows**, **64 MiB uncompressed worksheet XML**, and a row-generation deadline of at most **45 seconds**, reduced to leave five seconds under PHP's configured execution limit where possible. Oversized datasets/cells fail rather than silently truncate; narrow filters and retry. The browser receives the finished ZIP as a Blob, not all source rows or a client-generated workbook.

## Import/export history

- Detailed history remains under “ورود فایل‌ها”. Import rows show filename, browser-local date/time, provider when stored, actual total/success/skipped/failed/warning counts and Persian status labels. Missing counters display “—”. Completed jobs with recorded warnings/failures receive a derived warning label without changing backend states. Existing resume/cancel/detail/diagnostic actions remain.
- Export history reuses `tapin_logs` (`export_completed`, `export_failed`) and the existing three-month cleanup. `GET /tapin/v1/exports` is admin-only and returns the latest **50 events**. No new history table or migration.
- Events record timestamp, requesting user ID, XLSX format, available row count and safe filter context. Raw search text is intentionally not logged because it may contain private addresses or phones; only its presence is recorded.
- “File ready” means generation succeeded, not proof the browser saved the download. Files are not archived for re-download. Permission/input rejections and hard process termination are not guaranteed generation-history events. Logging is best effort; it does not prevent a valid export if the log write fails.
- History has an independent loading/error/retry area, so export-history failure does not block uploading. No full history table was added to the dashboard.

## Safety and deferred validation

Filters are type/length/enum checked; provider IDs must be valid positive integers. Existing prepared repository queries remain the only point-filter implementation. Export accepts no filesystem path, exports only fixed user-facing columns, sends no-cache download headers, uses temporary files outside `ABSPATH`, and attempts cleanup on success, failure and PHP shutdown. User-facing failures do not include exception details or filesystem paths.

Requires writable private PHP temporary storage, ZipArchive and transactional InnoDB/MySQL snapshot support. Host limits may be tighter; abnormal OS termination can require administrator cleanup of private `tapin-export-*` leftovers. Snapshot cost, concurrent edits, execution limits, actual download delivery, Excel/LibreOffice interoperability and runtime compatibility are **unverified** in this session.

Later validation must cover workbook structure/types/formula safety, exact filtered multi-page row sets and address-only records, REST permissions/nonces/malformed input, temp cleanup/resource failures, history counts and retention, RTL/mobile/keyboard UX, and Phase 1/2 dashboard/map/import regressions. Release packaging and lifecycle checks are also deferred.

## Implementation commits

- `7621c30` — paginated accessible service-point table.
- `562ac8e` — server-side filtered XLSX export and safe event recording.
- `42881ea` — import summaries and export activity views.
- `4148af9` — export input/resource safeguards and directory error states.
- The subsequent `docs(phase3)` commit contains this handoff.

Each stage was committed and pushed immediately using `void-fatima <vantafatima@gmail.com>`. Existing branches and Phase 2 commits were preserved.
