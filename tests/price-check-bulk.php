<?php
/** Original bulk expectations, isolated from WordPress and all external access. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\BulkMapping;
use Tapin\ServicePointLocator\PriceCheck\BulkConfig;
$passed = 0;
function bulk_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function bulk_json( $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ); }
function bulk_expand( $value ) {
	if ( $value instanceof stdClass && isset( $value->{'$fixture'} ) ) { return $GLOBALS['fixtures']->inputs->{$value->{'$fixture'}}; }
	if ( $value instanceof stdClass ) { $out = new stdClass(); foreach ( (array) $value as $key => $item ) { $out->$key = bulk_expand( $item ); } return $out; }
	return is_array( $value ) ? array_map( 'bulk_expand', $value ) : $value;
}
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-bulk.json' ) );
bulk_check( JSON_ERROR_NONE === json_last_error() && false === $fixtures->provenance->live_access, 'Invalid bulk fixtures.' );
foreach ( $fixtures->cases as $index => $case ) {
	$case->args = bulk_expand( $case->args );
	try {
		if ( 'incoming' === $case->op ) { foreach ( array( 3, 5 ) as $position ) { if ( isset( $case->args[$position] ) ) { $case->args[$position] = array_map( static fn( $row ) => (array) $row, (array) $case->args[$position] ); } } }
		if ( 'provider_id' === $case->op ) { $value = BulkConfig::field_provider_id( ...$case->args ); }
		elseif ( 'inferred_destination' === $case->op ) { list( $cfg, $providers ) = $case->args; $value = BulkMapping::destination( $cfg, BulkMapping::fields_with_kinds( $cfg, $providers, BulkConfig::active_providers( $cfg, $providers ) ) ); }
		else { $value = call_user_func_array( array( BulkMapping::class, $case->op ), $case->args ); }
		$actual = array( 'ok' => true, 'value' => $value );
	} catch ( UnexpectedValueException $error ) { $actual = array( 'ok' => false, 'kind' => 'TypeError' ); }
	bulk_check( bulk_json( $actual ) === bulk_json( $case->expected ), "Original bulk helper mismatch {$index} ({$case->op})." );
}
// Cartesian construction follows the exact loop in _bulk_check_results, including empty-choice reset.
$combos = BulkMapping::combinations( $fixtures->config, 1 );
bulk_check( bulk_json( $combos ) === '[{"13":"a","14.141":"2"},{"13":"a","14.141":"3"},{"13":"b","14.141":"2"},{"13":"b","14.141":"3"}]', 'Combination order.' );
$empty = (object) array( 'variants' => (object) array( '1' => (object) array( '13' => array( 'a' ), '14.141' => array() ) ) );
bulk_check( bulk_json( BulkMapping::combinations( $empty, 1 ) ) === '[{}]', 'Empty choices reset combinations.' );
echo "Price-check bulk checks passed ({$passed} assertions).\n";
