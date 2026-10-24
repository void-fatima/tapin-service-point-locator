<?php
/** Provider-name conflicts through module REST/storage services; SQLite and simulated MySQL errors only. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckRest.php';
require __DIR__ . '/support/PriceCheckDatabase.php';

use Tapin\ServicePointLocator\PriceCheck\ConfigurationApiService;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationDatabase;
use Tapin\ServicePointLocator\PriceCheck\ProviderConfig;
use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\PublicApiService;
use Tapin\ServicePointLocator\PriceCheck\RestApi;
use Tapin\ServicePointLocator\PriceCheck\StorageError;

if ( ! extension_loaded( 'pdo_sqlite' ) ) {
	fwrite( STDERR, "Enable pdo_sqlite for the isolated provider-conflict checks.\n" );
	exit( 1 );
}

/** Compose the shared SQL double without changing its error behavior for other suites. */
final class ProviderConflictDatabase {
	public PriceCheckDatabase $database;
	public $before_write = null;
	public ?array $fault = null;
	public ?string $duplicate_key = null;
	public bool $case_insensitive_names = false;
	public int $name_reads = 0;
	public ?int $fail_name_read_at = null;
	public int $sql_duplicates = 0;

	public function __construct() { $this->database = new PriceCheckDatabase(); }
	public function __get( string $key ) { return $this->database->$key; }
	public function __set( string $key, $value ): void { $this->database->$key = $value; }
	public function __call( string $method, array $args ) {
		if ( 'get_results' === $method && preg_match( '/^SELECT id FROM `wp_tapin_price_providers` WHERE name = /', $args[0] ) ) {
			$this->name_reads++;
			if ( $this->name_reads === $this->fail_name_read_at ) {
				$this->database->last_error = 'private-sql-token: ownership read failed';
				return null;
			}
			// Explicitly simulate a case-insensitive MySQL collation in this SQLite fixture.
			if ( $this->case_insensitive_names ) { $args[0] .= ' COLLATE NOCASE'; }
		}
		if ( in_array( $method, array( 'insert', 'update' ), true ) ) {
			if ( null !== $this->before_write && 'wp_tapin_price_providers' === $args[0] ) {
				$callback = $this->before_write; $this->before_write = null;
				$callback( $this->database->pdo, $method, $args );
			}
			if ( null !== $this->fault && $method === $this->fault['method'] && 'wp_tapin_price_' . $this->fault['table'] === $args[0] ) {
				$fault = $this->fault; $this->fault = null;
				$this->database->last_error = $fault['error'];
				return $fault['result'] ?? false;
			}
		}
		$result = $this->database->$method( ...$args );
		if ( false === $result && null !== $this->duplicate_key && in_array( $method, array( 'insert', 'update' ), true ) ) {
			$error = $this->database->pdo->errorInfo();
			// The SQL constraint really rejected the write. Only the driver's wording is simulated.
			if ( 0 === strpos( $error[2] ?? '', 'UNIQUE constraint failed: wp_tapin_price_providers.' ) ) {
				$this->sql_duplicates++;
				$this->database->last_error = "Duplicate entry 'private-sql-token' for key '" . $this->duplicate_key . "'";
			}
		}
		return $result;
	}
}

