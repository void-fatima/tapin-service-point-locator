# Tapin Service Point Locator — Project Handover

## 1. Project Overview

Tapin Service Point Locator is a Persian-first WordPress plugin for administrators managing shipping and logistics service points. It provides a provider registry, service-point management, CSV/XLSX import and export, operational history, an admin dashboard, optional address geocoding, and a public map and directory. Public visitors can search and filter published branches; address-only records remain available in the directory without misleading map markers.

## 2. Repository and Release Information

- Main plugin entry: `tapin-service-point-locator.php`
- Plugin release: **1.4.0**; database schema version: **6**
- Requirements declared by the plugin: WordPress **6.0+**, PHP **7.4+**. The documented runtime database is MySQL/MariaDB with InnoDB transactions and named-lock support.
- The README identifies `main` as the main branch. GitHub currently reports `feat/backend-foundation` as the repository default branch; that branch contains an older 0.1.0 bootstrap. The 1.4.0 release and current documentation are on `main`.
- The package is a WordPress plugin directory containing the entry file, `src/`, and bundled runtime assets. Build an uploadable archive with `scripts/build.ps1`; the documented output is `dist/tapin-service-point-locator-1.4.0.zip`.

## 3. Installation

1. If building from source on Windows, run `powershell -ExecutionPolicy Bypass -File scripts/build.ps1` from the repository root.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, select the ZIP, install it, and activate **Tapin Service Point Locator**.
3. Open **تاپین** in the admin menu. Administration requires the `manage_options` capability.

No Composer or npm installation is required on the production WordPress host. XLSX import requires PHP `ZipArchive`, `XMLReader`, and `SimpleXML`; XLSX export requires `ZipArchive`. CSV import does not require those Excel extensions. The plugin bundles Leaflet, fonts, brand assets, and geographic data.

## 4. Public Locator Setup

Add this shortcode to a WordPress page, for example in a Shortcode block:

```text
[tapin_service_points]
```

The public interface provides a searchable branch directory and interactive map. Public responses include active service points from active providers and omit private source and metadata fields.

## 5. Data Import

The importer accepts UTF-8 CSV (optional BOM; comma, semicolon, or tab delimiters) and values-only XLSX (first worksheet). Legacy XLS, formula evaluation, and multi-sheet selection are unsupported. Convert those workbooks to CSV or XLSX values first.

Map the available columns during preview. Core branch information includes name, province, and address; city is recommended and may be left blank with a warning. Optional fields include provider/code, postal code, phone fields, latitude/longitude, status, source, and metadata. Coordinates must be a valid pair.

