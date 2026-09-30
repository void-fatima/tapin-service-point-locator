<?php
/** Real WordPress/MySQL; isolated plugin tables, mocked provider, no live API calls. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Geocoding\{Jobs,GeocoderInterface,AddressQuery};
use Tapin\ServicePointLocator\Repository\{ServicePointRepository,ProviderRepository};
use Tapin\ServicePointLocator\Database\{Schema,WriteLock};
use Tapin\ServicePointLocator\Service\PointService;
use Tapin\ServicePointLocator\Http\Api;
use Tapin\ServicePointLocator\{Activator,Deactivator};

final class QueueFixtureGeocoder implements GeocoderInterface {
	public $response; public $during; public int $calls = 0; public bool $ready = true;
	public function name(): string { return 'queue-fixture-' . $GLOBALS['wpdb']->prefix; }
	public function configured(): bool { return $this->ready; }
	public function geocode( array $query ) { $this->calls++; if ( $this->during ) { ( $this->during )(); } return $this->response; }
}
$passed = 0; $failed = 0;
function queue_check( bool $ok, string $name ): void { global $passed, $failed; $ok ? $passed++ : $failed++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; }
function queue_row( int $id ): ?array { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Jobs::table() . ' WHERE point_id = %d', $id ), ARRAY_A ); }
function work_queue( int $id ): void {
	global $wpdb;
	delete_option( 'tapin_geocoding_next_request' );
	$wpdb->update( Jobs::table(), array( 'next_attempt' => '2000-01-01 00:00:00' ), array( 'point_id' => $id ) );
	Jobs::run();
}
$original = $wpdb->prefix;
$table_prefix = $original . 'phase2_' . bin2hex( random_bytes( 4 ) ) . '_';
$real_rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A );
$options = array(); foreach ( array( 'tapin_db_version', 'tapin_geocoding_next_request', 'cron' ) as $option ) { $options[$option] = get_option( $option, null ); }
$wpdb->prefix = $table_prefix;
$mock = new QueueFixtureGeocoder(); $filter = static fn() => $mock;
add_filter( 'tapin_geocoder', $filter );
$block_http = static fn() => new WP_Error( 'unexpected_http', 'Live network prohibited during queue tests.' );
add_filter( 'pre_http_request', $block_http );
$candidate = array( 'latitude' => 35.7, 'longitude' => 51.4, 'province' => 'تهران', 'city' => 'تهران', 'quality' => 'matched' );
$mock->response = array( $candidate );
try {
	Activator::activate();
	$repo = new ServicePointRepository(); $providers = new ProviderRepository(); $post = (int) $providers->get_by_slug( 'post' )['id'];
	$base = array( 'provider_id' => $post, 'name' => 'آزمایش موقعیت', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'خیابان آزادی پلاک 12', 'postal_code' => '1234567890', 'landline_phone' => '02112345678', 'status' => 'active', 'metadata' => array( 'private_import' => 'PRIVATE_SENTINEL' ) );
	$make = static function( array $extra = array() ) use ( $repo, $base ) { static $seq = 0; $seq++; return $repo->insert( array_replace( $base, array( 'code' => 'P2-' . $seq, 'address' => 'خیابان آزادی پلاک ' . ( 100 + $seq ) ), $extra ) ); };
	$id = $make(); $snapshot = $repo->get_by_id( $id ); Schema::migrate(); Schema::migrate();
	queue_check( $repo->get_by_id( $id ) === $snapshot, 'schema 8 migration twice preserves point and private provenance' );
	queue_check( (int) get_option( Schema::DB_VERSION_OPTION ) === 8, 'schema version is 8' );
	queue_check( count( $providers->get_all() ) === 2, 'repeated activation does not duplicate providers' );
	queue_check( in_array( 'query_hash', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Jobs::table() ), true ), 'durable queue schema exists' );
	$enqueue = WriteLock::run( static fn() => Jobs::enqueue( $repo->get_by_id( $id ) ) );
	queue_check( $enqueue['status'] === 'pending', 'eligible missing point queued' );
	Jobs::enqueue( $repo->get_by_id( $id ) );
	queue_check( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Jobs::table() ) === 1, 'duplicate queue prevented by point identity' );
	$mock->ready = false; work_queue( $id );
	queue_check( (int) queue_row( $id )['attempts'] === 0 && $mock->calls === 0, 'unconfigured provider does not consume attempts' );
	$mock->ready = true; work_queue( $id ); $saved = $repo->get_by_id( $id );
	queue_check( $saved['has_coordinates'] && $saved['latitude'] === 35.7 && queue_row( $id )['status'] === 'succeeded', 'worker persists successful coordinates' );
	queue_check( $saved['metadata']['coordinate_source'] === 'geocoded' && $saved['metadata']['geocoding']['quality'] === 'matched' && isset( $saved['metadata']['geocoding']['geocoded_at'] ), 'geocoded provenance and quality stored' );
	queue_check( $saved['metadata']['private_import'] === 'PRIVATE_SENTINEL', 'geocoding preserves import metadata' );
	$calls = $mock->calls; Jobs::retry( array( $id ) ); work_queue( $id );
	queue_check( $mock->calls === $calls && $repo->get_by_id( $id ) === $saved, 'located point skipped and never overwritten' );

	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) ); $hash = queue_row( $id )['query_hash'];
	$repo->update( $id, array( 'address' => 'خیابان آزادی پلاک 777' ) );
	work_queue( $id );
	queue_check( queue_row( $id )['query_hash'] !== $hash && queue_row( $id )['status'] === 'pending' && ! $repo->get_by_id( $id )['has_coordinates'], 'changed address invalidates old job before HTTP' );
	work_queue( $id );
	queue_check( queue_row( $id )['status'] === 'succeeded', 'changed address gets fresh successful work' );

	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) );
	$wpdb->update( Jobs::table(), array( 'status' => 'processing', 'attempts' => 1 ), array( 'point_id' => $id ) ); work_queue( $id );
	queue_check( queue_row( $id )['status'] === 'succeeded' && (int) queue_row( $id )['attempts'] === 2, 'expired processing lease recovers' );
	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) );
	$mock->response = new WP_Error( 'transport_error', 'redacted', array( 'retryable' => true, 'retry_after' => 180 ) );
	for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
		work_queue( $id ); $job = queue_row( $id );
		queue_check( (int) $job['attempts'] === $attempt && $job['status'] === ( $attempt < 4 ? 'retry' : 'failed' ), 'bounded transient retry attempt ' . $attempt );
		queue_check( strtotime( $job['next_attempt'] . ' UTC' ) >= time() + max( 180, 60 * 2 ** ( $attempt - 1 ) ) - 2, 'backoff honors Retry-After at attempt ' . $attempt );
	}
	$calls = $mock->calls; work_queue( $id );
	queue_check( $mock->calls === $calls, 'terminal job never loops automatically' );
	queue_check( ! $repo->get_by_id( $id )['has_coordinates'] && $repo->get_by_id( $id )['data_quality_status'] === 'missing_coordinates', 'failed record remains missing_coordinates' );
	Jobs::retry( array( $id ) );
	queue_check( queue_row( $id )['status'] === 'pending' && (int) queue_row( $id )['attempts'] === 0, 'explicit retry resets terminal attempts' );
	$mock->response = new WP_Error( 'http_480', 'redacted' ); work_queue( $id );
	queue_check( queue_row( $id )['status'] === 'failed' && (int) get_option( 'tapin_geocoding_next_request' ) >= time() + 3598, 'credential error triggers provider-wide cooldown' );
	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) ); $calls = $mock->calls; Jobs::run();
	queue_check( $mock->calls === $calls && (int) queue_row( $id )['attempts'] === 0, 'provider cooldown stops other queued points' );
	$mock->response = array( $candidate ); work_queue( $id );

	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) );
	$mock->during = static function() use ( $repo, $id ) { $repo->update( $id, array( 'latitude' => 35.72, 'longitude' => 51.42, 'metadata' => array( 'coordinate_source' => 'manual' ) ) ); };
	work_queue( $id ); $mock->during = null;
	queue_check( $repo->get_by_id( $id )['latitude'] === 35.72 && $repo->get_by_id( $id )['metadata']['coordinate_source'] === 'manual', 'manual coordinates during HTTP defeat stale response' );
	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) );
	$mock->during = static function() use ( $repo, $id ) { $repo->update( $id, array( 'address' => 'خیابان ولیعصر پلاک 999' ) ); };
	work_queue( $id ); $mock->during = null;
	queue_check( ! $repo->get_by_id( $id )['has_coordinates'] && queue_row( $id )['status'] === 'pending', 'source query revalidated after provider request' );
	work_queue( $id );
	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) );
	$mock->response = array( array_replace( $candidate, array( 'longitude' => 44.0 ) ) ); work_queue( $id );
	queue_check( ! $repo->get_by_id( $id )['has_coordinates'] && queue_row( $id )['status'] === 'failed', 'invalid provider result never persisted' );
	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) );
	$providers->update( $post, array( 'is_active' => 0 ) ); work_queue( $id );
	queue_check( queue_row( $id )['status'] === 'blocked', 'inactive provider blocks work' );
	$providers->update( $post, array( 'is_active' => 1 ) ); Jobs::enqueue( $repo->get_by_id( $id ) );
	queue_check( queue_row( $id )['status'] === 'pending', 'reactivation makes blocked job eligible' );
	$mock->response = array( $candidate ); work_queue( $id );
	$deleted_provider = $providers->insert( array( 'slug' => 'delete-fixture', 'name' => 'Temporary', 'is_active' => 1 ) );
	$id = $make( array( 'provider_id' => $deleted_provider ) ); Jobs::enqueue( $repo->get_by_id( $id ) ); $providers->delete( $deleted_provider ); work_queue( $id );
	queue_check( queue_row( $id )['status'] === 'blocked' && $repo->get_by_id( $id ) !== null, 'deleted provider preserves point and safely blocks work' );
	$id = $make(); Jobs::enqueue( $repo->get_by_id( $id ) ); $repo->delete( $id ); work_queue( $id );
	queue_check( queue_row( $id )['status'] === 'skipped', 'deleted point is skipped safely' );
	$counts = Jobs::status()['counts'];
	queue_check( array_sum( $counts ) === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Jobs::table() ), 'queue totals account for every job' );

	$address_id = $make( array( 'name' => 'فقط نشانی رسمی', 'source' => 'https://tapin.ir/map/tehran.pdf' ) );
	$point_before = $repo->get_by_id( $address_id );
	$server = rest_get_server(); wp_set_current_user( 0 );
	foreach ( array( array( 'GET', '/tapin/v1/geocoding' ), array( 'POST', '/tapin/v1/geocoding/retry' ) ) as $route ) {
		$request = new WP_REST_Request( $route[0], $route[1] ); $request->set_body_params( array( 'ids' => array( $address_id ) ) );
		queue_check( $server->dispatch( $request )->get_status() === 401, 'anonymous blocked: ' . $route[1] );
	}
	$subscriber = new WP_User(); $subscriber->ID = 987654; $subscriber->caps = array( 'subscriber' => true ); $subscriber->get_role_caps(); $GLOBALS['current_user'] = $subscriber;
	queue_check( $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/geocoding' ) )->get_status() === 403, 'non-admin capability denied' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) ); wp_set_current_user( $admins[0]->ID );
	foreach ( array( '0', '-1', '1,bad', '1.2', '99999999999999999999999', array( 1 ), implode( ',', range( 1, 101 ) ) ) as $bad ) {
		$request = new WP_REST_Request( 'GET', '/tapin/v1/geocoding' ); $request->set_param( 'ids', $bad );
		queue_check( $server->dispatch( $request )->get_status() === 400, 'invalid status IDs rejected: ' . json_encode( $bad ) );
	}
	foreach ( array( array(), array( -1 ), array( 1.5 ), array( true ), array( '1 OR 1=1' ), range( 1, 101 ) ) as $bad ) {
		$request = new WP_REST_Request( 'POST', '/tapin/v1/geocoding/retry' ); $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( array( 'ids' => $bad ) ) );
		queue_check( $server->dispatch( $request )->get_status() === 400, 'invalid retry IDs rejected: ' . json_encode( $bad ) );
	}
	$request = new WP_REST_Request( 'POST', '/tapin/v1/geocoding/retry' ); $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( array( 'ids' => array( $address_id ) ) ) );
	queue_check( $server->dispatch( $request )->get_status() === 200, 'authorized retry endpoint queues point' );
	wp_set_current_user( 0 );
	$request = new WP_REST_Request( 'GET', '/tapin/v1/public/points/' . $address_id ); $response = $server->dispatch( $request ); $body = $response->get_data();
	queue_check( $response->get_status() === 200 && $body['landline_phone'] === '02112345678' && $body['postal_code'] === '1234567890' && $body['provider_id'] === $post, 'public address-only detail exposes safe branch fields' );
	queue_check( ! isset( $body['metadata'], $body['source'] ) && strpos( wp_json_encode( $body ), 'PRIVATE_SENTINEL' ) === false, 'public allowlist excludes internal source/import data' );
	queue_check( null === $body['latitude'] && null === $body['longitude'], 'public address-only detail has no fake coordinates' );
	$request = new WP_REST_Request( 'GET', '/tapin/v1/public/directory' ); $request->set_param( 'search', 'فقط نشانی رسمی' ); $request->set_param( 'province', 'تهران' ); $request->set_param( 'city', 'تهران' ); $request->set_param( 'provider_id', $post );
	queue_check( $server->dispatch( $request )->get_data()['total'] === 1, 'address-only searchable with combined filters' );
	$request = new WP_REST_Request( 'GET', '/tapin/v1/public/points' ); $request->set_param( 'search', 'فقط نشانی رسمی' );
	queue_check( $server->dispatch( $request )->get_data()['total'] === 0, 'address-only excluded from marker endpoint' );
	$repo->update( $address_id, array( 'status' => 'inactive' ) );
	queue_check( $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/public/points/' . $address_id ) )->get_status() === 404, 'inactive point hidden from detail' );
	$repo->update( $address_id, array( 'status' => 'active' ) ); $providers->update( $post, array( 'is_active' => 0 ) );
	queue_check( $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/public/points/' . $address_id ) )->get_status() === 404, 'inactive provider hidden from detail' );
	$providers->update( $post, array( 'is_active' => 1 ) );
	$before_deactivate = $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A );
	Deactivator::deactivate(); Activator::activate();
	queue_check( $before_deactivate === $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A ), 'deactivation/reactivation preserve every record' );
	queue_check( ! wp_next_scheduled( Jobs::HOOK ), 'deactivation unschedules geocoding event' );
	// Replay only the worker's init callback, not WordPress core block registration.
	foreach ( $GLOBALS['wp_filter']['init']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			if ( $callback['function'] instanceof Closure && Jobs::class === ( ( new ReflectionFunction( $callback['function'] ) )->getClosureScopeClass()->name ?? '' ) ) { $callback['function'](); }
		}
	}
	queue_check( (bool) wp_next_scheduled( Jobs::HOOK ), 'reactivated plugin reschedules worker on init' );
	$before_uninstall_jobs = $wpdb->get_results( 'SELECT * FROM ' . Jobs::table() . ' ORDER BY point_id', ARRAY_A );
	define( 'WP_UNINSTALL_PLUGIN', 'tapin-service-point-locator/tapin-service-point-locator.php' );
	require dirname( __DIR__ ) . '/uninstall.php';
	queue_check( is_array( $before_uninstall_jobs ) && count( $before_uninstall_jobs ) > 0 && $before_deactivate === $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A ) && $before_uninstall_jobs === $wpdb->get_results( 'SELECT * FROM ' . Jobs::table() . ' ORDER BY point_id', ARRAY_A ), 'ordinary uninstall preserves points and queued work' );
	queue_check( ! wp_next_scheduled( Jobs::HOOK ), 'ordinary uninstall clears worker schedule' );
} finally {
	remove_filter( 'tapin_geocoder', $filter ); remove_filter( 'pre_http_request', $block_http ); wp_set_current_user( 0 );
	// Only this run's explicitly generated plugin tables can be removed.
	if ( preg_match( '/^' . preg_quote( $original, '/' ) . 'phase2_[a-f0-9]{8}_$/D', $table_prefix ) ) {
		foreach ( array( 'tapin_geocoding_jobs', 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_import_points', 'tapin_logs' ) as $suffix ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $table_prefix . $suffix ); }
	}
	$wpdb->prefix = $original;
	foreach ( $options as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value ); }
	queue_check( $real_rows === $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A ), 'original database records unchanged by isolated tests' );
}
echo "RESULT Phase 2 integration: {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
