# Price Check existing-company saves

## Confirmed cause and request path

The Companies editor already uses the correct update flow. `admin.js` opens the
editor with the provider ID and retains it in the shared record. Its disabled ID
control is display-only; serialization builds a complete JSON payload from that
record and the controls across all six tabs. One submit handler sends existing
records with `PUT /api/admin/providers/{id}` and new records with collection
`POST`. The JSON body intentionally omits the provider ID.

`wordpress.js` maps that update to
`/tapin-price-check/v1/admin/providers/{id}`, including installations using
`index.php?rest_route=/tapin-price-check/v1/admin/providers/{id}`. The REST
configuration service resolves the route's existing provider, rejects missing
identifiers with 404, normalizes the complete payload, and pins the resolved ID
onto the record. PUT retains its existing full-record replacement contract.

The bug was in `ProviderStorage::save`: an UPDATE returning zero caused an
INSERT with the same provider ID. WordPress/MySQL reports zero changed rows
when the existing row's values are unchanged. The resulting duplicate-key error
produced the generic 409 save error and stopped nested parameter/field writes.
This also broke edits that changed only nested fields. A baseline isolated SQL
probe confirmed one provider INSERT attempt and an `insert` storage failure on
an unchanged save; the provider count remained one.

## Correction

Storage now checks whether the row exists after a successful zero-row UPDATE
and continues saving its nested records when it does. The database wrapper
continues to reject `false` and nonempty database error details, and the REST
response retains its sanitized 409 error contract.

REST edits pass `require_existing=true`. Missing IDs and rows deleted between
route lookup and storage cause failure without an INSERT. Generic storage
callers retain the source contract that an explicitly supplied, absent ID may
create a row; the existing supplied-ID 42 fixture still passes. Creation from
the UI remains a separate POST.

The archived Python fixture intentionally records the old unchanged-save
failure. `price-check-storage.php` replaces only that scenario with corrected
save, unchanged-settings/IDs, and row-count assertions. All other source
fixtures retain their comparisons. The optional WordPress storage integration
runner now expects an unchanged save to succeed.

## Verification

`tests/price-check-provider-save.php` passes 208 assertions through the actual
module REST handler, configuration service and storage with REST boundary and
SQLite wpdb doubles. It covers display-field persistence and reopen, unchanged
UPDATE 0, nested-only edits, complete settings retention, stable provider and
nested IDs, row counts, shop references and bulk associations, creation, invalid
input (422), absent identifiers (404), a deletion race, UPDATE false and positive
write results with `last_error` (409), supplied-ID storage creation, capability
and nonce denials (403), and a locator-table sentinel. An isolated copy of the
same test against the original runtime fails at the unchanged save.

`tests/price-check-provider-save.cjs` passes in Chromium using the actual PHP
renderer and runtime assets with local REST persistence and identity doubles.
It inspects the complete outgoing payload from every editor tab, repeated
unchanged PUTs, numeric/string IDs, the item URL for both REST URL formats,
one write per click, display-field persistence/reopen, separate creation POST,
and pending/409 responses without a success message. All nonlocal network
requests are blocked; no external requests were attempted.

The existing storage (160 source operations, 227 assertions), configuration
REST (664), REST boundary (396), operational REST (68), foundation (343),
validation (645 parity, 5 compatibility, 6 float), single storage (20), bulk
storage (20), location/bulk composition (9), security guard (1,005), public
rendering (43), protected admin rendering (116), and locator smoke (38) checks
pass. The existing adapter and full public/admin Chromium suites also pass.
Changed PHP files and relevant JavaScript pass syntax checks; `git diff --check`
passes.

Run the new focused checks from the repository root:

```powershell
& artifacts/php-runtime/php.exe -n -d extension_dir=artifacts/php-runtime/ext -d extension=pdo_sqlite tests/price-check-provider-save.php
node tests/price-check-provider-save.cjs
```

Connected Chrome tools were unavailable, so the existing authenticated live
tab and its network traffic could not be inspected. No disposable WordPress
test site is configured (`TAPIN_WP_ROOT` and the dedicated-site opt-in are
absent); the optional WordPress/MySQL runner was checked only at its prerequisite
guard. These results are local doubles and Chromium fixture verification, not
live WordPress/MySQL verification. No live configuration was modified and no
shipping-provider requests were sent. No push, deployment, version bump or ZIP
rebuild was performed.
