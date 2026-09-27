<?php
/** Operator reviewed JSON snapshot: php scripts/import-tapin.php reviewed.json --reviewed [--import] */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) || ! in_array( '--reviewed', $argv, true ) ) { fwrite( STDERR, "Set TAPIN_WP_ROOT and pass reviewed.json --reviewed. Add --import to publish records.\n" ); exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Import\TapinDirectory;
use Tapin\ServicePointLocator\Normalization\DataNormalizer;
$snapshot = json_decode( file_get_contents( $argv[1] ), true );
if ( ! is_array( $snapshot ) || empty( $snapshot['candidates'] ) || empty( $snapshot['province'] ) ) { throw new RuntimeException( 'Expected a reviewed province snapshot with candidates.' ); }
$response = wp_remote_get( TapinDirectory::INDEX_URL, array( 'timeout' => 30, 'redirection' => 0 ) );
if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { throw new RuntimeException( 'Official source catalog unavailable; snapshot unchanged.' ); }
$catalog = TapinDirectory::discover( wp_remote_retrieve_body( $response ) );
if ( ! in_array( $snapshot['url'] ?? '', $catalog, true ) ) { throw new RuntimeException( 'Document is not linked by the official Tapin directory.' ); }
$post = ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_by_slug( 'post' );
if ( ! $post ) { throw new RuntimeException( 'Post provider is missing.' ); }
$rows = array();
foreach ( $snapshot['candidates'] as $row ) {
	if ( ( $row['source'] ?? '' ) !== $snapshot['url'] || ( $row['province'] ?? '' ) !== $snapshot['province'] || null !== ( $row['latitude'] ?? null ) || null !== ( $row['longitude'] ?? null ) ) { throw new RuntimeException( 'Mismatched source/province or coordinates in a text-only directory.' ); }
	$row['provider_id'] = (int) $post['id'];
	$row = DataNormalizer::normalize_service_point( $row );
	if ( ! \Tapin\ServicePointLocator\Validation\ServicePointValidator::validate( $row )->is_valid() ) { throw new RuntimeException( 'Invalid reviewed row: ' . $row['name'] ); }
	$rows[] = $row;
}
$result = \Tapin\ServicePointLocator\Database\WriteLock::run( static function() use ( $rows, $snapshot, $argv, $post ) {
	$existing = array_filter( get_option( 'tapin_directory_snapshot', array() ), static fn( $r ) => $r['source'] !== $snapshot['url'] );
	update_option( 'tapin_directory_snapshot', array_merge( array_values( $existing ), $rows ), false );
	if ( in_array( '--import', $argv, true ) ) {
		foreach ( $rows as $row ) { echo wp_json_encode( \Tapin\ServicePointLocator\Import\RowProcessor::process( $row, (int) $post['id'], 'skip' ), JSON_UNESCAPED_UNICODE ) . "\n"; }
	}
	return count( $rows );
} );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
echo "Reviewed reference rows installed: {$result}\n";
