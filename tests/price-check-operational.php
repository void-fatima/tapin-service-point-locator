<?php
/** Original Python expectations, compared using the isolated wpdb SQL double. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckDatabase.php';
use Tapin\ServicePointLocator\PriceCheck\CacheSettings;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\QuoteLogStorage;
use Tapin\ServicePointLocator\PriceCheck\StorageError;
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
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "Enable pdo_sqlite for isolated operational checks.\n" ); exit( 1 ); }
$passed = $operations = $differences = 0;
function operational_check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function operational_execute( $item ) {
	global $logs, $cache, $routes;
	switch ( $item->op ) {
		case 'settings_get': return (object) CacheSettings::get();
		case 'settings_save': return (object) CacheSettings::save( ...$item->args );
		case 'log_save': return $logs->save( ...$item->args );
		case 'log_list': return $logs->list_rows( ...$item->args );
		case 'log_clear': return $logs->clear();
		case 'log_delete': return $logs->delete( ...$item->args );
		case 'cache_save': $cache->save( ...$item->args ); return null;
		case 'cache_get': return $cache->get( ...$item->args );
		case 'cache_clear': return $cache->clear();
		case 'cache_fresh': return $cache->is_fresh( ...$item->args );
		case 'cache_key': return PriceCacheKey::make( ...$item->args );
		case 'cache_ids': return ConfigurationDatabase::rows( 'SELECT id, provider_id, params_hash FROM ' . ConfigurationDatabase::table( 'price_cache' ) . ' ORDER BY id' );
		case 'provider_delete': ConfigurationDatabase::delete( 'providers', array( 'id' => $item->args[0] ) ); return null;
		case 'security_get': return (object) SecuritySettings::get();
		case 'security_save': return (object) SecuritySettings::save( ...$item->args );
		case 'route_get': return $routes->get();
		case 'route_save': return $routes->save( ...$item->args );
		case 'route_delete': return $routes->delete( ...$item->args );
		case 'route_raw': return ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'route_limits' ) );
		case 'block_add': return BlockedClientStorage::add( new BlockedClient( (array) $item->args[0] ) );
		case 'block_find': return BlockedClientStorage::find_active( ...$item->args );
		case 'block_active': return BlockedClientStorage::set_active( ...$item->args );
		case 'block_update': return BlockedClientStorage::update( ...$item->args );
		case 'block_delete': return BlockedClientStorage::delete( ...$item->args );
		case 'block_list': return BlockedClientStorage::list_rows( ...$item->args );
		case 'allow_add': return AllowlistStorage::add( ...$item->args );
		case 'allow_contains': return AllowlistStorage::contains( ...$item->args );
		case 'allow_list': return AllowlistStorage::list_rows( ...$item->args );
		case 'allow_delete': return AllowlistStorage::delete( ...$item->args );
		case 'client_remember': ClientStorage::remember( ...$item->args ); return null;
		case 'client_exists': return ClientStorage::exists( ...$item->args );
		case 'client_rows': return ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'clients' ) );
		case 'event_record': SecurityEventStorage::record( new SecurityEvent( (array) $item->args[0] ) ); return null;
		case 'event_list': return SecurityEventStorage::list_rows( ...$item->args );
		case 'event_clear': return SecurityEventStorage::clear();
		case 'stats': return (object) OperationalStats::get();
	}
	throw new LogicException( 'Unknown operation.' );
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-operational.json' ) );
operational_check( JSON_ERROR_NONE === json_last_error(), 'Invalid fixture JSON.' );
foreach ( $fixtures->scenarios as $case ) {
	$wpdb = new PriceCheckDatabase(); $now = new DateTimeImmutable( '2026-01-20T12:00:00.123456+00:00' );
	$clock = new OperationalClock( static function() use ( &$now ) { return $now; } ); $logs = new QuoteLogStorage( $clock );
	$cache = new PriceCacheStorage( $clock );
	$routes = new RouteLimitStorage( $clock );
	if ( isset( $case->initial_cache_json ) ) { $wpdb->pdo->prepare( 'INSERT INTO wp_tapin_price_cache_settings VALUES(1, ?)' )->execute( array( $case->initial_cache_json ) ); }
	if ( isset( $case->initial_security_json ) ) { $wpdb->pdo->prepare( 'INSERT INTO wp_tapin_price_security_settings VALUES(1, ?)' )->execute( array( $case->initial_security_json ) ); }
	if ( ! empty( $case->provider ) ) { $wpdb->pdo->exec( "INSERT INTO wp_tapin_price_providers(id, name, display_name) VALUES(1, 'fixture', 'fixture')" ); }
	foreach ( $case->actions as $index => $item ) {
		if ( isset( $item->now ) ) { $now = new DateTimeImmutable( $item->now ); }
		$wpdb->failure = isset( $item->failure ) ? (array) $item->failure : null;
		try { $actual = array( 'ok' => true, 'value' => operational_execute( $item ) ); }
		catch ( StorageError $error ) { $actual = array( 'ok' => false, 'kind' => 'DatabaseError' ); operational_check( false === strpos( $error->getMessage(), 'Synthetic' ), 'Database details exposed.' ); }
		catch ( InvalidArgumentException $error ) { $actual = array( 'ok' => false, 'kind' => 'ValueError', 'message' => $error->getMessage() ); }
		$flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
		$expected = isset( $item->wordpress_error ) ? (object) array( 'ok' => false, 'kind' => $item->wordpress_error ) : $item->expected;
		operational_check( json_encode( $actual, $flags ) === json_encode( $expected, $flags ), "Python mismatch: {$case->name}, {$index} ({$item->op})." );
		if ( isset( $item->wordpress_error ) ) { $differences++; } else { $operations++; }
	}
	operational_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Locator touched.' );
}
$wpdb = new PriceCheckDatabase(); $logs = new QuoteLogStorage( $clock );
foreach ( array( (object) array( 'payload' => (object) array( 'nested' => (object) array( 'token' => 'inert-marker' ) ) ), (object) array( 'response' => (object) array( 'Authorization' => 'inert-marker' ) ), (object) array( 'error_message' => 'Bearer inert-marker' ), (object) array( 'endpoint' => 'https://inert-user@example.test/' ), (object) array( 'response' => '{"token":"inert-marker"}' ), (object) array( 'endpoint' => 'https://example.test/?%74oken=inert-marker' ) ) as $input ) {
	$input->status = 'error';
	try { $logs->save( $input ); throw new RuntimeException( 'Credential-bearing log accepted.' ); }
	catch ( InvalidArgumentException $expected ) { operational_check( ! $wpdb->queries, 'Credential guard ran after SQL.' ); }
}
$wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'quote_logs', 'mode' => 'error_only' );
try { $logs->save( (object) array( 'status' => 'error' ) ); throw new RuntimeException( 'False log success.' ); }
catch ( StorageError $expected ) { $passed++; }
operational_check( false === $wpdb->suppress_errors( false ), 'Error suppression not restored.' );
$cache = new PriceCacheStorage( $clock );
foreach ( $fixtures->iso_compatibility_limits as $item ) {
	try { $cache->is_fresh( $item->input ); throw new RuntimeException( 'Unsupported ISO form silently accepted.' ); }
	catch ( DomainException $expected ) { $passed++; }
}
$wpdb->pdo->exec( "INSERT INTO wp_tapin_price_providers(id, name, display_name) VALUES(1, 'fixture', 'fixture')" );
$wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'price_cache', 'mode' => 'error_only' );
try { $cache->save( (object) array( 'provider_id' => 1 ) ); throw new RuntimeException( 'False cache save success.' ); }
catch ( StorageError $expected ) { $passed++; }
foreach ( array( 'Bearer inert-marker', '{"token":"inert-marker"}' ) as $detail ) {
	$wpdb = new PriceCheckDatabase();
	try { SecurityEventStorage::record( new SecurityEvent( array( 'detail' => $detail ) ) ); throw new RuntimeException( 'Unsafe event stored.' ); }
	catch ( InvalidArgumentException $expected ) { operational_check( ! $wpdb->queries, 'Event guard ran after SQL.' ); }
}
foreach ( array( 'security_settings', 'route_limits', 'blocked_clients', 'security_allowlist', 'clients', 'security_events' ) as $table ) {
	$wpdb = new PriceCheckDatabase(); $wpdb->failure = array( 'kind' => 'INSERT', 'table' => $table, 'mode' => 'error_only' );
	try {
		switch ( $table ) {
			case 'security_settings': SecuritySettings::save( (object) array( 'max_requests' => 4, 'window_seconds' => 60 ) ); break;
			case 'route_limits': $routes->save( '/api/x', 4, 60 ); break;
			case 'blocked_clients': BlockedClientStorage::add( new BlockedClient() ); break;
			case 'security_allowlist': AllowlistStorage::add( '192.0.2.1', '', '', 0 ); break;
			case 'clients': ClientStorage::remember( '01', 0 ); break;
			case 'security_events': SecurityEventStorage::record( new SecurityEvent() ); break;
		}
		throw new RuntimeException( 'False operational save success.' );
	} catch ( StorageError $expected ) { $passed++; }
	operational_check( false === $wpdb->suppress_errors( false ), 'Error suppression not restored.' );
}
echo "PASS operational storage: {$operations} Python-derived SQL-double comparisons; {$differences} explicit database-failure differences; {$passed} assertions. Not WordPress/MySQL integration parity.\n";