$passed = 0;
function conflict_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$GLOBALS['passed']++;
}
function conflict_json( $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ); }
function conflict_copy( $value ) { return json_decode( conflict_json( $value ), false, 512, JSON_THROW_ON_ERROR ); }
function conflict_setup(): array {
	global $wpdb, $rest_admin, $rest_logged;
	$wpdb = new ProviderConflictDatabase(); $rest_admin = $rest_logged = true;
	$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-rest.json' ), false, 512, JSON_THROW_ON_ERROR );
	foreach ( array( 1 => 'first-company', 2 => 'other-company' ) as $id => $name ) {
		$record = conflict_copy( $fixture->cases[0]->provider ); $record->id = $id; $record->name = $name;
		ProviderStorage::save( ProviderConfig::normalise( $record ) );
	}
	$service = new ConfigurationApiService( new PublicApiService( null, null, static fn() => null ) );
	$api = new RestApi(
		static fn( $operation, $input ) => $service->call( $operation, $input ),
		static function() { throw new LogicException( 'Admin conflict test reached the public guard.' ); }
	);
	return array( $api, ProviderStorage::load( 1 ), ProviderStorage::load( 2 ) );
}
function conflict_request( RestApi $api, $record, ?int $id = 1 ): WP_REST_Response {
	$request = new PriceRestRequest(); $request->method = null === $id ? 'POST' : 'PUT';
	$request->route = '/' . RestApi::NAMESPACE . '/admin/providers' . ( null === $id ? '' : '/' . $id );
	$request->path = null === $id ? array() : array( 'provider_id' => (string) $id );
	$request->headers = array( 'x_wp_nonce' => 'valid-rest-nonce' ); $request->body = conflict_json( $record );
	return $api->handle( $request, null === $id ? 'admin_create_provider' : 'admin_update_provider', false, true );
}
function conflict_snapshot(): string { return conflict_json( ProviderStorage::all() ); }
function conflict_sanitized( WP_REST_Response $response ): void {
	$body = conflict_json( $response->get_data() );
	foreach ( array( 'private-sql-token', 'Duplicate entry', 'UNIQUE constraint', 'wp_tapin_price_', 'PRIMARY' ) as $private ) {
		conflict_check( false === strpos( $body, $private ), 'A provider response exposed private database diagnostics.' );
	}
}
function conflict_owner( WP_REST_Response $response, int $owner ): void {
	conflict_check( 409 === $response->get_status(), 'Another provider\'s technical name did not return HTTP 409.' );
	$data = $response->get_data();
	conflict_check( 'نام فنی واردشده قبلاً برای شرکت دیگری با شناسه ' . $owner . ' ثبت شده است.' === $data['detail'], 'The conflict did not identify the other provider.' );
	conflict_check( array( array( 'field' => 'name', 'message' => $data['detail'] ) ) === $data['errors'], 'The conflict did not identify the technical-name field.' );
	conflict_sanitized( $response );
}
function conflict_generic( WP_REST_Response $response ): void {
	conflict_check( 409 === $response->get_status(), 'A database failure changed the established HTTP 409 contract.' );
	conflict_check( array( 'detail' => 'ذخیره در پایگاه داده ناموفق بود.', 'errors' => array() ) === $response->get_data(), 'An unrelated database failure was mislabeled as a technical-name conflict.' );
	conflict_sanitized( $response );
}

// Known ownership is checked before writing; keeping the current name succeeds.
[$api, $first, $other] = conflict_setup();
$before = conflict_snapshot();
conflict_check( 200 === conflict_request( $api, $first )->get_status(), 'An unchanged provider conflicted with itself.' );
conflict_check( $before === conflict_snapshot(), 'An unchanged provider save changed settings or nested IDs.' );
$rename = conflict_copy( $first ); $rename->name = $other->name; $rename->params[0]->default = 77;
$wpdb->database->queries = array();
conflict_owner( conflict_request( $api, $rename ), 2 );
conflict_check( $before === conflict_snapshot(), 'A name conflict changed providers or nested configuration.' );
foreach ( $wpdb->database->queries as $query ) { conflict_check( ! preg_match( '/^(INSERT|UPDATE|DELETE)\b/i', $query ), 'An ownership precheck conflict reached a database write.' ); }
$new = conflict_copy( $other ); unset( $new->id );
conflict_owner( conflict_request( $api, $new, null ), 2 );
conflict_check( $before === conflict_snapshot(), 'Duplicate-name creation changed provider count or configuration.' );

