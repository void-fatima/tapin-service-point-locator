<?php
/** Optional real WP/MySQL check; only disposable quotation tables receive writes. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) {
	fwrite( STDERR, "Set TAPIN_WP_ROOT to an installed test WordPress site to run the database checks.\n" );
	exit( 1 );
}
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
if ( ! defined( 'TAPIN_PLUGIN_DIR' ) ) { require dirname( __DIR__ ) . '/tapin-service-point-locator.php'; }

use Tapin\ServicePointLocator\PriceCheck\Schema;

global $wpdb;
$original_prefix = $wpdb->prefix;
$saved_version = get_option( Schema::DB_VERSION_OPTION, null );
$wpdb->prefix .= 'price_test_' . bin2hex( random_bytes( 4 ) ) . '_';
$tables = Schema::tables();
$passed = 0;
function price_check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['passed']++;
}
try {
	delete_option( Schema::DB_VERSION_OPTION );
	price_check( Schema::migrate(), 'Fresh migration failed.' );
	foreach ( $tables as $table ) {
		price_check( '0' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'Fresh schema seeded data.' );
	}
	// Source IDs are stable supplied IDs; nested/cache rows cascade with their provider.
	price_check( false !== $wpdb->insert( $tables['providers'], array( 'id' => 17, 'name' => 'fixture', 'display_name' => 'Fixture' ) ), 'Provider fixture insert failed.' );
	price_check( false !== $wpdb->insert( $tables['provider_params'], array( 'provider_id' => 17, 'name' => 'fixture_field' ) ), 'Param fixture insert failed.' );
	$param_id = $wpdb->insert_id;
	price_check( false !== $wpdb->insert( $tables['provider_fields'], array( 'param_id' => $param_id, 'name' => 'nested' ) ), 'Nested fixture insert failed.' );
	price_check( false !== $wpdb->insert( $tables['price_cache'], array( 'created_at' => '2026-01-01T00:00:00+00:00', 'provider_id' => 17, 'params_hash' => 'fixture', 'params_json' => '{}', 'endpoint' => '', 'quote_json' => '{}' ) ), 'Cache fixture insert failed.' );
	price_check( Schema::migrate(), 'Repeated migration failed.' );
	price_check( '1' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['providers']}`" ), 'Repeated migration altered data.' );
	$wpdb->query( "ALTER TABLE `{$tables['providers']}` DROP COLUMN weight_input_param" );
	update_option( Schema::DB_VERSION_OPTION, 0 );
	Schema::maybe_migrate();
	price_check( Schema::VERSION === (int) get_option( Schema::DB_VERSION_OPTION ) && in_array( 'weight_input_param', $wpdb->get_col( "SHOW COLUMNS FROM `{$tables['providers']}`" ), true ), 'Upgrade did not restore missing column.' );
	$wpdb->delete( $tables['providers'], array( 'id' => 17 ), array( '%d' ) );
	foreach ( array( 'provider_params', 'provider_fields', 'price_cache' ) as $suffix ) {
		price_check( '0' === (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables[$suffix]}`" ), 'Cascade failed: ' . $suffix );
	}
	echo "PASS price-check real WP/MySQL: {$passed} checks; fresh/repeat/upgrade, no seeds, data preservation and cascading relationships.\n";
} finally {
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); }
	$wpdb->prefix = $original_prefix;
	if ( null === $saved_version ) { delete_option( Schema::DB_VERSION_OPTION ); }
	else { update_option( Schema::DB_VERSION_OPTION, $saved_version ); }
}
