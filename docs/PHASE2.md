# Phase 2 implementation handoff — validation deferred

Branch: `feat/geocoding-map-enrichment`.
Base: `97e6fd5` on `feat/tapin-source-dashboard-refinement`, verified against the fetched remote before implementation.

User direction for this implementation: do not run tests; commit and push each stage. No PHP/JavaScript syntax checks, automated suites, browser sessions, live geocoding requests, activation checks, or packaging were run. Existing Phase 1 test results are not Phase 2 results. No test files were added or changed; focused Phase 2 coverage remains to be written and executed in the later validation pass.

Release stays **1.2.0** until validation. Database schema version **6** adds the idempotent `tapin_geocoding_jobs` table. No database reset, seed, production migration, or live dataset update was executed in this workspace. The source snapshots and geometry assets were not changed; the reported 241 live records were not re-counted here.

## Configuration

The default adapter implements the current [Neshan geocoding contract](https://platform.neshan.org/docs/api/search-category/geocoding/): `GET https://api.neshan.org/geocoding/v1?json=...`, with `Api-Key` in the server-side request header.

Set `TAPIN_NESHAN_API_KEY` in the WordPress PHP process environment or define it in the site's private `wp-config.php`. A defined constant takes precedence over the environment. Never put the credential in source control, uploaded metadata, JavaScript, or public tile URLs. `TAPIN_NESHAN_PLUS` may be defined as `true` to select `/geocoding/v1/plus`; that requires an appropriate provider subscription/key.

The implementation environment did not have `TAPIN_NESHAN_API_KEY` set. No live provider request was made and no records were enriched locally. Deployment credentials and WordPress runtime configuration have not been inspected.

Alternative providers can implement `Geocoding\GeocoderInterface` and return that instance from the server-side `tapin_geocoder` filter. `name()` must be a stable adapter/version identifier; `configured()` reports readiness without exposing a key. `geocode()` returns a list of candidates with `latitude`, `longitude`, `province`, `city`, and `quality`, or a `WP_Error`. Only an adapter-supported complete match should receive `quality = matched`; errors may carry `retryable` and `retry_after` data. Raw responses must not be returned to users or stored as diagnostics.

Only the normalized address, province, and city are sent to the provider. Telephone numbers, import metadata, and API credentials are not included in the query or public REST fields.

## Coordinate and evidence policy

- Valid uploaded coordinates take precedence during import. Existing valid coordinates are retained when an upload has blank coordinate columns. Manual coordinate edits are marked `manual` when coordinates change.
- Automatic enrichment skips every existing valid pair, including legacy pairs whose origin is unknown; it does not invent historical provenance. New origins are stored under `metadata.coordinate_source` as `uploaded`, `manual`, or `geocoded`.
- No city/province-center, random, or Tehran fallback is used. New unresolved records keep NULL coordinates and `missing_coordinates`, remaining searchable in the directory without markers.
- Query construction reuses `DataNormalizer` and prefers verified Tapin evidence from an official source URL. Conflict, probable-match, and stale evidence blocks enrichment. Manual edits to reconciled fields mark old evidence stale; reconcile/review that evidence before retrying. Manual coordinate entry remains available.
- Numeric/finite/range checks precede point-in-polygon validation using the bundled Iran province boundaries, including polygon holes. Province must match both geometry and any returned province metadata. Returned city metadata must agree when an expected city exists.
- Neshan candidates with missing or nonempty `unMatchedTerm` are rejected. Exactly one distinct geographically consistent complete match is required; multiple accepted candidates remain ambiguous. This is deliberately conservative, not a guarantee of building-level accuracy. Historical boundary precision can reject legitimate border locations; use human review rather than weakening the check or fabricating a point.
- Successful results retain provider, address hash, quality, timestamp, and attempts inside existing metadata. No redundant coordinate columns were introduced.

## Queue and recovery

Successful imports and manual saves enqueue eligible unresolved records without making provider HTTP calls. Existing historical records can be queued through the service-point page, either individually or for the currently displayed page (respecting its filters).

The existing WP-Cron system runs `tapin_geocode_points` every minute. Each run claims at most one row and makes at most one provider request; default HTTP timeout is eight seconds, redirects are disabled, and response size is capped. A database named lock serializes workers, while short shared write locks protect claim/final persistence against concurrent imports and admin edits. The external request does not hold the shared import lock.

The queue's unique `point_id` prevents simultaneous duplicate jobs. Unchanged pending/processing/retry jobs are reused. Changed queries invalidate old work. Five-minute processing leases recover interrupted workers; each claim consumes an attempt, capped at four. Timeouts, transport failures, HTTP 408/425/429/482 and server failures use exponential backoff, honoring longer `Retry-After` delays. Permanent errors stop that job. Credential/quota failures also pause requests across the queue for an hour. A retry action resets terminal job attempts, not live jobs or provider-wide cooldowns.

Before persistence the worker rereads the point, provider activity, and query hash. Newly supplied coordinates or changed source/address evidence prevent stale API results from being written. Queue failures do not turn an otherwise saved import row into data loss; saved rows remain available for later retry.

Successful candidate caching uses the normalized query plus provider/version/policy hash for 30 days, with validation on reuse. Rejected matching results are cached for one hour; retrying within that hour can reuse the rejection. Correcting the query selects a new cache key. Transport errors are not cached. Requests across queue records are serialized to avoid concurrent paid lookups for the same address.

WP-Cron depends on site traffic. Low-traffic sites should arrange an actual scheduler for WordPress cron. Missing credentials leave jobs pending without consuming provider attempts. Deactivation clears the event but preserves jobs and points; reactivation resumes scheduling. Ordinary uninstall preserves data; the existing explicit destructive-uninstall option also includes the new queue table.

## Admin and public behavior

- Service-point management adds per-point and current-page retry, status polling, attempts, friendly failure reasons, and queue totals. It does not alter the approved dashboard layout or bottom provider drawer.
- `GET /tapin/v1/geocoding?ids=1,2` returns operational status for up to 100 requested IDs plus overall queue counts. `POST /tapin/v1/geocoding/retry` accepts `{"ids":[1,2]}`. Both require `manage_options`; writes retain the existing WordPress nonce and shared write-lock flow.
- `GET /tapin/v1/public/points/{id}` returns allowlisted branch detail fields for an active point from an active provider, including address-only points. It never returns import/source/debug metadata. The authenticated `/points/{id}/details` counterpart also permits inactive records for administrators.
- Existing marker/directory list responses use the same safe source-backed presentation. Admin map queries opt in using `map_view=1`; management endpoints retain their raw fields for editing/review.
- Single-marker and directory detail clicks fetch current branch data. Repeated clicks and close actions abort/invalidate older requests. Postal code, landline, mobile/general phone, provider identity, and full address remain available. `tel:` links preserve extension notes without concatenating them into the number. Directions use stored coordinates without geocoding again.
- Keyboard Escape/close restores focus; marker hover/focus adds a compact label. Coincident clusters at high zoom use a bounded picker and fetch only the chosen detail. Existing clustering, bounding-box pagination, province/city/provider/search filters, and province-click behavior remain in place.
- Idle first-page map views refresh after 60 seconds without changing filters or viewport. Refresh is suspended while a detail is open, the document is hidden, a control in the widget is focused, or additional directory pages have been loaded. Dashboard totals still come from database aggregates using the current filters.
- Changed local script/style URLs include file timestamps so Phase 1 browser caches do not hide Phase 2 changes while the release version is held pending validation.

## Later validation and release work

No checks listed here have been executed for Phase 2. Add focused mocked-provider coverage for query construction, quality/ambiguity, Iran/province/city mismatches, cache reuse, timeout/transient/permanent errors, retry bounds, interrupted leases, duplicate enqueue, source edits during HTTP, and existing-coordinate precedence. Cover queue accounting, migration preservation, capability/nonce protection, and public allowlists.

Run the existing PHP, WordPress, source/data, REST, filter, admin, map, and public UX suites. Add browser coverage for both provider identities, fresh details, phone/directions links, keyboard focus, clustered markers, address-only detail, and delayed response cancellation. Run syntax and activation/deactivation checks, then prepare release 1.3.0 using the existing conventions only after successful validation.

Phase 3 remains excluded: final accessible table UX, filtered XLSX export, export naming/metadata, import/export history polish, final delivery audit, and final merge/release work.

## Implementation commits

- `a510207` — `feat(geocoding): add configurable provider and coordinate validation`
- `4703d39` — `feat(geocoding): queue enrichment with bounded retries and safe persistence`
- `e2e8e66` — `feat(map): add verified branch details and admin enrichment controls`
- `c516e65` — `fix(geocoding): reject locality-only input and refresh job eligibility`

Each implementation stage was pushed immediately after committing. The documentation handoff is a separate final commit on the same branch. All commits use the repository-local identity `void-fatima <vantafatima@gmail.com>`.
