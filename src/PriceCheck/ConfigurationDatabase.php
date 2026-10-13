<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Checked wpdb operations, restricted to tables already owned by this module. */
final class ConfigurationDatabase {
	public static function table( string $suffix ): string {
		if ( ! in_array( $suffix, array( 'providers', 'provider_params', 'provider_fields', 'shops', 'bulk_config', 'cache_settings', 'quote_logs', 'price_cache' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown price-check configuration table.' );
		}
		return self::identifier( Schema::tables()[$suffix] );
	}

	private static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function run( string $operation, callable $callback ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			$result = $callback( $wpdb );
			if ( false === $result || '' !== (string) $wpdb->last_error ) { throw new StorageError( $operation ); }
			return $result;
		} finally { $wpdb->suppress_errors( $previous ); }
	}

	private static function prepare( $database, string $sql, array $values ): string {
		$prepared = $values ? $database->prepare( $sql, ...$values ) : $sql;
		if ( ! is_string( $prepared ) || '' === $prepared ) { throw new StorageError( 'prepare' ); }
		return $prepared;
	}

	public static function rows( string $sql, array $values = array() ): array {
		$rows = self::run( 'read', static fn( $db ) => $db->get_results( self::prepare( $db, $sql, $values ), ARRAY_A ) );
		if ( ! is_array( $rows ) ) { throw new StorageError( 'read' ); }
		return array_map( array( self::class, 'row_types' ), $rows );
	}

	/** Shops have varchar IDs and must not use provider-row integer casts. */
	public static function shop_rows( string $sql, array $values = array() ): array {
		$rows = self::run( 'read', static fn( $db ) => $db->get_results( self::prepare( $db, $sql, $values ), ARRAY_A ) );
		if ( ! is_array( $rows ) ) { throw new StorageError( 'read' ); }
		foreach ( $rows as &$row ) { if ( isset( $row['has_token'] ) ) { $row['has_token'] = (int) $row['has_token']; } }
		unset( $row );
		return $rows;
	}

	/** wpdb returns numeric columns as strings; PyMySQL exposes their database types. */
	private static function row_types( array $row ): array {
		foreach ( array( 'id', 'provider_id', 'param_id', 'position', 'min_items', 'visible', 'required', 'active', 'referral_enabled', 'has_token' ) as $key ) {
			if ( array_key_exists( $key, $row ) && null !== $row[$key] ) { $row[$key] = PythonValue::integer( $row[$key] ); }
		}
		if ( isset( $row['timeout'] ) ) { $row['timeout'] = (float) $row['timeout']; }
		return $row;
	}

	public static function query( string $sql, array $values = array() ): int {
		return (int) self::run( 'write', static fn( $db ) => $db->query( self::prepare( $db, $sql, $values ) ) );
	}

	private static function values( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( ! preg_match( '/^[a-z_]+$/D', $key ) ) { throw new \InvalidArgumentException( 'Invalid configuration column.' ); }
			if ( is_float( $value ) ) {
				if ( ! is_finite( $value ) ) { throw new \DomainException( 'Non-finite database numbers need an explicit compatibility decision.' ); }
				$data[$key] = PythonValue::string( $value );
			} elseif ( is_bool( $value ) ) { $data[$key] = $value ? 1 : 0; }
		}
		return $data;
	}

	private static function formats( array $data ): array {
		return array_map( static fn( $value ) => is_int( $value ) ? '%d' : '%s', array_values( $data ) );
	}

	public static function insert( string $suffix, array $data ): void {
		$data = self::values( $data );
		self::table( $suffix ); $table = Schema::tables()[$suffix];
		$result = self::run( 'insert', static fn( $db ) => $db->insert( $table, $data, self::formats( $data ) ) );
		if ( 1 !== (int) $result ) { throw new StorageError( 'insert' ); }
	}

	public static function insert_id(): int {
		global $wpdb;
		$id = PythonValue::integer( $wpdb->insert_id );
		if ( $id <= 0 ) { throw new StorageError( 'insert_id' ); }
		return $id;
	}

	public static function update( string $suffix, array $data, array $where ): int {
		$data = self::values( $data ); $where = self::values( $where );
		self::table( $suffix ); $table = Schema::tables()[$suffix];
		return (int) self::run( 'update', static fn( $db ) => $db->update( $table, $data, $where, self::formats( $data ), self::formats( $where ) ) );
	}

	public static function delete( string $suffix, array $where ): int {
		$where = self::values( $where ); self::table( $suffix ); $table = Schema::tables()[$suffix];
		return (int) self::run( 'delete', static fn( $db ) => $db->delete( $table, $where, self::formats( $where ) ) );
	}

	/** Preserve IDs on conflict; REPLACE would delete and reinsert the row. */
	public static function upsert( string $suffix, array $data, array $update_columns ): void {
		$data = self::values( $data ); $columns = $slots = $values = array();
		foreach ( $data as $column => $value ) {
			$columns[] = self::identifier( $column );
			$slots[] = null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' );
			if ( null !== $value ) { $values[] = $value; }
		}
		$updates = array();
		foreach ( $update_columns as $column ) {
			if ( ! array_key_exists( $column, $data ) ) { throw new \InvalidArgumentException( 'Unknown upsert column.' ); }
			$key = self::identifier( $column ); $updates[] = "{$key} = VALUES({$key})";
		}
		self::query( 'INSERT INTO ' . self::table( $suffix ) . ' (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $slots ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ), $values );
	}

}
