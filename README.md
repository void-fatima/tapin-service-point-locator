# Tapin Service Point Locator

Installable WordPress plugin for managing shipping service points, importing CSV/XLSX files, and publishing an interactive locator. The Persian RTL admin application follows the approved dark dashboard direction and uses actual stored data and official brand assets.

Phase 2 implementation is on `feat/geocoding-map-enrichment`, based on Phase 1 commit `97e6fd5`. See [Phase 2 configuration and handoff](docs/PHASE2.md). Tests and release packaging are explicitly deferred; the release version remains 1.2.0. Earlier verification results below do not validate Phase 2.

## Install

1. Upload `dist/tapin-service-point-locator-1.0.0.zip` through **Plugins → Add New → Upload Plugin**, then activate it. Alternatively copy the `tapin-service-point-locator` folder into `wp-content/plugins`.
2. Open **تاپین** in WordPress admin. Administrators (`manage_options`) can manage points, providers, and imports.
3. Add `[tapin_service_points]` to a Shortcode block in a public page.
4. Add real points or import a file. Installation does not insert demo service points or fabricated coordinates.

No npm/Composer installation is required on the WordPress host. JavaScript, Leaflet, font, logos, and boundary geometry are included in the ZIP.

Requirements: WordPress 6.0+, PHP 7.4+, MySQL 5.7+/MariaDB 10.3+. Resumable imports require InnoDB tables and database named locks. Excel additionally requires PHP ZipArchive, XMLReader, and SimpleXML. Files are staged in the PHP system temporary directory, which must be writable and outside the web document root. Tested here with WordPress 7.1.2, PHP 8.2.29, and MySQL 8.4.0; older minimum versions have not been runtime-tested.

## Completed workflows

- Points: create, edit, delete, search, provider/province/city/status/coordinate filters, paginated lists, branch codes, phones, postal codes, and JSON metadata.
- Address-only points: retained with an explicit missing-coordinate state; absent from map markers. Both coordinates must be supplied together. Invalid numeric values are rejected. Blank coordinate fields on an update preserve existing valid coordinates. Eligible unresolved points enter the background geocoding queue; automatic enrichment never overwrites valid coordinates.
- Providers: add/edit/delete, unique slugs, active status, configurable color and logo URL. Providers with points cannot be deleted. Inactive providers are hidden publicly.
- Dashboard: real totals, coordinate coverage, provider distribution, incomplete/duplicate candidate links, recent points and import activity.
- Public map: active points from active providers only; filters, branch detail popups, zoom/pan, reset view, an accessible branch list, and explicit loading/empty/error states. Internal metadata never appears in public responses. Map results load in pages of 500 with a visible load-more control and count.
- Imports: private preview, column mapping, provider choice, duplicate policy, resumable batches, exact row counts, diagnostic links and JSON report download.

## Import details and limits

Use the header-only template at `assets/import-template.csv`. Required mapped fields: `name`, `province`, `city`, `address`. Optional: `code`, `phone`, `postal_code`, `latitude`, `longitude`, `status`. English and Persian headers are recognized; mapping can be changed before any records are written.

- CSV: UTF-8, optional BOM, comma/semicolon/tab delimiters; at most 50 MiB. Quoted newlines are supported. Mismatched column counts fail that row with a report.
- Excel: `.xlsx`, first worksheet only, at most 10 MiB compressed / 32 MiB expanded / 8 MiB shared-string XML. Shared and inline strings are supported. Formulas, external entities, malformed XML, and excessive archive sizes are rejected. Keep codes and phones as text to preserve leading zeros.
- Legacy `.xls`, macros, formula evaluation and multiple-sheet selection are not supported. Save as `.xlsx` or UTF-8 CSV first.
- Maximum 100,000 data rows, 64 columns, 16 KiB per cell, 64 MiB staged data; preparation has a 15-second application budget. Host upload/PHP limits may be smaller. Split files when prompted.
- Each processing request handles at most 50 rows or about 2 seconds of row work. A database transaction commits rows and the checkpoint together. A shared database lock serializes web writes. Repeating a completed request does not reinsert rows.
- Closing the page pauses processing. Reopen the operation from history and choose **ادامه ورود داده**. Cancellation retains completed rows and discards unprocessed input. This is resumable browser-driven processing, not an unattended background queue.
- **Skip** reports potential duplicates without changing existing rows. **Update** updates only one unambiguous exact branch-code match within the same provider. Shared phones or matching branch identity never trigger an automatic update.
- Dashboard duplicate candidates use matching provider/code or matching provider/city/name/address. Import duplicate checks additionally flag shared provider/city/phone. Candidates need human review; nothing is automatically merged or deleted.
- Successful-with-warning rows include missing phones/coordinates and suspicious postal codes. Failed and skipped counts are separate. Full counters are retained; diagnostic detail is capped at 1,000 rows per job. The latest 50 jobs appear in the UI; older history remains in the database.
- Completed/cancelled staging files are deleted immediately. Abandoned jobs expire after 24 hours through hourly WordPress cron or the next upload. On low-traffic sites, schedule real WP-Cron. Host cleanup of temporary files may cause earlier expiry.

