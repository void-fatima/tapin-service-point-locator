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
	foreach ( array( 'assets/vendor/leaflet.js', 'assets/fonts/Vazirmatn.woff2', 'assets/brand/tapin.png', 'assets/brand/post.png', 'assets/brand/tipax.svg', 'assets/iran-provinces.geojson' ) as $asset ) {
		if ( ! is_file( $package . '/' . $asset ) ) { throw new RuntimeException( 'Missing packaged asset: ' . $asset ); }
	}
	echo "PASS extracted ZIP: real WordPress bootstrap, fresh activation, repeat activation, point save, shortcode and runtime assets.\n";
} finally {
	foreach ( array( 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_logs' ) as $suffix ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $temporary_prefix . $suffix );
	}
	$wpdb->prefix = $original_prefix;
}
