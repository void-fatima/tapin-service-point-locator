<?php
/** Table-driven tests against expectations captured by the isolated original Python runner. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\ProviderConfig;
use Tapin\ServicePointLocator\PriceCheck\BulkConfig;
use Tapin\ServicePointLocator\PriceCheck\PayloadNormalizer;
use Tapin\ServicePointLocator\PriceCheck\ProviderDefinition;
use Tapin\ServicePointLocator\PriceCheck\Units;
use Tapin\ServicePointLocator\PriceCheck\RecordError;
use Tapin\ServicePointLocator\PriceCheck\PayloadError;
use Tapin\ServicePointLocator\PriceCheck\PythonValue;

function expand_fixture( $value ) {
	global $fixture;
	if ( $value instanceof stdClass && property_exists( $value, '$ref' ) ) { return expand_fixture( $fixture->shared[$value->{'$ref'}] ); }
	if ( $value instanceof stdClass && property_exists( $value, '$float' ) ) { return 'nan' === $value->{'$float'} ? NAN : ( '-inf' === $value->{'$float'} ? -INF : INF ); }
	if ( $value instanceof stdClass && property_exists( $value, '$repeat' ) ) { return str_repeat( $value->{'$repeat'}, $value->count ); }
	if ( $value instanceof stdClass && property_exists( $value, '$sequence' ) ) {
		$rows = array(); for ( $i = 0; $i < $value->{'$sequence'}; $i++ ) { $row = clone $value->row; $row->code = (string) $i; $rows[] = $row; } return $rows;
	}
	if ( is_array( $value ) ) { return array_map( 'expand_fixture', $value ); }
	if ( $value instanceof stdClass ) { $copy = clone $value; foreach ( $copy as $key => $item ) { $copy->$key = expand_fixture( $item ); } return $copy; }
	return $value;
}
function execute_case( $case ) {
	$args = expand_fixture( $case->args );
	switch ( $case->op ) {
		case 'config': return ProviderConfig::normalise( ...$args );
		case 'bulk': return BulkConfig::normalise( ...$args );
		case 'currency': return Units::currency_amount( ...$args );
		case 'weight': return Units::weight_amount( ...$args );
		case 'units': return (object) array( 'currency' => Units::currency( $args[0] ), 'weight' => Units::weight( $args[0] ) );
		case 'coerce': return PayloadNormalizer::coerce( ...$args );
		case 'target': return ProviderDefinition::target( ...$args );
		case 'find': return ProviderDefinition::find_param( ...$args );
		case 'mapping_row': return BulkConfig::mappings_for( ...$args );
		case 'defaults': return ProviderDefinition::defaults( ...$args );
		case 'active': return BulkConfig::active_providers( ...$args );
		case 'rule_clause': return PayloadNormalizer::rule_clause( ...$args );
		case 'payload': $result = PayloadNormalizer::normalise( ...$args ); return (object) array( 'payload' => $result->values, 'origin' => $result->origin );
		case 'payload_json': return json_decode( json_encode( PayloadNormalizer::normalise( ...$args ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
	throw new LogicException( 'Unknown operation.' );
}
function equal_value( $actual, $expected ): bool {
	if ( gettype( $actual ) !== gettype( $expected ) ) { return false; }
	if ( is_array( $actual ) ) { if ( count( $actual ) !== count( $expected ) ) { return false; } foreach ( $actual as $i => $value ) { if ( ! equal_value( $value, $expected[$i] ) ) { return false; } } return true; }
	if ( $actual instanceof stdClass ) {
		if ( array_keys( (array) $actual ) !== array_keys( (array) $expected ) ) { return false; }
		foreach ( (array) $actual as $key => $value ) { if ( ! equal_value( $value, $expected->$key ) ) { return false; } } return true;
	}
	if ( is_float( $actual ) && is_float( $expected ) ) {
		if ( is_nan( $actual ) && is_nan( $expected ) ) { return true; }
		if ( 0.0 === $actual && 0.0 === $expected ) { return json_encode( $actual, JSON_PRESERVE_ZERO_FRACTION ) === json_encode( $expected, JSON_PRESERVE_ZERO_FRACTION ); }
	}
	return $actual === $expected;
}
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-python.json' ) );
if ( JSON_ERROR_NONE !== json_last_error() || ! $fixture instanceof stdClass ) { throw new RuntimeException( 'Invalid Python fixture JSON.' ); }
$failed = 0; $passed = 0;
foreach ( $fixture->cases as $case ) {
	try { $actual = (object) array( 'ok' => true, 'value' => execute_case( $case ) ); }
	catch ( RecordError $error ) { $actual = (object) array( 'ok' => false, 'kind' => 'RecordError', 'message' => $error->getMessage(), 'errors' => json_decode( json_encode( $error->errors, JSON_UNESCAPED_UNICODE ) ) ); }
	catch ( PayloadError $error ) { $actual = (object) array( 'ok' => false, 'kind' => 'PayloadError', 'message' => $error->getMessage(), 'errors' => json_decode( json_encode( $error->errors, JSON_UNESCAPED_UNICODE ) ) ); }
	catch ( InvalidArgumentException $error ) { $actual = (object) array( 'ok' => false, 'kind' => 'ValueError' ); }
	catch ( TypeError $error ) { $actual = (object) array( 'ok' => false, 'kind' => 'TypeError', 'message' => $error->getMessage() ); }
	$expected = expand_fixture( $case->expected );
	if ( equal_value( $actual, $expected ) ) { $passed++; }
	else {
		$failed++;
		echo "FAIL {$case->name}\n";
		if ( $failed <= 8 ) { echo json_encode( array( 'actual' => $actual, 'expected' => $case->expected ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) . "\n"; }
	}
}
echo "Python-derived parity: {$passed} passed, {$failed} failed; original Python {$fixture->python}.\n";
$guarded = 0;
foreach ( $fixture->compatibility_limits as $case ) {
	try { execute_case( $case ); $failed++; echo "FAIL compatibility guard {$case->name}\n"; }
	catch ( DomainException $error ) { $guarded++; }
}
echo "Explicit PHP compatibility guards: {$guarded} passed (not parity claims).\n";

$float_regressions = array(
	array( 35.7, '35.7' ),
	array( 1.0e-5, '1e-05' ),
	array( 1.0e-6, '1e-06' ),
	array( 1.0e16, '1e+16' ),
	array( 1.0, '1.0' ),
	array( -0.0, '-0.0' ),
);
$initial_serialize_precision = ini_get( 'serialize_precision' );
foreach ( $float_regressions as $regression ) {
	if ( PythonValue::string( $regression[0] ) !== $regression[1] ) { echo 'FAIL Python float representation: ' . $regression[1] . "\n"; exit( 1 ); }
}
if ( ini_get( 'serialize_precision' ) !== $initial_serialize_precision ) { echo "FAIL serialize_precision was not restored.\n"; exit( 1 ); }
echo 'Python float representation regressions: ' . count( $float_regressions ) . " passed.\n";
exit( $failed ? 1 : 0 );
