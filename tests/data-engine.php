<?php
/** Isolated WordPress/MySQL regression checks. No production records are touched. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Database\Schema;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Import\ImportManager;
use Tapin\ServicePointLocator\Service\OperationalLog;

$original = $wpdb->prefix;
$wpdb->prefix .= 'engine_test_' . bin2hex( random_bytes( 4 ) ) . '_';
$passed = 0;
function verify_engine( bool $ok, string $message ): void {
	global $passed;
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	$passed++; echo "PASS {$message}\n";
}
try {
	\Tapin\ServicePointLocator\Activator::activate();
	Schema::migrate();
	$providers = new ProviderRepository(); $points = new ServicePointRepository();
	verify_engine( count( $providers->get_all() ) === 2, 'fresh activation and repeated migration preserve providers' );
	$post = (int) $providers->get_by_slug( 'post' )['id'];
	$base = array( 'provider_id' => $post, 'name' => 'Test only', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'Test address', 'latitude' => 35.7, 'longitude' => 51.4, 'phone' => '02112345678' );
	$id = $points->insert( $base );
	$table = $points->get_table_name();
	$wpdb->query( "ALTER TABLE {$table} DROP COLUMN mobile_phone, DROP COLUMN landline_phone, DROP COLUMN source, DROP COLUMN data_quality_status" );
	Schema::migrate();
	$legacy = $points->get_by_id( $id );
	verify_engine( $legacy['phone'] === '02112345678' && $legacy['has_coordinates'] && $legacy['data_quality_status'] === 'needs_review', 'legacy migration preserves phone and coordinates and backfills quality' );
	$points->delete( $id );
	$import = new ImportManager();
	$file = TAPIN_PLUGIN_DIR . 'assets/data/post-semnan.csv';
	$first = $import->import_csv( $file, $post );
	verify_engine( $first->get_inserted_rows() === 10 && ! $first->get_errors(), 'reviewed source CSV imports ten real address records' );
	$second = $import->import_csv( $file, $post );
	verify_engine( $second->get_inserted_rows() === 0 && $second->get_skipped_rows() === 10, 'source reimport is idempotent' );
	$row = $points->query()['items'][0];
	verify_engine( $row['source'] === 'https://tapin.ir/map/semnan.pdf' && $row['landline_phone'] === '02333348602' && $row['data_quality_status'] === 'missing_coordinates', 'source and typed contacts survive import' );
	verify_engine( $points->summary()['total'] === 10 && $points->summary()['missing'] === 10 && $points->summary()['public_mapped'] === 0, 'address records never inflate map statistics' );
	$base['mobile_phone'] = '09121234567'; $base['landline_phone'] = '02122223333'; $base['source'] = 'test-only';
	verify_engine( $points->batch_insert( array( $base ) ) === 1, 'batch insertion supports the extended column order' );
	$found = $points->query( array( 'province' => 'تهران' ) )['items'][0];
	verify_engine( $found['mobile_phone'] === $base['mobile_phone'] && $found['landline_phone'] === $base['landline_phone'] && $found['latitude'] === 35.7, 'batch contacts and coordinates are not shifted' );
	$server = rest_get_server();
	$req = new WP_REST_Request( 'GET', '/tapin/v1/public/points' );
	foreach ( array( 'north' => 36, 'south' => 35, 'east' => 52, 'west' => 51, 'province' => 'تهران', 'provider_id' => $post ) as $key => $value ) { $req->set_param( $key, $value ); }
	$data = $server->dispatch( $req )->get_data();
	verify_engine( $data['total'] === 1 && $data['items'][0]['mobile_phone'] === $base['mobile_phone'] && ! isset( $data['items'][0]['source'] ), 'viewport API filters and exposes only public contacts' );
	$req->set_param( 'west', 52 ); $req->set_param( 'east', 53 );
	verify_engine( $server->dispatch( $req )->get_data()['total'] === 0, 'viewport excludes outside coordinates' );
	$req->set_param( 'province', 'سمنان' );
	verify_engine( $server->dispatch( $req )->get_data()['total'] === 0, 'province filter never maps address-only rows' );
	$logs = OperationalLog::table();
	verify_engine( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs} WHERE event = 'import_completed'" ) === 2, 'each synchronous import records one summary event' );
	OperationalLog::record( 'system_error', array( 'phone' => 'private', 'provider_id' => $post ) );
	$context = $wpdb->get_var( "SELECT context FROM {$logs} ORDER BY id DESC LIMIT 1" );
	verify_engine( strpos( $context, 'private' ) === false, 'logs do not retain raw personal fields' );
	verify_engine( ! OperationalLog::record( 'page_view' ), 'meaningless events are rejected' );
	$wpdb->query( "INSERT INTO {$logs} (event,user_id,context,created_at) VALUES ('system_error',0,'{}',UTC_TIMESTAMP() - INTERVAL 4 MONTH)" );
	OperationalLog::cleanup();
	verify_engine( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs} WHERE created_at < UTC_TIMESTAMP() - INTERVAL 3 MONTH" ) === 0 && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs}" ) === 3, 'three-month retention removes only old events' );
	$tipax = (int) $providers->get_by_slug( 'tipax' )['id'];
	$tipax_result = $import->import_csv( TAPIN_PLUGIN_DIR . 'assets/data/tipax-tehran.csv', $tipax );
	verify_engine( $tipax_result->get_inserted_rows() === 231 && ! $tipax_result->get_errors(), 'verified official Tipax snapshot imports without validation errors' );
	verify_engine( $points->query( array( 'provider_id' => $tipax, 'public' => true ) )['total'] === 230, 'only 230 source-published Tipax positions become map points' );
	verify_engine( $points->query( array( 'provider_id' => $tipax, 'has_coordinates' => 0 ) )['total'] === 1, 'Tipax record lacking a navigation position remains address-only' );
	verify_engine( $import->import_csv( TAPIN_PLUGIN_DIR . 'assets/data/tipax-tehran.csv', $tipax )->get_inserted_rows() === 0, 'official Tipax reimport does not duplicate branches' );
	echo "{$passed} engine checks passed.\n";
} finally {
	foreach ( array( 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_import_points', 'tapin_logs' ) as $suffix ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix ); }
	$wpdb->prefix = $original;
}