// Database comparison determines ownership; case-equivalent self names remain valid.
[$api, $first, $other] = conflict_setup();
$wpdb->case_insensitive_names = true;
$wpdb->pdo->exec( 'CREATE UNIQUE INDEX fixture_provider_name_nocase ON wp_tapin_price_providers(name COLLATE NOCASE)' );
$first->name = strtoupper( $first->name );
conflict_check( 200 === conflict_request( $api, $first )->get_status(), 'A case-equivalent current name caused a self-conflict.' );
$first->name = strtoupper( $other->name );
conflict_owner( conflict_request( $api, $first ), 2 );
conflict_check( 2 === count( ProviderStorage::all() ), 'Collation checks changed provider count.' );

// A competing write after the precheck is still rejected by the actual SQL unique constraint.
foreach ( array( 'provider_name', 'wp_tapin_price_providers.provider_name' ) as $key ) {
	foreach ( array( false, true ) as $create ) {
		[$api, $first, $other] = conflict_setup();
		$first_before = conflict_json( $first ); $target = conflict_copy( $first ); $target->name = 'racing-name';
		if ( $create ) { unset( $target->id ); }
		$wpdb->duplicate_key = $key;
		$wpdb->before_write = static function( PDO $pdo ) { $pdo->exec( "UPDATE wp_tapin_price_providers SET name = 'racing-name' WHERE id = 2" ); };
		conflict_owner( conflict_request( $api, $target, $create ? null : 1 ), 2 );
		conflict_check( 1 === $wpdb->sql_duplicates, 'The race did not exercise SQL uniqueness enforcement.' );
		conflict_check( 2 === $wpdb->name_reads, 'The raced conflict did not recheck ownership after the failed write.' );
		conflict_check( $first_before === conflict_json( ProviderStorage::load( 1 ) ) && 2 === count( ProviderStorage::all() ), 'A raced duplicate changed the edited provider or created another row.' );
	}
}

// An allocated primary-key race is a generic save failure, even though the driver says duplicate.
[$api, $first] = conflict_setup();
$first->name = 'new-name'; unset( $first->id ); $wpdb->duplicate_key = 'PRIMARY';
$wpdb->before_write = static function( PDO $pdo, string $method, array $args ) {
	$pdo->exec( "INSERT INTO wp_tapin_price_providers(id, name, display_name) VALUES(" . (int) $args[1]['id'] . ", 'concurrent-company', 'Concurrent')" );
};
conflict_generic( conflict_request( $api, $first, null ) );
conflict_check( 1 === $wpdb->sql_duplicates && 3 === count( ProviderStorage::all() ), 'The primary-key race did not reject the attempted SQL insert.' );

// Driver classification is narrow and stores only the known constraint identifier.
$recognized = array(
	"Duplicate entry 'private-sql-token' for key 'provider_name'",
	"Duplicate entry 'private-sql-token' for key 'wp_tapin_price_providers.provider_name'",
	"Duplicate entry 'private-sql-token' for key `provider_name`",
);
$unrecognized = array(
	"Duplicate entry 'private-sql-token' for key 'PRIMARY'",
	"Duplicate entry 'private-sql-token' for key 'provider_name_other'",
	'Connection lost while accessing provider_name: private-sql-token',
	'errno 1062: unknown duplicate constraint private-sql-token',
	'UNIQUE constraint failed: wp_tapin_price_providers.name',
	"Duplicate entry 'private-sql-token for key 'provider_name'' for key 'PRIMARY'",
	"Duplicate entry 'private-sql-token' for key 'provider_name' trailing diagnostic",
);
foreach ( array( 'insert', 'update' ) as $method ) {
	foreach ( array_merge( $recognized, $unrecognized ) as $raw ) {
		foreach ( array( false, 1 ) as $result ) {
			conflict_setup();
			$wpdb->fault = array( 'method' => $method, 'table' => 'providers', 'error' => $raw, 'result' => $result );
			try {
				if ( 'insert' === $method ) { ConfigurationDatabase::insert( 'providers', array( 'id' => 3, 'name' => 'third', 'display_name' => 'Third' ) ); }
				else { ConfigurationDatabase::update( 'providers', array( 'display_name' => 'Changed' ), array( 'id' => 1 ) ); }
				throw new RuntimeException( 'A failed database operation was accepted.' );
			} catch ( StorageError $error ) {
				conflict_check( $method === $error->operation, 'Database conflict classification lost the operation.' );
				conflict_check( ( in_array( $raw, $recognized, true ) ? 'provider_name' : null ) === $error->unique_constraint, 'Database constraint classification accepted an unrelated failure.' );
				conflict_check( false === strpos( $error->getMessage(), 'private-sql-token' ) && false === strpos( conflict_json( get_object_vars( $error ) ), 'private-sql-token' ), 'StorageError retained private database diagnostics.' );
			}
			conflict_check( null === $wpdb->fault && false === $wpdb->suppress_errors( false ), 'The injected database failure or error-suppression restoration was not exercised.' );
		}
	}
}

