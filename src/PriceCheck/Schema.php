<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Python storage/security DDL adapted to dbDelta; no data or settings seeds. */
final class Schema {

	const VERSION = 1;
	const DB_VERSION_OPTION = 'tapin_price_db_version';

	public static function tables(): array {
		global $wpdb;
		$tables = array();
		foreach ( array_keys( self::definitions() ) as $suffix ) {
			$tables[$suffix] = $wpdb->prefix . 'tapin_price_' . $suffix;
		}
		return $tables;
	}

	/** Skip current and newer schemas; failed upgrades remain eligible for retry. */
	public static function maybe_migrate(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < self::VERSION ) {
			self::migrate();
		}
	}

	public static function migrate(): bool {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) > self::VERSION ) { return false; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = self::tables();
		$charset_collate = $wpdb->get_charset_collate();
		foreach ( self::definitions() as $suffix => $definition ) {
			dbDelta( "CREATE TABLE {$tables[$suffix]} (\n{$definition}\n) ENGINE=InnoDB {$charset_collate};" );
			// Check every column/index, not only a sample, before marking the schema current.
			if ( ! self::verify_table( $tables[$suffix], $definition ) ) { return false; }
		}
		// dbDelta does not manage foreign keys; add and verify them separately.
		foreach ( self::relationships() as $child => $relation ) {
			if ( ! self::ensure_relationship( $tables[$child], $relation[0], $tables[$relation[1]] ) ) { return false; }
		}
		update_option( self::DB_VERSION_OPTION, self::VERSION, false );
		return (int) get_option( self::DB_VERSION_OPTION ) === self::VERSION;
	}

	private static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function verify_table( string $table, string $definition ): bool {
		global $wpdb;
		$column_rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::identifier( $table ), ARRAY_A );
		$index_rows = $wpdb->get_results( 'SHOW INDEX FROM ' . self::identifier( $table ), ARRAY_A );
		if ( ! $column_rows || ! $index_rows ) { return false; }
		$columns = array_column( $column_rows, null, 'Field' );
		$indexes = array();
		foreach ( $index_rows as $row ) {
			$indexes[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
			$indexes[$row['Key_name']]['unique'] = 0 === (int) $row['Non_unique'];
		}
		foreach ( explode( "\n", $definition ) as $line ) {
			$line = trim( $line, " \t," );
			if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:(\w+)\s+)?\(([^)]+)\)$/', $line, $match ) ) {
				$name = 'PRIMARY KEY' === $match[1] ? 'PRIMARY' : $match[2];
				$expected = array_map( 'trim', explode( ',', $match[3] ) );
				$actual = $indexes[$name] ?? array();
				if ( empty( $actual['columns'] ) ) { return false; }
				ksort( $actual['columns'] );
				if ( $expected !== array_values( $actual['columns'] ) || ( 'KEY' !== $match[1] ) !== $actual['unique'] ) { return false; }
			} else {
				if ( ! preg_match( '/^(\w+)\s+(\w+(?:\(\d+\))?)(.*)$/', $line, $match ) ) { return false; }
				$column = $columns[$match[1]] ?? null;
				// MySQL 8 omits integer display widths; MariaDB/older MySQL may include them.
				$type = $column ? preg_replace( '/\b(bigint|int|tinyint)\(\d+\)/', '$1', strtolower( $column['Type'] ) ) : '';
				$nullable = false === strpos( $match[3], 'NOT NULL' ) ? 'YES' : 'NO';
				if ( ! $column || $match[2] !== $type || $nullable !== $column['Null'] ) { return false; }
				$default = null;
				if ( preg_match( "/DEFAULT ('[^']*'|[0-9]+)/", $match[3], $value ) ) { $default = trim( $value[1], "'" ); }
				$actual_default = null === $column['Default'] ? null : (string) $column['Default'];
				if ( $default !== $actual_default || ( false !== strpos( $match[3], 'AUTO_INCREMENT' ) ) !== ( false !== strpos( strtolower( $column['Extra'] ), 'auto_increment' ) ) ) { return false; }
			}
		}
		return true;
	}

	private static function relationships(): array {
		return array(
			'provider_params' => array( 'provider_id', 'providers' ),
			'provider_fields' => array( 'param_id', 'provider_params' ),
			'price_cache'     => array( 'provider_id', 'providers' ),
		);
	}

	private static function ensure_relationship( string $child, string $column, string $parent ): bool {
		global $wpdb;
		// Prefix-derived names avoid schema-wide constraint collisions, including test tables.
		$name = 'tapin_price_fk_' . substr( hash( 'sha256', $child . ':' . $column ), 0, 32 );
		$query = $wpdb->prepare(
			'SELECT k.REFERENCED_TABLE_NAME AS parent_table, k.REFERENCED_COLUMN_NAME AS parent_column, r.DELETE_RULE AS delete_rule
			FROM information_schema.KEY_COLUMN_USAGE k
			JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
			WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = %s AND k.COLUMN_NAME = %s AND k.REFERENCED_TABLE_NAME IS NOT NULL',
			$child, $column
		);
		$relationship = $wpdb->get_row( $query, ARRAY_A );
		if ( ! $relationship ) {
			$sql = 'ALTER TABLE ' . self::identifier( $child ) . ' ADD CONSTRAINT ' . self::identifier( $name ) . ' FOREIGN KEY (' . self::identifier( $column ) . ') REFERENCES ' . self::identifier( $parent ) . ' (`id`) ON DELETE CASCADE';
			if ( false === $wpdb->query( $sql ) ) { return false; }
			$relationship = $wpdb->get_row( $query, ARRAY_A );
		}
		return $relationship && $parent === $relationship['parent_table'] && 'id' === $relationship['parent_column'] && 'CASCADE' === $relationship['delete_rule'];
	}

	/** Keep column/index declarations aligned with storage.py and security/infra_mysql.py. */
	public static function definitions(): array {
		return array(
			'providers' => "id bigint NOT NULL,
name varchar(191) NOT NULL,
display_name varchar(255) NOT NULL,
logo mediumtext,
check_price_url text,
shop_id varchar(64),
price_fields_json text,
timeout double,
main_price_key varchar(128),
price_currency_in varchar(16),
price_currency_out varchar(16),
price_input_param varchar(64),
weight_unit_in varchar(16),
weight_unit_out varchar(16),
weight_input_param varchar(64),
referral_enabled tinyint NOT NULL DEFAULT 0,
referral_title varchar(255),
referral_url text,
referral_description text,
rules_json text,
location_config_json text,
active tinyint NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
UNIQUE KEY provider_name (name)",
			'provider_params' => "id bigint NOT NULL AUTO_INCREMENT,
provider_id bigint NOT NULL,
position int NOT NULL DEFAULT 0,
name varchar(191) NOT NULL,
label varchar(255) NOT NULL DEFAULT '',
input varchar(32) NOT NULL DEFAULT 'text',
type varchar(32) NOT NULL DEFAULT 'str',
visible tinyint NOT NULL DEFAULT 0,
default_value text,
min_items int,
kind varchar(32),
options_json text,
required tinyint NOT NULL DEFAULT 0,
description text,
map_json text,
PRIMARY KEY  (id),
KEY provider_position (provider_id,position)",
			'provider_fields' => "id bigint NOT NULL AUTO_INCREMENT,
param_id bigint NOT NULL,
position int NOT NULL DEFAULT 0,
name varchar(191) NOT NULL,
label varchar(255) NOT NULL DEFAULT '',
input varchar(32) NOT NULL DEFAULT 'text',
type varchar(32) NOT NULL DEFAULT 'str',
visible tinyint NOT NULL DEFAULT 0,
default_value text,
kind varchar(32),
options_json text,
required tinyint NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY param_position (param_id,position)",
			'shops' => "id varchar(64) NOT NULL,
title varchar(255) NOT NULL,
province varchar(128),
city varchar(128),
token text,
PRIMARY KEY  (id),
KEY province (province),
KEY city (city)",
			'bulk_config' => "id bigint NOT NULL,
data_json mediumtext NOT NULL,
PRIMARY KEY  (id)",
			'security_settings' => "id bigint NOT NULL,
data_json mediumtext NOT NULL,
PRIMARY KEY  (id)",
			'cache_settings' => "id bigint NOT NULL,
data_json mediumtext NOT NULL,
PRIMARY KEY  (id)",
			'route_limits' => "route varchar(191) NOT NULL,
max_requests int NOT NULL,
window_seconds int NOT NULL,
enabled tinyint NOT NULL DEFAULT 1,
updated_at_ms bigint NOT NULL DEFAULT 0,
PRIMARY KEY  (route)",
			'quote_logs' => "id bigint NOT NULL AUTO_INCREMENT,
created_at varchar(40) NOT NULL,
provider_id bigint,
provider_name varchar(255) NOT NULL DEFAULT '',
display_name varchar(255) NOT NULL DEFAULT '',
endpoint text NOT NULL,
status varchar(32) NOT NULL DEFAULT 'error',
error_message text,
payload_json mediumtext NOT NULL,
response_json mediumtext,
PRIMARY KEY  (id),
KEY created_at (created_at),
KEY provider_id (provider_id)",
			'price_cache' => "id bigint NOT NULL AUTO_INCREMENT,
created_at varchar(40) NOT NULL,
provider_id bigint NOT NULL,
provider_name varchar(255) NOT NULL DEFAULT '',
params_hash varchar(191) NOT NULL DEFAULT '',
params_json mediumtext NOT NULL,
endpoint text NOT NULL,
quote_json mediumtext NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY provider_hash (provider_id,params_hash),
KEY created_at (created_at)",
			'blocked_clients' => "id bigint NOT NULL AUTO_INCREMENT,
type varchar(32) NOT NULL,
value varchar(255) NOT NULL,
reason text NOT NULL,
created_at_ms bigint NOT NULL,
expires_at_ms bigint NULL,
created_by varchar(128) NOT NULL DEFAULT '',
is_active tinyint NOT NULL DEFAULT 1,
PRIMARY KEY  (id),
KEY blocked_lookup (type,value,is_active)",
			'security_events' => "id bigint NOT NULL AUTO_INCREMENT,
created_at_ms bigint NOT NULL,
kind varchar(64) NOT NULL,
client_fingerprint varchar(64) NOT NULL DEFAULT '',
client_id varchar(64) NOT NULL DEFAULT '',
ip varchar(64) NOT NULL DEFAULT '',
route varchar(255) NOT NULL DEFAULT '',
detail mediumtext,
PRIMARY KEY  (id),
KEY kind_id (kind,id),
KEY identity (ip,client_id,client_fingerprint)",
			'clients' => "client_id varchar(64) NOT NULL,
first_seen_ms bigint NOT NULL,
PRIMARY KEY  (client_id)",
			'security_allowlist' => "id bigint NOT NULL AUTO_INCREMENT,
ip varchar(64) NOT NULL,
reason text NOT NULL,
created_at_ms bigint NOT NULL,
created_by varchar(128) NOT NULL DEFAULT '',
PRIMARY KEY  (id),
UNIQUE KEY allowed_ip (ip)",
		);
	}
}
