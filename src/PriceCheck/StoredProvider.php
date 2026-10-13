<?php

namespace Tapin\ServicePointLocator\PriceCheck;

use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;

defined( 'ABSPATH' ) || exit;

/** Lenient storage-row readers from providers.py, separate from Stage 3's strict draft validation. */
final class StoredProvider {
	private static function text( $value ): string { return V::trim( V::string( V::fallback( $value, '' ) ) ); }
	private static function nullable( $value ): ?string { $text = self::text( $value ); return '' === $text ? null : $text; }
	private static function json_or( $value, $fallback ) {
		try { return ConfigurationJson::decode( $value ); } catch ( \InvalidArgumentException $error ) { return $fallback; }
	}

	public static function decode( array $row, array $params ): \stdClass {
		$timeout = $row['timeout'];
		if ( null !== $timeout && '' !== $timeout ) {
			try { $timeout = V::number( $timeout ); if ( $timeout <= 0 ) { $timeout = null; } }
			catch ( \InvalidArgumentException $error ) { $timeout = null; }
		} else { $timeout = null; }
		$rules = $row['rules_json'];
		if ( V::truth( $rules ) && is_string( $rules ) ) { $rules = self::json_or( $rules, array() ); }
		$rules = V::listing( $rules ) ? array_values( array_filter( $rules, array( V::class, 'mapping' ) ) ) : array();
		$prices = self::json_or( $row['price_fields_json'], array() ); $price_fields = array();
		foreach ( V::listing( $prices ) ? $prices : array() as $price ) {
			if ( ! V::mapping( $price ) ) { continue; } $key = self::text( V::get( $price, 'key' ) );
			if ( '' !== $key ) { $price_fields[] = V::object( array( 'key' => $key, 'label' => V::string( V::fallback( V::get( $price, 'label' ), $key ) ) ) ); }
		}
		return V::object( array(
			'id' => $row['id'], 'active' => V::truth( $row['active'] ), 'name' => $row['name'], 'display_name' => $row['display_name'],
			'logo' => $row['logo'], 'check_price_url' => $row['check_price_url'], 'shop_id' => V::fallback( $row['shop_id'], null ),
			'price_fields' => $price_fields, 'timeout' => $timeout, 'main_price_key' => self::nullable( $row['main_price_key'] ),
			'price_currency_in' => Units::currency( $row['price_currency_in'] ), 'price_currency_out' => Units::currency( $row['price_currency_out'] ),
			'price_input_param' => self::nullable( $row['price_input_param'] ), 'weight_unit_in' => Units::weight( $row['weight_unit_in'] ),
			'weight_unit_out' => Units::weight( $row['weight_unit_out'] ), 'weight_input_param' => self::nullable( $row['weight_input_param'] ),
			'referral' => V::object( array( 'enabled' => V::truth( $row['referral_enabled'] ), 'title' => V::string( V::fallback( $row['referral_title'], '' ) ), 'url' => V::string( V::fallback( $row['referral_url'], '' ) ), 'description' => V::string( V::fallback( $row['referral_description'], '' ) ) ) ),
			'rules' => $rules, 'location_config' => self::location( $row['location_config_json'] ),
			'params' => array_map( array( self::class, 'param' ), $params ),
		) );
	}

	private static function param( array $row ): \stdClass {
		$out = array(
			'id' => $row['id'], 'name' => $row['name'], 'label' => $row['label'], 'input' => $row['input'], 'type' => $row['type'],
			'visible' => V::truth( $row['visible'] ), 'kind' => V::fallback( $row['kind'], null ), 'description' => V::fallback( $row['description'], '' ),
			'required' => V::truth( $row['required'] ), 'default' => PayloadNormalizer::coerce( $row['default_value'], $row['type'] ),
		);
		// Unlike nested options, malformed top-level options raise in the source.
		if ( V::truth( $row['options_json'] ) ) { $out['options'] = ConfigurationJson::decode( $row['options_json'] ); }
		if ( 'list' === $row['input'] ) { $out['min_items'] = null === $row['min_items'] ? 1 : $row['min_items']; }
		if ( in_array( $row['input'], array( 'list', 'dict' ), true ) ) { $out['fields'] = array_map( array( self::class, 'field' ), $row['field_rows'] ?? array() ); }
		if ( 'map' === $row['input'] ) {
			$map = self::json_or( $row['map_json'], null );
			if ( ! V::mapping( $map ) ) { $out['map'] = V::object( array( 'mode' => 'split', 'lat_param' => 'lat', 'long_param' => 'long' ) ); }
			elseif ( 'combined' === V::get( $map, 'mode' ) ) { $out['map'] = V::object( array( 'mode' => 'combined', 'param' => V::string( V::fallback( V::get( $map, 'param' ), '' ) ) ) ); }
			else { $out['map'] = V::object( array( 'mode' => 'split', 'lat_param' => V::string( V::fallback( V::get( $map, 'lat_param' ), 'lat' ) ), 'long_param' => V::string( V::fallback( V::get( $map, 'long_param' ), 'long' ) ) ) ); }
		}
		return V::object( $out );
	}

