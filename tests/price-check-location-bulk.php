<?php
/** Production snapshot adapter + real module stores over SQLite; all transports mocked. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'ARRAY_A', 'ARRAY_A' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckDatabase.php';
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;
use Tapin\ServicePointLocator\PriceCheck\BulkStorage;
use Tapin\ServicePointLocator\PriceCheck\ProviderDefinition;
use Tapin\ServicePointLocator\PriceCheck\LocationService;
use Tapin\ServicePointLocator\PriceCheck\LocationCache;
use Tapin\ServicePointLocator\PriceCheck\LocationSnapshotAdapter;
use Tapin\ServicePointLocator\PriceCheck\BulkQuoteService;
use Tapin\ServicePointLocator\PriceCheck\WordPressBulkQuoteStore;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "Requires pdo_sqlite for isolated location/bulk composition.\n" ); exit( 1 ); }
$passed = 0;
function location_bulk_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
final class LocationBulkCache implements LocationCache {
	public ?array $entry = null;
	public function read(): ?array { return $this->entry; }
	public function save( array $entry ): void { $this->entry = $entry; }
	public function clear(): void { $this->entry = null; }
	public function acquire(): ?string { return 'isolated'; }
	public function release( string $owner ): void {}
}
final class LocationBulkTransport implements ProviderTransport {
	public array $requests = array();
	public string $body;
	public function send( ProviderRequest $request ): ProviderHttpResponse { $this->requests[] = array( 'url' => $request->url, 'authorization' => 'inert-test-marker' === ( $request->headers()['Authorization'] ?? null ), 'body' => $request->body ); return new ProviderHttpResponse( 200, $this->body ); }
}
$wpdb = new PriceCheckDatabase(); $now = new DateTimeImmutable( '2026-01-01T00:00:00+00:00' ); $clock = new OperationalClock( static function() use ( &$now ) { return $now; } );
$bulk_fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-bulk.json' ) ); $location_fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-locations.json' ) );
$providers = array_slice( $bulk_fixtures->providers, 0, 2 ); foreach ( $providers as $index => $provider ) { $provider->name = 0 === $index ? 'post' : 'tipax'; $provider->shop_id = '01'; ProviderStorage::save( $provider ); }
ShopStorage::save( array( (object) array( 'id' => '01', 'title' => 'fixture', 'token' => 'inert-test-marker' ) ) );
$config = $bulk_fixtures->config; $config->providers = array( 1, 2 ); $config->variants = new stdClass();
foreach ( (array) $config->mappings as $id => $row ) { foreach ( (array) $row as $shared => $target ) { $row->$shared = ProviderDefinition::target( $providers[(int) $id - 1], $target ); } }
$config->fields[2]->source->param = 'mode'; BulkStorage::save( $config );
$reference_http = new LocationBulkTransport(); $reference_http->body = json_encode( array( 'cities' => $location_fixtures->flat ) );
$quote_http = new LocationBulkTransport(); $quote_http->body = '{"total":15}';
$locations = new LocationService( $reference_http, new LocationBulkCache(), $clock ); $adapter = new LocationSnapshotAdapter( $locations ); $bulk_store = new WordPressBulkQuoteStore( $clock ); $service = new BulkQuoteService( $adapter, $quote_http, $bulk_store );
$raw = json_encode( array( 'values' => array( 'money' => 15, 'cost' => 1, 'prov' => 1, 'dest' => 10 ) ) ); $result = $service->execute( $raw );
location_bulk_check( 2 === count( $result['results'] ) && true === $result['results'][0]['ok'] && false === $result['results'][1]['ok'] && true === $result['results'][1]['error']['unserved'], 'Production snapshot did not enforce per-provider destination membership.' );
location_bulk_check( count( $reference_http->requests ) === 1 && true === $reference_http->requests[0]['authorization'] && $reference_http->requests[0]['body']->shop_id === '01', 'Shared reference cache/private shop construction failed.' );
location_bulk_check( count( $quote_http->requests ) === 1 && false === strpos( $quote_http->requests[0]['url'], 'tipax' ), 'Unserved provider executed a quote.' );
$service->execute( $raw ); location_bulk_check( count( $reference_http->requests ) === 1 && count( $quote_http->requests ) === 1, 'Fresh snapshot/quote cache was bypassed.' );
$now = $now->modify( '+7 hours' ); $service->execute( $raw ); location_bulk_check( count( $reference_http->requests ) === 2, 'Expired location entry did not refresh.' );
location_bulk_check( 0 === (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_tapin_price_quote_logs' )->fetchColumn(), 'Location/coverage operations entered quote logs.' );
location_bulk_check( false === strpos( json_encode( array( $result, $locations->cache_info() ) ), 'inert-test-marker' ), 'Location/bulk boundary leaked authorization.' );
location_bulk_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Locator dataset was touched.' );
$default_bulk = new BulkQuoteService( null, $quote_http, $bulk_store ); $section_property = new ReflectionProperty( BulkQuoteService::class, 'section' ); $section_property->setAccessible( true ); location_bulk_check( $section_property->getValue( $default_bulk ) instanceof LocationSnapshotAdapter, 'Bulk constructor did not default to production snapshot adapter.' );
echo "PASS location/bulk composition: {$passed} assertions; SQLite stores and injected transports, not live WordPress/MySQL.\n";
