<?php

namespace Tapin\ServicePointLocator\Repository;

use Tapin\ServicePointLocator\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for service point database operations.
 */
class ServicePointRepository {
	/** Canonical comparisons also cover legacy records without rewriting their provenance. */
	private static function location_sql( string $field ): string {
		$sql = $field;
		$map = array( 'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', "\u{200c}" => ' ' );
		foreach ( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ) as $index => $digit ) { $map[$digit] = (string) ( $index % 10 ); }
		foreach ( $map as $from => $to ) { $sql = "REPLACE({$sql}, '{$from}', '{$to}')"; }
		return "TRIM({$sql})";
	}

	/**
	 * Returns the table name.
	 */
	public function get_table_name(): string {
		return Schema::get_service_points_table();
	}

	/**
	 * Retrieves a service point by primary ID.
	 */
	public function get_by_id( int $id ): ?array {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->format_row( $row );
	}

	/**
	 * Retrieves a service point by provider ID and branch code.
	 */
	public function get_by_code( int $provider_id, string $code ): ?array {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE provider_id = %d AND code = %s LIMIT 1",
				$provider_id,
				sanitize_text_field( $code )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->format_row( $row );
	}

	/**
	 * Inserts a single service point.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$prepared = $this->prepare_record_for_storage( $data );
		$now      = current_time( 'mysql', true );

		$prepared['created_at'] = $now;
		$prepared['updated_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$this->get_table_name(),
			$prepared,
			array_map( fn( $column ) => $this->get_column_formats()[$column] ?? '%s', array_keys( $prepared ) )
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates an existing service point.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$prepared = $this->prepare_record_for_storage( $data, false );
		if ( empty( $prepared ) ) {
			return false;
		}

		$prepared['updated_at'] = current_time( 'mysql', true );

		$formats = array();
		foreach ( array_keys( $prepared ) as $column ) {
			$formats[] = $this->get_column_formats()[ $column ] ?? '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$this->get_table_name(),
			$prepared,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Deletes a service point by ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			$this->get_table_name(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/** Clear every service point and its queued geocoding task for an explicit admin reset. */
	public function delete_all(): int {
		global $wpdb;
		$points = $this->get_table_name();
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'tapin_geocoding_jobs' );
		$deleted = $wpdb->query( 'DELETE FROM ' . $points );
		if ( false === $deleted ) { $wpdb->query( 'ROLLBACK' ); return -1; }
		$wpdb->query( 'COMMIT' );
		return (int) $deleted;
	}

	/**
	 * High-performance batch insertion for bulk imports.
	 *
	 * Automatically chunks rows (default 100 per query) to avoid MySQL packet/placeholder limits.
	 *
	 * @param array<int, array> $records List of sanitized/normalized records.
	 * @param int $chunk_size Number of rows per multi-row INSERT statement.
	 * @return int Total number of successfully inserted rows.
	 */
	public function batch_insert( array $records, int $chunk_size = 100 ): int {
		if ( empty( $records ) ) {
			return 0;
		}

		global $wpdb;

		$table          = $this->get_table_name();
		$now            = current_time( 'mysql', true );
		$total_inserted = 0;
		$chunks         = array_chunk( $records, max( 1, min( 250, $chunk_size ) ) );

		$columns = array(
			'provider_id',
			'code',
			'name',
			'province',
			'city',
			'address',
			'postal_code',
			'phone',
			'mobile_phone',
			'landline_phone',
			'source',
			'data_quality_status',
			'latitude',
			'longitude',
			'has_coordinates',
			'status',
			'metadata',
			'created_at',
			'updated_at',
		);

		$columns_sql = implode( ', ', array_map( static fn( $col ) => "`{$col}`", $columns ) );

		foreach ( $chunks as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $raw_record ) {
				$prepared = $this->prepare_record_for_storage( $raw_record );

				$row_placeholders = array(
					'%d', // provider_id
					'%s', // code
					'%s', // name
					'%s', // province
					'%s', // city
					'%s', // address
					'%s', // postal_code
					'%s', // phone
					'%s', // mobile_phone
					'%s', // landline_phone
					'%s', // source
					'%s', // data_quality_status
					null !== $prepared['latitude'] ? '%f' : 'NULL', // latitude
					null !== $prepared['longitude'] ? '%f' : 'NULL', // longitude
					'%d', // has_coordinates
					'%s', // status
					'%s', // metadata
					'%s', // created_at
					'%s', // updated_at
				);

				$placeholders[] = '(' . implode( ', ', $row_placeholders ) . ')';

				$values[] = (int) $prepared['provider_id'];
				$values[] = $prepared['code'];
				$values[] = $prepared['name'];
				$values[] = $prepared['province'];
				$values[] = $prepared['city'];
				$values[] = $prepared['address'];
				$values[] = $prepared['postal_code'];
				$values[] = $prepared['phone'];
				foreach ( array( 'mobile_phone', 'landline_phone', 'source', 'data_quality_status' ) as $field ) { $values[] = $prepared[$field]; }

				if ( null !== $prepared['latitude'] ) {
					$values[] = (float) $prepared['latitude'];
				}
				if ( null !== $prepared['longitude'] ) {
					$values[] = (float) $prepared['longitude'];
				}

				$values[] = (int) $prepared['has_coordinates'];
				$values[] = $prepared['status'];
				$values[] = $prepared['metadata'];
				$values[] = $now;
				$values[] = $now;
			}

			$query = "INSERT INTO {$table} ({$columns_sql}) VALUES " . implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $wpdb->prepare( $query, $values ) );

			if ( false !== $result ) {
				$total_inserted += (int) $result;
			}
		}

		return $total_inserted;
	}

	/**
	 * Queries service points with filtering, search, pagination, and sorting.
	 *
	 * @param array $args Filter and pagination arguments.
	 * @return array{items: array, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$table = $this->get_table_name();

		$defaults = array(
			'provider_id'     => null,
			'province'        => null,
			'city'            => null,
			'has_coordinates' => null,
			'status'          => 'active',
			'search'          => null,
			'bounds'          => null, // array{north: float, south: float, east: float, west: float}
			'page'            => 1,
			'per_page'        => 20,
			'orderby'         => 'id',
			'order'           => 'ASC',
		);

		$params = wp_parse_args( $args, $defaults );

		$where_clauses = array();
		$where_values  = array();
		if ( ! empty( $params['public'] ) ) {
			$providers = Schema::get_providers_table();
			$where_clauses[] = "status = 'active' AND provider_id IN (SELECT id FROM {$providers} WHERE is_active = 1)";
			if ( empty( $params['directory'] ) ) {
				$where_clauses[] = 'has_coordinates = 1 AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180';
			}
		}
		if ( ( $params['issue'] ?? '' ) === 'incomplete' ) {
			$where_clauses[] = "((COALESCE(phone, '') = '' AND COALESCE(mobile_phone, '') = '' AND COALESCE(landline_phone, '') = '') OR address = '' OR province = '' OR city = '')";
		}
		if ( ( $params['issue'] ?? '' ) === 'duplicate' ) {
			$where_clauses[] = self::duplicate_clause( $table );
		}

		// Provider filter.
		if ( ! empty( $params['provider_id'] ) ) {
			if ( is_array( $params['provider_id'] ) ) {
				$ids = array_map( 'intval', $params['provider_id'] );
				if ( ! empty( $ids ) ) {
					$in_sql          = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
					$where_clauses[] = "provider_id IN ({$in_sql})";
					$where_values    = array_merge( $where_values, $ids );
				}
			} else {
				$where_clauses[] = 'provider_id = %d';
				$where_values[]  = (int) $params['provider_id'];
			}
		}

		// Province filter.
		if ( ! empty( $params['province'] ) ) {
			$where_clauses[] = self::location_sql( 'province' ) . ' = %s';
			$where_values[]  = \Tapin\ServicePointLocator\Normalization\DataNormalizer::normalize_location( sanitize_text_field( $params['province'] ) );
		}

		// City filter.
		if ( ! empty( $params['city'] ) ) {
			$where_clauses[] = self::location_sql( 'city' ) . ' = %s';
			$where_values[]  = \Tapin\ServicePointLocator\Normalization\DataNormalizer::normalize_location( sanitize_text_field( $params['city'] ) );
		}

		// Coordinates availability filter.
		if ( null !== $params['has_coordinates'] ) {
			$where_clauses[] = 'has_coordinates = %d';
			$where_values[]  = $params['has_coordinates'] ? 1 : 0;
		}

		// Status filter ('any' ignores status).
		if ( ! empty( $params['status'] ) && 'any' !== $params['status'] ) {
			$where_clauses[] = 'status = %s';
			$where_values[]  = sanitize_key( $params['status'] );
		}

		// Bounding box filter for map queries.
		if ( ! empty( $params['bounds'] ) && is_array( $params['bounds'] ) ) {
			$north = (float) ( $params['bounds']['north'] ?? 0 );
			$south = (float) ( $params['bounds']['south'] ?? 0 );
			$east  = (float) ( $params['bounds']['east'] ?? 0 );
			$west  = (float) ( $params['bounds']['west'] ?? 0 );

			$where_clauses[] = 'has_coordinates = 1 AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f';
			$where_values[]  = min( $south, $north );
			$where_values[]  = max( $south, $north );
			$where_values[]  = min( $west, $east );
			$where_values[]  = max( $west, $east );
		}

		// One search definition shared by directory, viewport and management queries.
		if ( ! empty( $params['search'] ) ) {
			$term = \Tapin\ServicePointLocator\Normalization\DataNormalizer::normalize_persian_text( sanitize_text_field( $params['search'] ) );
			$search_like = '%' . $wpdb->esc_like( $term ?? '' ) . '%';
			$providers = Schema::get_providers_table();
			$where_clauses[] = "(name LIKE %s OR address LIKE %s OR code LIKE %s OR phone LIKE %s OR city LIKE %s OR province LIKE %s OR mobile_phone LIKE %s OR landline_phone LIKE %s OR provider_id IN (SELECT id FROM {$providers} WHERE name LIKE %s OR slug LIKE %s))";
			$where_values = array_merge( $where_values, array_fill( 0, 10, $search_like ) );
		}

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		// Count total matching records.
		$count_query = "SELECT COUNT(*) FROM {$table} {$where_sql}";
		if ( ! empty( $where_values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_query, $where_values ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var( $count_query );
		}

		// Whitelist sorting columns.
		// Aggregate using exactly the same predicates as the directory, without downloading rows.
		$summary = null;
		if ( ! empty( $args['include_summary'] ) ) {
			$sql = "SELECT provider_id, COUNT(*) total, COALESCE(SUM(has_coordinates = 1),0) located FROM {$table} {$where_sql} GROUP BY provider_id";
			$distribution = $wpdb->get_results( $where_values ? $wpdb->prepare( $sql, $where_values ) : $sql, ARRAY_A );
			$located = array_sum( array_column( $distribution, 'located' ) );
			$summary = array( 'total' => $total, 'located' => $located, 'missing' => $total - $located, 'distribution' => $distribution );
		}
		$allowed_orderby = array(
			'id'          => 'id',
			'name'        => 'name',
			'city'        => 'city',
			'province'    => 'province',
			'created_at'  => 'created_at',
			'updated_at'  => 'updated_at',
			'provider_id' => 'provider_id',
		);
		$orderby_col     = $allowed_orderby[ $params['orderby'] ] ?? 'id';
		$order_dir       = strtoupper( (string) $params['order'] ) === 'DESC' ? 'DESC' : 'ASC';

		// Pagination calculation.
		$page        = max( 1, (int) $params['page'] );
		$per_page    = max( 1, min( 500, (int) $params['per_page'] ) );
		$offset      = ( $page - 1 ) * $per_page;
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

		$select_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby_col} {$order_dir} LIMIT %d OFFSET %d";
		$query_args = array_merge( $where_values, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $select_sql, $query_args ), ARRAY_A );

		$items = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$items[] = $this->format_row( $row );
			}
		}

		return array(
			'summary'     => $summary,
			'items'       => $items,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Prepares record array for database persistence.
	 */
	private function prepare_record_for_storage( array $data, bool $is_insert = true ): array {
		$record = array();

		if ( isset( $data['provider_id'] ) || $is_insert ) {
			$record['provider_id'] = (int) ( $data['provider_id'] ?? 0 );
		}

		if ( array_key_exists( 'code', $data ) || $is_insert ) {
			$record['code'] = ! empty( $data['code'] ) ? sanitize_text_field( (string) $data['code'] ) : null;
		}

		if ( isset( $data['name'] ) || $is_insert ) {
			$record['name'] = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		}

		if ( isset( $data['province'] ) || $is_insert ) {
			$record['province'] = sanitize_text_field( (string) ( $data['province'] ?? '' ) );
		}

		if ( isset( $data['city'] ) || $is_insert ) {
			$record['city'] = sanitize_text_field( (string) ( $data['city'] ?? '' ) );
		}

		if ( isset( $data['address'] ) || $is_insert ) {
			$record['address'] = sanitize_textarea_field( (string) ( $data['address'] ?? '' ) );
		}

		if ( array_key_exists( 'postal_code', $data ) || $is_insert ) {
			$record['postal_code'] = ! empty( $data['postal_code'] ) ? sanitize_text_field( (string) $data['postal_code'] ) : null;
		}

		if ( array_key_exists( 'phone', $data ) || $is_insert ) {
			$record['phone'] = ! empty( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : null;
		}

		foreach ( array( 'mobile_phone', 'landline_phone', 'source' ) as $field ) {
			if ( array_key_exists( $field, $data ) || $is_insert ) { $record[$field] = sanitize_text_field( (string) ( $data[$field] ?? '' ) ); }
		}
		// Coordinates handling.
		$has_lat = isset( $data['latitude'] ) && '' !== (string) $data['latitude'] && null !== $data['latitude'];
		$has_lng = isset( $data['longitude'] ) && '' !== (string) $data['longitude'] && null !== $data['longitude'];

		if ( $has_lat && $has_lng && is_numeric( $data['latitude'] ) && is_numeric( $data['longitude'] ) && is_finite( (float) $data['latitude'] ) && is_finite( (float) $data['longitude'] ) && abs( (float) $data['latitude'] ) <= 90 && abs( (float) $data['longitude'] ) <= 180 ) {
			$record['latitude']        = (float) $data['latitude'];
			$record['longitude']       = (float) $data['longitude'];
			$record['has_coordinates'] = 1;
		} elseif ( $is_insert || array_key_exists( 'latitude', $data ) || array_key_exists( 'longitude', $data ) ) {
			$record['latitude']        = null;
			$record['longitude']       = null;
			$record['has_coordinates'] = 0;
		}

		if ( isset( $data['status'] ) || $is_insert ) {
			$status           = strtolower( (string) ( $data['status'] ?? 'active' ) );
			$record['status'] = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';
		}

		if ( array_key_exists( 'metadata', $data ) || $is_insert ) {
			$meta = $data['metadata'] ?? null;
			if ( is_array( $meta ) ) {
				$record['metadata'] = wp_json_encode( $meta );
			} elseif ( is_string( $meta ) && ! empty( $meta ) ) {
				$record['metadata'] = $meta;
			} else {
				$record['metadata'] = null;
			}
		}

		if ( $is_insert || array_key_exists( 'has_coordinates', $record ) ) {
			$record['data_quality_status'] = empty( $record['has_coordinates'] ) ? 'missing_coordinates' : 'needs_review';
		}
		return $record;
	}

	/**
	 * Formats a raw database row into clean typed array.
	 */
	private function format_row( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'provider_id'     => (int) $row['provider_id'],
			'code'            => $row['code'],
			'name'            => $row['name'],
			'province'        => $row['province'],
			'city'            => $row['city'],
			'address'         => $row['address'],
			'postal_code'     => $row['postal_code'],
			'phone'           => $row['phone'],
			'mobile_phone' => $row['mobile_phone'] ?? null,
			'landline_phone' => $row['landline_phone'] ?? null,
			'source' => $row['source'] ?? null,
			'data_quality_status' => $row['data_quality_status'] ?? 'needs_review',
			'latitude'        => null !== $row['latitude'] ? (float) $row['latitude'] : null,
			'longitude'       => null !== $row['longitude'] ? (float) $row['longitude'] : null,
			'has_coordinates' => (bool) (int) $row['has_coordinates'],
			'status'          => $row['status'],
			'metadata'        => ! empty( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : null,
			'created_at'      => $row['created_at'],
			'updated_at'      => $row['updated_at'],
		);
	}

	/**
	 * Column format mapping for wpdb queries.
	 */
	private function get_column_formats(): array {
		return array(
			'provider_id'     => '%d',
			'code'            => '%s',
			'name'            => '%s',
			'province'        => '%s',
			'city'            => '%s',
			'address'         => '%s',
			'postal_code'     => '%s',
			'phone'           => '%s',
			'latitude'        => '%f',
			'longitude'       => '%f',
			'has_coordinates' => '%d',
			'status'          => '%s',
			'metadata'        => '%s',
			'created_at'      => '%s',
			'updated_at'      => '%s',
		);
	}

	/** Candidate duplicates, never an instruction to merge records automatically. */
	public static function duplicate_clause( string $table ): string {
		return "(EXISTS (SELECT 1 FROM {$table} d WHERE d.provider_id = {$table}.provider_id AND d.id <> {$table}.id AND {$table}.code IS NOT NULL AND {$table}.code <> '' AND d.code = {$table}.code) OR EXISTS (SELECT 1 FROM {$table} d WHERE d.provider_id = {$table}.provider_id AND d.id <> {$table}.id AND d.city = {$table}.city AND d.name = {$table}.name AND d.address = {$table}.address))";
	}

	public function summary(): array {
		global $wpdb;
		$table = $this->get_table_name();
		$totals = $wpdb->get_row( "SELECT COUNT(*) total, COALESCE(SUM(has_coordinates = 1),0) located, COALESCE(SUM(has_coordinates = 0),0) missing, COALESCE(SUM(status = 'inactive'),0) inactive, COALESCE(SUM((COALESCE(phone, '') = '' AND COALESCE(mobile_phone, '') = '' AND COALESCE(landline_phone, '') = '') OR address = '' OR province = '' OR city = ''),0) incomplete FROM {$table}", ARRAY_A );
		$totals = array_map( 'intval', $totals );
		$totals['duplicate'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . self::duplicate_clause( $table ) );
		$totals['distribution'] = $wpdb->get_results( "SELECT provider_id, COUNT(*) total FROM {$table} GROUP BY provider_id", ARRAY_A );
		$totals['public_mapped'] = $this->query( array( 'public' => true, 'per_page' => 1 ) )['total'];
		$totals['public_directory'] = $this->query( array( 'public' => true, 'directory' => true, 'per_page' => 1 ) )['total'];
		return $totals;
	}

	public function locations( bool $public = false ): array {
		global $wpdb;
		$table = $this->get_table_name();
		$providers = Schema::get_providers_table();
		$where = $public ? "WHERE status = 'active' AND provider_id IN (SELECT id FROM {$providers} WHERE is_active = 1)" : '';
		$rows = $wpdb->get_results( "SELECT DISTINCT provider_id, province, city FROM {$table} {$where} ORDER BY province, city", ARRAY_A );
		$unique = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'province', 'city' ) as $field ) { $row[$field] = \Tapin\ServicePointLocator\Normalization\DataNormalizer::normalize_location( $row[$field] ); }
			$unique[wp_json_encode( $row )] = $row;
		}
		return array_values( $unique );
	}
}
