# Service-point provenance

The primary reference is https://tapin.ir/map/ (inspected 2026-09-27).
It is a clickable province SVG linking to PDF postal-office directories, not a
coordinate API. Province selection therefore remains a geographic directory filter.

## Bundled reviewed starter dataset

`assets/data/post-semnan.csv` transcribes ten postal service points from
https://tapin.ir/map/semnan.pdf. It contains no invented coordinates, mobile numbers,
or provider branch codes. The `tapin-semnan-N` keys are source-local identifiers
using the original PDF row numbers. Names, postal codes and landlines come from
that directory; personal contact names were omitted from addresses.

The document is undated; current branch operation is not independently confirmed.
Row 3 is excluded because its telephone appears malformed. Row 9 repeats the
Damghan address, postal code and telephone of row 5 and is excluded as a duplicate.
This postal sample is not national coverage.

Import both reference datasets explicitly with `wp tapin import-reference`, or
upload each CSV in the existing importer and select its provider. Re-running uses the existing duplicate
protection. No data is imported during activation. Source URLs are retained on
admin records; private metadata/source fields are excluded from public responses.

## Official Tipax Tehran snapshot

`assets/data/tipax-tehran.csv` comes from the Tehran province/city selection at
https://tipaxco.com/branches/standardpoint, retrieved 2026-09-27. Each included row
is checked against its own official `/branches/...` detail URL, which is retained
as `source`. Branch codes must match between the directory and detail page.
Coordinates come exclusively from that branch's published navigation destination;
nearby-branch navigation links are excluded. These are source-published locations,
not independently surveyed coordinates. No geocoding is performed.

The snapshot includes 231 records: 230 with coordinates, one address-only, and
176 with published phone numbers. One of the 232 directory selections was excluded:
code `21225/3` linked to a detail page whose branch identity did not match. Including
the ten postal records, the two files contain 241 points, 230 located and 11 missing
coordinates. These counts describe the bundled snapshot, not nationwide totals.

Published eleven-digit phone numbers are separated into mobile and landline
fields. Multiple numbers remain separate; absent contacts stay empty. Manager
names are not collected. Postal codes are preserved and the importer reports
format warnings rather than fabricating replacements. This is a Tehran snapshot,
not a national or continuously synchronized Tipax directory.

Maintainers can reproduce collection with `node scripts/collect-tipax.cjs` followed
by `node scripts/enrich-tipax.cjs` after installing the existing Playwright dev
dependency. Both run locally and access only the public official site. Intermediate
snapshots and rejected-row reports stay in ignored `artifacts/`; review the final
CSV diff before importing. The collector requires the site's current markup and
fails closed when branch identities do not match. It is not a scheduled scraper.

Without WP-CLI, set `TAPIN_WP_ROOT` to a local WordPress root and run
`php scripts/import-reference.php`. This explicitly imports into that database;
activation itself still never imports records.

## Future sources

Use official provider exports or branch directories, retain their URL in `source`,
and map provider IDs through the existing provider registry. Review names,
addresses, freshness and coordinate provenance before publication. Do not geocode
to city centroids or import unrelated POIs. Import coordinates only when the
source identifies the actual branch position. The CSV/XLSX pipeline supports
both contacts independently and supports providers without code changes.

`missing_coordinates` means no valid coordinate pair exists. `needs_review` means
a coordinate pair exists but has not been independently verified; it is not a
claim of official geographic accuracy. Existing phone values remain in the
legacy phone field because their type cannot safely be inferred.
