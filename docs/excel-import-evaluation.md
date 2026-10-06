# Excel import implementation decision

The earlier library evaluation remains relevant for broader spreadsheet features. The shipped 1.0 implementation preserves PHP 7.4 compatibility and uses PHP ZipArchive, XMLReader and SimpleXML for a deliberately bounded, values-only XLSX reader. It resolves the first worksheet through workbook relationships, handles shared/inline strings, and stages rows in a private line-oriented file before the preview is returned.

CSV and XLSX use the same ColumnMapper, DataNormalizer, ServicePointValidator, DuplicateDetector and RowProcessor pipeline. The existing synchronous ImportManager delegates row processing to this pipeline; the admin UI uses resumable ImportJobs. Imports do not preload the complete provider dataset.

The aliases `عنوان نمایندگی` and `عنوان سرویس دهنده` identify branch name and provider; `آدرس`, `استان` and `شهر` retain their existing mappings. Preview labels each detected field and identifies an unmapped required name/address column before starting. A missing province column has its own source-reconciliation notice rather than a generic missing-column error.

When a provider column exists, preview counts its labels across the entire file and offers an explicit configured-provider choice for each label. Exact names, slugs, IDs and the existing `پست` alias remain supported. An unknown label such as `قطار بار` is never assigned to another provider automatically. The global selection applies only when the file has no mapped provider column. Older API clients that leave an unknown label unmapped receive a named row failure in the report.

Focused local coverage: `php tests/mixed-provider-import.php` requires ZipArchive/XMLReader/SimpleXML and runs the real reader/import pipeline with an in-memory database double. `node tests/mixed-provider-preview.cjs` exercises actual admin assets with network fixtures in both themes at desktop/mobile widths. Neither is a WordPress/MySQL installation or live import test.

Supported: first-sheet XLSX values, Persian headers, blank trailing cells, preview/mapping, and row reports. Unsupported: legacy XLS, formula evaluation, macro workbooks and multiple-sheet selection. Formula workbooks must be converted to values. Large files should use CSV or be split. Limits and extension requirements are documented in README.md and in the upload screen.

Security checks include compressed/expanded/shared-string/row/cell bounds, DTD/entity rejection, malformed XML handling, restricted internal worksheet paths, private staging, authenticated uploads, and transactional checkpoints. Regression coverage exercises shared strings, incomplete XML, formulas and external-entity input against a real WordPress database.

A maintained streaming spreadsheet library can be reconsidered if multi-sheet selection or additional formats become necessary. No Composer runtime is required for this scoped implementation.
