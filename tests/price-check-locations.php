<?php
/** Original location expectations. All HTTP and storage are injected/doubled. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\LocationData;
use Tapin\ServicePointLocator\PriceCheck\LocationError;
use Tapin\ServicePointLocator\PriceCheck\LocationService;
use Tapin\ServicePointLocator\PriceCheck\LocationCredentials;
use Tapin\ServicePointLocator\PriceCheck\LocationCache;
use Tapin\ServicePointLocator\PriceCheck\LocationCacheError;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
use Tapin\ServicePointLocator\PriceCheck\QuoteStore;
final class LocationFixtureCache implements LocationCache {
	public ?array $entry = null;
	public bool $busy = false;
	public bool $fail_save = false;
	public int $owners = 0;
	public function read(): ?array { return $this->entry; }
	public function save( array $entry ): void { if ( $this->fail_save ) { throw new LocationCacheError( 'fixture cache failure' ); } $this->entry = json_decode( location_json( (object) $entry ), true ); $this->entry['payload'] = json_decode( location_json( $entry['payload'] ) ); }
	public function clear(): void { $this->entry = null; }
	public function acquire(): ?string { if ( $this->busy ) { return null; } $this->owners++; return 'test-owner'; }
	public function release( string $owner ): void { $this->owners--; }
}
final class LocationFixtureTransport implements ProviderTransport {
	public array $requests = array();
	public $reply;
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		$this->requests[] = array( 'url' => $request->url, 'body' => $request->body, 'authorization' => 'inert-test-marker' === ( $request->headers()['Authorization'] ?? null ), 'timeout' => $request->timeout );
		if ( $this->reply instanceof Throwable ) { throw $this->reply; } return $this->reply;
	}
}
final class LocationFixtureStore implements QuoteStore {
	public array $rows = array();
	public array $failed = array();
	public bool $fail_list = false;
	public array $lookups = array();
	public function shops(): array { if ( $this->fail_list ) { throw new RuntimeException( 'private fixture failure' ); } return $this->rows; }
	public function shop( string $id ): ?array { $this->lookups[] = $id; if ( in_array( $id, $this->failed, true ) ) { throw new RuntimeException( 'private fixture failure' ); } return in_array( $id, array( '01', '0', '1', 'fallback', 'empty' ), true ) ? array( 'id' => $id, 'token' => 'empty' === $id ? null : ' inert-test-marker ' ) : null; }
	public function provider( string $id ): ?stdClass { throw new LogicException( 'Unexpected provider read.' ); }
	public function cache_get( int $id, string $key ): ?array { throw new LogicException( 'Quote cache touched.' ); }
	public function cache_fresh( $stamp ): bool { throw new LogicException( 'Quote cache touched.' ); }
	public function cache_save( stdClass $record ): void { throw new LogicException( 'Quote cache touched.' ); }
	public function log_save( stdClass $record ): void { throw new LogicException( 'Quote log touched.' ); }
}
$passed = 0;
function location_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function location_json( $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ); }
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-locations.json' ) );
location_check( JSON_ERROR_NONE === json_last_error() && false === $fixtures->provenance->live_access, 'Invalid location fixtures.' );
foreach ( (array) $fixtures->casefold as $input => $expected ) { location_check( $expected === LocationData::canonical( $input ), 'Original Unicode casefold mismatch.' ); }
foreach ( $fixtures->cases as $index => $case ) {
	try { $args = $case->args; if ( 'decode' === $case->op ) { $args[1] = base64_decode( $args[1] ); } $value = 'ttl' === $case->op ? ( new LocationService( new LocationFixtureTransport(), new LocationFixtureCache(), null, new LocationFixtureStore(), static fn() => array(), static fn() => 21600 ) )->ttl( ...$args ) : call_user_func_array( array( LocationData::class, $case->op ), $args ); $actual = array( 'ok' => true, 'value' => $value ); }
	catch ( LocationError $error ) { $actual = array( 'ok' => false, 'message' => $error->getMessage() ); }
	location_check( location_json( $actual ) === location_json( $case->expected ), "Original location helper mismatch {$index} ({$case->op})." );
}
foreach ( $fixtures->limits as $case ) { location_check( 'decoded' === $case->python, 'Missing original JSON representation evidence.' ); try { LocationData::decode( 200, $case->raw ); throw new RuntimeException( 'Lossy location JSON accepted.' ); } catch ( DomainException $error ) { location_check( true, 'Explicit JSON representation guard.' ); } }
foreach ( $fixtures->credential_cases as $case ) {
	$store = new LocationFixtureStore(); $store->rows = $case->shops; $store->failed = $case->failed_shops; $store->fail_list = $case->fail_list;
	$service = new LocationService( new LocationFixtureTransport(), new LocationFixtureCache(), null, $store, static fn() => array(), static fn() => 21600 );
	try { $credential = $service->credentials( (object) array( 'shop_id' => $case->id ) ); $actual = array( 'ok' => true, 'shop_id' => $credential->shop_id, 'authorization' => 'inert-test-marker' === $credential->token() ); }
	catch ( LocationError $error ) { $actual = array( 'ok' => false, 'message' => $error->getMessage() ); }
	location_check( location_json( $actual ) === location_json( $case->expected ) && $store->lookups === $case->lookups, 'Original credential selection/order mismatch.' );
}
foreach ( $fixtures->snapshot_flows as $flow ) {
	$cache = new LocationFixtureCache(); $transport = new LocationFixtureTransport(); $service = new LocationService( $transport, $cache, null, new LocationFixtureStore(), static fn() => array(), static fn() => 21600 );
	foreach ( $flow->steps as $index => $step ) {
		if ( ! empty( $step->clear ) ) { $service->clear(); }
		$transport->reply = 'failure' === ( $step->mode ?? null ) ? new LocationError( 'fixture upstream unavailable' ) : new ProviderHttpResponse( $step->status ?? 503, $step->raw ?? location_json( array( 'returns' => array( 'status' => '01', 'message' => 'M' ), 'cities' => $fixtures->flat ) ) );
		try { $value = $service->snapshot( $step->names ?? array( 'post', 'پست', 'tipax' ), ( $step->credentials ?? true ) ? new LocationCredentials( '01', 'inert-test-marker' ) : null, $step->refresh ?? false, $step->now, $step->ttl ?? 10, $fixtures->providers ); $actual = array( 'ok' => true, 'value' => $value ); }
		catch ( LocationError $error ) { $actual = array( 'ok' => false, 'message' => $error->getMessage() ); }
		location_check( location_json( $actual ) === location_json( $step->expected ), "Original snapshot mismatch {$flow->name}/{$index}." );
		location_check( location_json( $cache->read() ? (object) $cache->read() : null ) === location_json( $step->cache ) && location_json( $transport->requests ) === location_json( $step->requests ), "Original snapshot state/request mismatch {$flow->name}/{$index}." );
		location_check( 0 === $cache->owners && false === strpos( location_json( array( $actual, $cache->read() ) ), 'inert-test-marker' ), 'Lease or credential leaked.' );
	}
}
// WordPress contention/failure policies cannot occur in Python's infallible process-memory store.
$cache->busy = true; $service->clear();
try { $service->snapshot( array( 'post' ), null ); throw new RuntimeException( 'Cold contention accepted.' ); } catch ( LocationError $error ) { location_check( 'به‌روزرسانی لیست شهر و استان در حال انجام است.' === $error->getMessage(), 'Busy-cache error changed.' ); }
$cache->busy = false; $cache->fail_save = true; $transport->reply = new ProviderHttpResponse( 200, location_json( array( 'cities' => $fixtures->flat ) ) );
try { $service->snapshot( array( 'post' ), new LocationCredentials( '01', 'inert-test-marker' ) ); throw new RuntimeException( 'Failed cache save accepted.' ); } catch ( LocationCacheError $error ) { location_check( null === $cache->read() && 0 === $cache->owners, 'Failed save changed snapshot or leaked lease.' ); }
echo "PASS locations: {$passed} assertions; original Python helpers + explicit JSON guards, no live HTTP.\n";