## Maps, brands and privacy

See [third-party attribution](docs/THIRD-PARTY.md). Official logos are unchanged. Provider registry marker colors follow the approved dashboard: Post yellow, Tipax green, other providers violet. Provider IDs are not hardcoded. Existing custom provider styles remain available.

The map uses real geographic geometry and real saved points. It does not reproduce the mockup's illustrative markers or metrics. Bundled boundaries are historical contextual data, not an authoritative administrative registry. WordPress navigation remains available around the scoped plugin UI.

OpenStreetMap background tiles require internet and expose browser IP/referrer to that service. Use a production tile provider appropriate for your traffic. Server-side filters `tapin_tile_url` and `tapin_tile_attribution` can replace the defaults; retain required attribution. Public tile URLs must not contain private server credentials. Local boundaries and the accessible branch list still work when external tiles fail. Custom provider-logo URLs are also loaded by the visitor's browser.

## Development and verification

All feature branches are cumulative: `feature/service-points` → `feature/imports` → `feature/dashboard-ui`. The final branch includes the earlier branches; no force-push or main-branch merge is performed.

From a shell with PHP on PATH:

```powershell
php tests/run-checks.php
$env:TAPIN_WP_ROOT = 'C:\path\to\development-wordpress'
php tests/integration.php
npm ci
npx playwright install chromium
$env:TAPIN_SESSION_FILE = Join-Path $env:TEMP 'tapin-browser-session.json'
php tests/browser-session.php
npm run test:browser
php tests/browser-session.php cleanup
```

For LocalWP use its Site Shell or pass its PHP executable and site `php.ini` with `-c`. Integration tests use an isolated temporary provider and delete their test rows. The browser helper creates a one-hour session for an existing local administrator without changing the password; keep the session file private and always run cleanup. It also creates a local review page at `/tapin-service-points/` if absent. Run browser tests only on a development site. Screenshots go to ignored `artifacts/`.

Run the optional 10,000-row development import benchmark with `php tests/benchmark.php`. It uses isolated fixture data and removes it afterward. Results are host-specific, not a guarantee for shared hosting.

Build the ZIP on Windows with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build.ps1
```

The build copies an explicit runtime allowlist and verifies required assets and prohibited-file exclusions. See [verification record](docs/VERIFICATION.md) for completed checks and practical limits.

## Public locator reference

The client reference at [Tapin's province map](https://tapin.ir/map/) currently opens PDF branch directories, such as [Semnan](https://tapin.ir/map/semnan.pdf), containing branch names, addresses, postal codes and phones. The public shortcode follows that province-to-branch workflow in a light RTL interface, with the requested additional provider filters and live markers. The dark dashboard design remains specific to administration.

Click a province or choose it from the accessible select, narrow by provider/city, or search for a branch, address or phone. Provider changes update the available provinces and cities. Cards expose postal codes and telephone links. Active branches without coordinates remain visible as address-only cards, never as fabricated markers. Inactive branches and inactive providers are excluded publicly. The existing fields support these needs without a schema rebuild or automatic copying/geocoding of the reference PDFs.

`/public/points` remains marker-only; `/public/directory` includes address-only entries. Both omit private metadata. Dashboard metrics distinguish public directory entries, public markers, stored coordinate coverage and inactive records. Its operational map continues to include inactive records for administrators and labels this explicitly.

## Data retention policy

Normal deactivation and uninstall preserve business data. To deliberately erase all plugin tables/options on uninstall, define `TAPIN_UNINSTALL_DROP_DATA` as `true` in `wp-config.php` first. This is irreversible. Take a database backup before upgrades or destructive operations. The plugin does not upload files to a third-party processing service and ships no credentials.
