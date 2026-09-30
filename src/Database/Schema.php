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
  mobile_phone varchar(64) DEFAULT NULL,
  landline_phone varchar(64) DEFAULT NULL,
  source varchar(500) DEFAULT NULL,
  data_quality_status varchar(32) NOT NULL DEFAULT 'missing_coordinates',
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
  KEY province_map (province, has_coordinates, status, provider_id),
  KEY coords_status (has_coordinates, status),
  KEY lat_lng (latitude, longitude),
  KEY code_provider (provider_id, code),
  KEY provider_city_name (provider_id, city, name(100)),
  KEY provider_city_phone (provider_id, city, phone)
) {$charset_collate};";

		dbDelta( $sql );
		$geocoding = $wpdb->prefix . 'tapin_geocoding_jobs';
		dbDelta( "CREATE TABLE {$geocoding} (
  point_id bigint(20) unsigned NOT NULL,
  query_hash varchar(64) NOT NULL,
  provider varchar(64) NOT NULL,
  status varchar(20) NOT NULL,
  attempts int unsigned NOT NULL DEFAULT 0,
  next_attempt datetime NOT NULL,
  last_code varchar(64) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (point_id),
  KEY status_due (status, next_attempt)
) ENGINE=InnoDB {$charset_collate};" );
		$imports = $wpdb->prefix . 'tapin_imports';
		dbDelta( "CREATE TABLE {$imports} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  filename varchar(255) NOT NULL,
  status varchar(20) NOT NULL,
  data longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status_updated (status, updated_at)
) ENGINE=InnoDB {$charset_collate};" );

		// Backfill existing rows without inventing coordinates or contact types.
		$wpdb->query( "UPDATE {$service_points_table} SET data_quality_status = CASE WHEN has_coordinates = 0 THEN 'missing_coordinates' ELSE 'needs_review' END WHERE data_quality_status = 'missing_coordinates' AND has_coordinates = 1" );
		$logs = $wpdb->prefix . 'tapin_logs';
		dbDelta( "CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(40) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  context longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY event_created (event, created_at)
) ENGINE=InnoDB {$charset_collate};" );
		self::seed_default_providers();

		// Do not mark a failed/partial migration current: let the next request retry.
		foreach ( array( $service_points_table => array( 'mobile_phone', 'landline_phone', 'source', 'data_quality_status' ), $logs => array( 'event', 'context', 'created_at' ), $geocoding => array( 'point_id', 'query_hash', 'provider', 'status', 'attempts', 'next_attempt', 'last_code', 'updated_at' ) ) as $table => $required ) {
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
			if ( array_diff( $required, $columns ?: array() ) ) { return; }
		}
		if ( (int) get_option( self::DB_VERSION_OPTION ) < 7 && ! self::repair_tipax_provider( $providers_table, $service_points_table ) ) { return; }
		update_option( self::DB_VERSION_OPTION, TAPIN_DB_VERSION );
	}

	/** Correct imports assigned to Post when their source identifies Tipax. */
	private static function repair_tipax_provider( string $providers_table, string $points_table ): bool {
		global $wpdb;
		$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$providers_table} WHERE slug = %s", 'post' ) );
		$tipax_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$providers_table} WHERE slug = %s", 'tipax' ) );
		if ( ! $post_id || ! $tipax_id ) { return true; }
		$patterns = array( 'https://tipaxco.com/%', 'https://www.tipaxco.com/%', 'http://tipaxco.com/%', 'http://www.tipaxco.com/%' );
		$sql = $wpdb->prepare(
			"UPDATE {$points_table} SET provider_id = %d, updated_at = %s WHERE provider_id = %d AND (source LIKE %s OR source LIKE %s OR source LIKE %s OR source LIKE %s)",
			$tipax_id, current_time( 'mysql', true ), $post_id, ...$patterns
		);
		return false !== $wpdb->query( $sql );
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
