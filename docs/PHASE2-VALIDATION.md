# Phase 2 validation — 2026-09-28

Branch: `feat/geocoding-map-enrichment`. Validation baseline: `42c7d25`; resumed integration work after `dfc8cc6`. Release: **1.3.0**, database schema **6**.

## Environment and limits

Windows; WordPress 7.1.2; LocalWP PHP 8.2.29 and MySQL 8.4.0 binaries; Playwright 1.55.1 with Chromium. No registered existing LocalWP site was available. A disposable WordPress/database under ignored `artifacts/validation-runtime` was used; no original site was reset or modified. Source-map validation verified 230 published Tipax positions and ten address-only Post records from bundled sources, not a recount of the unavailable original site's reported 241 records.

Neshan credentials were unavailable. Live smoke testing was skipped, not failed; automated geocoding uses mocked provider responses. No credentials or runtime/session artifacts are packaged or committed. Minimum PHP/WordPress versions, multisite and production deployment remain unverified.

## Final automated results

| Check | Result |
| --- | --- |
| PHP syntax | 50 tracked PHP files passed |
| JavaScript syntax | `assets/admin.js`, `assets/map.js` passed |
| `tests/run-checks.php` | 38 passed, 0 failed |
| `tests/geocoding.php` | 64 passed, 0 failed |
| `tests/integration.php` | 36 passed, 0 failed |
| `tests/data-engine.php` | 19 passed |
| `tests/discovery.php` | 10 passed |
| `tests/phase1.php` | 32 passed |
| `tests/geocoding-integration.php` | 65 passed, 0 failed |
| `tests/tapin-source.mjs` | Passed; 31 document discovery entries, Semnan/Tehran parser fixtures |
| Browser suites | `browser`, `admin-ui`, `public-ux`, `map-engine`, `source-map`, `phase1-ui`, `phase2-ui`: all passed |
| Phase 2 browser assertions | 53 passed; zero uncaught JavaScript errors |

The final combined suite was run once. Failures were followed only by targeted reruns. Total PHP assertions: **264 passed**. Lifecycle coverage verifies migration repeatability, preservation across deactivation/reactivation, worker rescheduling, ordinary uninstall preservation and removal of scheduled work. Isolated database tests verify pre-existing rows remain unchanged. Browser checks include actual admin/public loading, approved dashboard/mobile layout, REST nonce enforcement and Phase 1 filter regression.

## Defects and test corrections

- Fixed marker keyboard activation: focused markers now open branch details on Enter/Space.
- Updated old coordinate-clearing and arbitrary uploaded-provenance expectations to the approved Phase 2 preservation/trusted-origin policy.
- Updated mocked detail responses and co-located marker-picker assertions for the Phase 2 detail endpoint.
- Corrected lifecycle test queue ordering to the actual `point_id` key, required nonempty preserved rows, and isolated the worker callback instead of replaying WordPress core initialization.

Log review found the initial failing assertions and test-harness SQL/core-registration diagnostics above; targeted corrected runs passed. Earlier WordPress update-connectivity warnings came from blocked external update checks, not Phase 2. No new Phase 2 application warnings/notices were observed in the corrected runs.

## Packaging and deferred work

`scripts/build.ps1` passed with **73 archive entries** in `dist/tapin-service-point-locator-1.3.0.zip`. `tests/package-check.php` passed against the extracted ZIP: real WordPress bootstrap, fresh/repeated activation, address-only save, shortcode and bundled assets. Development files, credentials and sessions are excluded by the build checks.

Phase 3 remains untouched: accessible-points table redesign, XLSX/filtered export and filenames/metadata, final import/export history, delivery audit and final merge/release work.
