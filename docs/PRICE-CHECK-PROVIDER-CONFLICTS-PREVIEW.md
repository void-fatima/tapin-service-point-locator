# Provider conflict and preview review

## Findings against the Python report

The supplied `PROVIDER_UPDATE_409_FIX.md` describes Python changes and expected
manual outcomes. It is reference material, not evidence of executed PHP tests.
The local Python checkout still has the zero-row INSERT fallback, direct
parameter ID indexing in `records.public_schema`, and origin definitions without
the metadata mentioned in the report. It was inspected without modification.

PHP's zero-row UPDATE bug was already corrected in `b6a603a`. The existing
provider-save regression checks continue to demonstrate successful unchanged
saves, display-field persistence, nested-only edits, stable IDs/counts/settings
and shop/bulk associations, separate creation, invalid-input rejection, and
missing/deleted edit targets without fallback creation. This change extends that
fix; `ProviderStorage.php` does not need another implementation.

PHP did not have the report's misleading self-conflict message. It returned a
generic sanitized 409 for every storage failure, including genuine conflicts
with another provider's technical name. That missing conflict detail is the
production behavior corrected here.

The preview exception does not affect PHP. The corresponding protected route is
`POST /tapin-price-check/v1/admin/preview/provider-schema`, mapped to
`QuoteApiService::draft`, `ProviderConfig::normalise`, `PublicApiService::schema`
and `ProviderSchema::public_schema`. Optional unsaved IDs are read safely and
normalized to null; generated origin schema controls already include `id`,
`description` and `required`. Required draft configuration still passes through
validation. No production preview or origin-definition changes were necessary.

## Production change

Commit `583fab4` contains the conflict fix and its focused regressions.

`ConfigurationApiService` checks technical-name ownership through the existing
SQL lookup, preserving the installed database's comparison/collation rules. The
route-resolved current provider ID is excluded. A name owned by another provider
returns 409 with a readable Persian message identifying that other ID and a
`name` field error. The submitted name and raw database diagnostics are not
copied into that message.

The existing `provider_name (name)` unique index remains authoritative for
concurrent writes. `ConfigurationDatabase` recognizes only a failed provider
INSERT/UPDATE's MySQL/MariaDB duplicate-entry diagnostic ending in that exact
index name, optionally qualified by its table. `StorageError` retains only the
safe `provider_name` constraint identity. The service then rechecks ownership to
identify a concurrent conflicting provider.

PRIMARY and other index failures, nested-table writes, and unrelated failures
keep the existing generic sanitized 409 save response. If the follow-up lookup
fails, finds no owner, or finds the current provider, the response remains
generic rather than falsely identifying a self-conflict. No raw diagnostic
logging was added. Authentication, manage_options, REST nonces, schema, UI and
pricing behavior are unchanged.

## Verification

The new `tests/price-check-provider-conflicts.php` passes 415 assertions through
the actual module REST/configuration/storage paths with an isolated SQLite wpdb
double and explicitly simulated MySQL/MariaDB diagnostic formats. Its interleaving hooks
exercise SQL uniqueness enforcement after the name precheck, without contacting
an external database or provider. Against an isolated copy of the previous
`b6a603a` runtime, the same suite fails because the conflict does not identify
the other provider.

The new `tests/price-check-provider-preview.php` passes 115 assertions through
registered protected REST callbacks and production services. It promotes PHP
warnings/notices to failures and checks unsaved/existing drafts, missing
provider/parameter/nested IDs, generated origins, required/default metadata,
disabled origins, invalid drafts (422), invalid JSON bodies (400), required
submitted values (422), and login/capability/nonce denials (403). Preview issues
zero database queries and provider HTTP requests; the full seeded SQLite state
remains unchanged. Location and HTTP dependencies are doubles.

The existing provider-save suite passes 217 assertions. Existing storage,
configuration/boundary/quote/operational/workbook REST, foundation, validation,
operational storage, single/bulk storage, location/bulk, security guard/rate,
public/admin rendering and locator smoke suites pass. Local Chromium provider
save, adapter and full public/admin UI checks also pass. PHP/JavaScript syntax
checks and `git diff --check` pass.

Run the focused PHP suites from the repository root:

```powershell
& artifacts/php-runtime/php.exe -n -d extension_dir=artifacts/php-runtime/ext -d extension=pdo_sqlite tests/price-check-provider-conflicts.php
& artifacts/php-runtime/php.exe -n -d extension_dir=artifacts/php-runtime/ext -d extension=pdo_sqlite tests/price-check-provider-preview.php
& artifacts/php-runtime/php.exe -n -d extension_dir=artifacts/php-runtime/ext -d extension=pdo_sqlite tests/price-check-provider-save.php
```

Connected Chrome tools remain unavailable, so the existing authenticated live
tab and its network requests could not be inspected. There is no configured
disposable WordPress/MySQL test site; its optional integration runner stops at
the prerequisite guard. These results establish local service/storage and
browser fixture behavior, not live WordPress/MySQL verification. In particular,
MySQL/MariaDB diagnostic strings and case-insensitive name comparisons in the
new conflict suite are simulations. No manual outcomes from the supplied report
are claimed as completed tests. No live company writes, shipping-provider calls,
push, deployment, version bump or ZIP rebuild were performed.
