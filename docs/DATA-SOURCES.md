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
This sample is not national coverage and contains no Tipax locations.

Import explicitly with `wp tapin import-reference`, or upload the CSV in the
existing importer and select Iran Post. Re-running uses the existing duplicate
protection. No data is imported during activation. Source URLs are retained on
admin records; private metadata/source fields are excluded from public responses.

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