// A key with the same spelling on another table must never become a provider-name error.
foreach ( array( 'provider_params', 'provider_fields', 'shops' ) as $table ) {
	conflict_setup(); $wpdb->fault = array( 'method' => 'update', 'table' => $table, 'error' => $recognized[0] );
	try { ConfigurationDatabase::update( $table, array( 'name' => 'ignored' ), array( 'id' => 1 ) ); throw new RuntimeException( 'An unrelated table failure was accepted.' ); }
	catch ( StorageError $error ) { conflict_check( null === $error->unique_constraint, 'Another table\'s duplicate was classified as a provider-name conflict.' ); }
}

// Ordinary failures, including success-shaped return values with last_error, keep generic responses.
foreach ( $unrecognized as $raw ) {
	foreach ( array( false, 1 ) as $result ) {
		[$api, $first] = conflict_setup(); $before = conflict_snapshot();
		$wpdb->fault = array( 'method' => 'update', 'table' => 'providers', 'error' => $raw, 'result' => $result );
		conflict_generic( conflict_request( $api, $first ) );
		conflict_check( $before === conflict_snapshot(), 'An injected provider write failure changed configuration.' );
	}
}
foreach ( array( 'provider_params', 'provider_fields' ) as $table ) {
	[$api, $first] = conflict_setup();
	$wpdb->fault = array( 'method' => 'update', 'table' => $table, 'error' => $recognized[0] );
	conflict_generic( conflict_request( $api, $first ) );
	conflict_check( null === $wpdb->fault, 'The intended nested storage failure was not exercised.' );
}

// A later missing/self owner or failed enrichment read must never falsely identify the edited row.
foreach ( array( 'self', 'missing', 'read-failure' ) as $owner_state ) {
	[$api, $first] = conflict_setup();
	if ( 'missing' === $owner_state ) { $first->name = 'unowned-name'; }
	if ( 'read-failure' === $owner_state ) { $wpdb->fail_name_read_at = 2; }
	$wpdb->fault = array( 'method' => 'update', 'table' => 'providers', 'error' => $recognized[0] );
	conflict_generic( conflict_request( $api, $first ) );
	conflict_check( 2 === $wpdb->name_reads, 'A typed write conflict did not recheck ownership.' );
}
[$api, $first] = conflict_setup(); $before = conflict_snapshot(); $wpdb->fail_name_read_at = 1;
conflict_generic( conflict_request( $api, $first ) );
conflict_check( $before === conflict_snapshot(), 'A failed ownership precheck wrote provider configuration.' );
conflict_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Provider conflict handling changed the locator table.' );

echo "PASS provider conflicts: {$passed} assertions (module REST/storage, SQLite constraints, simulated MySQL/MariaDB errors and collation; no live WordPress, MySQL or provider HTTP).\n";
