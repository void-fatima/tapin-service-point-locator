<?php
/** Single execution wired to the real module stores over disposable SQLite; no WP/live HTTP. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'ARRAY_A', 'ARRAY_A' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckDatabase.php';
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;
use Tapin\ServicePointLocator\PriceCheck\CacheSettings;
use Tapin\ServicePointLocator\PriceCheck\OperationalClock;
use Tapin\ServicePointLocator\PriceCheck\WordPressQuoteStore;
use Tapin\ServicePointLocator\PriceCheck\SingleQuoteService;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
use Tapin\ServicePointLocator\PriceCheck\ProviderCallError;
use Tapin\ServicePointLocator\PriceCheck\QuoteError;
use Tapin\ServicePointLocator\PriceCheck\QuoteLogStorage;
use Tapin\ServicePointLocator\PriceCheck\PriceCacheStorage;
if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "Requires pdo_sqlite for isolated single/storage checks.\n" ); exit( 1 ); }
$passed = 0;
function single_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
final class SingleStoreTransport implements ProviderTransport {
	public int $calls = 0;
	public $reply;
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		$this->calls++; single_check( 'inert-test-marker' === ( $request->headers()['Authorization'] ?? null ), 'Private shop authorization missing.' );
		if ( $this->reply instanceof Throwable ) { throw $this->reply; } return $this->reply;
	}
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-quote.json' ) ); $case = $fixtures->single_cases[0];
$wpdb = new PriceCheckDatabase(); $now = new DateTimeImmutable( '2026-01-20T12:00:00+00:00' ); $clock = new OperationalClock( static function() use ( &$now ) { return $now; } );
ProviderStorage::save( $case->provider ); ShopStorage::save( array( (object) array( 'id' => '01', 'title' => 'fixture', 'token' => 'inert-test-marker' ) ) ); CacheSettings::save( (object) array( 'price' => 2, 'price_retention' => 10 ) );
$transport = new SingleStoreTransport(); $transport->reply = new ProviderHttpResponse( 200, '{"entries":{"total":"15","tax":0}}' );
$store = new WordPressQuoteStore( $clock ); $service = new SingleQuoteService( $transport, $store );
$raw = $case->actions[0]->body; $result = $service->execute( $raw );
$flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
single_check( json_encode( $result, $flags ) === json_encode( $case->actions[0]->expected->body, $flags ), 'Stored-provider quote differs from Python output.' );
single_check( false === $result['cached'] && 1 === $transport->calls && array() === ( new QuoteLogStorage( $clock ) )->list_rows(), 'Successful quote logged or not executed.' );
$loaded = ProviderStorage::load( 'post' ); $loaded->main_price_key = 'entries.tax'; ProviderStorage::save( $loaded );
$hit = $service->execute( $raw );
single_check( true === $hit['cached'] && 1 === $transport->calls && false === $hit['prices'][0]->main && true === $hit['prices'][1]->main, 'Fresh cache/current main failed.' );
$now = $now->modify( '+2 seconds' ); $transport->reply = new ProviderCallError( 'سرویس قیمت در زمان مقرر پاسخ نداد.', array( 'status_code' => 504 ) );
$stale = $service->execute( $raw ); single_check( true === $stale['cached'] && 2 === $transport->calls && array() === ( new QuoteLogStorage( $clock ) )->list_rows(), 'Stale fallback created a failure log.' );
$now = $now->modify( '+9 seconds' );
try { $service->execute( $raw ); throw new RuntimeException( 'Expired cache accepted.' ); }
catch ( QuoteError $error ) { single_check( 504 === $error->status_code, 'Expired-cache error changed.' ); }
$logs = ( new QuoteLogStorage( $clock ) )->list_rows();
single_check( 1 === count( $logs ) && '' === $logs[0]['endpoint'] && null === $logs[0]['response'] && 'error' === $logs[0]['status'], 'Source timeout log shape changed.' );
( new QuoteLogStorage( $clock ) )->clear(); $transport->reply = new ProviderHttpResponse( 200, '{"other":1,"token":"inert-test-marker","echo":"inert-test-marker","returns":{"status":"inert-test-marker"},"inert-test-marker":5}' );
try { $service->execute( $raw ); throw new RuntimeException( 'Missing price accepted.' ); }
catch ( QuoteError $error ) { single_check( 502 === $error->status_code && 'سرویس در حال حاضر پاسخگو نیست.' === $error->detail && array() === $error->errors, 'Carrier internals exposed publicly.' ); }
$logs = ( new QuoteLogStorage( $clock ) )->list_rows();
single_check( 1 === count( $logs ) && 'https://example.test/quote' === $logs[0]['endpoint'] && false === strpos( json_encode( $logs ), 'inert-test-marker' ), 'Credential in persisted error log.' );
( new QuoteLogStorage( $clock ) )->clear(); $transport->reply = new ProviderHttpResponse( 503, '{"entries":{"total":15,"tax":0},"token":"inert-test-marker","echo":"inert-test-marker"}' );
$wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'price_cache' ); $result = $service->execute( $raw );
single_check( false === $result['cached'] && 2 === $result['prices'][0]->value && 0 === (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_tapin_price_price_cache' )->fetchColumn(), 'Failed cache write altered the live quote.' );
$service->execute( $raw );
$rows = $wpdb->pdo->query( 'SELECT quote_json, params_json FROM wp_tapin_price_price_cache' )->fetchAll( PDO::FETCH_ASSOC );
single_check( 1 === count( $rows ) && false === strpos( json_encode( $rows ), 'inert-test-marker' ) && false === strpos( $rows[0]['quote_json'], '"main"' ), 'Canonical cache leaked credentials/main flags.' );
( new PriceCacheStorage( $clock ) )->clear(); $transport->reply = new ProviderCallError( 'ارتباط با سرویس قیمت برقرار نشد.' ); $wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'quote_logs' );
try { $service->execute( $raw ); throw new RuntimeException( 'Network failure accepted.' ); }
catch ( QuoteError $error ) { single_check( 502 === $error->status_code && 0 === (int) $wpdb->pdo->query( 'SELECT COUNT(*) FROM wp_tapin_price_quote_logs' )->fetchColumn(), 'Log failure replaced the original quote error.' ); }
$wpdb->failure = array( 'kind' => 'SELECT', 'table' => 'providers' );
try { $service->execute( $raw ); throw new RuntimeException( 'Failed provider read accepted.' ); }
catch ( QuoteError $error ) { single_check( 500 === $error->status_code && array( 'detail' => 'internal error' ) === $error->jsonSerialize(), 'Private database error exposed.' ); }
single_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Locator table changed.' );
echo "PASS single quote with module stores: {$passed} assertions; SQLite wpdb double and mocked HTTP, not WordPress/MySQL integration.\n";
