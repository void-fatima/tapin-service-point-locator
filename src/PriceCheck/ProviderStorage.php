<?php

namespace Tapin\ServicePointLocator\PriceCheck;

use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;

defined( 'ABSPATH' ) || exit;

/** storage.py provider operations. Inputs are supplied records; no seeds or normalization side effects. */
final class ProviderStorage {
	public static function list_rows(): array {
		return ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'providers' ) . ' ORDER BY id' );
	}

	public static function get_row( int $id ): ?array {
		$rows = ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'providers' ) . ' WHERE id = %d', array( $id ) );
		return $rows[0] ?? null;
	}

	public static function id_by_name( string $name ): ?int {
		$rows = ConfigurationDatabase::rows( 'SELECT id FROM ' . ConfigurationDatabase::table( 'providers' ) . ' WHERE name = %s', array( $name ) );
		return $rows[0]['id'] ?? null;
	}

	public static function param_rows( int $id ): array {
		return ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'provider_params' ) . ' WHERE provider_id = %d ORDER BY position, id', array( $id ) );
	}

	public static function field_rows( array $param_ids ): array {
		if ( ! $param_ids ) { return array(); }
		return ConfigurationDatabase::rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'provider_fields' ) . ' WHERE param_id IN (' . implode( ',', array_fill( 0, count( $param_ids ), '%d' ) ) . ') ORDER BY param_id, position, id', $param_ids );
	}

	public static function next_id(): int {
		$rows = ConfigurationDatabase::rows( 'SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM ' . ConfigurationDatabase::table( 'providers' ) );
		if ( ! isset( $rows[0]['next_id'] ) ) { throw new StorageError( 'next_provider_id' ); }
		return V::integer( $rows[0]['next_id'] );
	}

	private static function text( $value ): string { return V::trim( V::string( V::fallback( $value, '' ) ) ); }
	private static function nullable( $value ): ?string { $text = self::text( $value ); return '' === $text ? null : $text; }
	private static function target( $value ): ?string {
		$text = null === $value || is_bool( $value ) ? '' : V::trim( V::string( $value ) );
		return '' === $text ? null : $text;
	}
	private static function row_id( $value ): ?int {
		if ( null === $value || is_bool( $value ) || ( is_float( $value ) && ( ! is_finite( $value ) || floor( $value ) !== $value ) ) ) { return null; }
		try { return V::integer( $value ); } catch ( \InvalidArgumentException $error ) { return null; }
	}

	private static function default_text( $value ): ?string { return null === $value || '' === $value ? null : V::string( $value ); }

	public static function save( $record ): int {
		if ( ! V::mapping( $record ) ) { throw new \InvalidArgumentException( 'Provider must be a mapping.' ); }
		$name = self::text( V::get( $record, 'name' ) );
		if ( '' === $name ) { throw new \InvalidArgumentException( 'نام فنی شرکت الزامی است.' ); }
		$timeout = V::get( $record, 'timeout' ); $referral = V::get( $record, 'referral' );
		$location = V::get( $record, 'location_config' );
		$data = array(
			'name' => $name, 'display_name' => V::fallback( self::text( V::get( $record, 'display_name' ) ), $name ),
			'logo' => V::get( $record, 'logo' ), 'check_price_url' => V::get( $record, 'check_price_url' ), 'shop_id' => V::get( $record, 'shop_id' ),
			'price_fields_json' => ConfigurationJson::optional( V::get( $record, 'price_fields' ) ),
			'timeout' => null === $timeout || '' === $timeout ? null : V::number( $timeout ),
			'main_price_key' => self::nullable( V::get( $record, 'main_price_key' ) ),
			'price_currency_in' => 'toman' === Units::currency( V::get( $record, 'price_currency_in' ) ) ? 'toman' : null,
			'price_currency_out' => 'toman' === Units::currency( V::get( $record, 'price_currency_out' ) ) ? 'toman' : null,
			'price_input_param' => self::target( V::get( $record, 'price_input_param' ) ),
			'weight_unit_in' => 'kilogram' === Units::weight( V::get( $record, 'weight_unit_in' ) ) ? 'kilogram' : null,
			'weight_unit_out' => 'kilogram' === Units::weight( V::get( $record, 'weight_unit_out' ) ) ? 'kilogram' : null,
			'weight_input_param' => self::target( V::get( $record, 'weight_input_param' ) ),
			'referral_enabled' => V::truth( V::get( $referral, 'enabled' ) ) ? 1 : 0,
			'referral_title' => self::nullable( V::get( $referral, 'title' ) ), 'referral_url' => self::nullable( V::get( $referral, 'url' ) ),
			'referral_description' => self::nullable( V::get( $referral, 'description' ) ),
			'rules_json' => ConfigurationJson::optional( V::get( $record, 'rules' ) ),
			'location_config_json' => V::mapping( $location ) ? ConfigurationJson::optional( $location ) : null,
			'active' => false === V::get( $record, 'active' ) ? 0 : 1,
		);
		$id = V::get( $record, 'id' );
		if ( null === $id || '' === $id ) {
			$id = self::next_id(); ConfigurationDatabase::insert( 'providers', array( 'id' => $id ) + $data );
		} else {
			$id = V::integer( $id );
			// Preserve the source rowcount fallback, including its unchanged-update duplicate-key failure.
			if ( 0 === ConfigurationDatabase::update( 'providers', $data, array( 'id' => $id ) ) ) { ConfigurationDatabase::insert( 'providers', array( 'id' => $id ) + $data ); }
		}
		$owned = array_column( self::param_rows( $id ), 'id' ); $owners = array();
		foreach ( self::field_rows( $owned ) as $field ) { $owners[$field['id']] = $field['param_id']; }
		$kept = $used = array();
		foreach ( V::fallback( V::get( $record, 'params' ), array() ) as $position => $param ) {
			$param_name = self::text( V::get( $param, 'name' ) ); if ( '' === $param_name ) { continue; }
			$input = V::string( V::fallback( V::get( $param, 'input' ), 'text' ) ); $map = V::get( $param, 'map' );
			$values = array(
				'position' => $position, 'name' => $param_name, 'label' => V::fallback( self::text( V::get( $param, 'label' ) ), $param_name ),
				'input' => $input, 'type' => V::string( V::fallback( V::get( $param, 'type' ), 'str' ) ), 'visible' => V::truth( V::get( $param, 'visible' ) ) ? 1 : 0,
				'default_value' => in_array( $input, array( 'list', 'dict' ), true ) ? null : self::default_text( V::get( $param, 'default' ) ),
				'min_items' => V::get( $param, 'min_items' ), 'options_json' => ConfigurationJson::optional( V::get( $param, 'options' ) ),
				'required' => V::truth( V::get( $param, 'required' ) ) ? 1 : 0, 'kind' => self::nullable( V::get( $param, 'kind' ) ),
				'description' => self::nullable( V::get( $param, 'description' ) ), 'map_json' => V::mapping( $map ) ? ConfigurationJson::optional( $map ) : null,
			);
			$pid = self::row_id( V::get( $param, 'id' ) );
			if ( null === $pid || ! in_array( $pid, $owned, true ) || in_array( $pid, $used, true ) ) {
				ConfigurationDatabase::insert( 'provider_params', array( 'provider_id' => $id ) + $values ); $pid = ConfigurationDatabase::insert_id();
			} else { $used[] = $pid; ConfigurationDatabase::update( 'provider_params', $values, array( 'id' => $pid, 'provider_id' => $id ) ); }
			$kept[] = $pid; $kept_fields = $used_fields = array();
			foreach ( V::fallback( V::get( $param, 'fields' ), array() ) as $field_position => $field ) {
				$field_name = self::text( V::get( $field, 'name' ) ); if ( '' === $field_name ) { continue; }
				$field_values = array(
					'position' => $field_position, 'name' => $field_name, 'label' => V::fallback( self::text( V::get( $field, 'label' ) ), $field_name ),
					'input' => V::string( V::fallback( V::get( $field, 'input' ), 'text' ) ), 'type' => V::string( V::fallback( V::get( $field, 'type' ), 'str' ) ),
					'visible' => V::truth( V::get( $field, 'visible' ) ) ? 1 : 0, 'default_value' => self::default_text( V::get( $field, 'default' ) ),
					'kind' => self::nullable( V::get( $field, 'kind' ) ), 'options_json' => ConfigurationJson::optional( V::get( $field, 'options' ) ),
					'required' => V::truth( V::get( $field, 'required' ) ) ? 1 : 0,
				);
				$fid = self::row_id( V::get( $field, 'id' ) );
				if ( null === $fid || ( $owners[$fid] ?? null ) !== $pid || in_array( $fid, $used_fields, true ) ) {
					ConfigurationDatabase::insert( 'provider_fields', array( 'param_id' => $pid ) + $field_values ); $fid = ConfigurationDatabase::insert_id();
				} else { $used_fields[] = $fid; ConfigurationDatabase::update( 'provider_fields', $field_values, array( 'id' => $fid, 'param_id' => $pid ) ); }
				$kept_fields[] = $fid;
			}
			self::remove_missing( 'provider_fields', 'param_id', $pid, $kept_fields );
		}
		self::remove_missing( 'provider_params', 'provider_id', $id, $kept );
		return $id;
	}

	private static function remove_missing( string $table, string $owner, int $id, array $kept ): void {
		$sql = 'DELETE FROM ' . ConfigurationDatabase::table( $table ) . " WHERE {$owner} = %d";
		if ( $kept ) { $sql .= ' AND id NOT IN (' . implode( ',', array_fill( 0, count( $kept ), '%d' ) ) . ')'; }
		ConfigurationDatabase::query( $sql, array_merge( array( $id ), $kept ) );
	}

	/** Like storage.delete_provider: pruning bulk references is a separate operation. */
	public static function delete( int $id ): void { ConfigurationDatabase::delete( 'providers', array( 'id' => $id ) ); }

	public static function load( $identifier ): ?\stdClass {
		$key = V::trim( V::string( $identifier ) );
		$id = V::digit_string( $key ) ? V::integer( $key ) : self::id_by_name( $key );
		$row = null === $id ? null : self::get_row( $id );
		return null === $row ? null : self::decode( $row );
	}

	public static function all(): array { return array_map( array( self::class, 'decode' ), self::list_rows() ); }

	private static function decode( array $row ): \stdClass {
		$params = self::param_rows( $row['id'] ); $fields = self::field_rows( array_column( $params, 'id' ) );
		foreach ( $params as &$param ) { $param['field_rows'] = array_values( array_filter( $fields, static fn( $field ) => $field['param_id'] === $param['id'] ) ); }
		unset( $param );
		return StoredProvider::decode( $row, $params );
	}
}
