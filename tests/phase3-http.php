<?php
/** Parse the actual HTTP download produced by phase3-ui.cjs using the existing XLSX reader. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Import\TableReader;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Service\PointEvidence;
$file = dirname( __DIR__ ) . '/artifacts/phase3-http-export.xlsx';
$rows = iterator_to_array( TableReader::rows( $file, 'xlsx' ), false );
$expected = ( new ServicePointRepository() )->query( array( 'province' => 'سمنان', 'has_coordinates' => 0, 'status' => 'any', 'per_page' => 500 ) );
if ( $expected['total'] > 500 ) { throw new RuntimeException( 'HTTP fixture exceeds validation page.' ); }
$checks = 0;
$check = static function( bool $ok, string $message ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $message ); } $checks++; echo 'PASS ' . $message . "\n"; };
$check( count( $rows ) === $expected['total'] + 1 && $expected['total'] > 0, 'real download contains complete filtered Semnan address-only dataset' );
$check( count( $rows[0] ) === 12 && $rows[0][5] === 'کد پستی', 'real download Persian workbook headers' );
$exported = array_slice( $rows, 1 ); $names = array_column( $exported, 1 ); $wanted = array_column( array_map( array( PointEvidence::class, 'fields' ), $expected['items'] ), 'name' );
sort( $names ); sort( $wanted ); $check( $names === $wanted, 'real download matches stored trusted branch names' );
$check( count( array_filter( $exported, static fn( $r ) => $r[9] !== '' || $r[10] !== '' ) ) === 0, 'real download address-only coordinate cells remain empty' );
$check( count( array_filter( $exported, static fn( $r ) => $r[2] !== 'سمنان' ) ) === 0, 'real HTTP filter preserved' );
echo "RESULT Phase 3 HTTP workbook: {$checks} passed, 0 failed\n";
