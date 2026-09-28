<?php
/** Offline Phase 2 unit checks. No WordPress database or live provider access. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
require ABSPATH . 'src/Autoloader.php';
Tapin\ServicePointLocator\Autoloader::register();
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value ) { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
function get_transient( $key ) { return $GLOBALS['geo_cache'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['geo_cache'][$key] = $value; $GLOBALS['geo_ttl'][$key] = $ttl; }
function apply_filters( $name, $value ) { return $value; }
function wp_remote_get( $url, $options ) { $GLOBALS['http_request'] = array( $url, $options ); return $GLOBALS['http_response']; }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][$name] ?? ''; }
function wp_remote_retrieve_body( $response ) { return json_encode( $response['body'] ); }
function __( $message, $domain = '' ) { return $message; }

use Tapin\ServicePointLocator\Geocoding\AddressQuery;
use Tapin\ServicePointLocator\Geocoding\CoordinatePolicy;
use Tapin\ServicePointLocator\Geocoding\GeocoderInterface;
use Tapin\ServicePointLocator\Geocoding\GeocodingService;
use Tapin\ServicePointLocator\Geocoding\NeshanGeocoder;
use Tapin\ServicePointLocator\Service\PointEvidence;
use Tapin\ServicePointLocator\Validation\ServicePointValidator;

final class FixtureGeocoder implements GeocoderInterface {
	public $result; public int $calls = 0; public bool $ready = true;
	public function name(): string { return 'offline-unit-v1'; }
	public function configured(): bool { return $this->ready; }
	public function geocode( array $query ) { $this->calls++; return $this->result; }
}
$passed = 0; $failed = 0;
function geo_check( bool $ok, string $name ): void { global $passed, $failed; $ok ? $passed++ : $failed++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n"; }
function rejected( $result, string $code ): bool { return is_wp_error( $result ) && $result->get_error_code() === $code; }
$point = array( 'provider_id' => 1, 'name' => 'شعبه نمونه', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'خیابان آزادی پلاک ۱۲', 'latitude' => null, 'longitude' => null, 'status' => 'active', 'metadata' => array() );
$query = AddressQuery::build( $point );
geo_check( $query['address'] === 'ایران، تهران، تهران، خیابان آزادی پلاک 12', 'normalized query includes country, province, city, Persian digits' );
$variant = $point; $variant['address'] = "خيابان  آزادي\nپلاك ١٢";
geo_check( AddressQuery::build( $variant ) === $query, 'Arabic letter/digit and whitespace variants share query' );
foreach ( array( '', 'تهران', 'ایران استان تهران شهر تهران' ) as $address ) {
	geo_check( rejected( AddressQuery::build( array_replace( $point, array( 'address' => $address ) ) ), 'insufficient_address' ), 'reject locality-only or empty address: ' . $address );
}
$official = $point;
$official['metadata']['tapin_reconciliation'] = array( 'result' => 'verified', 'source_url' => 'https://tapin.ir/map/tehran.pdf', 'official' => array( 'address' => 'خیابان ولیعصر پلاک 20', 'landline_phone' => '02112345678' ) );
geo_check( strpos( AddressQuery::build( $official )['address'], 'ولیعصر' ) !== false, 'verified official address wins for geocoding' );
geo_check( PointEvidence::fields( $official )['landline_phone'] === '02112345678', 'verified landline used for safe presentation' );
foreach ( array( 'conflict', 'probable_match', 'stale' ) as $status ) {
	$blocked = $official; $blocked['metadata']['tapin_reconciliation']['result'] = $status;
	geo_check( rejected( AddressQuery::build( $blocked ), 'source_conflict' ), 'source state blocks geocoding: ' . $status );
}
$untrusted = $official; $untrusted['metadata']['tapin_reconciliation']['source_url'] = 'https://example.invalid/source.pdf';
geo_check( AddressQuery::build( $untrusted ) === $query, 'untrusted source URL cannot replace query' );
$candidate = array( 'latitude' => 35.7, 'longitude' => 51.4, 'province' => 'تهران', 'city' => 'تهران', 'quality' => 'matched' );
$provider = new FixtureGeocoder(); $service = new GeocodingService( $provider );
$resolve = static function( $candidates, $input = null ) use ( $provider, $service, $query ) { $GLOBALS['geo_cache'] = array(); $provider->result = $candidates; return $service->resolve( $input ?? $query ); };
geo_check( $resolve( array( $candidate ) ) === $candidate, 'accept one real geographically consistent candidate' );
foreach ( array( array( 'latitude', 91 ), array( 'longitude', 181 ), array( 'latitude', NAN ), array( 'longitude', INF ), array( 'latitude', 'invalid' ), array( 'latitude', null ), array( 'latitude', array() ) ) as $case ) {
	geo_check( rejected( $resolve( array( array_replace( $candidate, array( $case[0] => $case[1] ) ) ) ), 'invalid_coordinates' ), 'reject malformed/range coordinate ' . $case[0] );
}
geo_check( rejected( $resolve( array( array_replace( $candidate, array( 'latitude' => 35.0, 'longitude' => 44.0 ) ) ) ), 'outside_iran' ), 'inside coarse bbox but outside actual Iran polygon rejected' );
geo_check( rejected( $resolve( array( array_replace( $candidate, array( 'latitude' => 29.6, 'longitude' => 52.5 ) ) ) ), 'province_mismatch' ), 'geometry province mismatch rejected even if provider labels Tehran' );
geo_check( rejected( $resolve( array( array_replace( $candidate, array( 'province' => 'فارس' ) ) ) ), 'province_mismatch' ), 'structured province mismatch rejected' );
geo_check( rejected( $resolve( array( array_replace( $candidate, array( 'city' => 'ری' ) ) ) ), 'city_mismatch' ), 'structured city mismatch rejected' );
geo_check( rejected( $resolve( array( $candidate, array_replace( $candidate, array( 'longitude' => 51.41 ) ) ) ), 'ambiguous' ), 'multiple distinct complete matches rejected' );
geo_check( $resolve( array( $candidate, $candidate ) ) === $candidate, 'identical duplicate candidates are not ambiguous' );
geo_check( rejected( $resolve( array( array_replace( $candidate, array( 'quality' => 'partial' ) ) ) ), 'low_quality' ), 'partial quality rejected' );
geo_check( rejected( $resolve( array( array( 'latitude' => 35.7 ) ) ), 'invalid_coordinates' ), 'incomplete result rejected' );
geo_check( rejected( $resolve( array() ), 'no_match' ), 'no result never yields fallback coordinates' );
$GLOBALS['geo_cache'] = array(); $provider->result = array( $candidate ); $calls = $provider->calls;
$service->resolve( $query ); $service->resolve( AddressQuery::build( $variant ) );
geo_check( $provider->calls === $calls + 1, 'same normalized query uses one provider request' );
geo_check( in_array( 30 * DAY_IN_SECONDS, $GLOBALS['geo_ttl'], true ), 'successful cache TTL is thirty days' );
$service->resolve( AddressQuery::build( $official ) );
geo_check( $provider->calls === $calls + 2, 'corrected source address invalidates query cache' );
geo_check( AddressQuery::hash( $query, 'a' ) !== AddressQuery::hash( $query, 'b' ), 'provider identity namespaces cache' );
$provider->ready = false;
geo_check( rejected( $service->resolve( $query ), 'not_configured' ), 'missing credential stops provider calls' );
$provider->ready = true;

$located = array_replace( $point, array( 'latitude' => 35.7, 'longitude' => 51.4, 'metadata' => array( 'coordinate_source' => 'manual', 'source_row' => 8 ) ) );
$retained = CoordinatePolicy::prepare( $point, $located, 'uploaded' );
geo_check( $retained['latitude'] === 35.7 && $retained['longitude'] === 51.4, 'blank upload preserves existing coordinates' );
geo_check( $retained['metadata']['coordinate_source'] === 'manual' && $retained['metadata']['source_row'] === 8, 'manual origin and source metadata preserved' );
$upload = CoordinatePolicy::prepare( array_replace( $point, array( 'latitude' => 35.71, 'longitude' => 51.41 ) ), $located, 'uploaded' );
geo_check( $upload['latitude'] === 35.71 && $upload['metadata']['coordinate_source'] === 'uploaded', 'valid explicitly uploaded coordinate takes precedence' );
$manual = CoordinatePolicy::prepare( array_replace( $point, array( 'latitude' => 35.72, 'longitude' => 51.42 ) ), $upload, 'manual' );
geo_check( $manual['latitude'] === 35.72 && $manual['metadata']['coordinate_source'] === 'manual', 'explicit manual coordinate correction retains manual origin' );
$missing = CoordinatePolicy::prepare( $point, null, 'uploaded' );
geo_check( null === $missing['latitude'] && null === $missing['longitude'] && ! isset( $missing['metadata']['coordinate_source'] ), 'unresolved coordinates remain NULL without fake origin' );
geo_check( ServicePointValidator::validate( $missing )->is_valid(), 'address-only remains a valid record' );
$forged = $point; $forged['metadata'] = array( 'coordinate_source' => 'manual', 'geocoding' => array( 'status' => 'succeeded' ) );
geo_check( ! isset( CoordinatePolicy::prepare( $forged, null, 'uploaded' )['metadata']['coordinate_source'] ), 'uploaded JSON cannot forge coordinate origin' );

$adapter = new NeshanGeocoder( 'offline-placeholder' );
$http_response = array( 'code' => 200, 'body' => array( 'items' => array( array( 'location' => array( 'latitude' => 35.7, 'longitude' => 51.4 ), 'province' => 'تهران', 'city' => 'تهران', 'unMatchedTerm' => '' ) ) ) );
geo_check( $adapter->geocode( $query ) === array( $candidate ), 'current Neshan response normalized' );
list( $url, $options ) = $http_request;
parse_str( parse_url( $url, PHP_URL_QUERY ), $parameters );
geo_check( json_decode( $parameters['json'], true ) === $query && strpos( $url, 'offline-placeholder' ) === false, 'provider query contract and no credential in URL' );
geo_check( $options['timeout'] === 8 && $options['redirection'] === 0 && $options['limit_response_size'] === 65536, 'timeout, redirect and response-size bounds' );
unset( $http_response['body']['items'][0]['unMatchedTerm'] );
geo_check( $adapter->geocode( $query )[0]['quality'] === 'partial', 'missing match quality never assumed exact' );
$http_response['body'] = array( 'unexpected' => 'payload' );
geo_check( rejected( $adapter->geocode( $query ), 'invalid_response' ), 'malformed provider response rejected' );
foreach ( array( 'timeout', 'connection refused' ) as $error ) {
	$http_response = new WP_Error( 'http_request_failed', $error . ' private provider message' );
	$result = $adapter->geocode( $query );
	geo_check( rejected( $result, 'transport_error' ) && $result->get_error_data()['retryable'] && strpos( $result->get_error_message(), 'private' ) === false, 'transport error retryable and redacted: ' . $error );
}
foreach ( array( 408, 425, 429, 482, 500, 503 ) as $code ) {
	$http_response = array( 'code' => $code, 'body' => array() );
	geo_check( $adapter->geocode( $query )->get_error_data()['retryable'], 'retryable HTTP ' . $code );
}
foreach ( array( 400, 401, 403, 480, 481, 483, 484, 485 ) as $code ) {
	$http_response = array( 'code' => $code, 'body' => array() );
	geo_check( ! $adapter->geocode( $query )->get_error_data()['retryable'], 'permanent HTTP ' . $code );
}
$http_response = array( 'code' => 429, 'headers' => array( 'retry-after' => '180' ), 'body' => array() );
geo_check( $adapter->geocode( $query )->get_error_data()['retry_after'] === 180, 'Retry-After seconds preserved' );
$http_response['headers']['retry-after'] = gmdate( 'D, d M Y H:i:s', time() + 240 ) . ' GMT';
$delay = $adapter->geocode( $query )->get_error_data()['retry_after'];
geo_check( $delay >= 238 && $delay <= 240, 'Retry-After HTTP date preserved' );
geo_check( rejected( ( new NeshanGeocoder( '' ) )->geocode( $query ), 'not_configured' ), 'adapter works without real credential in tests and rejects empty credential' );
echo "RESULT geocoding unit: {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
