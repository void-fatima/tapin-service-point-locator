# Phase 3 validation — 2026-09-28

Branch: `feat/service-points-xlsx-history`. Starting HEAD: `95410a659910ac06108cbbae22fc2883c292fa96`. Release **1.4.0**, database schema **6**.

## Environment

Disposable WordPress 7.1.2 with LocalWP PHP 8.2.29/MySQL 8.4.0 binaries; Playwright 1.55.1 and Chromium on Windows. The original LocalWP site was unavailable. Existing disposable source data was preserved; isolated PHP tests create/drop only their own random-prefix tables. No production reset or deployment and no live Neshan calls. The WordPress debug log did not grow during this validation.

## Results

| PHP suite | Passed | Failed |
| --- | ---: | ---: |
| `run-checks.php` | 38 | 0 |
| `geocoding.php` | 64 | 0 |
| `integration.php` | 36 | 0 |
| `data-engine.php` | 19 | 0 |
| `discovery.php` | 10 | 0 |
| `phase1.php` | 32 | 0 |
| `geocoding-integration.php` | 65 | 0 |
| `phase3.php` | 53 | 0 |
| `phase3-http.php` | 5 | 0 |
| **Total** | **322** | **0** |

All **8 browser suites** passed: `browser`, `admin-ui`, `public-ux`, `map-engine`, `source-map`, `phase1-ui`, `phase2-ui`, `phase3-ui`. Phase 2 reports **53 checks**; Phase 3 reports **61 checks**, with zero uncaught JavaScript errors. Phase 3 includes real authenticated XLSX delivery plus network-only table/history fixtures. Mobile overflow checks and screenshot review passed. Source rendering verified 230 Tipax markers and ten address-only Post entries; this is not a recount of the unavailable original site.

Syntax: **55 tracked PHP files**, plus **5 JavaScript files** (`assets/admin.js`, `assets/map.js`, Phase 1/2/3 browser suites) passed. The official-source parser passed with 31 province-document entries and Semnan/Tehran fixtures. Release-time changed PHP files received targeted syntax checks.

The full relevant suite was run once after targeted fixes. Its legacy CRUD browser suite initially stopped on an outdated status-label assertion; only that failed suite was rerun before continuing the remaining suites. No repeated full-suite runs.

## XLSX, security and lifecycle coverage

- The existing `TableReader` opened actual OOXML ZIP workbooks; XML inspection verified text postal/phone cells, numeric coordinates, blank address-only coordinates, Persian headers, RTL worksheet and frozen header. A real HTTP download was parsed and compared with the stored filtered records.
- A **505-row combined-filter export** crossed the 500-row query boundary with no duplicates/omissions. Other checks covered province, city, provider, search, active/inactive status, coordinate filters, empty results and trusted source presentation. Pagination/path parameters cannot select server files or limit the filtered export to a page.
- `=`, `+`, `-`, `@` values remain text; no formula elements exist. HTML is stripped while ordinary Persian text remains intact. Internal metadata and sentinels were absent. Postal and all phone leading zeros survived.
- Anonymous/non-admin requests and invalid/missing nonces are denied. Invalid filter types, enum values, oversized strings, invalid provider IDs and an SQL-like search were exercised. MIME, filename, no-store/nosniff headers and private response data passed. Oversized cells return a friendly failure without filesystem/exception details.
- Provider lookup occurred once; rows used two bounded 500-row repository queries. No geocoding jobs or HTTP requests were created by export. The focused stage (multiple workbooks plus checks) took about **1.5 seconds** and **48 MiB peak CLI memory** on this environment, not a maximum-size benchmark.
- Export events recorded rows, format, timestamp, user and safe filters; raw search text was omitted. Failure history did not fabricate a count. The 50-event cap and existing three-month retention passed. Browser checks covered actual import counters, warning/running/failure labels, missing statistics, export history errors and retry.
- Activation/repeated migration/deactivation/reactivation preserved records; export worked afterward. Phase 2 lifecycle tests also verify worker scheduling and ordinary-uninstall preservation. Original rows remained unchanged after isolated tests.
- `scripts/build.ps1` creates the runtime-only ZIP and rejects development/session/credential paths. The enhanced `tests/package-check.php` validates the extracted package's bootstrap, activation lifecycle, address-only save/preservation, shortcode/assets, parsed XLSX and export history. Package tests now clean their queue table and restore cron/schema options.

## Defects fixed and commits

- `833f65f`: inactive providers' names were replaced with an em dash in XLSX; include inactive registry entries for admin export. Added 53 focused PHP assertions.
- `3d758ce`: added 61 Phase 3 browser checks and five real HTTP-workbook checks.
- `4f17319`: restored the missing empty-directory clear-filters action. Updated Phase 1 selectors from obsolete cards to table rows while retaining behavior assertions.
- `432b64b`: aligned the old dashboard status assertion with the intentional paginated wording.
- `192b1bb`: strengthened extracted-package export/history/lifecycle validation.
- The subsequent `chore(release)` commit records 1.4.0 metadata and this report.

## Limits

No unresolved defect was observed in the required validation. Excel/LibreOffice desktop GUI compatibility, minimum-version/multisite execution, maximum 100,000-row stress, concurrent-writer load and hard-kill cleanup were not separately tested. No live geocoding was necessary. The feature branch remains unmerged pending user review.
