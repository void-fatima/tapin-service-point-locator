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
use Tapin\ServicePointLocator\PriceCheck\BulkStorage;
use Tapin\ServicePointLocator\PriceCheck\CacheSettings;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\QuoteLogStorage;
use Tapin\ServicePointLocator\PriceCheck\PriceCacheStorage;
use Tapin\ServicePointLocator\PriceCheck\PriceCacheKey;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationDatabase;
use Tapin\ServicePointLocator\PriceCheck\SecuritySettings;
use Tapin\ServicePointLocator\PriceCheck\RouteLimitStorage;
use Tapin\ServicePointLocator\PriceCheck\BlockedClient;
use Tapin\ServicePointLocator\PriceCheck\BlockedClientStorage;
use Tapin\ServicePointLocator\PriceCheck\AllowlistStorage;
use Tapin\ServicePointLocator\PriceCheck\ClientStorage;
use Tapin\ServicePointLocator\PriceCheck\SecurityEvent;
use Tapin\ServicePointLocator\PriceCheck\SecurityEventStorage;
use Tapin\ServicePointLocator\PriceCheck\OperationalStats;

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
	storage_integration_check( $id === ProviderStorage::save( $again, true ) && 1 === count( ProviderStorage::list_rows() ), 'Unchanged provider save failed or duplicated the row.' );
	ProviderStorage::delete( $id );
	storage_integration_check( null === ProviderStorage::load( $id ) && array() === ProviderStorage::field_rows( array( $pid ) ), 'Delete cascade failed.' );
	$shop = (object) array( 'id' => '01', 'title' => "fixture'shop", 'province' => '0', 'city' => null, 'token' => 'inert-test-text' );
	storage_integration_check( 1 === ShopStorage::save( array( $shop ) ) && 1 === ShopStorage::save( array( $shop ) ), 'Shop insert/unchanged upsert failed.' );
	$listing = ShopStorage::list_rows( '0' );
	storage_integration_check( '01' === $listing[0]['id'] && ! array_key_exists( 'token', $listing[0] ) && 1 === $listing[0]['has_token'], 'Shop list shape changed.' );
	$shop->city = '2'; ShopStorage::save( array( $shop ) );
	storage_integration_check( '2' === ShopStorage::get_private( '01' )['city'] && ShopStorage::delete( '01' ) && ! ShopStorage::delete( '01' ), 'Shop update/delete failed.' );
	storage_integration_check( ! property_exists( BulkStorage::get(), 'weight' ), 'Unsaved bulk defaults changed.' );
	$bulk = (object) array( 'providers' => array( 1, 2 ), 'mappings' => (object) array( '1' => (object) array( 'shared' => '4' ), '2' => new stdClass() ), 'fields' => array( (object) array( 'name' => 'shared', 'source' => (object) array( 'provider' => 1, 'param' => 4 ) ) ) );
	BulkStorage::save( $bulk ); BulkStorage::save( $bulk );
	storage_integration_check( BulkStorage::get()->mappings->{'1'}->shared === '4', 'Bulk mapping round trip failed.' );
	storage_integration_check( BulkStorage::prune_provider( 1 ) && ! BulkStorage::prune_provider( 1 ), 'Bulk prune/no-op failed.' );
	storage_integration_check( array( 2 ) === BulkStorage::get()->providers && null === BulkStorage::get()->fields[0]->source, 'Bulk references survived pruning.' );
	$now = new DateTimeImmutable( '2026-01-20T12:00:00+00:00' );
	$clock = new OperationalClock( static function() use ( &$now ) { return $now; } );
	CacheSettings::save( (object) array( 'price' => 1, 'price_retention' => 10, 'quote_logs' => 1, 'security_events' => 1 ) );
	$logs = new QuoteLogStorage( $clock );
	storage_integration_check( 0 === $logs->save( (object) array( 'status' => 'success' ) ), 'Success log was stored.' );
	$log_id = $logs->save( (object) array( 'status' => 'error', 'payload' => (object) array( 'zero' => 0 ), 'response' => false ) );
	storage_integration_check( $log_id === $logs->list_rows()[0]['id'] && false === $logs->list_rows()[0]['response'], 'Log shape failed.' );
	$now = $now->modify( '+1 second' );
	storage_integration_check( 1 === count( $logs->list_rows() ), 'Log retention boundary changed.' );
	$now = $now->modify( '+1 second' );
	storage_integration_check( array() === $logs->list_rows(), 'Log retention failed.' );
	$id = ProviderStorage::save( (object) array( 'name' => 'operational-fixture', 'params' => array() ) );
	$cache = new PriceCacheStorage( $clock ); $hash = PriceCacheKey::make( new stdClass(), new stdClass() );
	$entry = (object) array( 'provider_id' => $id, 'params_hash' => $hash, 'quote' => (object) array( 'prices' => array( 1.25 ) ) );
	$cache->save( $entry ); $cache->save( $entry );
	$rows = ConfigurationDatabase::rows( 'SELECT id FROM ' . ConfigurationDatabase::table( 'price_cache' ) );
	storage_integration_check( 1 === count( $rows ) && 1.25 === $cache->get( $id, $hash )['quote']->prices[0], 'Cache upsert failed.' );
	$cache_id = $rows[0]['id']; $now = $now->modify( '+1 second' ); $cache->save( $entry );
	storage_integration_check( $cache_id === ConfigurationDatabase::rows( 'SELECT id FROM ' . ConfigurationDatabase::table( 'price_cache' ) )[0]['id'], 'Cache replacement changed ID.' );
	$stamp = $cache->get( $id, $hash )['created_at']; $now = $now->modify( '+1 second' );
	storage_integration_check( ! $cache->is_fresh( $stamp ) && null !== $cache->get( $id, $hash ), 'Stale cache was not retained.' );
	ProviderStorage::delete( $id ); storage_integration_check( null === $cache->get( $id, $hash ), 'Cache cascade failed.' );
	storage_integration_check( 4 === SecuritySettings::get()['max_requests'], 'Unsaved security defaults changed.' );
	SecuritySettings::save( (object) array( 'max_requests' => 10, 'window_seconds' => 30 ) );
	$routes = new RouteLimitStorage( $clock ); $routes->save( '/api/price-check', 4, 60, false ); $routes->save( '/api/price-check', 4, 60, false );
	storage_integration_check( false === $routes->get()->{'/api/price-check'}->enabled && $routes->delete( '/api/price-check' ), 'Route override round trip failed.' );
	$first = BlockedClientStorage::add( new BlockedClient( array( 'type' => 'ip', 'value' => '192.0.2.1' ) ) );
	$second = BlockedClientStorage::add( new BlockedClient( array( 'type' => 'ip', 'value' => '192.0.2.1', 'expires_at_ms' => 1000 ) ) );
	storage_integration_check( $first->id !== $second->id && $second->id === BlockedClientStorage::find_active( 'ip', '192.0.2.1', 999 )->id && $first->id === BlockedClientStorage::find_active( 'ip', '192.0.2.1', 1000 )->id, 'Block duplicate/expiry semantics failed.' );
	storage_integration_check( BlockedClientStorage::set_active( $first->id, false ) && ! BlockedClientStorage::set_active( $first->id, false ), 'Block changed-row behavior failed.' );
	$allow_id = AllowlistStorage::add( '192.0.2.1', 'first', 'actor1', 1000 );
	storage_integration_check( $allow_id === AllowlistStorage::add( '192.0.2.1', 'second', 'actor2', 2000 ) && 'actor1' === AllowlistStorage::list_rows()[0]['created_by'] && 1000 === AllowlistStorage::list_rows()[0]['created_at_ms'], 'Allowlist upsert changed original actor/time.' );
	ClientStorage::remember( '01', 1000 ); ClientStorage::remember( '01', 2000 );
	storage_integration_check( ClientStorage::exists( '01' ) && ! ClientStorage::exists( '1' ) && 1000 === ConfigurationDatabase::rows( 'SELECT first_seen_ms FROM ' . ConfigurationDatabase::table( 'clients' ) )[0]['first_seen_ms'], 'Client registry changed first-seen time.' );
	SecurityEventStorage::record( new SecurityEvent( array( 'created_at_ms' => 1000, 'kind' => 'fixture' ) ) );
	SecurityEventStorage::record( new SecurityEvent( array( 'created_at_ms' => 2000, 'kind' => 'fixture' ) ) );
	storage_integration_check( 2 === count( SecurityEventStorage::list_rows() ), 'Event retention boundary changed.' );
	SecurityEventStorage::record( new SecurityEvent( array( 'created_at_ms' => 2001, 'kind' => 'fixture' ) ) );
	storage_integration_check( 2001 === SecurityEventStorage::list_rows()[0]->created_at_ms && 2 === OperationalStats::get()['security_events_rows'] && 2 === SecurityEventStorage::clear(), 'Event prune/count/clear failed.' );
	$test_prefix = $wpdb->prefix; $wpdb->prefix .= 'missing_';
	try { BulkStorage::save( new stdClass() ); throw new RuntimeException( 'Missing-table write reported success.' ); }
	catch ( StorageError $expected ) { $passed++; }
	finally { $wpdb->prefix = $test_prefix; }
	echo "PASS dedicated WordPress/MySQL configuration and operational storage: {$passed} assertions.\n";
} finally {
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); }
	$wpdb->prefix = $original_prefix;
	if ( null === $saved_version ) { delete_option( Schema::DB_VERSION_OPTION ); }
	else { update_option( Schema::DB_VERSION_OPTION, $saved_version ); }
}
