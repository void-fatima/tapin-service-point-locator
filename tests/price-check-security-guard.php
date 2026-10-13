<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckDatabase.php'; require __DIR__ . '/support/PriceCheckRateDatabase.php'; require __DIR__ . '/support/PriceCheckSecurityDatabase.php';
use Tapin\ServicePointLocator\PriceCheck\SecurityStateStore;
use Tapin\ServicePointLocator\PriceCheck\WordPressSecurityStateStore;
use Tapin\ServicePointLocator\PriceCheck\SecurityGuard;
use Tapin\ServicePointLocator\PriceCheck\SecurityRequest;
use Tapin\ServicePointLocator\PriceCheck\SecurityIpResolver;
use Tapin\ServicePointLocator\PriceCheck\SecurityLists;
use Tapin\ServicePointLocator\PriceCheck\SecurityEvent;
use Tapin\ServicePointLocator\PriceCheck\BlockedClient;
use Tapin\ServicePointLocator\PriceCheck\SecurityEventStorage;
use Tapin\ServicePointLocator\PriceCheck\SecurityRateLimiter;
use Tapin\ServicePointLocator\PriceCheck\RateLimitStore;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\StorageError;
use Tapin\ServicePointLocator\PriceCheck\ClientStorage;
use Tapin\ServicePointLocator\PriceCheck\WordPressRateLimitStore;
use Tapin\ServicePointLocator\PriceCheck\SecurityTime;
use Tapin\ServicePointLocator\PriceCheck\SecuritySettings;
use Tapin\ServicePointLocator\PriceCheck\RouteLimitStorage;

