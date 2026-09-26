<?php

namespace Tapin\ServicePointLocator\Repository;

use Tapin\ServicePointLocator\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for service point database operations.
 */
class ServicePointRepository {

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
			$this->get_column_formats()
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
			$where_clauses[] = 'province = %s';
			$where_values[]  = sanitize_text_field( $params['province'] );
		}

		// City filter.
		if ( ! empty( $params['city'] ) ) {
			$where_clauses[] = 'city = %s';
			$where_values[]  = sanitize_text_field( $params['city'] );
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

		// Free text search across branch name, address, code, and phone.
		if ( ! empty( $params['search'] ) ) {
			$search_like     = '%' . $wpdb->esc_like( sanitize_text_field( $params['search'] ) ) . '%';
			$where_clauses[] = '(name LIKE %s OR address LIKE %s OR code LIKE %s OR phone LIKE %s)';
			$where_values[]  = $search_like;
			$where_values[]  = $search_like;
			$where_values[]  = $search_like;
			$where_values[]  = $search_like;
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

		if ( isset( $data['code'] ) || $is_insert ) {
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

		if ( isset( $data['postal_code'] ) || $is_insert ) {
			$record['postal_code'] = ! empty( $data['postal_code'] ) ? sanitize_text_field( (string) $data['postal_code'] ) : null;
		}

		if ( isset( $data['phone'] ) || $is_insert ) {
			$record['phone'] = ! empty( $data['phone'] ) ? sanitize_text_field( (string) $data['phone'] ) : null;
		}

		// Coordinates handling.
		$has_lat = isset( $data['latitude'] ) && '' !== (string) $data['latitude'] && null !== $data['latitude'];
		$has_lng = isset( $data['longitude'] ) && '' !== (string) $data['longitude'] && null !== $data['longitude'];

		if ( $has_lat && $has_lng ) {
			$record['latitude']        = (float) $data['latitude'];
			$record['longitude']       = (float) $data['longitude'];
			$record['has_coordinates'] = 1;
		} elseif ( $is_insert || isset( $data['latitude'] ) || isset( $data['longitude'] ) ) {
			$record['latitude']        = null;
			$record['longitude']       = null;
			$record['has_coordinates'] = 0;
		}

		if ( isset( $data['status'] ) || $is_insert ) {
			$status           = strtolower( (string) ( $data['status'] ?? 'active' ) );
			$record['status'] = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';
		}

		if ( isset( $data['metadata'] ) || $is_insert ) {
			$meta = $data['metadata'] ?? null;
			if ( is_array( $meta ) ) {
				$record['metadata'] = wp_json_encode( $meta );
			} elseif ( is_string( $meta ) && ! empty( $meta ) ) {
				$record['metadata'] = $meta;
			} else {
				$record['metadata'] = null;
			}
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
}
