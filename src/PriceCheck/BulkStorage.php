<?php

namespace Tapin\ServicePointLocator\PriceCheck;

use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;

defined( 'ABSPATH' ) || exit;

/** storage.py bulk singleton and reference pruning. No validation, quotes or provider fan-out. */
final class BulkStorage {
	/** Preserve the source's unsaved shape, including the absent weight/value_mappings keys. */
	public static function empty_config(): \stdClass {
		return V::object( array(
			'providers' => array(), 'fields' => array(), 'mappings' => V::object(), 'variants' => V::object(),
			'location' => V::object( array(
				'origin' => V::object( array( 'enabled' => true, 'display' => 'combined' ) ),
				'destination' => V::object( array( 'enabled' => true, 'display' => 'combined', 'province_field' => null, 'city_field' => null ) ),
			) ),
			'price' => V::object( array( 'field' => null, 'unit' => 'rial' ) ),
		) );
	}

	public static function get(): \stdClass {
		$rows = ConfigurationDatabase::rows( 'SELECT data_json FROM ' . ConfigurationDatabase::table( 'bulk_config' ) . ' WHERE id = 1' );
		if ( ! $rows ) { return self::empty_config(); }
		try { $data = ConfigurationJson::decode( $rows[0]['data_json'] ); }
		catch ( \InvalidArgumentException $error ) { return self::empty_config(); }
		return V::mapping( $data ) ? V::object( (array) $data ) : self::empty_config();
	}

	/** Replace the singleton's JSON verbatim in shape; strict draft normalization belongs to callers. */
	public static function save( $config ): void {
		if ( ! V::mapping( $config ) ) { throw new \InvalidArgumentException( 'Bulk configuration must be a mapping.' ); }
		ConfigurationDatabase::upsert( 'bulk_config', array( 'id' => 1, 'data_json' => ConfigurationJson::encode( V::object( (array) $config ) ) ), array( 'data_json' ) );
	}

	private static function is_ref( $value, int $id, ?string $old ): bool {
		if ( null === $value || is_bool( $value ) ) { return false; }
		if ( is_int( $value ) ) { return $value === $id; }
		if ( is_string( $value ) ) {
			$text = V::trim( $value );
			if ( V::digit_string( $text ) ) { try { return V::integer( $text ) === $id; } catch ( \InvalidArgumentException $error ) { return false; } }
			return null !== $old && $text === $old;
		}
		return false;
	}

	/** Provider deletion remains separate, matching the source storage API's two operations. */
	public static function prune_provider( $provider_id, $legacy_name = null ): bool {
		try { $id = V::integer( $provider_id ); } catch ( \InvalidArgumentException $error ) { return false; }
		$old = V::trim( V::string( V::fallback( $legacy_name, '' ) ) ); $old = '' === $old ? null : $old;
		$config = self::get(); $changed = false;
		$selected = V::get( $config, 'providers' );
		if ( V::listing( $selected ) ) {
			$clean = array_values( array_filter( $selected, static fn( $ref ) => ! self::is_ref( $ref, $id, $old ) ) );
			if ( count( $clean ) !== count( $selected ) ) { $config->providers = $clean; $changed = true; }
		}
		foreach ( array( 'mappings', 'value_mappings', 'variants' ) as $key ) {
			$mapping = V::get( $config, $key ); if ( ! V::mapping( $mapping ) ) { continue; }
			foreach ( array_keys( (array) $mapping ) as $ref ) {
				// JSON dictionary keys are strings, even when PHP casts numeric array keys to ints.
				if ( self::is_ref( (string) $ref, $id, $old ) ) { unset( $mapping->{(string) $ref} ); $changed = true; }
			}
		}
		$fields = V::get( $config, 'fields' );
		foreach ( V::listing( $fields ) ? $fields : array() as $field ) {
			if ( ! V::mapping( $field ) ) { continue; }
			foreach ( array( 'source', 'mirror' ) as $slot ) {
				$reference = V::get( $field, $slot );
				if ( V::mapping( $reference ) && self::is_ref( V::get( $reference, 'provider' ), $id, $old ) ) { $field->$slot = null; $changed = true; }
			}
		}
		if ( $changed ) { self::save( $config ); }
		return $changed;
	}
}
