<?php
/** CLI only: dry-run by default; --apply removes only independently rechecked candidates. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Service\SyntheticCleanup;
$rows = SyntheticCleanup::candidates();
echo wp_json_encode( array_map( static fn( $r ) => array_intersect_key( $r, array_flip( array( 'id', 'name', 'code', 'source' ) ) ), $rows ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
if ( in_array( '--apply', $argv, true ) ) {
	$result = \Tapin\ServicePointLocator\Database\WriteLock::run( static fn() => SyntheticCleanup::remove( array_map( 'intval', array_column( $rows, 'id' ) ) ) );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	echo "Removed: {$result}\n";
}
