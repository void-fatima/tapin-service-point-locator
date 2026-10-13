<?php
/** Optional dedicated test-site checks. Creates only random, disposable module tables. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) || '1' !== getenv( 'TAPIN_PRICE_STORAGE_TEST_SITE' ) ) {
	fwrite( STDERR, "Requires TAPIN_WP_ROOT and TAPIN_PRICE_STORAGE_TEST_SITE=1 for a dedicated disposable WordPress/MySQL test site.\n" );
	exit( 1 );
}
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
if ( ! defined( 'TAPIN_PLUGIN_DIR' ) ) { require dirname( __DIR__ ) . '/tapin-service-point-locator.php'; }

use Tapin\ServicePointLocator\PriceCheck\Schema;
use Tapin\ServicePointLocator\PriceCheck\ProviderConfig;
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\StorageError;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;

global $wpdb;
$original_prefix = $wpdb->prefix;
$saved_version = get_option( Schema::DB_VERSION_OPTION, null );
$wpdb->prefix .= 'price_storage_test_' . bin2hex( random_bytes( 4 ) ) . '_';
$tables = Schema::tables(); $passed = 0;
function storage_integration_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); } $GLOBALS['passed']++;
}
try {
	delete_option( Schema::DB_VERSION_OPTION );
	storage_integration_check( Schema::migrate(), 'Disposable schema creation failed.' );
	$record = ProviderConfig::normalise( (object) array(
		'name' => "storage'fixture", 'location_config' => (object) array( 'origin' => (object) array( 'enabled' => false ) ),
		'timeout' => 1.23456789012345, 'params' => array( (object) array( 'name' => 'items', 'input' => 'list', 'fields' => array( (object) array( 'name' => 'count', 'type' => 'int', 'default' => 0 ) ) ) ),
	) );
	$id = ProviderStorage::save( $record ); $loaded = ProviderStorage::load( $id );
	storage_integration_check( $loaded->name === $record->name && $loaded->timeout === $record->timeout && 0 === $loaded->params[0]->fields[0]->default, 'Provider round trip failed.' );
	$pid = $loaded->params[0]->id; $fid = $loaded->params[0]->fields[0]->id;
	$loaded->name = 'renamed'; $loaded->params[0]->name = 'renamed_items'; $loaded->params[0]->fields[0]->name = 'renamed_count';
	ProviderStorage::save( $loaded ); $again = ProviderStorage::load( $id );
	storage_integration_check( $pid === $again->params[0]->id && $fid === $again->params[0]->fields[0]->id, 'Stable owned IDs changed.' );
	try { ProviderStorage::save( $again ); throw new RuntimeException( 'Source unchanged-update failure was not preserved.' ); }
	catch ( StorageError $expected ) { $passed++; }
	ProviderStorage::delete( $id );
	storage_integration_check( null === ProviderStorage::load( $id ) && array() === ProviderStorage::field_rows( array( $pid ) ), 'Delete cascade failed.' );
	$shop = (object) array( 'id' => '01', 'title' => "fixture'shop", 'province' => '0', 'city' => null, 'token' => 'inert-test-text' );
	storage_integration_check( 1 === ShopStorage::save( array( $shop ) ) && 1 === ShopStorage::save( array( $shop ) ), 'Shop insert/unchanged upsert failed.' );
	$listing = ShopStorage::list_rows( '0' );
	storage_integration_check( '01' === $listing[0]['id'] && ! array_key_exists( 'token', $listing[0] ) && 1 === $listing[0]['has_token'], 'Shop list shape changed.' );
	$shop->city = '2'; ShopStorage::save( array( $shop ) );
	storage_integration_check( '2' === ShopStorage::get_private( '01' )['city'] && ShopStorage::delete( '01' ) && ! ShopStorage::delete( '01' ), 'Shop update/delete failed.' );
	echo "PASS dedicated WordPress/MySQL configuration storage: {$passed} assertions.\n";
} finally {
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); }
	$wpdb->prefix = $original_prefix;
	if ( null === $saved_version ) { delete_option( Schema::DB_VERSION_OPTION ); }
	else { update_option( Schema::DB_VERSION_OPTION, $saved_version ); }
}
