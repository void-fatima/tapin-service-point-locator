# Phase 1 — official source, filtering and approved dashboard

Branch: `feat/tapin-source-dashboard-refinement`. Release: **1.2.0**.
Verified against the existing LocalWP site on 2026-09-28 (Asia/Tehran).
Database tables, existing REST routes, import jobs, duplicate detector and repository architecture remain in place. No geocoding or Excel export was added.

On continuation, local services had stopped. Validation resumed with LocalWP's bundled MySQL/PHP and the existing development HTTP router, using the same WordPress root and database; production web-server configuration was not changed.

## Production-like data cleanup

The initial LocalWP table contained 243 points. Exactly two had no source:

| ID | Name | Code | Action |
| --- | --- | --- | --- |
| 11037 | Debug 1790536145363 | DBG-1790536145807 | Removed |
| 11038 | Trace 1790536355201 | DBG-1790536355679 | Removed |

All 241 source-backed records remain: 231 Tipax Tehran records and ten Semnan postal records; 230 have coordinates and 11 are address-only. No other demo/default service points were found in this database. Legitimate Tehran data was preserved.

No checked-in runtime or test code creates the two names or `DBG-` codes. Activation seeds providers only. The signatures indicate prior debug activity, but the exact originating script cannot be established from repository evidence. This is not attributed to a particular test without evidence.

`scripts/cleanup-synthetic.php` is dry-run by default; `--apply` rechecks the exact Trace/Debug timestamp name, DBG timestamp code, empty source and empty metadata before deletion. It never selects by province. It is not called on activation. Browser fixtures now use unique names, register cleanup information before creation, and clean points/providers in `finally`; the private session helper also supports recovery after interrupted tests. Database regression fixtures use disposable tables or temporary providers with cleanup.

## Official source and review workflow

