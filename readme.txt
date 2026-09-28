=== Tapin Service Point Locator ===
Contributors: void-fatima
Tags: locator, shipping, rtl, csv, map
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Persian RTL service-point management, CSV/XLSX imports, live dashboard and public interactive map.

== Description ==
Manage shipping providers and physical service points. Save address-only records, import validated tables, review missing data, and publish active located points with [tapin_service_points].

Only administrators can manage data. Public responses exclude internal metadata. Bundled font, Leaflet, geographic boundaries and official brand assets require no build step.

== Installation ==
1. Upload the plugin ZIP and activate it.
2. Open the Tapin admin menu.
3. Add points or import a CSV/XLSX file.
4. Add [tapin_service_points] to a page.

== External services ==
Optional server-side Neshan geocoding sends normalized country/province/city/address to https://api.neshan.org/geocoding/v1 only when configured by the site administrator. Credentials remain server-side; phone numbers and import metadata are not sent. Without a key, address-only records remain usable and no provider requests are made. See docs/PHASE2.md for configuration and provider validation policy.
Background map tiles are requested by the visitor's browser from https://tile.openstreetmap.org. Requests include the visitor IP and referrer. Usage policy: https://operations.osmfoundation.org/policies/tiles/ . Privacy policy: https://osmfoundation.org/wiki/Privacy_Policy . Site developers may configure another tile URL and attribution using tapin_tile_url and tapin_tile_attribution. Custom provider logo URLs are browser-loaded images. Uploaded import files are processed locally, not sent to external services.

== Limits ==
CSV: UTF-8, 50 MiB. XLSX: first sheet, values only, 10 MiB compressed / 32 MiB expanded. 100,000 rows per file. XLS is not supported; convert to XLSX or CSV. Imports require InnoDB and named locks. XLSX requires PHP zip, XMLReader and SimpleXML. Closing the page pauses an import; resume it from history. See README.md for full limits.

== Changelog ==
= 1.3.0 =
* Optional validated geocoding, durable bounded-retry queue, coordinate preservation, safe public branch details, provider markers and keyboard activation.
* Mocked geocoding, migration, security, lifecycle and browser regression validation; existing dashboard and Phase 1 filters preserved.
= 1.1.0 =
* Source-backed postal and Tipax datasets, separate contacts and provenance, viewport/provider markers, province focus, operational logs and retention.
= 1.0.0 =
* Persian RTL dashboard, service-point/provider management, resumable CSV/XLSX preview and mapping, public map, validation and integration checks.

== Upgrade Notice ==
= 1.3.0 =
Preserves existing points, coordinates and provenance. Adds the geocoding queue through an idempotent migration. Neshan requires explicit server-side configuration; ordinary uninstall preserves data.
= 1.1.0 =
Preserves existing records while adding source, contact and quality columns and operational logs. Reference data is imported explicitly, never during activation.
= 1.0.0 =
Adds runtime UI and import history to the existing backend foundation. Database migrations preserve service points and providers.