/** Spy around actual Stage 5 stores; injected exceptions are never printed. */
final class SecurityFixtureState implements SecurityStateStore {
	public array $calls = array(); public string $fail = ''; private WordPressSecurityStateStore $store;
	public function __construct() { $this->store = new WordPressSecurityStateStore(); }
	public function contains( string $ip ): bool { $this->calls[] = array( 'contains', $ip ); if ( 'allow' === $this->fail ) { throw new RuntimeException( 'inert-test-marker' ); } return $this->store->contains( $ip ); }
	public function find_active( string $type, string $value, int $now ): ?BlockedClient { $this->calls[] = array( 'find', $type, $value, $now ); if ( 'block' === $this->fail ) { throw new RuntimeException( 'inert-test-marker' ); } return $this->store->find_active( $type, $value, $now ); }
	public function record( SecurityEvent $event ): void { if ( 'event' === $this->fail ) { throw new RuntimeException( 'inert-test-marker' ); } $this->store->record( $event ); }
	public function add_block( BlockedClient $entry ): BlockedClient { return $this->store->add_block( $entry ); }
	public function set_active( int $id, bool $active ): bool { return $this->store->set_active( $id, $active ); }
	public function update_block( int $id, string $reason, ?int $expiry ): bool { return $this->store->update_block( $id, $reason, $expiry ); }
	public function delete_block( int $id ): bool { return $this->store->delete_block( $id ); }
	public function blocks( string $query, string $type, string $active, int $now, int $limit ): array { return $this->store->blocks( $query, $type, $active, $now, $limit ); }
	public function add_allow( string $ip, string $reason, string $actor, int $now ): int { return $this->store->add_allow( $ip, $reason, $actor, $now ); }
	public function delete_allow( int $id ): bool { return $this->store->delete_allow( $id ); }
	public function allowlist( string $query, int $limit ): array { return $this->store->allowlist( $query, $limit ); }
}
final class SecurityFixtureRates implements RateLimitStore {
	public array $slots = array(); public bool $fail = false; public bool $fail_after_ip = false;
	public function increment( string $key, int $window, int $now ): array { if ( $this->fail || ( $this->fail_after_ip && false !== strpos( $key, ':client:' ) ) ) { throw new RuntimeException( 'inert-test-marker' ); } if ( ! isset( $this->slots[$key] ) || $now - $this->slots[$key][1] >= $window * 1000 ) { $this->slots[$key] = array( 0, $now ); } $this->slots[$key][0]++; return $this->read( $key, $window, $now ); }
	public function read( string $key, int $window, int $now ): array { if ( ! isset( $this->slots[$key] ) || $now - $this->slots[$key][1] >= $window * 1000 ) { return array( 0, 0 ); } return array( $this->slots[$key][0], max( 1, (int) ceil( ( $window * 1000 - ( $now - $this->slots[$key][1] ) ) / 1000 ) ) ); }
	public function delete( string $key ): void { unset( $this->slots[$key] ); }
	public function clear(): void { $this->slots = array(); }
}
$count = 0;
function guard_assert( $actual, $expected ): void { global $count; if ( json_encode( $actual ) !== json_encode( $expected ) ) { throw new RuntimeException( 'Guard assertion ' . $count . ' failed.' ); } $count++; }
function wp_cache_delete( $key, $group ): void {}
$now = 1000; $clock = new OperationalClock( static function() use ( &$now ) { return DateTimeImmutable::createFromFormat( 'U.u', sprintf( '%d.%06d', (int) floor( $now / 1000 ), ( ( $now % 1000 + 1000 ) % 1000 ) * 1000 ) ); } );
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-security-guard.json' ) );
foreach ( $fixture->scenarios as $scenario ) {
	$wpdb = new PriceCheckDatabase(); $state = new SecurityFixtureState(); $state->fail = $scenario->fail ?? ''; $rates = new SecurityFixtureRates(); $rates->fail = 'rate' === $state->fail; $rates->fail_after_ip = 'rate_after_ip' === $state->fail;
	foreach ( $scenario->allow ?? array() as $ip ) { $state->add_allow( $ip, '', '', 0 ); }
	foreach ( $scenario->blocks ?? array() as $block ) { $state->add_block( new BlockedClient( (array) $block ) ); }
	$guard = new SecurityGuard( $state, new SecurityRateLimiter( $rates, $clock, 2, 3 ), new SecurityIpResolver( $scenario->trusted ?? array() ), $clock );
	$context = (array) $scenario->context; $context['headers'] = (array) ( $context['headers'] ?? array() ); $context['cookies'] = (array) ( $context['cookies'] ?? array() );
	foreach ( $scenario->outputs as $output ) {
		$now = $output->now; $request = SecurityRequest::from_context( $context, $context['body'] ?? null, $context['body_size'] ?? 0 ); $result = $guard->check( $request );
		guard_assert( $result, $output->result ); guard_assert( $result->allowed ? null : $result->deny_response(), $output->response );
		if ( ! $result->allowed ) { guard_assert( false !== strpos( json_encode( $result->deny_response() ), 'inert-test-marker' ), false ); }
		$cookie = $result->cookie( static fn( $bytes ) => str_repeat( 'b', $bytes * 2 ) ); guard_assert( null !== $cookie, $result->issue_client_id );
		if ( null !== $cookie ) { guard_assert( $cookie, array( 'name' => 'tapin_cid', 'value' => str_repeat( 'b', 32 ), 'max_age' => 31536000, 'path' => '/', 'httponly' => true, 'samesite' => 'lax', 'secure' => false ) ); }
	}
	$events = array_reverse( SecurityEventStorage::list_rows( '', '', 1000 ) ); foreach ( $events as $event ) { $event->id = 0; }
	guard_assert( $events, $scenario->events ); guard_assert( $state->calls, $scenario->calls ); guard_assert( false !== strpos( json_encode( $events ), 'inert-test-marker' ), false );
	guard_assert( (object) $rates->slots, $scenario->counters );
	guard_assert( $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'locator-preserved' );
	guard_assert( $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_tapin_price_clients' )->fetchColumn(), 0 );
}
$wpdb = new PriceCheckDatabase(); $lists = new SecurityLists( new WordPressSecurityStateStore(), $clock ); $now = 1000;
foreach ( $fixture->list_cases as $case ) {
	try {
		$op = array( 'check' => 'check_blocks' )[$case->op] ?? $case->op; $actual = $lists->$op( ...$case->args );
		if ( isset( $case->error ) ) { throw new RuntimeException( 'Expected block validation error.' ); } guard_assert( $actual, $case->result );
	} catch ( InvalidArgumentException $error ) { guard_assert( $error->getMessage(), $case->error ); }
}
guard_assert( $lists->allow( '2001:DB8::1', ' reason ', ' actor ' ), array( 'id' => 1, 'ip' => '2001:db8::1' ) );
guard_assert( $lists->allowlist()[0]['reason'], 'reason' ); guard_assert( $lists->is_allowed( '2001:db8::1' ), true ); guard_assert( $lists->remove_allow( 1 ), true );
try { $lists->allow( 'bad' ); throw new RuntimeException( 'Missing IP validation.' ); } catch ( InvalidArgumentException $error ) { guard_assert( $error->getMessage(), 'آدرس آی‌پی معتبر نیست.' ); }
// Complete default production composition uses actual module stores and shared-option counters.
$wpdb = new PriceCheckSecurityDatabase(); $guard = new SecurityGuard( null, null, null, $clock );
$request = SecurityRequest::from_context( array( 'route' => '/api/price-check', 'direct_ip' => '192.0.2.50' ), (object) array( 'values' => (object) array( 'weight' => 1 ) ) );
for ( $i = 0; $i < 5; $i++ ) { $result = $guard->check( $request ); guard_assert( $result->allowed, $i < 4 ); }
guard_assert( $result->http_status, 429 ); guard_assert( $wpdb->rates->pdo->query( 'SELECT COUNT(*) FROM wp_options' )->fetchColumn(), 1 );
ClientStorage::remember( str_repeat( 'a', 32 ), 1 ); guard_assert( $guard->check( $request )->allowed, false ); // registry does not bypass a limit.
( new SecurityRateLimiter( new WordPressRateLimitStore(), $clock ) )->reset_identity( '192.0.2.50', '', '', '/api/price-check' ); guard_assert( $guard->check( $request )->allowed, true );
$wpdb->rates->fail = 'UPDATE'; guard_assert( $guard->check( $request )->allowed, true );
$wpdb->state->failure = array( 'kind' => 'SELECT', 'table' => 'blocked_clients' ); guard_assert( $guard->check( $request )->allowed, true );
$wpdb->state->failure = array( 'kind' => 'SELECT', 'table' => 'security_allowlist' ); guard_assert( $guard->check( $request )->allowed, true );
$lists = new SecurityLists( new WordPressSecurityStateStore(), $clock ); $block = $lists->block( 'ip', '192.0.2.50', 'reason', 'test' );
$wpdb->state->failure = array( 'kind' => 'INSERT', 'table' => 'security_events' ); guard_assert( $guard->check( $request )->http_status, 403 );
$wpdb->state->failure = array( 'kind' => 'DELETE', 'table' => 'security_events' ); guard_assert( $guard->check( $request )->http_status, 403 );
foreach ( array( array( 'INSERT', 'block', array( 'ip', '192.0.2.51', '', '' ) ), array( 'UPDATE', 'unblock', array( $block->id ) ), array( 'DELETE', 'delete', array( $block->id ) ) ) as [$kind, $op, $args] ) {
	$wpdb->state->failure = array( 'kind' => $kind, 'table' => 'blocked_clients' );
	try { $lists->$op( ...$args ); throw new RuntimeException( 'False successful block mutation.' ); } catch ( StorageError $error ) { guard_assert( false !== strpos( $error->getMessage(), 'inert-test-marker' ), false ); }
}
guard_assert( $wpdb->state->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'locator-preserved' );
// Configured unlimited routes still enforce blocks; stored settings apply to unknown identities.
( new RouteLimitStorage( $clock ) )->save( '/api/price-check', 1, 2, false ); guard_assert( $guard->check( $request )->http_status, 403 );
$lists->delete( $block->id ); guard_assert( $guard->check( $request )->allowed, true );
SecuritySettings::save( (object) array( 'max_requests' => 1, 'window_seconds' => 5 ) ); $other = SecurityRequest::from_context( array( 'route' => '/unknown', 'direct_ip' => '192.0.2.51' ) );
guard_assert( $guard->check( $other )->allowed, true ); guard_assert( $guard->check( $other )->http_status, 429 );
$lists->block( 'ip', '192.0.2.50', '{"token":"inert-test-marker"}', 'test' ); $before = count( SecurityEventStorage::list_rows() );
guard_assert( $guard->check( $request )->http_status, 403 ); guard_assert( count( SecurityEventStorage::list_rows() ), $before );
guard_assert( false !== strpos( json_encode( SecurityEventStorage::list_rows() ), 'inert-test-marker' ), false );
// Millisecond expiry equality must not lose one millisecond through float multiplication.
foreach ( array( -1, 0, 1001, 1002, 3999, 4000 ) as $boundary ) { $now = $boundary; guard_assert( SecurityTime::milliseconds( $clock ), $boundary ); }
$now = 1000; $verdict = $guard->check( SecurityRequest::from_context( array( 'direct_ip' => 'bad' ), null, 262145 ) ); guard_assert( $verdict->cookie(), null );
$verdict = $guard->check( SecurityRequest::from_context( array( 'direct_ip' => '192.0.2.100' ) ) );
try { $verdict->cookie( static fn() => 'bad' ); throw new RuntimeException( 'Missing random validation.' ); } catch ( RuntimeException $error ) { guard_assert( $error->getMessage(), 'Client ID generator returned an invalid ID.' ); }
$cookie = $verdict->cookie(); guard_assert( preg_match( '/^[0-9a-f]{32}$/D', $cookie['value'] ), 1 );
try { $verdict->deny_response(); throw new RuntimeException( 'An allowed request fabricated a response.' ); } catch ( LogicException $error ) { guard_assert( true, true ); }
echo "Security guard/lists: {$count} assertions passed (original fixtures and SQLite module composition).\n";
