<?php
/** Optional development-only benchmark using disposable tables. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Import\ImportJobs;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
$count = 10000;
$original_prefix = $wpdb->prefix;
$wpdb->prefix .= 'benchmark_' . bin2hex( random_bytes( 4 ) ) . '_';
\Tapin\ServicePointLocator\Database\Schema::migrate();
$providers = new ProviderRepository(); $points = new ServicePointRepository();
$provider = $providers->insert( array( 'slug' => 'benchmark-' . wp_generate_password( 12, false ), 'name' => 'Temporary benchmark', 'is_active' => 1 ) );
$file = tempnam( sys_get_temp_dir(), 'tapin-benchmark-' ); $job_id = 0;
try {
	$h = fopen( $file, 'wb' ); fputcsv( $h, array( 'code', 'name', 'province', 'city', 'address', 'phone' ) );
	for ( $i = 0; $i < $count; $i++ ) { fputcsv( $h, array( 'BENCH-' . $i, 'Benchmark ' . $i, 'Benchmark', 'Benchmark', 'Test address ' . $i, '02' . str_pad( (string) $i, 9, '0', STR_PAD_LEFT ) ) ); } fclose( $h );
	$start = microtime( true );
	$job = ImportJobs::stage( $file, 'benchmark.csv' );
	if ( is_wp_error( $job ) ) { throw new RuntimeException( $job->get_error_message() ); }
	$stage_seconds = microtime( true ) - $start;
	$job_id = (int) $job['id'];
	$job = ImportJobs::start( $job_id, array( 'provider_id' => $provider, 'mapping' => $job['data']['mapping'], 'duplicate_action' => 'skip' ) );
	$batches = 0; $longest = 0; $process_start = microtime( true );
	while ( ! is_wp_error( $job ) && $job['status'] === 'running' ) {
		$batch_start = microtime( true ); $job = ImportJobs::step( $job_id );
		$longest = max( $longest, microtime( true ) - $batch_start ); $batches++;
		if ( $batches % 40 === 0 ) { echo 'Processed ' . $job['data']['processed'] . " rows\n"; }
	}
	if ( is_wp_error( $job ) || $job['data']['inserted'] !== $count ) { throw new RuntimeException( 'Benchmark failed or lost rows.' ); }
	$import_seconds = microtime( true ) - $process_start;
	$summary_start = microtime( true ); $summary = $points->summary(); $summary_seconds = microtime( true ) - $summary_start;
	echo wp_json_encode( array( 'rows' => $count, 'batches' => $batches, 'stage_seconds' => round( $stage_seconds, 3 ), 'import_seconds' => round( $import_seconds, 3 ), 'max_batch_seconds' => round( $longest, 3 ), 'dashboard_seconds' => round( $summary_seconds, 3 ), 'peak_memory_mib' => round( memory_get_peak_usage( true ) / 1048576, 1 ), 'diagnostic_rows' => count( $job['data']['issues'] ) ), JSON_PRETTY_PRINT ) . "\n";
} finally {
	global $wpdb;
	$wpdb->delete( $points->get_table_name(), array( 'provider_id' => $provider ), array( '%d' ) );
	$providers->delete( $provider );
	if ( $job_id ) { ImportJobs::cancel( $job_id ); $wpdb->delete( ImportJobs::table(), array( 'id' => $job_id ), array( '%d' ) ); }
	wp_delete_file( $file );
	foreach ( array( 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_logs' ) as $suffix ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix ); }
	$wpdb->prefix = $original_prefix;
}
