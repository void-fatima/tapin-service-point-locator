<?php
/** Original location expectations. All HTTP and storage are injected/doubled. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\LocationData;
use Tapin\ServicePointLocator\PriceCheck\LocationError;
$passed = 0;
function location_check( bool $condition, string $label ): void { if ( ! $condition ) { throw new RuntimeException( $label ); } $GLOBALS['passed']++; }
function location_json( $value ): string { return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ); }
$fixtures = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-locations.json' ) );
location_check( JSON_ERROR_NONE === json_last_error() && false === $fixtures->provenance->live_access, 'Invalid location fixtures.' );
foreach ( (array) $fixtures->casefold as $input => $expected ) { location_check( $expected === LocationData::canonical( $input ), 'Original Unicode casefold mismatch.' ); }
foreach ( $fixtures->cases as $index => $case ) {
	try { $args = $case->args; if ( 'decode' === $case->op ) { $args[1] = base64_decode( $args[1] ); } $actual = array( 'ok' => true, 'value' => call_user_func_array( array( LocationData::class, $case->op ), $args ) ); }
	catch ( LocationError $error ) { $actual = array( 'ok' => false, 'message' => $error->getMessage() ); }
	location_check( location_json( $actual ) === location_json( $case->expected ), "Original location helper mismatch {$index} ({$case->op})." );
}
foreach ( $fixtures->limits as $case ) { location_check( 'decoded' === $case->python, 'Missing original JSON representation evidence.' ); try { LocationData::decode( 200, $case->raw ); throw new RuntimeException( 'Lossy location JSON accepted.' ); } catch ( DomainException $error ) { location_check( true, 'Explicit JSON representation guard.' ); } }
echo "PASS locations: {$passed} assertions; original Python helpers + explicit JSON guards, no live HTTP.\n";
