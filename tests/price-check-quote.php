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
function wp_remote_retrieve_header( array $response, string $key ) { return $response['headers'][$key] ?? ''; }
class WP_Http {
	/** Fixture paths only; real WP absolute-URL resolution remains an integration check. */
	public static function make_absolute_url( string $location, string $url ): string {
		if ( false !== strpos( $location, '://' ) ) { return $location; }
		$parts = parse_url( $url ); return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . '/' . ltrim( $location, '/' );
	}
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-quote.json' ) );
quote_check( JSON_ERROR_NONE === json_last_error(), 'Invalid quote fixtures.' );
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
	quote_check( 0 === $args['redirection'] && false === $args['reject_unsafe_urls'] && true === $args['sslverify'] && false === $args['decompress'] && 2000001 === $args['limit_response_size'], 'WP transport policy changed.' );
	quote_check( false === strpos( json_encode( $actual ), 'inert-test-marker' ), 'Credential appeared in error.' );
}
foreach ( array( 301, 302, 303, 307, 308 ) as $status ) {
	$requests = array(); $responses = array( array( 'status' => $status, 'body' => '{}', 'headers' => array( 'location' => '/next' ) ), array( 'status' => 200, 'body' => '{}' ) );
	$reply = $transport->send( $request ); $follow = in_array( $status, array( 301, 302, 303 ), true );
	quote_check( count( $requests ) === ( $follow ? 2 : 1 ), 'POST redirect count changed.' );
	if ( $follow ) { quote_check( 'GET' === $requests[1][1]['method'] && null === $requests[1][1]['body'] && ! isset( $requests[1][1]['headers']['Content-Type'] ) && isset( $requests[1][1]['headers']['Authorization'] ), 'Same-origin redirect changed.' ); }
}
$requests = array(); $responses = array( array( 'status' => 302, 'body' => '', 'headers' => array( 'location' => 'https://other.test/' ) ), array( 'status' => 200, 'body' => '{}' ) ); $transport->send( $request );
quote_check( ! isset( $requests[1][1]['headers']['Authorization'] ), 'Credential forwarded across origins.' );
$requests = array(); $responses = array_fill( 0, 5, array( 'status' => 302, 'body' => '{}', 'headers' => array( 'location' => '/loop' ) ) ); $reply = $transport->send( $request );
quote_check( 5 === count( $requests ) && 302 === $reply->status, 'Redirect repeat limit changed.' );
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
$mock->reply = new ProviderHttpResponse( 200, '{"total":1,"token":"inert-test-marker","echo":"inert-test-marker"}' ); $provider->price_fields = array();
$quote = $client->fetch( $provider, (object) array( 'shop_id' => '01' ) );
quote_check( false === strpos( json_encode( $quote ), 'inert-test-marker' ) && ! property_exists( $quote->provider_response, 'token' ), 'Secret in cacheable quote.' );
echo "PASS quote request/response/transport: {$passed} assertions; mocked HTTP only.\n";
