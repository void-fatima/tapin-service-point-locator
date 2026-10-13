<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Pure providers.py field/reference/default helpers; consumes supplied records only. */
final class ProviderDefinition {
	const ORIGIN_PROVINCE = 'origin_province_id';
	const ORIGIN_CITY = 'origin_city_id';
	public static function params( $provider ): array { return V::get( $provider, 'params', array() ); }
	public static function fields( $param ): array { return V::fallback( V::get( $param, 'fields' ), array() ); }
	public static function location_defaults(): \stdClass {
		return V::object( array(
			'origin' => V::object( array( 'enabled' => true, 'display' => 'separate', 'source' => 'service', 'custom_mode' => 'merge' ) ),
			'destination' => V::object( array( 'enabled' => false, 'display' => 'separate', 'province_param' => null, 'city_param' => null, 'source' => 'service', 'custom_mode' => 'merge' ) ),
			'custom_locations' => V::object( array( 'provinces' => array(), 'cities' => array() ) ),
			'blocked_locations' => V::object( array( 'provinces' => array(), 'cities' => array() ) ),
		) );
	}
	public static function ensure_origin_params( array $params, $config = null ): array {
		$origin = V::get( $config, 'origin' );
		$rest = array_values( array_filter( $params, static fn( $p ) => ! in_array( V::get( $p, 'name' ), array( self::ORIGIN_PROVINCE, self::ORIGIN_CITY ), true ) ) );
		if ( false === V::get( $origin, 'enabled', true ) ) { return array_map( static fn( $p ) => V::object( (array) $p ), $rest ); }
		if ( ! $rest ) { return array_map( static fn( $p ) => V::object( (array) $p ), $params ); }
		$current = array(); foreach ( $params as $param ) { $current[V::get( $param, 'name' )] = $param; }
		$ensured = array();
		foreach ( array( array( self::ORIGIN_PROVINCE, 'استان مبدا', 'province' ), array( self::ORIGIN_CITY, 'شهر مبدا', 'city' ) ) as $row ) {
			$default = array( 'name' => $row[0], 'label' => $row[1], 'input' => 'select', 'type' => 'int', 'visible' => true, 'kind' => $row[2], 'default' => null, 'options' => array() );
			if ( isset( $current[$row[0]] ) ) {
				$existing = (array) $current[$row[0]];
				foreach ( array( 'label', 'input', 'type', 'visible', 'kind' ) as $key ) { $existing[$key] = $default[$key]; }
				$ensured[] = V::object( $existing );
			} else { $ensured[] = V::object( $default ); }
		}
		return array_merge( $ensured, array_map( static fn( $p ) => V::object( (array) $p ), $rest ) );
	}
	public static function find_param( $provider, $ref ) {
		return self::find( self::params( $provider ), $ref );
	}
	private static function find( array $items, $ref ) {
		if ( null === $ref || is_bool( $ref ) ) { return null; }
		$wanted = null;
		if ( is_int( $ref ) || ( is_float( $ref ) && is_finite( $ref ) && floor( $ref ) === $ref ) || ( is_string( $ref ) && V::digit_string( V::trim( $ref ) ) ) ) { $wanted = V::integer( $ref ); }
		if ( null !== $wanted ) {
			foreach ( $items as $item ) {
				try { if ( null !== V::get( $item, 'id' ) && V::integer( V::get( $item, 'id' ) ) === $wanted ) { return $item; } }
				catch ( \InvalidArgumentException $e ) { continue; }
			}
		}
		$name = V::trim( V::string( $ref ) );
		foreach ( $items as $item ) { if ( V::mapping( $item ) && V::get( $item, 'name' ) === $name ) { return $item; } }
		return null;
	}
	public static function find_field( $provider, $param_ref, $field_ref ): array {
		$param = self::find_param( $provider, $param_ref );
		return array( $param, null === $param ? null : self::find( self::fields( $param ), $field_ref ) );
	}
	public static function target( $provider, $ref, bool $canonical = false ): ?string {
		if ( null === $ref || is_bool( $ref ) ) { return null; }
		$text = V::trim( V::string( $ref ) ); if ( '' === $text ) { return null; }
		$pieces = explode( '.', $text, 2 );
		list( $param, $field ) = isset( $pieces[1] ) ? self::find_field( $provider, $pieces[0], $pieces[1] ) : array( self::find_param( $provider, $pieces[0] ), null );
		if ( null === $param || ( isset( $pieces[1] ) && null === $field ) ) { return null; }
		$key = $canonical ? 'id' : 'name';
		if ( null === V::get( $param, $key ) || ( isset( $pieces[1] ) && null === V::get( $field, $key ) ) ) { return null; }
		return V::string( V::get( $param, $key ) ) . ( isset( $pieces[1] ) ? '.' . V::string( V::get( $field, $key ) ) : '' );
	}
	public static function defaults( $provider ): \stdClass {
		$out = array();
		foreach ( self::params( $provider ) as $param ) {
			$name = V::get( $param, 'name' ); $input = V::get( $param, 'input' );
			if ( in_array( $input, array( 'list', 'dict' ), true ) ) {
				$row = array(); foreach ( self::fields( $param ) as $field ) { $row[V::get( $field, 'name' )] = V::get( $field, 'default' ); }
				$out[$name] = 'list' === $input ? array( V::object( $row ) ) : V::object( $row );
			} else {
				$default = V::get( $param, 'default' );
				if ( 'shop_id' === $name && V::truth( V::get( $provider, 'shop_id' ) ) && ( null === $default || '' === $default ) ) { $default = V::get( $provider, 'shop_id' ); }
				$out[$name] = $default;
			}
		}
		return V::object( $out );
	}
}
