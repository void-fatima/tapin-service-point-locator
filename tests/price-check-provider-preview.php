<?php
/** Provider draft previews through production REST/services; isolated SQLite and HTTP doubles only. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckRest.php';
require __DIR__ . '/support/PriceCheckDatabase.php';

use Tapin\ServicePointLocator\PriceCheck\BulkStorage;
use Tapin\ServicePointLocator\PriceCheck\ProviderConfig;
use Tapin\ServicePointLocator\PriceCheck\ProviderHttpResponse;
use Tapin\ServicePointLocator\PriceCheck\ProviderRequest;
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\ProviderTransport;
use Tapin\ServicePointLocator\PriceCheck\PublicApiService;
use Tapin\ServicePointLocator\PriceCheck\QuoteApiService;
use Tapin\ServicePointLocator\PriceCheck\RestApi;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	fwrite( STDERR, "Enable pdo_sqlite for the isolated provider-preview checks.\n" );
	exit( 1 );
}
// Missing draft keys must not be hidden as PHP notices or warnings.
set_error_handler( static function( $severity, $message, $file, $line ) {
	throw new ErrorException( $message, 0, $severity, $file, $line );
} );
$passed = 0;
function provider_preview_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['passed']++;
}
function provider_preview_json( $value ): string {
	return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
}
function provider_preview_copy( $value ) {
	return json_decode( provider_preview_json( $value ), false, 512, JSON_THROW_ON_ERROR );
}
function provider_preview_field( array $fields, string $name ): stdClass {
	foreach ( $fields as $field ) { if ( $name === $field->name ) { return $field; } }
	throw new RuntimeException( 'Missing schema field: ' . $name );
}
function provider_preview_snapshot(): string {
	$pdo = $GLOBALS['wpdb']->pdo;
	$state = array();
	foreach ( $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name" )->fetchAll( PDO::FETCH_COLUMN ) as $table ) {
		$state[$table] = $pdo->query( 'SELECT * FROM "' . str_replace( '"', '""', $table ) . '" ORDER BY rowid' )->fetchAll( PDO::FETCH_ASSOC );
	}
	return provider_preview_json( $state );
}
final class ProviderPreviewTransport implements ProviderTransport {
	public int $calls = 0;
	public function send( ProviderRequest $request ): ProviderHttpResponse {
		$this->calls++;
		throw new LogicException( 'Schema or invalid-value preview attempted provider HTTP.' );
	}
}

$wpdb = new PriceCheckDatabase();
$rest_logged = $rest_admin = true;
$transport = new ProviderPreviewTransport();
$section_calls = 0;
$schemas = new PublicApiService( null, null, static function( $provider, $refresh ) use ( &$section_calls ) {
	$section_calls++;
	provider_preview_check( false === $refresh, 'Preview unexpectedly forced location refresh.' );
	return (object) array( 'provider' => $provider->name, 'status' => 'ready', 'bindings' => new stdClass() );
} );
$service = new QuoteApiService( null, null, null, $schemas, null, null, $transport );
$api = new RestApi(
	array( $service, 'call' ),
	static function() { throw new LogicException( 'Admin preview reached the public guard.' ); },
	static function() { throw new LogicException( 'Admin preview requested public cookie context.' ); }
);
$api->register();
$callbacks = array();
foreach ( $rest_routes as [$namespace, $path, $definition] ) {
	if ( in_array( $path, array( '/admin/preview/provider-schema', '/admin/preview/price-check' ), true ) ) {
		provider_preview_check( RestApi::NAMESPACE === $namespace && 'POST' === $definition['methods'], 'Preview registration changed.' );
		provider_preview_check( array( RestApi::class, 'permission' ) === $definition['permission_callback'], 'Preview registration lost its admin permission callback.' );
		$callbacks[$path] = $definition['callback'];
	}
}
provider_preview_check( 2 === count( $callbacks ), 'Expected preview REST routes are missing.' );
function provider_preview_request( $body, string $path = '/admin/preview/provider-schema', ?string $raw = null ): WP_REST_Response {
	$request = new PriceRestRequest();
	$request->method = 'POST';
	$request->route = '/' . RestApi::NAMESPACE . $path;
	$request->headers = array( 'x_wp_nonce' => $GLOBALS['preview_nonce'] ?? 'valid-rest-nonce' );
	$request->body = $raw ?? provider_preview_json( $body );
	return ( $GLOBALS['callbacks'][$path] )( $request );
}
function provider_preview_error( $body, string $field, int $status = 422, ?string $raw = null, string $path = '/admin/preview/provider-schema' ): void {
	$before = $GLOBALS['section_calls'];
	$response = provider_preview_request( $body, $path, $raw );
	provider_preview_check( $status === $response->get_status(), 'Invalid preview returned the wrong HTTP status for ' . $field . '.' );
	$data = $response->get_data();
	provider_preview_check( isset( $data['detail'], $data['errors'] ) && is_array( $data['errors'] ), 'Validation response lost its readable detail/errors contract.' );
	if ( 422 === $status ) {
		provider_preview_check( in_array( $field, array_column( $data['errors'], 'field' ), true ), 'Missing expected preview validation field: ' . $field );
	}
	provider_preview_check( $before === $GLOBALS['section_calls'], 'Invalid preview reached location/schema generation.' );
}

// Every ID is deliberately absent, including nested fields. Origins are generated by normalisation.
$draft = (object) array(
	'name' => 'draft-preview',
	'check_price_url' => 'https://example.test/preview',
	'params' => array(
		(object) array( 'name' => 'amount', 'input' => 'number', 'type' => 'float', 'visible' => true, 'required' => true ),
		(object) array( 'name' => 'rows', 'input' => 'list', 'visible' => true, 'fields' => array(
			(object) array( 'name' => 'cost', 'input' => 'number', 'type' => 'float', 'visible' => true, 'default' => 12.5 ),
		) ),
		(object) array( 'name' => 'details', 'input' => 'dict', 'visible' => true, 'fields' => array(
			(object) array( 'name' => 'label', 'input' => 'text', 'visible' => true, 'required' => true ),
		) ),
	),
);
// Seed unrelated configuration and prove neither new nor existing draft previews change it.
$saved = ProviderConfig::normalise( $draft );
$saved->display_name = 'Persisted provider';
$saved_id = ProviderStorage::save( $saved );
ShopStorage::save( array( (object) array( 'id' => 'preview-shop', 'title' => 'Fixture shop', 'token' => 'inert-preview-marker' ) ) );
BulkStorage::save( (object) array( 'providers' => array( $saved_id ), 'fields' => array(), 'mappings' => new stdClass() ) );
$before = provider_preview_snapshot();
$wpdb->queries = array();

$response = provider_preview_request( (object) array( 'record' => $draft ) );
provider_preview_check( 200 === $response->get_status(), 'A valid unsaved draft with missing IDs failed to preview.' );
provider_preview_check( 'private, no-store, max-age=0' === $response->get_headers()['Cache-Control'], 'Draft schema lost private/no-store caching.' );
$schema = $response->get_data();
provider_preview_check( null === $schema->id && 'draft-preview' === $schema->name, 'Unsaved provider identity was fabricated or lost.' );
provider_preview_check( 'draft-preview' === $schema->display_name && '' === $schema->logo, 'Legitimate omitted display metadata stopped using established defaults.' );
provider_preview_check( 'ready' === $schema->locations->status, 'Schema no longer composes the mocked location section.' );
provider_preview_check( 5 === count( $schema->params ), 'Origin selectors or supplied parameters were lost.' );
foreach ( array( 'origin_province_id' => 'province', 'origin_city_id' => 'city' ) as $name => $kind ) {
	$origin = provider_preview_field( $schema->params, $name );
	foreach ( array( 'id', 'name', 'label', 'input', 'type', 'visible', 'kind', 'description', 'required', 'default', 'options' ) as $key ) {
		provider_preview_check( property_exists( $origin, $key ), 'Generated origin schema lacks ' . $key . '.' );
	}
	provider_preview_check( null === $origin->id && '' === $origin->description && false === $origin->required && null === $origin->default, 'Optional generated-origin metadata changed.' );
	provider_preview_check( 'select' === $origin->input && 'int' === $origin->type && true === $origin->visible && $kind === $origin->kind && array() === $origin->options, 'Generated origin control contract changed.' );
}
$amount = provider_preview_field( $schema->params, 'amount' );
$rows = provider_preview_field( $schema->params, 'rows' );
$details = provider_preview_field( $schema->params, 'details' );
provider_preview_check( null === $amount->id && true === $amount->required && '' === $amount->description, 'Required unsaved scalar metadata was lost.' );
provider_preview_check( null === $rows->id && null === $rows->fields[0]->id && 1 === $rows->min_items && 12.5 === $rows->fields[0]->default, 'Unsaved list/nested metadata failed.' );
provider_preview_check( null === $details->fields[0]->id && true === $details->fields[0]->required, 'Unsaved dict required-field metadata was lost.' );
provider_preview_check( 12.5 === $schema->defaults->rows[0]->cost && null === $schema->defaults->details->label, 'Nested defaults changed during schema generation.' );

$existing = provider_preview_copy( $draft );
$existing->id = $saved_id;
$existing->display_name = 'Unsaved display edit';
$existing->params[0]->id = 801;
$existing->params[1]->fields[0]->id = 802;
$response = provider_preview_request( (object) array( 'record' => $existing ) );
provider_preview_check( 200 === $response->get_status() && $saved_id === $response->get_data()->id, 'Existing-provider draft preview lost its ID.' );
provider_preview_check( 'Unsaved display edit' === $response->get_data()->display_name && 801 === provider_preview_field( $response->get_data()->params, 'amount' )->id && 802 === provider_preview_field( $response->get_data()->params, 'rows' )->fields[0]->id, 'Preview lost explicitly supplied draft metadata.' );
$disabled = provider_preview_copy( $draft );
$disabled->location_config = (object) array( 'origin' => (object) array( 'enabled' => false ) );
$response = provider_preview_request( (object) array( 'record' => $disabled ) );
provider_preview_check( 200 === $response->get_status() && 3 === count( $response->get_data()->params ), 'Origin-disabled valid drafts failed or gained origin selectors.' );

provider_preview_error( new stdClass(), 'body' );
provider_preview_error( (object) array( 'record' => null ), 'body' );
provider_preview_error( (object) array( 'record' => array() ), 'body' );
provider_preview_error( (object) array( 'record' => new stdClass() ), 'name' );
foreach ( array(
	'name' => static function( $record ) { unset( $record->name ); },
	'params' => static function( $record ) { $record->params = array(); },
	'params[0]' => static function( $record ) { $record->params[0] = 'malformed'; },
	'params[1].fields' => static function( $record ) { $record->params[1]->fields = new stdClass(); },
	'params[1].fields[0]' => static function( $record ) { $record->params[1]->fields[0] = 'malformed'; },
	'check_price_url' => static function( $record ) { $record->check_price_url = 'invalid-url'; },
) as $field => $invalidate ) {
	$invalid = provider_preview_copy( $draft ); $invalidate( $invalid );
	provider_preview_error( (object) array( 'record' => $invalid ), $field );
}
foreach ( array( '[', '[]', 'null' ) as $raw ) { provider_preview_error( null, 'body', 400, $raw ); }

// Required flags remain functional: explicitly blank visible values fail before any quote HTTP.
provider_preview_error( (object) array( 'record' => $disabled, 'values' => (object) array( 'amount' => '' ) ), 'amount', 422, null, '/admin/preview/price-check' );
provider_preview_error( (object) array( 'record' => $disabled, 'values' => (object) array( 'amount' => 1, 'details' => (object) array( 'label' => '' ) ) ), 'details.label', 422, null, '/admin/preview/price-check' );

foreach ( array( array( false, false, 'valid-rest-nonce' ), array( true, false, 'valid-rest-nonce' ), array( true, true, '' ), array( true, true, 'invalid' ) ) as [$rest_logged, $rest_admin, $preview_nonce] ) {
	$previous = $section_calls;
	provider_preview_check( 403 === provider_preview_request( (object) array( 'record' => $draft ) )->get_status(), 'Preview bypassed login, manage_options or REST nonce validation.' );
	provider_preview_check( $previous === $section_calls, 'Unauthorized draft reached schema generation.' );
}
provider_preview_check( 0 === $transport->calls, 'Preview regressions attempted shipping-provider HTTP.' );
provider_preview_check( array() === $wpdb->queries, 'Provider preview unexpectedly read or persisted database configuration.' );
provider_preview_check( $before === provider_preview_snapshot(), 'Provider preview changed stored providers, nested fields, shops, bulk or other module data.' );
restore_error_handler();
echo "Provider preview: {$passed} assertions passed (REST/service path, mocked locations/HTTP and SQLite; no WordPress/MySQL server).\n";