	private static function field( array $row ): \stdClass {
		$out = array(
			'id' => $row['id'], 'name' => $row['name'], 'label' => $row['label'], 'input' => $row['input'], 'type' => $row['type'],
			'visible' => V::truth( $row['visible'] ), 'kind' => V::fallback( $row['kind'], null ), 'required' => V::truth( $row['required'] ),
			'default' => PayloadNormalizer::coerce( $row['default_value'], $row['type'] ),
		);
		if ( V::truth( $row['options_json'] ) ) { $out['options'] = self::json_or( $row['options_json'], array() ); }
		return V::object( $out );
	}

	private static function location( $raw ): \stdClass {
		$out = ProviderDefinition::location_defaults();
		if ( is_string( $raw ) ) { $raw = self::json_or( $raw, null ); } if ( ! V::mapping( $raw ) ) { return $out; }
		foreach ( array( 'origin', 'destination' ) as $side ) {
			$input = V::get( $raw, $side ); if ( ! V::mapping( $input ) ) { continue; }
			if ( 'origin' === $side && false === V::get( $input, 'enabled' ) ) { $out->$side->enabled = false; }
			if ( 'destination' === $side && true === V::get( $input, 'enabled' ) ) { $out->$side->enabled = true; }
			foreach ( array( 'display' => array( 'separate', 'combined' ), 'source' => array( 'service', 'custom' ), 'custom_mode' => array( 'only', 'merge' ) ) as $key => $allowed ) {
				if ( in_array( V::get( $input, $key ), $allowed, true ) ) { $out->$side->$key = V::get( $input, $key ); }
			}
			if ( 'destination' === $side ) { $out->$side->province_param = self::nullable( V::get( $input, 'province_param' ) ); $out->$side->city_param = self::nullable( V::get( $input, 'city_param' ) ); }
		}
		foreach ( array( 'custom_locations', 'blocked_locations' ) as $key ) { $out->$key = self::locations( V::get( $raw, $key ) ); }
		return $out;
	}

	/** Match Python iteration even for malformed persisted mappings/strings. */
	private static function items( $value ): array {
		$value = V::fallback( $value, array() );
		if ( V::mapping( $value ) ) { return array_keys( (array) $value ); }
		if ( V::listing( $value ) ) { return $value; }
		if ( is_string( $value ) ) { preg_match_all( '/./us', $value, $characters ); return $characters[0]; }
		throw new \TypeError( 'Stored locations are not iterable.' );
	}

	private static function locations( $raw ): \stdClass {
		$out = array( 'provinces' => array(), 'cities' => array() );
		if ( ! V::mapping( $raw ) ) { return V::object( $out ); }
		foreach ( array_keys( $out ) as $key ) {
			$seen = array();
			foreach ( self::items( V::get( $raw, $key ) ) as $item ) {
				if ( ! V::mapping( $item ) ) { continue; } $code = self::text( V::get( $item, 'code' ) ); $province = self::text( V::get( $item, 'province_code' ) );
				if ( '' === $code || in_array( $code, $seen, true ) || ( 'cities' === $key && '' === $province ) ) { continue; }
				$seen[] = $code; $row = array( 'code' => $code, 'title' => V::fallback( V::trim( V::string( V::fallback( V::get( $item, 'title' ), $code ) ) ), $code ) );
				if ( 'cities' === $key ) { $row['province_code'] = $province; } $out[$key][] = V::object( $row );
			}
		}
		return V::object( $out );
	}
}
