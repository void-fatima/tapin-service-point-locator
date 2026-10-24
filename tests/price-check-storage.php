<?php
/** Isolated SQL-backed wpdb double, compared with original Python configuration-storage fixtures. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckDatabase.php';

use Tapin\ServicePointLocator\PriceCheck\ProviderStorage;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationDatabase;
use Tapin\ServicePointLocator\PriceCheck\ConfigurationJson;
use Tapin\ServicePointLocator\PriceCheck\StorageError;
use Tapin\ServicePointLocator\PriceCheck\ShopStorage;
use Tapin\ServicePointLocator\PriceCheck\BulkStorage;

if ( ! extension_loaded( 'pdo_sqlite' ) ) { fwrite( STDERR, "Enable pdo_sqlite for the isolated storage checks.\n" ); exit( 1 ); }
$passed = 0;
function storage_check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); } $GLOBALS['passed']++;
}
function storage_execute( $item ) {
	switch ( $item->op ) {
		case 'provider_save': return ProviderStorage::save( ...$item->args );
		case 'provider_raw': return ProviderStorage::get_row( ...$item->args );
		case 'provider_params': return ProviderStorage::param_rows( ...$item->args );
		case 'provider_fields': return ProviderStorage::field_rows( ...$item->args );
		case 'provider_load': return ProviderStorage::load( ...$item->args );
		case 'provider_all': return ProviderStorage::all();
		case 'provider_delete': ProviderStorage::delete( ...$item->args ); return null;
		case 'shops_save': return ShopStorage::save( ...$item->args );
		case 'shops_list': return ShopStorage::list_rows( ...$item->args );
		case 'shop_get': return ShopStorage::get_private( ...$item->args );
		case 'shop_delete': return ShopStorage::delete( ...$item->args );
		case 'bulk_get': return BulkStorage::get();
		case 'bulk_save': BulkStorage::save( ...$item->args ); return null;
		case 'bulk_prune': return BulkStorage::prune_provider( ...$item->args );
		case 'bulk_json': return ConfigurationDatabase::rows( 'SELECT data_json FROM ' . ConfigurationDatabase::table( 'bulk_config' ) . ' WHERE id = 1' )[0]['data_json'];
	}
	throw new LogicException( 'Unknown storage operation.' );
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-storage.json' ) );
storage_check( JSON_ERROR_NONE === json_last_error(), 'Invalid fixture JSON.' );
$operations = 0;
foreach ( $fixtures->scenarios as $case ) {
	$wpdb = new PriceCheckDatabase();
	if ( 'unchanged provider update is a source failure' === $case->name ) {
		// Intentional correction of the archived Python bug; other fixtures retain source parity.
		$id = ProviderStorage::save( $case->actions[0]->args[0] );
		$record = ProviderStorage::load( $id );
		storage_check( $id === ProviderStorage::save( $record, true ), 'Unchanged provider save failed.' );
		storage_check( json_encode( $record ) === json_encode( ProviderStorage::load( $id ) ), 'Unchanged provider settings or IDs changed.' );
		storage_check( 1 === count( ProviderStorage::list_rows() ), 'Unchanged provider save duplicated the row.' );
		continue;
	}
	if ( isset( $case->initial_bulk_json ) ) { $wpdb->pdo->prepare( 'INSERT INTO wp_tapin_price_bulk_config(id, data_json) VALUES(1, ?)' )->execute( array( $case->initial_bulk_json ) ); }
	foreach ( $case->actions as $index => $item ) {
		$wpdb->failure = isset( $item->failure ) ? (array) $item->failure : null;
		try { $actual = array( 'ok' => true, 'value' => storage_execute( $item ) ); }
		catch ( StorageError $error ) { $actual = array( 'ok' => false, 'kind' => 'DatabaseError' ); storage_check( false === strpos( $error->getMessage(), $wpdb->last_error ), 'Database details exposed.' ); }
		catch ( InvalidArgumentException $error ) { $actual = array( 'ok' => false, 'kind' => 'ValueError', 'message' => $error->getMessage() ); }
		$encoded = json_encode( $actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		$expected = json_encode( $item->expected, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
		storage_check( $encoded === $expected, "Python storage mismatch: {$case->name}, action {$index} ({$item->op})." );
		$operations++;
	}
	storage_check( 'locator-preserved' === $wpdb->pdo->query( 'SELECT sentinel FROM wp_tapin_providers' )->fetchColumn(), 'Locator changed.' );
}
$wpdb = new PriceCheckDatabase();
storage_check( array() === ProviderStorage::field_rows( array() ) && ! $wpdb->queries, 'Empty field read issued SQL.' );
try { ConfigurationDatabase::table( 'tapin_providers' ); throw new RuntimeException( 'Locator suffix accepted.' ); } catch ( InvalidArgumentException $expected ) { $passed++; }
foreach ( array( NAN, INF, -INF ) as $value ) {
	try { ConfigurationJson::encode( (object) array( 'value' => $value ) ); throw new RuntimeException( 'Non-finite JSON silently encoded.' ); } catch ( DomainException $expected ) { $passed++; }
}
try { ConfigurationJson::decode( '{"value": NaN}' ); throw new RuntimeException( 'Non-finite JSON silently discarded.' ); } catch ( DomainException $expected ) { $passed++; }
$wpdb->failure = array( 'kind' => 'INSERT', 'table' => 'providers', 'mode' => 'error_only' );
try { ProviderStorage::save( (object) array( 'name' => 'error-only', 'params' => array() ) ); throw new RuntimeException( 'last_error ignored after a positive write count.' ); }
catch ( StorageError $expected ) { storage_check( 'insert' === $expected->operation, 'Wrong failure operation.' ); }
storage_check( false === $wpdb->suppress_errors( false ), 'Database error display state was not restored.' );
storage_check( null !== ProviderStorage::get_row( 1 ), 'Autocommit partial-write behavior changed.' );
echo "PASS configuration storage: {$operations} Python-derived SQL-double operations; {$passed} assertions. Not WordPress/MySQL integration parity.\n";
