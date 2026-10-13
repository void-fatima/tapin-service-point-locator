<?php
/** WP option/HTTP doubles. No installed WordPress, database, credentials or live HTTP. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\WordPressLocationCache;
use Tapin\ServicePointLocator\PriceCheck\WordPressLocationTransport;
use Tapin\ServicePointLocator\PriceCheck\LocationCacheError;
use Tapin\ServicePointLocator\PriceCheck\LocationError;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\QuoteSecrets;
$passed = 0; $options = array(); $autoload = array(); $failure = ''; $requests = array(); $http = array();
function location_wp_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function get_option( string $name, $default = false ) { return $GLOBALS['options'][$name] ?? $default; }
function add_option( string $name, $value, string $unused = '', $autoload = null ): bool { if ( array_key_exists( $name, $GLOBALS['options'] ) || 'add' === $GLOBALS['failure'] ) { return false; } $GLOBALS['options'][$name] = $value; $GLOBALS['autoload'][$name] = $autoload; return true; }
function update_option( string $name, $value, $autoload = null ): bool { if ( 'update' === $GLOBALS['failure'] || ( isset( $GLOBALS['options'][$name] ) && $GLOBALS['options'][$name] === $value ) ) { return false; } $GLOBALS['options'][$name] = $value; $GLOBALS['autoload'][$name] = $autoload; return true; }
function delete_option( string $name ): bool { if ( 'delete' === $GLOBALS['failure'] || ! array_key_exists( $name, $GLOBALS['options'] ) ) { return false; } unset( $GLOBALS['options'][$name] ); return true; }
function wp_cache_delete( string $key, string $group ): bool { location_wp_check( 'options' === $group && in_array( $key, array( WordPressLocationCache::LOCK, 'notoptions' ), true ), 'Unrelated object cache touched.' ); return true; }
function maybe_serialize( $value ): string { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value; }
final class LocationOptionDatabase {
	public string $options = 'wp_options'; public string $last_error = ''; private bool $suppressed = false;
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function prepare( string $sql, ...$values ): array { return array( $sql, $values ); }
	public function query( array $prepared ) {
		list( $sql, $values ) = $prepared; location_wp_check( 'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s' === $sql && WordPressLocationCache::LOCK === $values[0], 'Lease SQL touched another data path.' );
		$this->last_error = ''; if ( 'lease' === $GLOBALS['failure'] ) { $this->last_error = 'private DB failure'; return false; }
		if ( ( $GLOBALS['options'][$values[0]] ?? null ) !== $values[1] ) { return 0; } unset( $GLOBALS['options'][$values[0]] ); return 1;
	}
}
class WP_Error { public function get_error_code(): string { return $GLOBALS['http_error_code']; } public function get_error_message(): string { return 'private inert-test-marker'; } }
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_request( string $url, array $args ) { $GLOBALS['requests'][] = array( $url, $args ); if ( ! $GLOBALS['http'] ) { throw new LogicException( 'Unexpected HTTP call.' ); } return array_shift( $GLOBALS['http'] ); }
function wp_remote_retrieve_response_code( array $r ): int { return $r['status']; }
function wp_remote_retrieve_body( array $r ): string { return $r['body']; }
function wp_remote_retrieve_headers( array $r ): array { return $r['headers'] ?? array(); }
function wp_remote_retrieve_header( array $r, string $key ) { return $r['headers'][$key] ?? ''; }
$wpdb = new LocationOptionDatabase(); $now = new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ); $clock = new OperationalClock( static function() use ( &$now ) { return $now; } ); $cache = new WordPressLocationCache( $clock );
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-locations.json' ) ); $entry = (array) $fixtures->snapshot_flows[0]->steps[0]->cache;
location_wp_check( null === $cache->read(), 'Missing cache is not a miss.' ); $cache->save( $entry ); $cache->save( $entry );
location_wp_check( json_encode( $cache->read() ) === json_encode( $entry ) && false === $autoload[WordPressLocationCache::DATA], 'Permanent/non-autoloaded snapshot round-trip.' );
$fresh_instance = new WordPressLocationCache( $clock ); $now = $now->modify( '+100 years' ); location_wp_check( null !== $fresh_instance->read(), 'Last valid data expired between instances.' );
$failure = 'update'; try { $changed = $entry; $changed['fetched_at'] = 101.0; $cache->save( $changed ); throw new RuntimeException( 'Failed update accepted.' ); } catch ( LocationCacheError $error ) { location_wp_check( $cache->read()['fetched_at'] === 100.0, 'Failed save destroyed stale data.' ); } $failure = '';
$old = $options[WordPressLocationCache::DATA]; foreach ( array( 'bad', '{"payload":NaN}', json_encode( array( 'fetched_at' => 1, 'payload' => array( 'cities' => array() ) ) ) ) as $bad ) { $options[WordPressLocationCache::DATA] = $bad; location_wp_check( null === $cache->read(), 'Corrupt cache accepted as validated.' ); } $options[WordPressLocationCache::DATA] = $old;
$first = $cache->acquire(); location_wp_check( is_string( $first ) && null === $fresh_instance->acquire() && false === $autoload[WordPressLocationCache::LOCK], 'Refresh ownership/contention.' );
$now = $now->modify( '+300 seconds' ); $second = $fresh_instance->acquire(); location_wp_check( is_string( $second ) && $second !== $first, 'Expired lease not replaced.' ); $cache->release( $first ); location_wp_check( null === $cache->acquire(), 'Old owner deleted successor lease.' );
$failure = 'lease'; try { $cache->release( $second ); throw new RuntimeException( 'Failed unlock accepted.' ); } catch ( LocationCacheError $error ) { location_wp_check( false === strpos( $error->getMessage(), 'private' ) && isset( $options[WordPressLocationCache::LOCK] ), 'Unlock failure exposed SQL or cleared lease.' ); } $failure = ''; $cache->release( $second );
$failure = 'add'; try { $cache->acquire(); throw new RuntimeException( 'Failed acquisition accepted.' ); } catch ( LocationCacheError $error ) { location_wp_check( true, 'Acquisition failure.' ); } $failure = '';
$failure = 'delete'; try { $cache->clear(); throw new RuntimeException( 'Failed clear accepted.' ); } catch ( LocationCacheError $error ) { location_wp_check( null !== $cache->read(), 'Failed clear destroyed retained data.' ); } $failure = ''; $cache->clear(); $cache->clear(); location_wp_check( null === $cache->read(), 'Clear is not idempotent.' );
$secrets = new QuoteSecrets(); $secrets->remember( 'inert-test-marker' ); $request = new ProviderRequest( \Tapin\ServicePointLocator\PriceCheck\LocationService::URL, (object) array( 'shop_id' => '01' ), array( 'Authorization' => 'inert-test-marker' ), 15.0, $secrets ); $transport = new WordPressLocationTransport();
$http = array( array( 'status' => 503, 'body' => '{"cities":[]}' ) ); $reply = $transport->send( $request );
location_wp_check( 503 === $reply->status && 15.0 === $requests[0][1]['timeout'] && 'POST' === $requests[0][1]['method'] && '{"shop_id": "01"}' === $requests[0][1]['body'], 'WordPress location HTTP shape.' );
location_wp_check( 0 === $requests[0][1]['redirection'] && true === $requests[0][1]['sslverify'] && false === $requests[0][1]['reject_unsafe_urls'] && 2000001 === $requests[0][1]['limit_response_size'], 'Location/quote net mechanics diverged.' );
foreach ( array( 'http_request_timeout' => 'سرویس شهر و استان در زمان مقرر پاسخ نداد.', 'http_request_failed' => 'ارتباط با سرویس شهر و استان برقرار نشد.' ) as $http_error_code => $message ) {
	$http = array( new WP_Error() ); try { $transport->send( $request ); throw new RuntimeException( 'WP failure accepted.' ); } catch ( LocationError $error ) { location_wp_check( $error->getMessage() === $message && false === strpos( $error->getMessage(), 'inert-test-marker' ), 'Transport error vocabulary/privacy.' ); }
}
$http = array( array( 'status' => 200, 'body' => str_repeat( 'x', 2000001 ) ) ); try { $transport->send( $request ); throw new RuntimeException( 'Oversize response accepted.' ); } catch ( LocationError $error ) { location_wp_check( 'پاسخ سرویس شهر و استان بیش از حد بزرگ است.' === $error->getMessage(), 'Size failure vocabulary.' ); }
$http = array( array( 'status' => 500, 'body' => str_repeat( 'x', 2000001 ) ) ); location_wp_check( 2000001 === strlen( $transport->send( $request )->body ), 'Source HTTPError cap-bypass changed.' );
$http = array( array( 'status' => 302, 'body' => '', 'headers' => array( 'location' => 'https://other.test/' ) ), array( 'status' => 200, 'body' => '{}' ) ); $transport->send( $request ); $last = end( $requests ); location_wp_check( 'GET' === $last[1]['method'] && ! isset( $last[1]['headers']['Authorization'] ), 'Cross-origin credential stripping changed.' );
echo "PASS WordPress location adapters: {$passed} assertions; option/SQL/HTTP doubles only.\n";
