<?php

namespace Tapin\ServicePointLocator\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Manages database table creation, schema updates, and migrations.
 */
final class Schema {

	const DB_VERSION_OPTION = 'tapin_db_version';

	/**
	 * Returns the table name for service points.
	 */
	public static function get_service_points_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tapin_service_points';
	}

	/**
	 * Returns the table name for providers.
	 */
	public static function get_providers_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tapin_providers';
	}

	/**
	 * Runs idempotent database migrations using dbDelta.
	 */
	public static function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate      = $wpdb->get_charset_collate();
		$providers_table      = self::get_providers_table();
		$service_points_table = self::get_service_points_table();

		// Schema definitions adhering strictly to dbDelta formatting requirements:
		// 1. Two spaces after PRIMARY KEY
		// 2. KEY instead of INDEX
		// 3. One column per line
		$sql = "CREATE TABLE {$providers_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(50) NOT NULL,
  name varchar(100) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY is_active (is_active)
) {$charset_collate};

CREATE TABLE {$service_points_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  provider_id bigint(20) unsigned NOT NULL,
  code varchar(64) DEFAULT NULL,
  name varchar(255) NOT NULL,
  province varchar(100) NOT NULL,
  city varchar(100) NOT NULL,
  address text NOT NULL,
  postal_code varchar(20) DEFAULT NULL,
  phone varchar(64) DEFAULT NULL,
  latitude decimal(10,8) DEFAULT NULL,
  longitude decimal(11,8) DEFAULT NULL,
  has_coordinates tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  metadata longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY provider_id (provider_id),
  KEY province_city (province, city),
  KEY status (status),
  KEY coords_status (has_coordinates, status),
  KEY lat_lng (latitude, longitude),
  KEY code_provider (provider_id, code)
) {$charset_collate};";

		dbDelta( $sql );

		self::seed_default_providers();

		update_option( self::DB_VERSION_OPTION, TAPIN_DB_VERSION );
	}

	/**
	 * Seeds baseline providers (Post, Tipax) if they do not exist.
	 */
	public static function seed_default_providers(): void {
		global $wpdb;

		$table = self::get_providers_table();

		// Check if providers already exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		$defaults = array(
			array(
				'slug'       => 'post',
				'name'       => 'شرکت ملی پست',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array(
				'slug'       => 'tipax',
				'name'       => 'تیپاکس',
				'is_active'  => 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
		);

		foreach ( $defaults as $provider ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				$provider,
				array( '%s', '%s', '%d', '%s', '%s' )
			);
		}
	}
}