The live index is [tapin.ir/map](https://tapin.ir/map/). Its 31 unique linked PDF URLs were downloaded and inspected by the collector. See [the source catalog](TAPIN-SOURCE-CATALOG.md) for every actual URL and extraction result. This is a postal address directory, not a coordinate API.

The reconciliation baseline contains:

- Ten previously reviewed [Semnan records](https://tapin.ir/map/semnan.pdf), preserving the existing starter CSV. The malformed phone in row 3 and duplicate row 9 remain excluded.
- 27 reviewed [Tehran records](https://tapin.ir/map/tehran.pdf), with exact URL, page, row, document SHA-256 and retrieval/review times in `assets/data/tapin-tehran-reviewed.json`. City is present only where branch name/address explicitly establishes it. Eleven ambiguous/invalid extracted Tehran rows remain outside the baseline.

The 27 Tehran reference rows were also installed into LocalWP's reference snapshot option to exercise the operator workflow. They were **not** published as new service points. No test/debug records were replaced with unreviewed source records.

Commands, from the development repository:

```sh
npm ci
node scripts/collect-tapin.mjs          # discover/download every linked province document
node scripts/collect-tapin.mjs semnan   # or inspect one province
node tests/tapin-source.mjs             # tests actual downloaded Semnan/Tehran PDFs
# Set TAPIN_WP_ROOT to the WordPress root for the PHP commands.
php scripts/import-tapin.php reviewed-province.json --reviewed
php scripts/import-tapin.php reviewed-province.json --reviewed --import
php scripts/cleanup-synthetic.php
php scripts/cleanup-synthetic.php --apply
```

The collector infers table columns from page headers, normalizes Persian presentation forms and digits, leaves unknown cities blank, and quarantines malformed or unsupported rows. Output goes to ignored `artifacts/tapin-directory/`. Review every candidate against its original PDF before passing `--reviewed`; remove ambiguous/duplicate rows or correct extraction errors only from the source. Candidate JSON is not a claim of verified national coverage. Some PDFs require manual transcription because their layout or text encoding is unsupported. The importer supports reviewed snapshots from every officially linked province, checks the live catalog, rejects coordinates and source/province inconsistencies, and preserves exact document provenance. Refreshes replace the reference snapshot for that document only. `--import` is explicit and uses the existing row processor and duplicate protection.

`pdfjs-dist` is a development-only extraction dependency (Apache-2.0); it is not included in the WordPress ZIP. Runtime imports do not make a network request per uploaded row.

## Reconciliation and field preservation

CSV and XLSX continue through column mapping → `DataNormalizer` → `TapinDirectory` → existing validator → duplicate detector → repository. Only the Post provider uses the postal reference.

Matching priority is exact normalized ten-digit postal code, then normalized valid landline with compatible province/city, then normalized name with compatible province/city. Postal/phone identity can be `verified`; name-only identity is `probable_match`. Ambiguous identities or contradictory locality produce `conflict`; no match produces `not_found`. Address similarity alone never verifies identity. `not_found` means absent from the reviewed snapshot, not absent nationwide.

Only empty fields are enriched after a verified match. Existing uploaded name/address/contact values are preserved. `metadata.tapin_reconciliation` records the raw original upload, normalized upload, official fields, result, match signal, exact source URL, reconciliation time and source retrieval time. The top-level uploaded `source` and existing coordinate metadata remain intact. No redundant table columns were added. Private metadata stays excluded from public responses.

Valid uploaded coordinate pairs remain unchanged. Source-only rows stay address-only and `missing_coordinates`; incomplete, malformed and out-of-range pairs still fail the existing validator. Mobile and landline fields remain independent. Unknown cities now yield a warning instead of encouraging fabricated city values. Province remains required after reconciliation.

## Dashboard and shared filters

The approved mockup determines the dashboard composition: navy RTL shell, yellow upload and purple add-branch actions, far-right filter title, left overview/provider distribution, dominant right map, and a bottom collapsible provider drawer with its title on the far right. Real counts replace illustration counts. There is one Other group; Post/Tipax retain the project's official logos. Drawer logos have keyboard/hover labels. The recent service-point/import tables and duplicate-candidate tile are removed from the dashboard; history and duplicate detection remain in their dedicated workflows.

Province/city options come from saved records through the existing locations endpoint. Persian/Arabic letters and digit variants are normalized and deduplicated. City options follow province/provider selection and discard incompatible selections. The service-point management page also now has a dependent city select.

The existing map widget owns search/provider/province/city state. Province polygons call the same change handler as the dropdown, reset the city, fit province bounds and refresh directory, markers and counts. SVG paths provide keyboard activation, labels and hover/selected styling. Geometry and stored coordinates are unchanged. Nationwide reset clears location state while retaining search/provider; full clear resets all controls.

The admin directory no longer forces coordinates. The points endpoint optionally computes aggregate counts using precisely the same SQL predicates as the paginated directory. Marker requests use the same filters plus valid coordinates and viewport bounds. Aborts/generation checks and immediate marker clearing prevent stale results. Empty provinces show the Persian empty state and disable a city select with no known cities. Accessible address-only cards never receive map buttons.

The production-like dataset is concentrated in Tehran, so nationwide markers naturally cluster there. The mockup's geographically scattered markers were not copied. Standard WordPress navigation/notices remain around the plugin. The map uses real geographic boundaries with restrained color treatment rather than the illustration's decorative texture.

Final viewport regression checks also cover rapid province changes: animated zoom transitions could previously override the latest requested bounds. Province/reset navigation now applies bounds immediately. Missing provider logos use the correct provider text instead of introducing extra Other labels, and header actions remain side by side on narrow layouts. Late responses from a disposed map are ignored.

## Validation

Executed on LocalWP PHP 8.2.29 / MySQL and Chromium through Playwright:

| Suite | Result |
| --- | --- |
| `tests/run-checks.php` | 38 passed, 0 failed |
| `tests/integration.php` | 36 passed, 0 failed |
| `tests/data-engine.php` | 19 passed |
| `tests/discovery.php` | 10 passed |
| `tests/phase1.php` | 32 passed |
| `tests/browser.cjs` | Passed CRUD, imports, nonce rejection, public map and mobile |
| `tests/admin-ui.cjs` | Passed dashboard, management table, details, imports and mobile |
| `tests/public-ux.cjs` | Passed actual-data UX and isolated failure/loading states |
| `tests/map-engine.cjs` | Passed pagination, clustering, provider pins and empty province |
| `tests/source-map.cjs` | Passed actual source-backed data and address-only behavior |
| `tests/phase1-ui.cjs` | Passed shared filters/counts, polygon activation, reset, empty state, drawer, desktop/mobile layout and zero JS errors |
| `tests/tapin-source.mjs` | Passed real PDF parsing, 31-document discovery, normalization, rejected rows, exact URLs/hashes and no invented city/coordinates |
| PHP syntax / JavaScript syntax | 39 PHP files and both runtime JavaScript files passed |
| Extracted 1.2.0 ZIP | Passed real WordPress bootstrap, fresh/repeated activation, point save, shortcode and required runtime assets |
| `tests/benchmark.php` | 10,000 rows / 200 batches in disposable tables; 38.839 seconds import, 0.263 seconds maximum batch, 0.348 seconds dashboard aggregation, 12 MiB peak memory |

The public UX suite initially expected a canvas element. It was updated to assert the rendered SVG geometry because keyboard-accessible province paths now use SVG; the rerun passed. A browser run overlapping the original benchmark failed when benchmark inserts displaced its newly created row from page one. The sequential rerun passed; the benchmark was then moved to disposable tables to prevent live-dashboard interference. The local PHP installation still prints its pre-existing missing Imagick DLL startup warning; Imagick is not used by the plugin.

Screenshots are in ignored `artifacts/phase1-dashboard.png`, `phase1-filter-desktop.png` and `phase1-filter-mobile.png`. Test fixture screenshots are explicitly synthetic network responses, never saved branches.

## Files and delivery

Changes are concentrated in `assets/admin.js`, `assets/map.js`, new `assets/dashboard.css`, the reviewed source JSON, `src/Import/`, `src/Normalization/DataNormalizer.php`, `src/Repository/ServicePointRepository.php`, `src/Http/Api.php`, `src/UI/App.php`, `src/Validation/ServicePointValidator.php`, new `src/Service/SyntheticCleanup.php`, operator scripts and tests. `uninstall.php` removes the snapshot option only when explicit full data removal is requested. Version 1.2.0 invalidates old browser asset caches. The database schema version is unchanged.

Use `git diff --name-only main...HEAD` for the exact file list and `git log --format='%H %s' main..HEAD` for the complete atomic commit list. All Phase 1 commits use `void-fatima <vantafatima@gmail.com>` without co-author trailers. The final handoff records push verification and the remote branch link; no merge to main is performed.

Remaining source maintenance: review/transcribe additional province PDFs before extending verified coverage, and periodically refresh undated documents. No claim is made that every source office is currently operating.

Phase 2 remains automatic geocoding and later map-detail enrichment. Phase 3 remains final filtered accessible-table/XLSX export and final import/export-history polish. Neither phase was started.
