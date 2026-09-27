<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;

defined( 'ABSPATH' ) || exit;

/** Synchronous compatibility API for trusted CLI callers. Web UI uses ImportJobs. */
class ImportManager {
 public function __construct( ?ServicePointRepository $points = null, ?ProviderRepository $providers = null ) {}
 public function import_csv( string $path, int $provider, array $options = array() ): ImportResult {
  $result = new ImportResult();
  $action = $options['duplicate_action'] ?? 'skip';
  if ( ! in_array( $action, array( 'skip', 'update' ), true ) || ! ( new ProviderRepository() )->get_by_id( $provider ) ) {
   $result->add_error( 0, 'options', 'Invalid provider or duplicate action (skip/update).' ); $result->finish(); return $result;
  }
  $headers = null;
  $mapper = new ColumnMapper( $options['custom_mapping'] ?? array() );
  $number = 1;
  try {
   foreach ( TableReader::rows( $path, 'csv' ) as $row ) {
    if ( null === $headers ) {
     $row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $row[0] );
     $headers = array_map( 'trim', $row );
     if ( in_array( '', $headers, true ) || count( array_unique( $headers ) ) !== count( $headers ) ) { throw new \RuntimeException( 'Headers must be nonempty and unique.' ); }
     $mapper->auto_detect_headers( $headers ); continue;
    }
    $number++; $result->increment_total(); $result->increment_processed();
    $outcome = count( $row ) === count( $headers ) ? RowProcessor::process( $mapper->map_row( array_combine( $headers, $row ) ), $provider, $action ) : array( 'result' => 'failed', 'messages' => array( 'Column count mismatch.' ) );
    if ( 'inserted' === $outcome['result'] ) { $result->increment_inserted(); }
    elseif ( 'updated' === $outcome['result'] ) { $result->increment_updated(); }
    else { $result->increment_skipped(); }
    foreach ( $outcome['messages'] as $message ) {
     if ( 'failed' === $outcome['result'] ) { $result->add_error( $number, 'row', $message ); }
     elseif ( 'skipped' === $outcome['result'] ) { $result->add_duplicate( $number, 'candidate', $message, $outcome['existing_id'] ?? null ); }
     else { $result->add_warning( $number, 'row', $message ); }
    }
   }
  } catch ( \Throwable $e ) { $result->add_error( $number, 'file', $e->getMessage() ); }
  $result->finish();
  \Tapin\ServicePointLocator\Service\OperationalLog::record( $result->get_errors() ? 'import_failed' : 'import_completed', array( 'provider_id' => $provider, 'inserted' => $result->get_inserted_rows(), 'updated' => $result->get_updated_rows(), 'skipped' => $result->get_skipped_rows(), 'failed' => count( $result->get_errors() ) ) );
  return $result;
 }
}
