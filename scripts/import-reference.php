<?php
/** Local operator alternative to `wp tapin import-reference` when WP-CLI is unavailable. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
if ( ! defined( 'TAPIN_PLUGIN_DIR' ) ) { throw new RuntimeException( 'Activate Tapin Service Point Locator first.' ); }
\Tapin\ServicePointLocator\Database\Schema::migrate();
foreach ( array( 'post' => 'post-semnan.csv', 'tipax' => 'tipax-tehran.csv' ) as $slug => $file ) {
	$provider = ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_by_slug( $slug );
	if ( ! $provider ) { throw new RuntimeException( 'Missing provider ' . $slug ); }
	$result = \Tapin\ServicePointLocator\Database\WriteLock::run( static function() use ( $provider, $file ) {
		return ( new \Tapin\ServicePointLocator\Import\ImportManager() )->import_csv( TAPIN_PLUGIN_DIR . 'assets/data/' . $file, (int) $provider['id'] );
	} );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	$report = $result->to_array();
	unset( $report['warnings'], $report['duplicates'] );
	echo $slug . ': ' . wp_json_encode( $report, JSON_UNESCAPED_UNICODE ) . "\n";
	if ( $result->get_errors() ) { exit( 1 ); }
}
echo 'Summary: ' . wp_json_encode( ( new \Tapin\ServicePointLocator\Repository\ServicePointRepository() )->summary() ) . "\n";
