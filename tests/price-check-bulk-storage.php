<?php
/** Bulk composition over the real module stores with disposable SQLite and mocked HTTP only. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'ARRAY_A', 'ARRAY_A' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckDatabase.php';
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;
use Tapin\ServicePointLocator\PriceCheck\BulkStorage;
use Tapin\ServicePointLocator\PriceCheck\BulkQuoteService;
use Tapin\ServicePointLocator\PriceCheck\WordPressBulkQuoteStore;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\CacheSettings;
use Tapin\ServicePointLocator\PriceCheck\PriceCacheStorage;
use Tapin\ServicePointLocator\PriceCheck\QuoteLogStorage;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
use Tapin\ServicePointLocator\PriceCheck\ProviderCallError;
use Tapin\ServicePointLocator\PriceCheck\QuoteError;
use Tapin\ServicePointLocator\PriceCheck\ProviderDefinition;
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "Requires pdo_sqlite for isolated bulk/storage checks.\n" ); exit( 1 ); }
$passed = 0;
function bulk_store_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function bulk_cache_rows(): array { return $GLOBALS['wpdb']->pdo->query( 'SELECT * FROM wp_tapin_price_price_cache' )->fetchAll( PDO::FETCH_ASSOC ); }
final class BulkStoreTransport implements ProviderTransport {
	public array $requests = array();
	public array $replies = array();
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		$this->requests[] = array( 'name' => basename( $request->url ), 'body' => $request->body, 'authorization' => 'inert-test-marker' === ( $request->headers()['Authorization'] ?? null ) );
		$reply = $this->replies[basename( $request->url )] ?? new ProviderHttpResponse( 200, '{"total":"15"}' );
		if ( $reply instanceof Throwable ) { throw $reply; } return $reply;
	}
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-bulk.json' ) );
$wpdb = new PriceCheckDatabase(); $now = new DateTimeImmutable( '2026-01-20T12:00:00+00:00' ); $clock = new OperationalClock( static function() use ( &$now ) { return $now; } );
foreach ( $fixtures->providers as $provider ) { ProviderStorage::save( $provider ); }
// Owned SQL IDs are assigned by persistence. Build the same config through legacy wire references.
$config = json_decode( json_encode( $fixtures->config ) );
foreach ( (array) $config->mappings as $id => $row ) { $original = $fixtures->providers[(int) $id - 1]; foreach ( (array) $row as $shared => $target ) { $row->$shared = ProviderDefinition::target( $original, $target ); } }
foreach ( $config->fields as $field ) { if ( isset( $field->source ) ) { $field->source->param = ProviderDefinition::target( $fixtures->providers[$field->source->provider - 1], $field->source->param ); } }
$config->variants = (object) array( '1' => (object) array( 'mode' => array( 'a', 'b' ), 'rows.cost' => array( '2', '3' ) ) );
BulkStorage::save( $config ); CacheSettings::save( (object) array( 'price' => 2, 'price_retention' => 10 ) );
$store = new WordPressBulkQuoteStore( $clock ); $transport = new BulkStoreTransport(); $service = new BulkQuoteService( static fn( $provider ) => null, $transport, $store );
$raw = $fixtures->flows[0]->actions[0]->body; $result = $service->execute( $raw ); $expected_body = json_decode( json_encode( $fixtures->flows[0]->actions[0]->expected->body ) );
foreach ( $expected_body->results as $row ) {
	$variant = new stdClass(); foreach ( (array) $row->variant as $target => $value ) { $variant->{ProviderDefinition::target( $fixtures->providers[$row->provider->id - 1], (string) $target )} = $value; } $row->variant = $variant;
	foreach ( $row->variant_detail as $detail ) { $detail->target = ProviderDefinition::target( $fixtures->providers[$row->provider->id - 1], $detail->target ); }
}
$expected = json_encode( $expected_body, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
bulk_store_check( json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) === $expected, 'Bulk output with SQL stores differs from original Python.' );
bulk_store_check( count( $transport->requests ) === 5 && $transport->requests[0]['name'] === 'two', 'Stored provider selection/order failed.' );
bulk_store_check( count( bulk_cache_rows() ) === 5 && ! ( new QuoteLogStorage( $clock ) )->list_rows(), 'Bulk cache/log counts after success.' );
$hit = $service->execute( $raw ); bulk_store_check( count( $transport->requests ) === 5 && ! in_array( false, array_column( $hit['results'], 'cached' ), true ), 'Stored bulk cache hit failed.' );
$now = $now->modify( '+2 seconds' ); $transport->replies = array( 'one' => new ProviderCallError( 'سرویس قیمت در زمان مقرر پاسخ نداد.', array( 'status_code' => 504 ) ), 'two' => new ProviderCallError( 'ارتباط با سرویس قیمت برقرار نشد.' ) );
$partial = $service->execute( $raw );
bulk_store_check( false === $partial['results'][0]['ok'] && true === $partial['results'][1]['ok'] && true === $partial['results'][1]['cached'], 'Timeout stale fallback versus network partial failure.' );
bulk_store_check( count( ( new QuoteLogStorage( $clock ) )->list_rows() ) === 1, 'Fallback/partial logs changed.' );
$now = $now->modify( '+9 seconds' ); $expired = $service->execute( $raw ); bulk_store_check( ! in_array( true, array_column( $expired['results'], 'ok' ), true ), 'Expired bulk entries supplied fallback.' );
// Valid unsaved preview differs from stored config and must not change either cache or logs.
foreach ( $fixtures->flows as $flow ) { if ( 'draft previews bypass existing cache and logs' === $flow->name ) { $draft = json_decode( $flow->actions[1]->body )->config; break; } }
bulk_store_check( isset( $draft ), 'Missing source-derived preview fixture.' );
foreach ( (array) $draft->mappings as $id => $row ) { foreach ( (array) $row as $shared => $target ) { $row->$shared = ProviderDefinition::target( $fixtures->providers[(int) $id - 1], $target ); } }
foreach ( $draft->fields as $field ) { if ( isset( $field->source ) ) { $field->source->param = 'mode'; } }
$draft->providers = array( 2 ); $draft->variants = new stdClass();
$transport->replies = array(); $cache_before = $wpdb->pdo->query( 'SELECT * FROM wp_tapin_price_price_cache' )->fetchAll(); $logs_before = ( new QuoteLogStorage( $clock ) )->list_rows();
$preview = $service->execute( json_encode( array( 'config' => $draft, 'values' => array( 'money' => 15, 'mode' => 'a' ) ) ), true );
bulk_store_check( count( $preview['results'] ) === 1 && false === $preview['results'][0]['cached'] && 2 === $preview['results'][0]['provider']->id, 'Preview used stored config/cache.' );
bulk_store_check( $cache_before === $wpdb->pdo->query( 'SELECT * FROM wp_tapin_price_price_cache' )->fetchAll() && json_encode( $logs_before ) === json_encode( ( new QuoteLogStorage( $clock ) )->list_rows() ), 'Preview changed quote cache/log state.' );
// The module's private shop path uses inert authorization, including echoed error/response keys.
$private = json_decode( json_encode( $fixtures->providers[1] ) ); $private->id = 4; $private->name = 'post'; $private->check_price_url = 'https://example.test/post'; $private->shop_id = '01'; $private->params[] = (object) array( 'name' => 'shop_id', 'type' => 'str', 'input' => 'text', 'visible' => false, 'default' => '01' );
ProviderStorage::save( $private ); ShopStorage::save( array( (object) array( 'id' => '01', 'title' => 'fixture', 'token' => 'inert-test-marker' ) ) );
BulkStorage::save( (object) array( 'providers' => array( 4 ), 'fields' => array(), 'mappings' => new stdClass() ) );
$transport->replies['post'] = new ProviderHttpResponse( 200, '{"token":"inert-test-marker","echo":"inert-test-marker","inert-test-marker":1}' );
$failure = $service->execute( '{}' );
bulk_store_check( false === $failure['results'][0]['ok'] && ! property_exists( $failure['results'][0]['error'], 'errors' ), 'Missing-price public bulk error shape.' );
bulk_store_check( true === end( $transport->requests )['authorization'], 'Private authorization not used.' );
bulk_store_check( false === strpos( json_encode( array( $failure, ( new QuoteLogStorage( $clock ) )->list_rows() ) ), 'inert-test-marker' ), 'Bulk error/log exposed a credential.' );
$transport->replies['post'] = new ProviderHttpResponse( 200, '{"total":15,"token":"inert-test-marker","echo":"inert-test-marker"}' ); $success = $service->execute( '{}' );
bulk_store_check( true === $success['results'][0]['ok'] && false === strpos( json_encode( array( $success, bulk_cache_rows() ) ), 'inert-test-marker' ), 'Successful bulk cache exposed a credential.' );
( new PriceCacheStorage( $clock ) )->clear();
$transport->replies['post'] = new ProviderCallError( 'fixture inert-test-marker', array( 'errors' => array( array( 'field' => 'total', 'message' => 'inert-test-marker' ) ), 'extra' => array( 'provider_response' => (object) array( 'echo' => 'inert-test-marker' ) ) ) );
$echo = $service->execute( '{}' );
bulk_store_check( 'fixture [redacted]' === $echo['results'][0]['error']->detail && false === strpos( json_encode( array( $echo, ( new QuoteLogStorage( $clock ) )->list_rows() ) ), 'inert-test-marker' ), 'Transport error body/log echoed authorization.' );
$transport->replies['post'] = new ProviderHttpResponse( 200, '{"total":15}' );
foreach ( array( 'bulk_config', 'providers' ) as $table ) {
	$wpdb->failure = array( 'kind' => 'SELECT', 'table' => $table );
	try { $service->execute( '{}' ); throw new RuntimeException( 'Failed bulk read accepted.' ); }
	catch ( QuoteError $error ) { bulk_store_check( 500 === $error->status_code && array( 'detail' => 'internal error' ) === $error->jsonSerialize(), 'Database read failure exposed details.' ); }
}
( new PriceCacheStorage( $clock ) )->clear(); $wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'price_cache' ); $success = $service->execute( '{}' );
bulk_store_check( true === $success['results'][0]['ok'] && ! bulk_cache_rows(), 'Failed cache write changed live result/reported persistence.' );
$transport->replies['post'] = new ProviderHttpResponse( 200, '{"other":1}' ); $log_count = count( ( new QuoteLogStorage( $clock ) )->list_rows() ); $wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'quote_logs' ); $failure = $service->execute( '{}' );
bulk_store_check( false === $failure['results'][0]['ok'] && $log_count === count( ( new QuoteLogStorage( $clock ) )->list_rows() ), 'Failed log write replaced public error/reported persistence.' );
bulk_store_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Locator table changed.' );
// A full public catalog must not expand the independently configured bulk subset.
$wpdb = new PriceCheckDatabase();
foreach ( array( 1, 2, 3, 4 ) as $id ) {
	$provider = json_decode( json_encode( $fixtures->providers[1] ) );
	$provider->id = $id; $provider->name = 'catalog-' . $id; $provider->active = 4 !== $id;
	$provider->check_price_url = 'https://example.test/catalog-' . $id; $provider->shop_id = 'catalog-shop';
	$provider->location_config = (object) array( 'origin' => (object) array( 'enabled' => false ), 'destination' => (object) array( 'enabled' => false ) );
	ProviderStorage::save( $provider );
}
ShopStorage::save( array( (object) array( 'id' => 'catalog-shop', 'title' => 'Fixture', 'token' => 'inert-test-marker' ) ) );
BulkStorage::save( (object) array( 'providers' => array( 1 ), 'fields' => array(), 'mappings' => new stdClass() ) );
$public = new \Tapin\ServicePointLocator\PriceCheck\PublicApiService( null, null, static fn() => null );
$input = new \Tapin\ServicePointLocator\PriceCheck\RestInput();
bulk_store_check( array( 1, 2, 3 ) === array_column( $public->call( 'list_providers', $input )->body['providers'], 'id' ), 'Public catalog lost active providers or exposed an inactive provider.' );
bulk_store_check( array( 1 ) === array_column( $public->call( 'bulk_schema', $input )->body['providers'], 'id' ), 'The full public catalog expanded the bulk schema.' );
$transport = new BulkStoreTransport();
$service = new BulkQuoteService( static fn() => null, $transport, new WordPressBulkQuoteStore( $clock ) );
$result = $service->execute( '{}' );
bulk_store_check( array( 'catalog-1' ) === array_column( $transport->requests, 'name' ), 'Bulk HTTP contacted a provider outside its configured subset.' );
bulk_store_check( 1 === count( $result['results'] ) && 1 === $result['results'][0]['provider']->id && true === $result['results'][0]['ok'], 'Bulk results expanded beyond the configured provider.' );

echo "PASS bulk with module stores: {$passed} assertions; SQLite wpdb double and mocked HTTP, not WordPress/MySQL integration.\n";
