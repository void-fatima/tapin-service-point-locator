<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register(); require __DIR__ . '/support/PriceCheckRateDatabase.php';
use Tapin\ServicePointLocator\PriceCheck\SecurityRouteLimits;
use Tapin\ServicePointLocator\PriceCheck\SecurityRateLimiter;
use Tapin\ServicePointLocator\PriceCheck\WordPressRateLimitStore;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\StorageError;
$count = 0;
function rate_assert( $actual, $expected ): void { global $count; if ( json_encode( $actual ) !== json_encode( $expected ) ) { throw new RuntimeException( 'Rate assertion ' . $count . ' failed.' ); } $count++; }
function wp_cache_delete( $key, $group ): void { global $invalidations; $invalidations[] = array( $key, $group ); }
$invalidations = array(); $fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-security-rate.json' ) );
foreach ( $fixture->route_cases as $case ) { $routes = new SecurityRouteLimits( static fn() => $case->settings, static fn() => $case->overrides ); rate_assert( SecurityRouteLimits::normalize( $case->route ), $case->normalized ); rate_assert( $routes->resolve( $case->route ), $case->result ); }
$wpdb = new PriceCheckRateDatabase(); $store = new WordPressRateLimitStore(); $now = 1000; $overrides = new stdClass();
$clock = new OperationalClock( static function() use ( &$now ) { return DateTimeImmutable::createFromFormat( 'U.u', sprintf( '%.6f', $now / 1000 ) ); } );
$routes = new SecurityRouteLimits( static fn() => array( 'max_requests' => 7, 'window_seconds' => 11 ), static function() use ( &$overrides ) { return $overrides; } );
$limiter = new SecurityRateLimiter( $store, $clock, 4, 60, array( $routes, 'resolve' ), array( SecurityRouteLimits::class, 'normalize' ) );
foreach ( $fixture->actions as $action ) {
	$args = $action->args; $actual = null;
	switch ( $action->op ) {
		case 'time': $now = $args[0]; break;
		case 'override': $overrides = $args[0]; break;
		case 'consume': $actual = $limiter->consume( ...$args ); break;
		case 'dimensions': $actual = $limiter->dimensions( ...$args ); break;
		case 'peek': $actual = $limiter->peek( ...$args ); break;
		case 'inspect': $actual = $limiter->inspect_identity( ...$args ); break;
		case 'reset': $limiter->reset_identity( ...$args ); break;
		case 'clear': $limiter->reset_all(); break;
		case 'increment': $actual = $store->increment( ...$args ); break;
		case 'read': $actual = $store->read( ...$args ); break;
		default: throw new RuntimeException( 'Unknown rate action.' );
	} rate_assert( $actual, $action->result );
}
$key = 'tapin:rate:ip:interleave'; rate_assert( $store->increment( $key, 60, 1000 ), array( 1, 60 ) );
$wpdb->interleave = static function() use ( $store, $key ) { rate_assert( $store->increment( $key, 60, 1000 ), array( 2, 60 ) ); };
rate_assert( $store->increment( $key, 60, 1000 ), array( 3, 60 ) ); rate_assert( $store->read( $key, 60, 1000 ), array( 3, 60 ) );
$wpdb->interleave = static function() use ( $store, $key ) { $store->delete( $key ); rate_assert( $store->increment( $key, 60, 62000 ), array( 1, 60 ) ); };
rate_assert( $store->increment( $key, 60, 62000 ), array( 2, 60 ) );
foreach ( array( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ) as $kind ) { foreach ( array( false, true ) as $error_only ) {
	$store->delete( $key ); if ( in_array( $kind, array( 'UPDATE', 'DELETE' ), true ) ) { $store->increment( $key, 60, 0 ); }
	$wpdb->fail = $kind; $wpdb->error_only = $error_only;
	try { 'DELETE' === $kind ? $store->delete( $key ) : $store->increment( $key, 60, 0 ); throw new RuntimeException( 'Missing DB failure.' ); }
	catch ( StorageError $error ) { rate_assert( false !== strpos( $error->getMessage(), 'inert-test-marker' ), false ); }
	rate_assert( $wpdb->suppressed, false );
} }
$wpdb->fail = 'SELECT'; rate_assert( $limiter->inspect_identity( 'ip', '', '', '/api/price-check' )['limited'], false );
$wpdb->pdo->exec( "INSERT INTO wp_options VALUES ('tapin_price_locations_snapshot','retained','no'), ('tapin_locator_state','retained','no'), ('tapin_price_rate_unrelated','retained','no')" );
$store->clear(); rate_assert( $wpdb->pdo->query( "SELECT COUNT(*) FROM wp_options WHERE option_value='retained'" )->fetchColumn(), 3 );
rate_assert( $wpdb->pdo->query( "SELECT COUNT(*) FROM wp_options WHERE autoload <> 'no'" )->fetchColumn(), 0 );
$routes = new SecurityRouteLimits( static function() { throw new RuntimeException( 'private read failure' ); }, static function() { throw new RuntimeException( 'private read failure' ); } );
rate_assert( $routes->resolve( '/api/providers' ), array( 60, 60 ) ); rate_assert( $routes->resolve( '/unknown' ), array( 4, 60 ) );
$broken = new SecurityRateLimiter( $store, $clock, 3, 9, static function() { throw new RuntimeException(); }, static function() { throw new RuntimeException(); } );
rate_assert( $broken->consume( 'ip', '', '', 0 )['allowed'], true ); rate_assert( $broken->peek( 'ip', '', '', 0 )->ip, array( 1, 9 ) );
$store->clear(); $wpdb->interleave_sql = 'INSERT';
$wpdb->interleave = static function() use ( $store, $key ) { rate_assert( $store->increment( $key, 60, 1000 ), array( 1, 60 ) ); };
rate_assert( $store->increment( $key, 60, 1000 ), array( 2, 60 ) ); $wpdb->interleave_sql = 'UPDATE';
$name = WordPressRateLimitStore::PREFIX . hash( 'sha256', $key );
$racer = static function() use ( &$racer, $wpdb, $name ) { $raw = $wpdb->pdo->query( "SELECT option_value FROM wp_options WHERE option_name = " . $wpdb->pdo->quote( $name ) )->fetchColumn(); [$hits, $start] = explode( ':', $raw ); $wpdb->pdo->exec( "UPDATE wp_options SET option_value = " . $wpdb->pdo->quote( ( (int) $hits + 1 ) . ':' . $start ) . " WHERE option_name = " . $wpdb->pdo->quote( $name ) ); $wpdb->interleave = $racer; };
$wpdb->interleave = $racer;
try { $store->increment( $key, 60, 1000 ); throw new RuntimeException( 'Missing contention failure.' ); } catch ( StorageError $error ) { rate_assert( true, true ); } $wpdb->interleave = null;
foreach ( array( 'SELECT', 'DELETE' ) as $kind ) { $wpdb->fail = $kind; try { $store->clear(); throw new RuntimeException( 'False successful reset.' ); } catch ( StorageError $error ) { rate_assert( true, true ); } }
$wpdb->pdo->exec( 'UPDATE wp_options SET option_value = ' . $wpdb->pdo->quote( 'corrupt' ) . ' WHERE option_name = ' . $wpdb->pdo->quote( $name ) );
foreach ( array( 'read', 'increment' ) as $method ) { try { $store->$method( $key, 60, 1000 ); throw new RuntimeException( 'Corrupt slot accepted.' ); } catch ( StorageError $error ) { rate_assert( true, true ); } }
$wpdb->pdo->exec( 'UPDATE wp_options SET option_value = ' . $wpdb->pdo->quote( PHP_INT_MAX . ':1000' ) . ' WHERE option_name = ' . $wpdb->pdo->quote( $name ) );
try { $store->increment( $key, 60, 1000 ); throw new RuntimeException( 'Counter overflow accepted.' ); } catch ( StorageError $error ) { rate_assert( true, true ); }
echo "Security rate limits: {$count} assertions passed (SQLite SQL/CAS double).\n";