Imports support up to 100,000 data rows and 64 columns, subject to file, PHP, and host limits. Processing is browser-driven in batches. Closing the page pauses the job; resume it from import history while its staging file is still available. Completed rows remain saved if a job is cancelled. The latest 50 jobs appear in the UI; older history remains stored. See [import limits and retention](OPERATIONS.md#import-details-and-limits).

Rows without valid coordinates are retained for search and directory display but never receive map markers. The importer reports warnings and failures; duplicate candidates require review. **Skip** leaves existing rows unchanged. **Update** only updates an unambiguous exact branch-code match for the same provider.

## 6. Service Point and Provider Management

Administrators can create, view, edit, delete, search, filter, and paginate service points. Filters include provider, province, city, active status, coordinate availability, and data-quality issues. The provider registry manages names, slugs, logos, colors, and active status; a provider with linked branches cannot be deleted.

Dashboard totals distinguish directory records from map markers and account for filters and inactive data. Duplicate detection identifies candidates for human review; records are not automatically merged or deleted. Import and export activity is available through the file-management/history interface.

## 7. Map and Geocoding

The map uses bundled **Leaflet 1.9.4** with OpenStreetMap background tiles by default. Iran province geometry and neighboring-country outlines are bundled locally. Background tiles require internet and must retain their attribution. A site can replace the tile URL and attribution through the `tapin_tile_url` and `tapin_tile_attribution` WordPress filters.

Only valid stored coordinates become markers. Address-only branches remain in the directory. The default optional geocoding adapter is Neshan; configure `TAPIN_NESHAN_API_KEY` as a private `wp-config.php` constant or PHP process environment variable. `TAPIN_NESHAN_PLUS` selects the Plus endpoint when configured. A custom adapter can be supplied through the `tapin_geocoder` filter.

Geocoding runs through WordPress cron, validates candidates against Iran geometry and source evidence, and does not use city/province centroids or fallback coordinates. Without a key, no Neshan requests are made. Low-traffic sites may need a real scheduler to trigger WP-Cron.

## 8. Data Sources

The primary reference is [Tapin’s province map](https://tapin.ir/map/), which links to postal-office PDFs rather than a coordinate API. [DATA-SOURCES.md](DATA-SOURCES.md) documents source provenance and limitations; [TAPIN-SOURCE-CATALOG.md](TAPIN-SOURCE-CATALOG.md) lists discovered province documents and extraction candidates.

The bundled reviewed snapshots contain ten Semnan postal records and 231 Tipax Tehran records: 241 records total, 230 with coordinates and 11 address-only. These files are regional starter data, not nationwide coverage or a continuously synchronized directory. Activation seeds providers but does not import service points. Review the source documentation before explicitly importing the bundled snapshots.

## 9. Data Persistence and Uninstall Behavior

Deactivation clears scheduled plugin jobs and temporary cache state but preserves service points, providers, settings, and other business data.

Normal uninstall removes scheduled hooks but preserves plugin tables and options. To deliberately drop plugin data during uninstall, set `TAPIN_UNINSTALL_DROP_DATA` to `true` in `wp-config.php` before uninstalling. This removes the plugin’s tables and options, including import, log, and geocoding data. Back up the database before upgrades or destructive maintenance.

## 10. Testing and Verification

The repository’s [verification record](VERIFICATION.md), [Phase 2 validation](PHASE2-VALIDATION.md), and [Phase 3 validation](PHASE3-VALIDATION.md) describe the checks actually run and their environments.

The Phase 3 record reports 322 passing PHP assertions across the listed suites and eight passing browser suites, including map, admin, public UX, geocoding, import/export, and lifecycle checks. Package checks exercised the extracted ZIP in disposable WordPress tables. Validation used WordPress 7.1.2, PHP 8.2.29, and MySQL 8.4.0; the minimum declared versions, multisite, and production deployment were not verified. No live Neshan request was made. Do not treat isolated fixtures or bundled reference snapshots as production or nationwide data.

## 11. Operations / Maintenance

- Take a database backup before upgrades or destructive changes.
- Use the import template at [assets/import-template.csv](../assets/import-template.csv). Check file limits and review import warnings/history after each run.
- Staging files for completed or cancelled jobs are removed immediately; abandoned jobs expire after 24 hours. WP-Cron performs scheduled cleanup, so configure a system-triggered cron on low-traffic sites if timely cleanup or geocoding is needed.
- Operational logs retain meaningful events and are cleaned after three months. Export history records generation outcomes, not proof that a browser saved the file; generated workbooks are not archived for re-download.
- Keep OpenStreetMap attribution visible and choose a tile provider appropriate for production traffic. Tile requests disclose the visitor’s IP address and referrer to the tile provider.
- Build updates with `scripts/build.ps1`, upload the resulting ZIP through WordPress, and verify the dashboard, import flow, and public shortcode after activation.
- For troubleshooting and local verification commands, see [OPERATIONS.md](OPERATIONS.md). For third-party licenses and attribution, see [THIRD-PARTY.md](THIRD-PARTY.md).

## 12. Known Limitations

- A point without a valid coordinate pair cannot appear as a map marker.
- The bundled source data is regional and not a complete national directory.
- Map background tiles depend on an external network service; bundled boundaries and the branch directory remain available if tiles fail.
- XLS import, formulas, and selecting additional worksheets are unsupported.
- Imports pause when the browser page closes; they resume from history only while staging data remains available.
- Geocoding is optional, depends on configured Neshan access and WP-Cron, and may return ambiguous or unverified candidates for human review.
- Bundled province geometry is historical contextual data, not an authoritative current administrative registry.

## 13. Key Files

| Path | Purpose |
| --- | --- |
| `tapin-service-point-locator.php` | Plugin metadata, bootstrap, and lifecycle hooks |
| `src/` | Database, API, UI, repositories, imports, exports, validation, logging, and geocoding |
| `assets/` | Admin/public UI, Leaflet, fonts, provider logos, map boundaries, and reference data |
| `docs/` | Operations, source provenance, phase notes, third-party attribution, and validation records |
| `scripts/` | Release packaging and source/reference data utilities |
| `tests/` | PHP, WordPress integration, browser, and package verification |
| `uninstall.php` | Scheduled-hook cleanup and explicitly gated data removal |

## 14. Handover Checklist

- [ ] Install and activate the plugin; confirm the **تاپین** admin page loads.
- [ ] Verify dashboard counts against the current database.
- [ ] Import a small sample CSV or XLSX; review column mapping, warnings, counters, and history.
- [ ] Add `[tapin_service_points]` to a test page and verify the public directory and map.
- [ ] Confirm valid coordinates create markers and address-only records remain directory entries.
- [ ] Check provider, province, city, and search filters.
- [ ] Verify import/export history and the three-month log-retention schedule.
- [ ] Confirm external map tiles load and attribution is visible; check the local directory still works if tiles are unavailable.
