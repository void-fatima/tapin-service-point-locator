<?php

namespace Tapin\ServicePointLocator\Repository;

use Tapin\ServicePointLocator\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Repository for provider entity database operations.
 */
class ProviderRepository {

	/**
	 * Returns the table name.
	 */
	public function get_table_name(): string {
		return Schema::get_providers_table();
	}

	/**
	 * Retrieves a provider by primary ID.
	 */
	public function get_by_id( int $id ): ?array {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Retrieves a provider by unique slug (e.g. 'post', 'tipax').
	 */
	public function get_by_slug( string $slug ): ?array {
		global $wpdb;

		$table = $this->get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", sanitize_key( $slug ) ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Retrieves all providers.
	 *
	 * @param bool $only_active Whether to filter only active providers.
	 * @return array<int, array>
	 */
	public function get_all( bool $only_active = true ): array {
		global $wpdb;

		$table = $this->get_table_name();
		$where = $only_active ? 'WHERE is_active = 1' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY id ASC", ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Inserts a new provider.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$insert_data = array(
			'slug'       => sanitize_key( $data['slug'] ?? '' ),
			'name'       => sanitize_text_field( $data['name'] ?? '' ),
			'is_active'  => ! empty( $data['is_active'] ) ? 1 : 0,
			'created_at' => $now,
			'updated_at' => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->insert(
			$this->get_table_name(),
			$insert_data,
			array( '%s', '%s', '%d', '%s', '%s' )
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates an existing provider.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$update_data = array(
			'updated_at' => current_time( 'mysql', true ),
		);
		$formats     = array( '%s' );

		if ( isset( $data['name'] ) ) {
			$update_data['name'] = sanitize_text_field( $data['name'] );
			$formats[]           = '%s';
		}

		if ( isset( $data['slug'] ) ) {
			$update_data['slug'] = sanitize_key( $data['slug'] );
			$formats[]           = '%s';
		}

		if ( isset( $data['is_active'] ) ) {
			$update_data['is_active'] = ! empty( $data['is_active'] ) ? 1 : 0;
			$formats[]                = '%d';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$this->get_table_name(),
			$update_data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Deletes a provider by ID.
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
}
