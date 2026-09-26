<?php
/** Run only against a development WordPress install: TAPIN_WP_ROOT=/path/to/wp php tests/integration.php */
$root = getenv( 'TAPIN_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set TAPIN_WP_ROOT to a development WordPress install.\n" ); exit( 1 ); }
require $root . '/wp-load.php';
if ( ! defined( 'TAPIN_PLUGIN_DIR' ) ) { require dirname( __DIR__ ) . '/tapin-service-point-locator.php'; }
\Tapin\ServicePointLocator\Database\Schema::migrate();

use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Service\PointService;
use Tapin\ServicePointLocator\Import\ImportJobs;

$passed = 0; $failed = 0;
function check( $condition, $name ) { global $passed, $failed; if ( $condition ) { $passed++; echo "PASS {$name}\n"; } else { $failed++; echo "FAIL {$name}\n"; } }
$providers = new ProviderRepository(); $points = new ServicePointRepository();
$provider = $providers->insert( array( 'slug' => 'test-' . wp_generate_password( 10, false ), 'name' => 'آزمایش خودکار', 'is_active' => 1 ) );
$ids = array(); $jobs = array(); $files = array();
try {
	$before = $points->summary();
	$base = array( 'provider_id' => $provider, 'name' => 'شعبه آزمایشی', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'نشانی آزمایشی', 'phone' => '02111111111', 'code' => 'A-1', 'latitude' => 35.7, 'longitude' => 51.4 );
	$saved = PointService::save( $base ); $id = $saved['item']['id']; $ids[] = $id;
	check( $id > 0 && $saved['item']['has_coordinates'], 'create located service point' );
	$base['latitude'] = null; $base['longitude'] = null;
	$cleared = PointService::save( $base, $id );
	check( null === $cleared['item']['latitude'] && ! $cleared['item']['has_coordinates'], 'clear coordinates on update' );
	check( $points->summary()['missing'] === $before['missing'] + 1, 'real missing-coordinate metric' );
	check( 0 === $points->query( array( 'provider_id' => $provider, 'public' => true ) )['total'], 'address-only excluded from public query' );
	$bad = $base; $bad['latitude'] = 'garbage';
	check( is_wp_error( PointService::save( $bad ) ), 'malformed coordinates rejected' );
	$bad = $base; $bad['provider_id'] = 999999999;
	check( is_wp_error( PointService::save( $bad ) ), 'unknown provider rejected' );
	$file = tempnam( sys_get_temp_dir(), 'tapin-test-' ); $files[] = $file;
	$csv = fopen( $file, 'wb' );
	fputcsv( $csv, array( 'code', 'name', 'province', 'city', 'address', 'phone', 'latitude', 'longitude' ) );
	fputcsv( $csv, array( 'B-2', 'جدید', 'تهران', 'تهران', 'نشانی دوم', '02122222222', '', '' ) );
	fputcsv( $csv, array( 'B-2', 'جدید تکراری', 'تهران', 'تهران', 'نشانی سوم', '02122222222', '', '' ) );
	fputcsv( $csv, array( 'C-3', 'نامعتبر', 'تهران', 'تهران', 'نشانی', '', 'bad', 'bad' ) );
	fputcsv( $csv, array( 'D-4', 'ستون ناقص' ) ); fclose( $csv );
	$job = ImportJobs::stage( $file, 'test.csv' ); check( ! is_wp_error( $job ), 'stage CSV and preview' );
	$jobs[] = (int) $job['id'];
	$started = ImportJobs::start( (int) $job['id'], array( 'provider_id' => $provider, 'mapping' => $job['data']['mapping'], 'duplicate_action' => 'skip' ) );
	check( 'running' === $started['status'], 'mapping starts import' );
	$done = ImportJobs::step( (int) $job['id'] );
	check( ! is_wp_error( $done ) && 'completed' === $done['status'], 'import checkpoint commits' );
	check( $done['data']['inserted'] === 1 && $done['data']['skipped'] === 1 && $done['data']['failed'] === 2, 'exact row outcome accounting' );
	check( ImportJobs::step( (int) $job['id'] )['data']['processed'] === 4, 'repeated completed request is idempotent' );
	check( ! isset( $done['data']['path'] ), 'private path never exposed' );
	// REST permissions and public field minimization.
	wp_set_current_user( 0 );
	$server = rest_get_server();
	$res = $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/points' ) );
	check( $res->get_status() >= 400, 'anonymous admin access denied' );
	$base['latitude'] = 35.7; $base['longitude'] = 51.4; $base['metadata'] = array( 'private' => 'hidden' );
	PointService::save( $base, $id );
	$req = new WP_REST_Request( 'GET', '/tapin/v1/public/points' ); $req->set_param( 'provider_id', $provider ); $req->set_param( 'status', 'any' );
	$res = $server->dispatch( $req ); $data = $res->get_data();
	check( count( $data['items'] ) === 1 && ! isset( $data['items'][0]['metadata'] ), 'public map returns located records without private metadata' );
	$providers->update( $provider, array( 'is_active' => 0 ) );
	check( $server->dispatch( $req )->get_data()['total'] === 0, 'inactive provider excluded publicly' );
} finally {
	global $wpdb;
	$wpdb->delete( $points->get_table_name(), array( 'provider_id' => $provider ), array( '%d' ) );
	$providers->delete( $provider );
	foreach ( $jobs as $job_id ) { ImportJobs::cancel( $job_id ); $wpdb->delete( ImportJobs::table(), array( 'id' => $job_id ), array( '%d' ) ); }
	foreach ( $files as $file ) { wp_delete_file( $file ); }
}
echo "{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
