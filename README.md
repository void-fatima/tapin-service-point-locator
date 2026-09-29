<div align="center">

![Tapin — connect every branch](docs/images/tapin-banner.svg)

# Tapin Service Point Locator

**A Persian-first shipping directory, built for WordPress.**

از مدیریت شعب تا پیدا کردن نقاط خدماتی روی نقشه

![Version](https://img.shields.io/badge/version-1.4.0-8b5cf6?style=flat-square)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b?style=flat-square&logo=wordpress)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![RTL](https://img.shields.io/badge/Persian-RTL-10b981?style=flat-square)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-f4bc53?style=flat-square)](LICENSE)

[Get started](#get-started) · [Features](#built-for-the-whole-workflow) · [Development](#development) · [Documentation](#documentation)

</div>

Tapin brings shipping providers, branch records, spreadsheet workflows and a public locator into one plugin. Manage operations in a **dark Persian RTL dashboard**, then publish a **light, interactive branch directory** with a single shortcode.

Real records power the totals and map. Branches without coordinates remain searchable as address cards, so an incomplete location never means a lost branch.

> The banner is a custom project illustration, not a screenshot or a representation of live branch locations. Current plugin version: **1.4.0**.

## Built for the whole workflow

| Feature | What you can do |
| :--- | :--- |
| **Operations dashboard** | Review stored totals, coordinate coverage, provider distribution, recent activity and records that need attention. |
| **Branch management** | Create, edit, delete and search points; filter by provider, province, city, status and coordinate availability. |
| **Provider registry** | Manage names, unique slugs, logos, colors and active status. Providers with linked points are protected from deletion. |
| **CSV & Excel imports** | Preview privately, map Persian or English columns, choose a duplicate policy, process resumable batches and download diagnostics. |
| **Filtered Excel export** | Export all applied filtered results to XLSX, including address-only branches, with phones and postal codes preserved as text. |
| **Public locator** | Explore provinces, filter providers and cities, search branches, open map details or use the accessible branch list. |
| **Optional geocoding** | Enrich eligible unresolved addresses through Neshan without overwriting valid coordinates. |
| **Import/export history** | Review import outcomes, resume paused jobs and see recent export activity. |

### Two views, one source of truth

**For administrators:** a scoped RTL application inside WordPress, paginated service-point tables, an operational map and data-quality signals. Management requires `manage_options`.

**For visitors:** active branches from active providers, contact details, postal codes, markers for located branches and address cards for unresolved branches. Internal metadata is excluded from public responses.

Leaflet, Vazirmatn, provider brand assets and contextual map geometry are bundled. No npm or Composer installation is needed on the WordPress host.

## Get started

### 1. Check the host

| Component | Requirement |
| :--- | :--- |
| WordPress | 6.0+ |
| PHP | 7.4+ |
| Database | MySQL 5.7+ or MariaDB 10.3+; InnoDB and named locks for resumable imports |
| Excel import | `ZipArchive`, `XMLReader` and `SimpleXML` |
| Excel export | `ZipArchive` and transactional database snapshot support |
| Temporary storage | Writable PHP temporary directory outside the web document root |

These are compatibility targets. See the [verification records](docs/VERIFICATION.md) for tested environments and remaining limits; minimum versions have not all been runtime-tested.

### 2. Install and activate

Copy this repository into `wp-content/plugins/tapin-service-point-locator`, then activate **Tapin Service Point Locator** in WordPress.

Alternatively, build an installable ZIP from the repository root on Windows:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build.ps1
```

Upload the generated `dist/tapin-service-point-locator-1.4.0.zip` through **Plugins → Add New → Upload Plugin**. Archives are generated locally and are not committed to this repository.

### 3. Add your branches

Open **تاپین** in WordPress admin. Configure providers, add branches manually or start with the [CSV import template](assets/import-template.csv).

Required mapped import fields: `name`, `province`, `city`, `address`. Optional fields include `code`, `phone`, `postal_code`, `latitude`, `longitude` and `status`.

Activation does not insert demo branches or fabricated coordinates. Reference datasets are imported explicitly; see [data sources](docs/DATA-SOURCES.md).

### 4. Publish the locator

Add this to a **Shortcode** block on any public page:

```text
[tapin_service_points]
```

Visitors can select a province, narrow by provider or city, and search for a branch, address or phone. Address-only branches remain visible in the directory; only branches with valid coordinates become markers.

## Spreadsheet workflows without guesswork

| Format | Supported behavior | Main limits |
| :--- | :--- | :--- |
| CSV import | UTF-8, optional BOM, comma/semicolon/tab separators, quoted newlines | 50 MiB |
| XLSX import | First worksheet, shared or inline strings, values only | 10 MiB compressed / 32 MiB expanded |
| XLSX export | All applied filtered rows, RTL worksheet, text-safe contact fields | 100,000 rows / 64 MiB worksheet XML |

Imports support up to **100,000 data rows** and **64 columns**, subject to host and processing limits. Legacy `.xls`, formula evaluation and multi-sheet selection are not supported.

Imports run in browser-driven batches: closing the page pauses work, and history lets you resume. **Skip** leaves potential duplicates untouched; **Update** changes only an unambiguous exact branch-code match within the same provider. Review the [full import limits and duplicate rules](docs/OPERATIONS.md#import-details-and-limits) before large imports.

## Optional geocoding and map services

Configure `TAPIN_NESHAN_API_KEY` in the WordPress PHP environment or the site's private `wp-config.php` to enable Neshan. Credentials stay server-side. Without a key, address-only records remain usable and no Neshan requests are made. See [geocoding configuration and validation limits](docs/PHASE2.md).

Default OpenStreetMap tiles require internet access and receive the visitor's IP/referrer. Replace the tile URL and attribution through `tapin_tile_url` and `tapin_tile_attribution` if needed. Bundled boundaries and the branch list remain usable when external tiles fail. See [service and asset attribution](docs/THIRD-PARTY.md).

## Inside the project

```text
tapin-service-point-locator/
├── tapin-service-point-locator.php   Plugin bootstrap and lifecycle hooks
├── src/
│   ├── Database/                    Schema and write coordination
│   ├── Repository/                  Provider and service-point queries
│   ├── Import/                      Preview, mapping and resumable jobs
│   ├── Export/                      XLSX generation and downloads
│   ├── Geocoding/                   Provider adapter and enrichment queue
│   ├── Http/                        WordPress REST API
│   ├── UI/                          Admin application and public locator
│   └── …                            Validation, normalization and services
├── assets/                          UI, Leaflet, fonts, brands and geography
├── scripts/                         Packaging and explicit data workflows
├── tests/                           PHP, integration and browser checks
└── docs/                            Configuration, sources and verification
```

## Development

Run the standalone checks with PHP on your PATH:

```sh
php tests/run-checks.php
```

For WordPress integration and browser tests, use a **development site** and follow the [environment and session setup](docs/OPERATIONS.md#development-and-verification). Node.js and Playwright are development dependencies only.

```powershell
$env:TAPIN_WP_ROOT = 'C:\path\to\development-wordpress'
php tests/integration.php
npm ci
npx playwright install chromium
$env:TAPIN_SESSION_FILE = Join-Path $env:TEMP 'tapin-browser-session.json'
php tests/browser-session.php
try { npm run test:all } finally { php tests/browser-session.php cleanup }
```

The browser helper creates a temporary administrator session and may create a local review page. Keep its session file private and always clean it up. Screenshots go to ignored `artifacts/`.

Historical validation covers logic, database integration, browser flows, REST permissions, workbook safety, lifecycle behavior and packaging. Those records describe their own environments and do not imply every check was rerun for this documentation update.

## Documentation

| Guide | Contents |
| :--- | :--- |
| [Operations & development](docs/OPERATIONS.md) | Complete import limits, duplicate rules, map behavior, setup and retention |
| [Geocoding](docs/PHASE2.md) | Neshan configuration, queue behavior and coordinate protection |
| [Directory & Excel export](docs/PHASE3.md) | Accessible directory, filtered workbooks and activity history |
| [Data sources](docs/DATA-SOURCES.md) | Reference datasets and provenance |
| [Tapin source catalog](docs/TAPIN-SOURCE-CATALOG.md) | Source discovery and reviewed directory data |
| [Verification](docs/VERIFICATION.md) | Recorded checks, benchmark and practical limits |
| [Geocoding validation](docs/PHASE2-VALIDATION.md) | Phase 2 test scope and results |
| [Export validation](docs/PHASE3-VALIDATION.md) | Phase 3 test scope and results |
| [Third-party notices](docs/THIRD-PARTY.md) | Libraries, fonts, logos, maps and attribution |

## Data retention and license

Normal deactivation and uninstall **preserve business data**. Explicitly defining `TAPIN_UNINSTALL_DROP_DATA` as `true` in `wp-config.php` enables irreversible removal of plugin tables/options during uninstall. Back up the database before destructive operations.

Released under **GPL-2.0-or-later**. See [LICENSE](LICENSE); bundled third-party assets retain their respective [licenses and attribution](docs/THIRD-PARTY.md).
