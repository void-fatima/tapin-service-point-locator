<?php
/** Existing-provider save regressions: real module services with REST and SQLite wpdb doubles. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckRest.php';
require __DIR__ . '/support/PriceCheckDatabase.php';

use Tapin\ServicePointLocator\PriceCheck\BulkStorage;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationApiService;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationDatabase;
use Tapin\ServicePointLocator\PriceCheck\ProviderConfig;
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\PublicApiService;
use Tapin\ServicePointLocator\PriceCheck\RestApi;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;
use Tapin\ServicePointLocator\PriceCheck\StorageError;

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	fwrite( STDERR, "Enable pdo_sqlite for the isolated provider-save checks.\n" );
	exit( 1 );
}
$passed = 0;
function provider_save_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['passed']++;
}
function provider_save_json( $value ): string {
	return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
}
function provider_save_copy( $value ) {
	return json_decode( provider_save_json( $value ), false, 512, JSON_THROW_ON_ERROR );
}
function provider_save_api( ?PublicApiService $schemas = null ): RestApi {
	// No location refresh, WordPress installation, credentials or provider HTTP is used.
	$service = new ConfigurationApiService( $schemas ?? new PublicApiService( null, null, static fn() => null ) );
	return new RestApi(
		static fn( $operation, $input ) => $service->call( $operation, $input ),
		static function() { throw new LogicException( 'Admin save reached the public guard.' ); },
		static function() { throw new LogicException( 'Admin save requested public cookie context.' ); }
	);
}
function provider_save_request( RestApi $api, string $operation, $body = null, string $identifier = '' ): WP_REST_Response {
	$request = new PriceRestRequest();
	$request->method = 'admin_create_provider' === $operation ? 'POST' : ( 'admin_update_provider' === $operation ? 'PUT' : 'GET' );
	$request->route = '/' . RestApi::NAMESPACE . '/admin/providers' . ( '' === $identifier ? '' : '/' . rawurlencode( $identifier ) );
	$request->path = '' === $identifier ? array() : array( 'provider_id' => $identifier );
	$request->headers = array( 'x_wp_nonce' => 'valid-rest-nonce' );
	$request->body = null === $body ? '' : provider_save_json( $body );
	return $api->handle( $request, $operation, false, true );
}
function provider_save_count(): int {
	return count( ProviderStorage::list_rows() );
}
function provider_save_no_insert( array $queries ): void {
	foreach ( $queries as $query ) {
		provider_save_check( ! preg_match( '/^INSERT\s+INTO\s+`?wp_tapin_price_providers`?\s/i', $query ), 'An existing-provider save attempted a provider INSERT.' );
	}
}
function provider_save_error( WP_REST_Response $response ): void {
	provider_save_check( 409 === $response->get_status(), 'A database write failure did not return HTTP 409.' );
	provider_save_check(
		provider_save_json( array( 'detail' => 'ذخیره در پایگاه داده ناموفق بود.', 'errors' => array() ) ) === provider_save_json( $response->get_data() ),
		'A database write failure did not return the sanitized error contract.'
	);
}

$wpdb = new PriceCheckDatabase();
$rest_admin = $rest_logged = true;
$api = provider_save_api();
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-rest.json' ), false, 512, JSON_THROW_ON_ERROR );
$record = provider_save_copy( $fixture->cases[0]->provider );
unset( $record->id );
$record->name = 'save-regression';
$record->display_name = 'Save regression';
$record->logo = 'https://example.test/logo.png';
$record->shop_id = '01';
$record->timeout = 12.5;
$record->price_fields = array( (object) array( 'key' => 'total', 'label' => 'Total' ) );
$record->main_price_key = 'total';
$record->price_currency_in = 'toman';
$record->price_currency_out = 'toman';
$record->price_input_param = 'amount';
$record->weight_unit_in = 'kilogram';
$record->weight_unit_out = 'kilogram';
$record->weight_input_param = 'rows.cost';
$record->referral = (object) array( 'enabled' => true, 'title' => 'Open carrier', 'url' => 'https://example.test/carrier', 'description' => 'Fixture referral' );
$record->rules = array( (object) array( 'name' => 'Amount rule', 'if_field' => 'amount', 'if_op' => 'gte', 'if_value' => '0', 'message' => 'Fixture rule' ) );
$record->params[] = (object) array( 'name' => 'credential_reference', 'input' => 'text', 'type' => 'str', 'visible' => false, 'default' => 'inert-reference-marker' );
$record->location_config->origin->source = 'custom';
$record->location_config->origin->custom_mode = 'only';
$record->location_config->custom_locations = (object) array(
	'provinces' => array( (object) array( 'code' => 'p1', 'title' => 'Manual province' ) ),
	'cities' => array( (object) array( 'code' => 'c1', 'title' => 'Manual city', 'province_code' => 'p1' ) ),
);
$record->location_config->blocked_locations = (object) array(
	'provinces' => array(),
	'cities' => array( (object) array( 'code' => 'c2', 'title' => 'Blocked city', 'province_code' => 'p1' ) ),
);
ShopStorage::save( array( (object) array( 'id' => '01', 'title' => 'Fixture shop', 'token' => 'inert-shop-marker', 'province' => 'p1', 'city' => 'c1' ) ) );
$response = provider_save_request( $api, 'admin_create_provider', $record );
provider_save_check( 201 === $response->get_status(), 'Creating the regression provider failed.' );
$id = $response->get_data()['id'];
provider_save_check( 1 === $id && 1 === provider_save_count(), 'Creation did not allocate exactly one provider.' );
$loaded = ProviderStorage::load( $id );
$param_ids = array_map( static fn( $param ) => $param->id, $loaded->params );
$field_id = $loaded->params[2]->fields[0]->id;
$bulk = (object) array(
	'providers' => array( $id ),
	'fields' => array( (object) array( 'name' => 'shared_amount', 'source' => (object) array( 'provider' => $id, 'param' => $param_ids[0] ) ) ),
	'mappings' => (object) array( (string) $id => (object) array( 'shared_amount' => (string) $param_ids[0] ) ),
);
BulkStorage::save( $bulk );
$shop_before = provider_save_json( ShopStorage::get_private( '01' ) );
$bulk_before = provider_save_json( BulkStorage::get() );

// The double models WordPress/MySQL changed-row counts, so this is an actual zero.
provider_save_check( 0 === ConfigurationDatabase::update( 'providers', array( 'display_name' => $loaded->display_name ), array( 'id' => $id ) ), 'The database double did not model a zero-row unchanged UPDATE.' );
$payload = provider_save_request( $api, 'admin_get_provider', null, (string) $id )->get_data();
$before = provider_save_json( $loaded );
$wpdb->queries = array();
$response = provider_save_request( $api, 'admin_update_provider', $payload, (string) $id );
provider_save_check( 200 === $response->get_status() && $id === $response->get_data()['id'], 'Saving unchanged values failed.' );
provider_save_no_insert( $wpdb->queries );
provider_save_check( $before === provider_save_json( ProviderStorage::load( $id ) ), 'An unchanged save altered provider configuration or nested IDs.' );

// Every editor tab submits the complete schema, while changing only its own field.
$edits = array(
	'general' => static function( $provider ) { $provider->display_name = 'Saved display name'; },
	'parameters' => static function( $provider ) { $provider->params[2]->fields[0]->default = 42.5; },
	'pricing' => static function( $provider ) { $provider->price_fields[0]->label = 'Saved price label'; },
	'rules' => static function( $provider ) { $provider->rules[0]->message = 'Saved rule message'; },
	'locations' => static function( $provider ) { $provider->location_config->custom_locations->cities[0]->title = 'Saved city title'; },
);
foreach ( $edits as $tab => $edit ) {
	$expected = ProviderStorage::load( $id );
	$payload = provider_save_request( $api, 'admin_get_provider', null, (string) $id )->get_data();
	$edit( $expected ); $edit( $payload );
	// A disabled/omitted ID, a string ID, or a conflicting body ID cannot change the route target.
	if ( 'general' === $tab ) { unset( $payload->id ); }
	elseif ( 'parameters' === $tab ) { $payload->id = (string) $id; }
	else { $payload->id = 999; }
	$wpdb->queries = array();
	$response = provider_save_request( $api, 'admin_update_provider', $payload, 'save-regression' );
	provider_save_check( 200 === $response->get_status() && $id === $response->get_data()['id'], "Saving the {$tab} editor tab failed." );
	provider_save_no_insert( $wpdb->queries );
	$reopened = provider_save_request( $api, 'admin_get_provider', null, (string) $id );
	provider_save_check( 200 === $reopened->get_status() && $id === $reopened->get_data()->id, 'Reopening the saved provider failed.' );
	$actual = ProviderStorage::load( $id );
	provider_save_check( provider_save_json( $expected ) === provider_save_json( $actual ), "Saving the {$tab} tab erased or changed another setting." );
	provider_save_check( $param_ids === array_map( static fn( $param ) => $param->id, $actual->params ) && $field_id === $actual->params[2]->fields[0]->id, 'A save changed owned parameter or field IDs.' );
	provider_save_check( 1 === provider_save_count(), 'Editing created a duplicate provider.' );
	provider_save_check( $shop_before === provider_save_json( ShopStorage::get_private( '01' ) ) && $bulk_before === provider_save_json( BulkStorage::get() ), 'Editing changed shop credentials or bulk associations.' );
}

$payload = provider_save_request( $api, 'admin_get_provider', null, (string) $id )->get_data();
$before = provider_save_json( ProviderStorage::load( $id ) );
$invalid = provider_save_copy( $payload ); $invalid->name = '';
$wpdb->queries = array();
provider_save_check( 422 === provider_save_request( $api, 'admin_update_provider', $invalid, (string) $id )->get_status(), 'Invalid updates stopped returning HTTP 422.' );
provider_save_no_insert( $wpdb->queries );
provider_save_check( $before === provider_save_json( ProviderStorage::load( $id ) ), 'Validation failure changed provider data.' );
foreach ( array( '999', 'missing-provider' ) as $missing ) {
	$wpdb->queries = array();
	provider_save_check( 404 === provider_save_request( $api, 'admin_update_provider', $payload, $missing )->get_status(), 'Updating a missing provider did not return HTTP 404.' );
	provider_save_no_insert( $wpdb->queries );
	provider_save_check( 1 === provider_save_count(), 'Updating a missing provider silently created a row.' );
}

foreach ( array( null, 'error_only' ) as $mode ) {
	$payload = provider_save_request( $api, 'admin_get_provider', null, (string) $id )->get_data();
	$before = provider_save_json( ProviderStorage::load( $id ) );
	$payload->display_name = null === $mode ? 'Rejected false UPDATE' : 'Positive write with last_error';
	$wpdb->failure = array( 'kind' => 'UPDATE', 'table' => 'providers', 'mode' => $mode );
	$wpdb->queries = array();
	provider_save_error( provider_save_request( $api, 'admin_update_provider', $payload, (string) $id ) );
	provider_save_no_insert( $wpdb->queries );
	provider_save_check( null === $wpdb->failure, 'The intended UPDATE failure was not exercised.' );
	provider_save_check( 1 === provider_save_count(), 'A failed UPDATE changed provider count.' );
	if ( null === $mode ) { provider_save_check( $before === provider_save_json( ProviderStorage::load( $id ) ), 'A false UPDATE changed provider data.' ); }
	else { provider_save_check( $payload->display_name === ProviderStorage::load( $id )->display_name, 'The last_error case did not exercise a positive write result.' ); }
	provider_save_check( false === $wpdb->suppress_errors( false ), 'The database error display state was not restored.' );
}

// New providers still use POST and receive their own nested records and ID.
$existing_before_create = provider_save_json( ProviderStorage::load( $id ) );
$new = provider_save_copy( $record ); $new->name = 'second-regression';
$response = provider_save_request( $api, 'admin_create_provider', $new );
provider_save_check( 201 === $response->get_status() && $id !== $response->get_data()['id'] && 2 === provider_save_count(), 'Creating another provider stopped working after an edit.' );
provider_save_check( $existing_before_create === provider_save_json( ProviderStorage::load( $id ) ), 'Creation changed the existing provider.' );

// A provider deleted after route lookup must never be recreated by the edit path.
$race_id = $response->get_data()['id'];
$race_payload = provider_save_request( $api, 'admin_get_provider', null, (string) $race_id )->get_data();
$race_api = provider_save_api( new PublicApiService( null, static function( $identifier ) {
	$provider = ProviderStorage::load( $identifier );
	if ( null !== $provider ) { ProviderStorage::delete( $provider->id ); }
	return $provider;
}, static fn() => null ) );
$wpdb->queries = array();
provider_save_error( provider_save_request( $race_api, 'admin_update_provider', $race_payload, (string) $race_id ) );
provider_save_no_insert( $wpdb->queries );
provider_save_check( null === ProviderStorage::load( $race_id ) && 1 === provider_save_count(), 'The edit path recreated a concurrently deleted provider.' );

// Strict updates reject absent IDs; generic storage retains supplied-ID creation.
foreach ( array( null, '', 999 ) as $missing_id ) {
	$missing = ProviderConfig::normalise( $record ); $missing->id = $missing_id;
	if ( null === $missing_id ) { unset( $missing->id ); }
	$wpdb->queries = array();
	try { ProviderStorage::save( $missing, true ); throw new RuntimeException( 'Strict storage created an absent provider.' ); }
	catch ( StorageError $error ) { provider_save_check( 'update' === $error->operation, 'Strict storage reported the wrong missing-row operation.' ); }
	provider_save_no_insert( $wpdb->queries );
	provider_save_check( 1 === provider_save_count(), 'Strict storage changed provider count for an absent ID.' );
}
$supplied = ProviderConfig::normalise( $record ); $supplied->id = 42; $supplied->name = 'supplied-id-regression';
provider_save_check( 42 === ProviderStorage::save( $supplied ) && 2 === provider_save_count(), 'Generic storage lost supplied-ID creation.' );
$supplied = ProviderStorage::load( 42 );
$wpdb->queries = array();
provider_save_check( 42 === ProviderStorage::save( $supplied ), 'Generic storage still fails unchanged updates.' );
provider_save_no_insert( $wpdb->queries );

// Keep the existing authentication/nonce boundary on the exercised save path.
foreach ( array( array( false, true, 'valid-rest-nonce' ), array( true, false, 'valid-rest-nonce' ), array( true, true, 'invalid' ) ) as [$logged, $admin, $nonce] ) {
	$rest_logged = $logged; $rest_admin = $admin;
	$request = new PriceRestRequest(); $request->method = 'PUT'; $request->route = '/' . RestApi::NAMESPACE . '/admin/providers/' . $id;
	$request->path = array( 'provider_id' => (string) $id ); $request->body = provider_save_json( $payload ); $request->headers = array( 'x_wp_nonce' => $nonce );
	$wpdb->queries = array();
	provider_save_check( 403 === $api->handle( $request, 'admin_update_provider', false, true )->get_status() && ! $wpdb->queries, 'Unauthorized provider edits reached storage.' );
}
provider_save_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Provider saves changed the locator table.' );
echo "PASS provider-save regressions: {$passed} assertions (module REST/storage services, SQLite and REST doubles; no live WordPress or provider HTTP).\n";
