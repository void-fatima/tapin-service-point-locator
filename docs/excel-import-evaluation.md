# Technical Evaluation: Excel (.xlsx) Import Support for Tapin Service Point Locator

## Executive Summary

The Tapin Service Point Locator plugin requires bulk importing of logistics service points (Post, Tipax, etc.) which are frequently provided in Excel formats (`.xlsx` / `.xls`) containing hundreds to tens of thousands of rows.

Per project guidelines, no external dependency has been automatically installed. The current backend foundation provides native, dependency-free streaming for CSV files. This document evaluates the optimal PHP library options for `.xlsx` processing and outlines the recommended roadmap.

---

## Candidate Libraries Comparison

| Criteria | **PhpSpreadsheet** (`phpoffice/phpspreadsheet`) | **OpenSpout** (`openspout/openspout`) | **SimpleXLSX** (`shuchkin/simplexlsx`) |
| :--- | :--- | :--- | :--- |
| **Primary Design** | Complete in-memory spreadsheet manipulation & calculation | High-speed, streaming reader/writer for big data | Lightweight, single-class reader |
| **Memory Footprint** | **High** (~1-2 KB per cell; 10,000 rows × 10 cols ≈ 150MB+ RAM) | **Constant / O(1)** (< 10MB RAM regardless of sheet size) | **Moderate** (Loads XML into SimpleXML objects) |
| **Format Support** | `.xlsx`, `.xls` (BIFF8), `.ods`, `.csv`, `.html`, `.pdf` | `.xlsx`, `.ods`, `.csv` | `.xlsx` only |
| **Speed** | Moderate | Very Fast | Fast |
| **Dependencies** | Many (psr/simple-cache, zip, xml, gd) | Minimal | Zero (standalone class) |
| **WordPress Precedent** | Common in WooCommerce export plugins | Standard in high-volume enterprise importers | Used in lightweight plugins |

---

## Detailed Evaluation

### 1. PhpSpreadsheet (`phpoffice/phpspreadsheet`)
- **Pros:**
  - The defacto standard in the PHP ecosystem.
  - Supports older legacy Excel formats (`.xls` 97-2003), which some governmental or postal branches in Iran occasionally export.
  - Active maintenance and extensive community documentation.
- **Cons:**
  - Memory consumption is a major concern on standard WordPress hosting (default `memory_limit` of 128MB or 256MB).
  - Can be mitigated by implementing an `IReadFilter` to read rows in chunks, but still initializes full ZIP/XML structures.
  - Large package footprint (~15MB+ in `vendor/`).

### 2. OpenSpout (`openspout/openspout`)
- **Pros:**
  - Built specifically for high-performance reading and writing of massive datasets.
  - Uses pull-based XML streaming reader: memory consumption stays under 10MB even on files with 100,000+ rows.
  - Ideal for background batch processing and server resource conservation.
- **Cons:**
  - Does not support old `.xls` (BIFF8 binary) files; only modern `.xlsx` (OpenXML).
  - Does not compute formulas (not needed for simple service point tabular data).

### 3. SimpleXLSX (`shuchkin/simplexlsx`)
- **Pros:**
  - Can be embedded directly or via Composer without bringing in complex dependency trees.
- **Cons:**
  - Less suitable for enterprise streaming when files exceed 20,000 rows.

---

## Recommendation & Next Steps

1. **For Production Enterprise Scale:**
   - **OpenSpout** is the recommended choice if all files are modern `.xlsx`. It guarantees that large postal imports will never trigger fatal PHP out-of-memory errors on client servers.
   - If legacy `.xls` files are mandatory, **PhpSpreadsheet** with chunked reading is the alternative.

2. **Integration Architecture:**
   - The plugin's import architecture has been built around a decoupled streaming pattern (`stream_batches()`).
   - Adding `.xlsx` support in the next phase will simply require implementing an `XlsxImporter::stream_batches()` class implementing the exact same generator contract as `CsvImporter::stream_batches()`. The `ImportManager`, `ColumnMapper`, `DataNormalizer`, `DuplicateDetector`, and `ServicePointValidator` will remain 100% untouched.

3. **Status:**
   - Awaiting user/team approval before running `composer require` for the chosen package.
