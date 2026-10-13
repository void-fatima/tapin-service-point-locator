<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\PriceCheck\SecurityFingerprint as F;
use Tapin\ServicePointLocator\PriceCheck\SecurityIpResolver as IP;
$fixture = json_decode( file_get_contents( __DIR__ . '/fixtures/price-check-security.json' ) ); $count = 0;
foreach ( $fixture->helpers as $case ) {
	$args = $case->args;
	switch ( $case->kind ) {
		case 'client_id': $actual = array( F::client_id( $args[0] ), F::forged( $args[0] ) ); break;
		case 'browser': $actual = F::browser( $args[0] ); break;
		case 'document': $actual = F::document( $args[0] ); break;
		case 'request': $actual = F::request( $args[0] ); break;
		case 'ip': $actual = IP::normalize( $args[0] ); break;
		case 'address': $actual = IP::address( $args[0] ); break;
		case 'resolve': $actual = ( new IP( $args[0], $args[1] ) )->resolve( $args[2], (array) $args[3] ); break;
		case 'client': $actual = F::client( ...$args ); break;
		case 'context': $actual = F::context( ...$args ); break;
		default: throw new RuntimeException( 'Unknown fixture case.' );
	}
	if ( json_encode( $actual ) !== json_encode( $case->result ) ) { throw new RuntimeException( 'Security helper mismatch: ' . $case->kind . ' case ' . $count ); } $count++;
}
$deep = new stdClass(); $node = $deep; for ( $i = 0; $i < 11; $i++ ) { $node->a = new stdClass(); $node = $node->a; }
foreach ( array( array( $deep, 'canonical document too deep' ), array( array_fill( 0, 10000, 1 ), 'canonical document too large' ), array( INF, 'cannot canonicalize non-finite float' ) ) as [$raw, $expected] ) {
	try { F::document( $raw ); throw new RuntimeException( 'Missing canonical safety error.' ); } catch ( InvalidArgumentException $error ) { if ( $expected !== $error->getMessage() ) { throw $error; } } $count++;
}
try { F::coerce( '9223372036854775808' ); throw new RuntimeException( 'Missing bounded integer guard.' ); } catch ( DomainException $error ) { $count++; }
$previous = ini_get( 'serialize_precision' ); ini_set( 'serialize_precision', '3' );
if ( '1.23456' !== F::coerce( array( 1.23456 ) ) || '3' !== ini_get( 'serialize_precision' ) || '1.23456' !== F::scalar( 1.23456 ) || '3' !== ini_get( 'serialize_precision' ) ) { throw new RuntimeException( 'Canonical precision must be restored.' ); }
ini_set( 'serialize_precision', $previous ); $count++;
echo "Security fingerprints/IP: {$count} assertions passed.\n";
