<?php
/** Mocked provider transport and original Python expectations. Never sends an HTTP request. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'ARRAY_A', 'ARRAY_A' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\ProviderRequestBuilder;
use Tapin\ServicePointLocator\PriceCheck\ProviderCallError;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationJson;
use Tapin\ServicePointLocator\PriceCheck\WordPressProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\QuoteSecrets;
use Tapin\ServicePointLocator\PriceCheck\ProviderResponse;
use Tapin\ServicePointLocator\PriceCheck\ProviderClient;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
use Tapin\ServicePointLocator\PriceCheck\QuoteStore;
use Tapin\ServicePointLocator\PriceCheck\SingleQuoteService;
use Tapin\ServicePointLocator\PriceCheck\QuoteError;
use Tapin\ServicePointLocator\PriceCheck\ProviderRedirect;
$passed = 0;
function quote_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function quote_error( ProviderCallError $error ): array { return array( 'ok' => false, 'message' => $error->getMessage(), 'status_code' => $error->status_code, 'field' => $error->field, 'endpoint' => $error->endpoint, 'http_status' => $error->http_status, 'provider_status' => $error->provider_status, 'provider_message' => $error->provider_message, 'provider_response' => $error->provider_response, 'errors' => $error->errors, 'extra' => (object) $error->extra ); }
class WP_Error {
	private string $message;
	public function __construct( string $message ) { $this->message = $message; }
	public function get_error_code(): string { return 'http_request_failed'; }
	public function get_error_message(): string { return $this->message; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function wp_remote_request( string $url, array $args ) { $GLOBALS['requests'][] = array( $url, $args ); if ( ! $GLOBALS['responses'] ) { throw new LogicException( 'Unexpected HTTP call.' ); } return array_shift( $GLOBALS['responses'] ); }
function wp_remote_retrieve_response_code( array $response ): int { return $response['status']; }
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function wp_remote_retrieve_headers( array $response ): array { return $response['headers'] ?? array(); }
function wp_remote_retrieve_header( array $response, string $key ) { return $response['headers'][$key] ?? ''; }
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-quote.json' ) );
quote_check( JSON_ERROR_NONE === json_last_error(), 'Invalid quote fixtures.' );
quote_check( isset( $fixtures->single_cases, $fixtures->redirect_url_cases ) && is_array( $fixtures->redirect_url_cases ), 'Regenerate the complete quote fixture corpus.' );
$lookup = static function( $key ) { return in_array( $key, array( '01', '0', '1' ), true ) ? array( 'id' => $key, 'token' => ' inert-test-marker ' ) : ( 'empty' === $key ? array( 'token' => false ) : null ); };
$builder = new ProviderRequestBuilder( $lookup );
foreach ( $fixtures->cases as $index => $case ) {
	try {
		if ( 'endpoint' === $case->op ) { $value = ProviderRequestBuilder::endpoint( ...$case->args ); }
		else { $request = $builder->build( ...$case->args ); $value = array( 'body' => $request->body, 'authorization' => ( $request->headers()['Authorization'] ?? null ) === 'inert-test-marker', 'wire' => ConfigurationJson::encode( $request->body ) ); }
		$actual = array( 'ok' => true, 'value' => $value );
	} catch ( ProviderCallError $error ) { $actual = quote_error( $error ); }
	$flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
	quote_check( json_encode( $actual, $flags ) === json_encode( $case->expected, $flags ), "Original request mismatch {$index}." );
}
$transport = new WordPressProviderTransport();
foreach ( $fixtures->transport_cases as $case ) {
	$requests = array(); $raw = false !== strpos( $case->mode, 'oversize' ) ? str_repeat( 'x', 2000001 ) : '{"total": 1}';
	$responses = array( 'timeout' === $case->mode ? new WP_Error( 'cURL error 28: inert-test-marker' ) : ( 'network' === $case->mode ? new WP_Error( 'inert-test-marker' ) : array( 'status' => 0 === strpos( $case->mode, 'http_error' ) ? 503 : 200, 'body' => $raw ) ) );
	$secrets = new QuoteSecrets(); $secrets->remember( 'inert-test-marker' );
	$request = new ProviderRequest( 'https://example.test/quote', (object) array( 'amount' => 1.0, 'text' => 'آ' ), array( 'Authorization' => 'inert-test-marker' ), 1.25, $secrets );
	try { $reply = $transport->send( $request ); $actual = array( 'ok' => true, 'status' => $reply->status, 'bytes' => strlen( $reply->body ) ); }
	catch ( ProviderCallError $error ) { $actual = quote_error( $error ); }
	$expected = $case->expected;
	if ( 'network' === $case->mode ) { $expected->message = 'ارتباط با سرویس قیمت برقرار نشد.'; }
	quote_check( json_encode( $actual, JSON_UNESCAPED_UNICODE ) === json_encode( $expected, JSON_UNESCAPED_UNICODE ), 'Transport result mismatch.' );
	$args = $requests[0][1];
	quote_check( $args['body'] === $case->request->body && $args['timeout'] === $case->request->timeout && $args['headers']['Authorization'] === 'inert-test-marker' && 'POST' === $args['method'], 'Wire request changed.' );
	$wire_headers = array_change_key_case( $args['headers'], CASE_LOWER ); unset( $wire_headers['authorization'], $wire_headers['accept-encoding'] );
	quote_check( $wire_headers === array_change_key_case( (array) $case->request->headers, CASE_LOWER ), 'Source content/accept/user-agent headers changed.' );
	quote_check( 0 === $args['redirection'] && false === $args['reject_unsafe_urls'] && true === $args['sslverify'] && false === $args['decompress'] && 2000001 === $args['limit_response_size'], 'WP transport policy changed.' );
	quote_check( false === strpos( json_encode( $actual ), 'inert-test-marker' ), 'Credential appeared in error.' );
}
foreach ( array( 301, 302, 303, 307, 308 ) as $status ) {
	$requests = array(); $responses = array( array( 'status' => $status, 'body' => '{}', 'headers' => array( 'location' => '/next' ) ), array( 'status' => 200, 'body' => '{}' ) );
	$reply = $transport->send( $request ); $source_redirect = array_values( array_filter( $fixtures->redirect_cases, static fn( $case ) => 'POST' === $case->method && $status === $case->status && 'https://example.test/next' === $case->target ) )[0]; $follow = $source_redirect->expected->follow;
	quote_check( count( $requests ) === ( $follow ? 2 : 1 ), 'POST redirect count changed.' );
	if ( $follow ) { quote_check( 'GET' === $requests[1][1]['method'] && null === $requests[1][1]['body'] && ! isset( $requests[1][1]['headers']['Content-Type'] ) && isset( $requests[1][1]['headers']['Authorization'] ), 'Same-origin redirect changed.' ); }
}
foreach ( array_filter( $fixtures->redirect_cases, static fn( $case ) => 'GET' === $case->method ) as $case ) {
	$requests = array(); $responses = array( array( 'status' => 302, 'body' => '', 'headers' => array( 'location' => '/first' ) ), array( 'status' => $case->status, 'body' => '', 'headers' => array( 'location' => $case->target ) ), array( 'status' => 200, 'body' => '{}' ) );
	$transport->send( $request );
	quote_check( 3 === count( $requests ) && $case->expected->method === $requests[2][1]['method'] && $case->expected->body === $requests[2][1]['body'] && $case->expected->authorization === isset( $requests[2][1]['headers']['Authorization'] ), 'Source GET redirect changed.' );
}
$requests = array(); $responses = array( array( 'status' => 302, 'body' => '', 'headers' => array( 'location' => 'https://other.test/' ) ), array( 'status' => 200, 'body' => '{}' ) ); $transport->send( $request );
quote_check( ! isset( $requests[1][1]['headers']['Authorization'] ), 'Credential forwarded across origins.' );
$requests = array(); $responses = array_fill( 0, 5, array( 'status' => 302, 'body' => '{}', 'headers' => array( 'location' => '/loop' ) ) ); $reply = $transport->send( $request );
quote_check( 5 === count( $requests ) && 302 === $reply->status, 'Redirect repeat limit changed.' );
$requests = array(); $responses = array( new WP_Error( 'cURL error 6: Could not resolve host timeout.example.test' ) );
try { $transport->send( $request ); throw new RuntimeException( 'Network failure accepted.' ); }
catch ( ProviderCallError $error ) { quote_check( 502 === $error->status_code, 'Hostname misclassified as timeout.' ); }
foreach ( $fixtures->response_cases as $index => $case ) {
	try {
		switch ( $case->op ) {
			case 'parse': $value = ProviderResponse::parse( $case->args[0], base64_decode( $case->args[1] ), $case->args[2] ); break;
			case 'extract': $value = ProviderResponse::extract( ...$case->args ); break;
			case 'evaluate': $value = ProviderResponse::evaluate( ...$case->args ); break;
			case 'candidates': $value = ProviderResponse::candidates( ...$case->args ); break;
			case 'number': $value = ProviderResponse::number( ...$case->args ); break;
			case 'main': $value = ProviderResponse::mark_main( ...$case->args ); break;
			case 'referral': $value = ProviderResponse::referral( ...$case->args ); break;
		}
		$actual = array( 'ok' => true, 'value' => $value );
	} catch ( ProviderCallError $error ) { $actual = quote_error( $error ); }
	$flags = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
	quote_check( json_encode( $actual, $flags ) === json_encode( $case->expected, $flags ), "Original response mismatch {$index} ({$case->op})." );
}
final class QuoteFixtureTransport implements ProviderTransport {
	public int $calls = 0;
	public $reply;
	public array $sent = array();
	public function send( ProviderRequest $request ): ProviderHttpResponse { $this->calls++; $this->sent[] = $request; if ( $this->reply instanceof Throwable ) { throw $this->reply; } return $this->reply; }
}
foreach ( $fixtures->fetch_cases as $index => $case ) {
	$mock = new QuoteFixtureTransport(); $mock->reply = new ProviderHttpResponse( $case->status, $case->raw ); $client = new ProviderClient( $mock, $builder );
	try { $quote = $client->fetch( $case->provider, new stdClass() ); $actual = array( 'ok' => true, 'quote' => $quote, 'public' => ProviderResponse::result( $case->provider, $quote ) ); }
	catch ( ProviderCallError $error ) { $actual = quote_error( $error ); }
	quote_check( json_encode( $actual, $flags ) === json_encode( $case->expected, $flags ), "Original fetch mismatch {$index}." );
}
foreach ( $fixtures->representation_limits as $case ) {
	try { ProviderResponse::parse( 200, $case->input, 'https://example.test' ); throw new RuntimeException( 'Unrepresentable response silently accepted.' ); }
	catch ( DomainException $expected ) { $passed++; }
}
$provider = (object) array( 'id' => 1, 'name' => 'post', 'params' => array(), 'check_price_url' => 'https://example.test', 'price_fields' => array( (object) array( 'key' => 'missing' ) ) );
$mock = new QuoteFixtureTransport(); $mock->reply = new ProviderHttpResponse( 200, '{"token":"inert-test-marker","message":"echo inert-test-marker","other":1}' ); $client = new ProviderClient( $mock, $builder );
try { $client->fetch( $provider, (object) array( 'shop_id' => '01' ) ); throw new RuntimeException( 'Missing price accepted.' ); }
catch ( ProviderCallError $error ) { quote_check( false === strpos( json_encode( quote_error( $error ) ), 'inert-test-marker' ), 'Secret in provider exception.' ); }
$mock->reply = new ProviderHttpResponse( 200, '{"returns":{"status":"inert-test-marker"},"inert-test-marker":1}' );
try { $client->fetch( $provider, (object) array( 'shop_id' => '01' ) ); throw new RuntimeException( 'Missing price accepted.' ); }
catch ( ProviderCallError $error ) { quote_check( false === strpos( json_encode( quote_error( $error ) ), 'inert-test-marker' ), 'Secret in response keys/status.' ); }
$mock->reply = new ProviderHttpResponse( 200, '{"total":1,"token":"inert-test-marker","echo":"inert-test-marker"}' ); $provider->price_fields = array();
$quote = $client->fetch( $provider, (object) array( 'shop_id' => '01' ) );
quote_check( false === strpos( json_encode( $quote ), 'inert-test-marker' ) && ! property_exists( $quote->provider_response, 'token' ), 'Secret in cacheable quote.' );
$short_client = new ProviderClient( $mock, new ProviderRequestBuilder( static fn( $id ) => array( 'token' => 'prices' ) ) );
$mock->reply = new ProviderHttpResponse( 200, '{"total":1,"prices":2}' );
$short_quote = $short_client->fetch( $provider, (object) array( 'shop_id' => '01' ) );
quote_check( 1 === $short_quote->prices[0]->value && ! property_exists( $short_quote->provider_response, 'prices' ), 'Short inert authorization changed the quote structure.' );

final class QuoteFixtureStore implements QuoteStore {
	public stdClass $configured;
	public array $listing;
	public DateTimeImmutable $now;
	public stdClass $cache;
	public array $logs = array();
	public ?string $failure = null;
	private function fail( string $kind ): void { if ( $this->failure === $kind ) { $this->failure = null; throw new RuntimeException( 'fixture unavailable' ); } }
	public function provider( string $identifier ): ?stdClass { $this->fail( 'provider' ); return in_array( $identifier, array( '1', '01', $this->configured->name ), true ) ? clone $this->configured : null; }
	public function shops(): array { $this->fail( 'shops' ); return $this->listing; }
	public function shop( string $identifier ): ?array { return ( $GLOBALS['lookup'] )( $identifier ); }
	public function cache_get( int $provider_id, string $key ): ?array {
		$this->fail( 'cache_get' ); $entry = $this->cache->$key ?? null;
		if ( null !== $entry && $this->now->getTimestamp() - ( new DateTimeImmutable( $entry->created_at ) )->getTimestamp() > 10 ) { unset( $this->cache->$key ); $entry = null; }
		return null === $entry ? null : (array) $entry;
	}
	public function cache_fresh( $created_at ): bool { return $this->now->getTimestamp() - ( new DateTimeImmutable( $created_at ) )->getTimestamp() < 2; }
	public function cache_save( stdClass $record ): void { $this->fail( 'cache_save' ); $this->cache->{$record->params_hash} = (object) array( 'created_at' => $this->now->format( 'Y-m-d\TH:i:sP' ), 'provider_name' => $record->provider_name, 'params' => $record->params, 'endpoint' => $record->endpoint, 'quote' => $record->quote ); }
	public function log_save( stdClass $record ): void { $this->fail( 'log_save' ); $this->logs[] = $record; }
}
$lookup = static function( $key ) { return in_array( $key, array( '01', '02', '0', '1' ), true ) ? array( 'id' => $key, 'token' => ' inert-test-marker ' ) : ( 'empty' === $key ? array( 'token' => false ) : null ); };
$single_count = 0;
foreach ( $fixtures->single_cases as $case ) {
	$store = new QuoteFixtureStore(); $store->configured = $case->provider; $store->cache = new stdClass(); $store->now = new DateTimeImmutable( '2026-01-20T12:00:00+00:00' );
	$store->listing = array( (object) array( 'id' => '01', 'title' => 'اول', 'province' => '1', 'city' => '1' ), (object) array( 'id' => '02', 'title' => 'دوم', 'province' => '1', 'city' => '2' ) );
	$mock = new QuoteFixtureTransport(); $service = new SingleQuoteService( $mock, $store );
	foreach ( $case->actions as $index => $step ) {
		if ( isset( $step->now ) ) { $store->now = new DateTimeImmutable( $step->now ); }
		foreach ( (array) ( $step->update ?? new stdClass() ) as $key => $value ) { $store->configured->$key = $value; }
		$store->failure = $step->failure ?? null;
		if ( isset( $step->corrupt_quote ) ) { foreach ( $store->cache as $entry ) { $entry->quote = $step->corrupt_quote; } }
		$mode = $step->mode ?? 'ok';
		$mock->reply = 'timeout' === $mode ? new ProviderCallError( 'سرویس قیمت در زمان مقرر پاسخ نداد.', array( 'status_code' => 504, 'endpoint' => $store->configured->check_price_url ) ) : ( 'network' === $mode ? new ProviderCallError( 'ارتباط با سرویس قیمت برقرار نشد.', array( 'endpoint' => $store->configured->check_price_url ) ) : new ProviderHttpResponse( $step->status ?? 200, $step->raw ?? '{"entries":{"total":"15","tax":0}}' ) );
		try { $actual = array( 'status' => 200, 'body' => $service->execute( $step->body, $step->query ?? null ) ); }
		catch ( QuoteError $error ) { $actual = array( 'status' => $error->status_code, 'body' => $error->jsonSerialize() ); }
		$expected = $step->expected;
		if ( ! empty( $step->sanitized_shop_error ) ) { $expected->body->errors[count( $expected->body->errors ) - 1]->message = 'خواندن شعب ناموفق بود.'; }
		quote_check( json_encode( $actual, $flags ) === json_encode( $expected, $flags ), "Single-quote mismatch: {$case->name}, {$index}." );
		$sent = array_map( static fn( $request ) => array( 'url' => $request->url, 'body' => $request->body, 'authorization' => ( $request->headers()['Authorization'] ?? null ) === 'inert-test-marker', 'timeout' => $request->timeout ), $mock->sent );
		$state = array( 'cache' => $store->cache, 'logs' => $store->logs, 'requests' => $sent );
		quote_check( json_encode( $state, $flags ) === json_encode( $step->state, $flags ), "Single state mismatch: {$case->name}, {$index}." );
		quote_check( false === strpos( json_encode( array( $actual, $state ) ), 'inert-test-marker' ), 'Credential in output/cache/log.' ); $single_count++;
	}
}
foreach ( $fixtures->timeout_cases as $case ) { quote_check( SingleQuoteService::timeout( (object) array( 'timeout' => $case->input ) ) === $case->expected, 'Source timeout default changed.' ); }
foreach ( $fixtures->redirect_url_cases as $case ) { quote_check( ProviderRedirect::resolve( base64_decode( $case->location_b64 ), $case->base ) === $case->expected, 'Source redirect URL resolution changed.' ); }
foreach ( $fixtures->request_encoding_limits as $case ) {
	try { $service->execute( base64_decode( $case->input_b64 ) ); throw new RuntimeException( 'Unsupported request encoding silently accepted.' ); }
	catch ( DomainException $expected ) { $passed++; }
}
echo "PASS quote execution: {$single_count} original single-quote actions; {$passed} assertions; mocked HTTP only.\n";
