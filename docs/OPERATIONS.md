# Operations and development guide

[Back to the project overview](../README.md)

Commands and paths below are relative to the repository root.

## Import details and limits

Use the headers in the [import template](../assets/import-template.csv) and save the workbook as **XLSX** for upload. The admin UI and REST upload route accept XLSX only; CSV is supported by the internal staging engine and source-data tooling.

Column detection is automatic. `name` and `address` must be recognized before a job starts. A saved record also needs a valid provider and province; verified source evidence can supply a missing province. City may remain blank with a warning. Optional columns include `code`, contact fields, `postal_code`, coordinates, `status`, `source` and `metadata`. Rename an unrecognized header in the workbook and upload it again; there is no manual column-mapping control in the current preview.

With a `provider` column, review each distinct label and choose its registered counterpart when necessary. Empty provider values must be fixed in the workbook. Without that column, select one provider for the file. These provider choices are separate from automatic column detection.

- CSV (internal engine): UTF-8, optional BOM, comma/semicolon/tab delimiters; at most 50 MiB. Quoted newlines are supported. Mismatched column counts fail that row with a report.
- Excel: `.xlsx`, first worksheet only, at most 10 MiB compressed / 32 MiB expanded / 8 MiB shared-string XML. Shared and inline strings are supported. Formulas, external entities, malformed XML, and excessive archive sizes are rejected. Keep codes and phones as text to preserve leading zeros.
- Legacy `.xls`, macros, formula evaluation and multiple-sheet selection are not supported. For web upload, save as a values-only `.xlsx` first.
- Maximum 100,000 data rows, 64 columns, 16 KiB per cell, 64 MiB staged data; preparation has a 15-second application budget. Host upload/PHP limits may be smaller. Split files when prompted.
- Each processing request handles at most 50 rows or about 2 seconds of row work. A database transaction commits rows and the checkpoint together. A shared database lock serializes web writes. Repeating a completed request does not reinsert rows.
- Closing the page pauses processing. Reopen the operation from history and choose **ادامه ورود داده**. Cancellation retains completed rows and discards unprocessed input. This is resumable browser-driven processing, not an unattended background queue.
- **Skip** reports potential duplicates without changing existing rows. **Update** updates only one unambiguous exact branch-code match within the same provider. Shared phones or matching branch identity never trigger an automatic update.
- Dashboard duplicate candidates use matching provider/code or matching provider/city/name/address. Import duplicate checks additionally flag shared provider/city/phone. Candidates need human review; nothing is automatically merged or deleted.
- Successful-with-warning rows include missing phones/coordinates and suspicious postal codes. Failed and skipped counts are separate. Full counters are retained; diagnostic detail is capped at 1,000 rows per job. The latest 50 jobs appear in the UI; older history remains in the database.
- Completed/cancelled staging files are deleted immediately. Abandoned jobs expire after 24 hours through hourly WordPress cron or the next upload. On low-traffic sites, schedule real WP-Cron. Host cleanup of temporary files may cause earlier expiry.

## Maps, brands and privacy

See [third-party attribution](THIRD-PARTY.md). Official logos are unchanged. Provider registry marker colors follow the approved dashboard: Post yellow, Tipax green, other providers violet. Provider IDs are not hardcoded. Existing custom provider styles remain available.

The map uses real geographic geometry and real saved points. It does not reproduce the mockup's illustrative markers or metrics. Bundled boundaries are historical contextual data, not an authoritative administrative registry. WordPress navigation remains available around the scoped plugin UI.

OpenStreetMap background tiles require internet and expose browser IP/referrer to that service. Use a production tile provider appropriate for your traffic. Server-side filters `tapin_tile_url` and `tapin_tile_attribution` can replace the defaults; retain required attribution. Public tile URLs must not contain private server credentials. Local boundaries and the accessible branch list still work when external tiles fail. Custom provider-logo URLs are also loaded by the visitor's browser.

## Development and verification


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

The build copies an explicit runtime allowlist and verifies required assets and prohibited-file exclusions. See [verification record](VERIFICATION.md) for completed checks and practical limits.

## Public locator reference

The client reference at [Tapin's province map](https://tapin.ir/map/) currently opens PDF branch directories, such as [Semnan](https://tapin.ir/map/semnan.pdf), containing branch names, addresses, postal codes and phones. The public shortcode follows that province-to-branch workflow in a light RTL interface, with the requested additional provider filters and live markers. The dark dashboard design remains specific to administration.

Click a province or choose it from the accessible select, narrow by provider/city, or search for a branch, address or phone. Provider changes update the available provinces and cities. Cards expose postal codes and telephone links. Active branches without coordinates remain visible as address-only cards, never as fabricated markers. Inactive branches and inactive providers are excluded publicly. The existing fields support these needs without a schema rebuild or automatic copying/geocoding of the reference PDFs.

`/public/points` remains marker-only; `/public/directory` includes address-only entries. Both omit private metadata. Dashboard metrics distinguish public directory entries, public markers, stored coordinate coverage and inactive records. Its operational map continues to include inactive records for administrators and labels this explicitly.

## Data retention policy

Normal deactivation and uninstall preserve business data. To deliberately erase all plugin tables/options on uninstall, define `TAPIN_UNINSTALL_DROP_DATA` as `true` in `wp-config.php` first. This is irreversible. Take a database backup before upgrades or destructive operations. The plugin does not upload files to a third-party processing service and ships no credentials.
