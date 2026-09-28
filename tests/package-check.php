<?php
/** Bootstrap the extracted release instead of the working tree; use disposable plugin tables. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) || ! getenv( 'TAPIN_PACKAGE_ROOT' ) ) { exit( 1 ); }
$package = realpath( getenv( 'TAPIN_PACKAGE_ROOT' ) );
if ( ! $package || ! is_file( $package . '/tapin-service-point-locator.php' ) ) { throw new RuntimeException( 'Invalid package root.' ); }
define( 'WP_PLUGIN_DIR', dirname( $package ) );
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
if ( ! defined( 'TAPIN_PLUGIN_DIR' ) ) { require $package . '/tapin-service-point-locator.php'; }
if ( realpath( TAPIN_PLUGIN_DIR ) !== $package ) { throw new RuntimeException( 'Package bootstrap used wrong source.' ); }
global $wpdb;
$original_prefix = $wpdb->prefix;
$saved_options = array(); foreach ( array( 'tapin_db_version', 'cron' ) as $key ) { $saved_options[$key] = get_option( $key, null ); }
$temporary_prefix = $original_prefix . 'tapin_pkg_' . bin2hex( random_bytes( 4 ) ) . '_';
$wpdb->prefix = $temporary_prefix;
try {
	\Tapin\ServicePointLocator\Activator::activate();
	$providers = ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_all();
	if ( count( $providers ) !== 2 ) { throw new RuntimeException( 'Fresh activation did not seed providers.' ); }
	\Tapin\ServicePointLocator\Activator::activate();
	if ( count( ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_all() ) !== 2 ) { throw new RuntimeException( 'Activation is not idempotent.' ); }
	$result = \Tapin\ServicePointLocator\Service\PointService::save( array( 'provider_id' => $providers[0]['id'], 'name' => 'Package check', 'province' => 'Test', 'city' => 'Test', 'address' => 'Package test only' ) );
	if ( is_wp_error( $result ) || $result['item']['has_coordinates'] ) { throw new RuntimeException( 'Packaged point workflow failed.' ); }
	if ( ! shortcode_exists( 'tapin_service_points' ) ) { throw new RuntimeException( 'Packaged shortcode missing.' ); }
	foreach ( array( 'assets/dashboard.css', 'assets/data/tapin-tehran-reviewed.json', 'src/Import/TapinDirectory.php', 'assets/vendor/leaflet.js', 'assets/fonts/Vazirmatn.woff2', 'assets/brand/tapin.png', 'assets/brand/post.png', 'assets/brand/tipax.svg', 'assets/iran-provinces.geojson' ) as $asset ) {
		if ( ! is_file( $package . '/' . $asset ) ) { throw new RuntimeException( 'Missing packaged asset: ' . $asset ); }
	}
	$point_id = $result['item']['id'];
	\Tapin\ServicePointLocator\Deactivator::deactivate();
	\Tapin\ServicePointLocator\Activator::activate();
	$point = ( new \Tapin\ServicePointLocator\Repository\ServicePointRepository() )->get_by_id( $point_id );
	if ( ! $point || $point['has_coordinates'] || $point['address'] !== 'Package test only' ) { throw new RuntimeException( 'Packaged lifecycle lost or changed address-only record.' ); }
	$response = \Tapin\ServicePointLocator\Export\ServicePoints::download( array( 'status' => 'any' ) );
	if ( ! $response instanceof \Tapin\ServicePointLocator\Export\DownloadResponse ) { throw new RuntimeException( 'Packaged export failed.' ); }
	$file = tempnam( sys_get_temp_dir(), 'tapin-package-check-' );
	try {
		ob_start(); \Tapin\ServicePointLocator\Export\DownloadResponse::serve( false, $response, null, null ); $bytes = ob_get_clean();
		file_put_contents( $file, $bytes );
		$rows = iterator_to_array( \Tapin\ServicePointLocator\Import\TableReader::rows( $file, 'xlsx' ), false );
		if ( count( $rows ) !== 2 || $rows[1][1] !== 'Package check' || $rows[1][9] !== '' || $rows[1][10] !== '' ) { throw new RuntimeException( 'Packaged XLSX contents failed.' ); }
	} finally { if ( is_file( $file ) ) { unlink( $file ); } }
	$events = \Tapin\ServicePointLocator\Service\OperationalLog::recent_exports();
	if ( is_wp_error( $events ) || count( $events ) !== 1 || $events[0]['context']['rows'] !== 1 ) { throw new RuntimeException( 'Packaged export history failed.' ); }
	echo "PASS extracted ZIP: real WordPress bootstrap, fresh/repeat activation, deactivation/reactivation, preserved address-only point, shortcode/assets, parsed XLSX and export history.\n";
} finally {
	foreach ( array( 'tapin_geocoding_jobs', 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_logs' ) as $suffix ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $temporary_prefix . $suffix );
	}
	$wpdb->prefix = $original_prefix;
	foreach ( $saved_options as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value ); }
}
