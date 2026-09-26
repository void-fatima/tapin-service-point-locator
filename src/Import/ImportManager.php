<?php

namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Normalization\DataNormalizer;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Validation\ServicePointValidator;

defined( 'ABSPATH' ) || exit;

/**
 * High-level coordinator for bulk service-point imports.
 */
class ImportManager {

	private ServicePointRepository $service_point_repo;
	private ProviderRepository $provider_repo;

	public function __construct(
		?ServicePointRepository $service_point_repo = null,
		?ProviderRepository $provider_repo = null
	) {
		$this->service_point_repo = $service_point_repo ?? new ServicePointRepository();
		$this->provider_repo      = $provider_repo ?? new ProviderRepository();
	}

	/**
	 * Imports service points from a CSV file.
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 * @param int $provider_id Target provider ID.
	 * @param array $options Import options (duplicate_action, custom_mapping, batch_size).
	 * @return ImportResult
	 */
	public function import_csv( string $file_path, int $provider_id, array $options = array() ): ImportResult {
		$result = new ImportResult();

		// Verify provider exists.
		$provider = $this->provider_repo->get_by_id( $provider_id );
		if ( ! $provider ) {
			$result->add_error( 0, 'provider_id', sprintf( 'Provider with ID %d does not exist.', $provider_id ) );
			$result->finish();
			return $result;
		}

		$duplicate_action = $options['duplicate_action'] ?? 'skip'; // 'skip' | 'update' | 'allow'
		$custom_mapping   = $options['custom_mapping'] ?? array();
		$batch_size       = max( 10, min( 250, (int) ( $options['batch_size'] ?? 100 ) ) );

		$column_mapper      = new ColumnMapper( $custom_mapping );
		$duplicate_detector = new DuplicateDetector( $provider_id );
		$duplicate_detector->preload_existing_records();

		$headers_mapped = false;

		foreach ( CsvImporter::stream_batches( $file_path, $batch_size ) as $batch ) {
			if ( ! $headers_mapped ) {
				$column_mapper->auto_detect_headers( $batch['headers'] );
				$headers_mapped = true;
			}

			$rows_to_insert = array();

			foreach ( $batch['rows'] as $row_item ) {
				$row_index = $row_item['row_index'];
				$raw_data  = $row_item['data'];

				$result->increment_total();

				// 1. Column Mapping.
				$mapped_data                = $column_mapper->map_row( $raw_data );
				$mapped_data['provider_id'] = $provider_id;

				// 2. Data Normalization.
				$normalized = DataNormalizer::normalize_service_point( $mapped_data );

				// 3. Validation.
				$validation = ServicePointValidator::validate( $normalized );

				// Collect warnings (non-blocking).
				if ( $validation->has_warnings() ) {
					foreach ( $validation->get_warnings() as $field => $msg ) {
						$result->add_warning( $row_index, $field, $msg, $raw_data );
					}
				}

				// If fatal validation errors exist, skip record and collect errors.
				if ( $validation->has_errors() ) {
					foreach ( $validation->get_errors() as $field => $msg ) {
						$result->add_error( $row_index, $field, $msg, $raw_data );
					}
					$result->increment_skipped();
					continue;
				}

				// 4. Duplicate Detection.
				$duplicate = $duplicate_detector->detect( $normalized );

				if ( null !== $duplicate ) {
					$result->add_duplicate( $row_index, $duplicate['type'], $duplicate['reason'], $duplicate['existing_id'] );

					if ( 'skip' === $duplicate_action ) {
						$result->increment_skipped();
						continue;
					}

					if ( 'update' === $duplicate_action && ! empty( $duplicate['existing_id'] ) ) {
						$updated = $this->service_point_repo->update( $duplicate['existing_id'], $normalized );
						if ( $updated ) {
							$result->increment_updated();
							$duplicate_detector->register( $normalized, $duplicate['existing_id'] );
						} else {
							$result->add_error( $row_index, 'database', 'Failed to update existing duplicate record.', $raw_data );
							$result->increment_skipped();
						}
						continue;
					}
				}

				// Queue for batch insertion and immediately register in duplicate detector
				// to prevent intra-batch duplicate collisions.
				$rows_to_insert[] = $normalized;
				$duplicate_detector->register( $normalized, 0 );
			}

			// 5. Batch Insert.
			if ( ! empty( $rows_to_insert ) ) {
				$inserted_count = $this->service_point_repo->batch_insert( $rows_to_insert );
				$result->increment_inserted( $inserted_count );
			}

			$result->increment_processed( count( $batch['rows'] ) );
		}

		$result->finish();

		return $result;
	}
}
