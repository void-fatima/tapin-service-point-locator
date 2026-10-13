<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Pure locations.py response, availability, alias and dataset helpers. Never accesses storage/HTTP. */
final class LocationData {
	const CITY_IDS = array( 'pk', 'id', 'code', 'city_pk', 'city_id', 'city_code' );
	const CITY_NAMES = array( 'title', 'name', 'city_title', 'city_name' );
	const PROVINCE_IDS = array( 'province_pk', 'province_id', 'province_code', 'state_pk', 'state_id', 'state_code' );
	const PROVINCE_NAMES = array( 'province_title', 'province_name', 'state_title', 'state_name', 'title', 'name' );
	private static ?array $fold = null;
	public static function canonical( $value ): string {
		if ( null === self::$fold ) { self::$fold = json_decode( file_get_contents( __DIR__ . '/unicode-casefold.json' ), true, 512, JSON_THROW_ON_ERROR ); }
		return strtr( V::trim( preg_replace( '/[\s\x{001c}-\x{001f}]+/u', ' ', null === $value ? '' : V::string( $value ) ) ), self::$fold );
	}
	public static function identifier( $value ): ?string {
		if ( null === $value || is_bool( $value ) ) { return null; }
		if ( is_float( $value ) ) { return is_finite( $value ) && floor( $value ) === $value ? (string) V::bounded_integer( $value ) : null; }
		$text = V::trim( V::string( $value ) ); return '' === $text ? null : $text;
	}
	public static function display_name( $value ): ?string { if ( null === $value || is_bool( $value ) ) { return null; } $text = V::trim( V::string( $value ) ); return '' === $text ? null : $text; }
	private static function first( $record, array $keys ) { foreach ( $keys as $key ) { if ( V::has( $record, $key ) ) { return V::get( $record, $key ); } } return null; }
	public static function flag( $value ): ?bool {
		if ( is_bool( $value ) ) { return $value; }
		if ( V::mapping( $value ) ) { foreach ( array( 'is_available', 'available', 'enabled', 'active', 'is_active' ) as $key ) { if ( V::has( $value, $key ) ) { $state = self::flag( V::get( $value, $key ) ); if ( null !== $state ) { return $state; } } } return null; }
		if ( is_int( $value ) || is_float( $value ) ) { return 1 == $value ? true : ( 0 == $value ? false : null ); }
		if ( is_string( $value ) ) { $word = self::canonical( V::trim( $value ) ); if ( in_array( $word, array( '1', 'true', 'yes', 'y', 'available', 'active', 'enabled' ), true ) ) { return true; } if ( in_array( $word, array( '0', 'false', 'no', 'n', 'unavailable', 'inactive', 'disabled' ), true ) ) { return false; } } return null;
	}
	private static function availability( $record ): \stdClass {
		$out = array(); $ignored = array_merge( self::CITY_IDS, self::CITY_NAMES, self::PROVINCE_IDS, self::PROVINCE_NAMES, array( 'province', 'state', 'cities', 'city', 'returns', 'entries' ) );
		foreach ( (array) $record as $key => $value ) { if ( in_array( (string) $key, $ignored, true ) ) { continue; } $flag = self::flag( $value ); if ( null !== $flag ) { $out[(string) $key] = $flag; } } return (object) $out;
	}
	private static function province( $record ): array {
		$nested = V::get( $record, 'province' ); if ( ! V::mapping( $nested ) || ! V::truth( $nested ) ) { $nested = V::get( $record, 'state' ); }
		if ( V::mapping( $nested ) ) { $pk = self::identifier( self::first( $nested, array_merge( array( 'pk', 'id', 'code' ), self::PROVINCE_IDS ) ) ); $name = self::display_name( self::first( $nested, array_merge( array( 'title', 'name' ), self::PROVINCE_NAMES ) ) ); if ( V::truth( $pk ) || V::truth( $name ) ) { return array( $pk, $name ); } }
		return array( self::identifier( self::first( $record, self::PROVINCE_IDS ) ), self::display_name( self::first( $record, self::PROVINCE_NAMES ) ) );
	}
	private static function city( $record, ?string $pk = null, ?string $name = null, $inherited = null ): ?\stdClass {
		$city_pk = self::identifier( self::first( $record, self::CITY_IDS ) ); $city_name = self::display_name( self::first( $record, self::CITY_NAMES ) ); list( $nested_pk, $nested_name ) = self::province( $record );
		$pk = V::fallback( $pk, $nested_pk ); $name = V::fallback( $name, $nested_name );
		if ( ! V::truth( $city_pk ) || ! V::truth( $city_name ) || ! V::truth( $pk ) || ! V::truth( $name ) ) { return null; }
		return (object) array( 'province_pk' => $pk, 'province_name' => $name, 'city_pk' => $city_pk, 'city_name' => $city_name, 'availability' => (object) array_replace( (array) $inherited, (array) self::availability( $record ) ) );
	}
	private static function mixed( array $items ): array {
		$out = array(); foreach ( $items as $item ) {
			if ( ! V::mapping( $item ) ) { continue; } $cities = V::get( $item, 'cities' );
			if ( V::listing( $cities ) ) {
				$pk = self::identifier( self::first( $item, array_merge( array( 'pk', 'id', 'code' ), self::PROVINCE_IDS ) ) ); $name = self::display_name( self::first( $item, array_merge( array( 'title', 'name' ), self::PROVINCE_NAMES ) ) );
				foreach ( $cities as $child ) { if ( V::mapping( $child ) ) { $city = self::city( $child, $pk, $name, self::availability( $item ) ); if ( null !== $city ) { $out[] = $city; } } }
			} else { $city = self::city( $item ); if ( null !== $city ) { $out[] = $city; } }
		} return $out;
	}
	/** HTTP status is deliberately not a success criterion in Python. */
	public static function decode( int $status, string $raw, string $endpoint = '' ): array {
		$text = QuoteJson::utf8( $raw ); if ( '' === V::trim( $text ) ) { throw new LocationError( 'سرویس شهر و استان پاسخ خالی برگرداند.' ); }
		try { $payload = QuoteJson::decode( $text ); } catch ( \InvalidArgumentException $error ) { throw new LocationError( 'پاسخ سرویس شهر و استان JSON معتبر نیست.' ); }
		$provider_status = null; $message = ''; $returns = V::get( $payload, 'returns' );
		if ( V::mapping( $returns ) ) { $value = V::get( $returns, 'status' ); if ( is_int( $value ) ) { $provider_status = $value; } elseif ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) { $provider_status = V::bounded_integer( $value ); } elseif ( ! is_bool( $value ) ) { $provider_status = null === $value ? null : V::fallback( V::trim( V::string( $value ) ), null ); } if ( null !== V::get( $returns, 'message' ) ) { $message = V::string( V::get( $returns, 'message' ) ); } }
		$candidates = array(); if ( V::listing( $payload ) ) { $candidates[] = $payload; } elseif ( V::mapping( $payload ) ) {
			$entries = V::get( $payload, 'entries' ); if ( ! V::mapping( $entries ) ) { $entries = null; }
			foreach ( array( $entries, $payload ) as $container ) { if ( ! V::mapping( $container ) ) { continue; } foreach ( array( 'cities', 'list', 'data', 'items' ) as $key ) { $value = V::get( $container, $key ); if ( V::listing( $value ) ) { $candidates[] = $value; } } }
		}
		$records = $seen = array(); foreach ( $candidates as $items ) { foreach ( self::mixed( $items ) as $record ) { $key = json_encode( array( $record->province_pk, $record->city_pk ) ); if ( isset( $seen[$key] ) ) { continue; } $seen[$key] = true; $records[] = $record; } }
		if ( ! $records ) { throw new LocationError( 'ساختار پاسخ سرویس شهر و استان شناخته نشد؛ هیچ شهر معتبری پیدا نشد.' ); } return array( $records, $provider_status, $message );
	}
	public static function aliases( array $names ): array { $aliases = array(); foreach ( $names as $name ) { if ( null === $name || is_bool( $name ) ) { continue; } $text = V::trim( V::string( $name ) ); if ( '' !== $text && ! in_array( $text, $aliases, true ) ) { $aliases[] = $text; } } if ( ! $aliases ) { throw new LocationError( 'حداقل یک نام شرکت برای لیست شهر و استان لازم است.' ); } return $aliases; }
	public static function targets( array $aliases, array $records ): \stdClass {
		$targets = array(); foreach ( $records as $record ) { if ( ! V::mapping( $record ) ) { continue; } $technical = V::get( $record, 'name' ); $title = V::get( $record, 'display_name' ); if ( is_string( $technical ) && '' !== V::trim( $technical ) ) { $targets[self::canonical( $technical )] = V::trim( $technical ); } if ( is_string( $title ) && '' !== V::trim( $title ) && ! array_key_exists( self::canonical( $title ), $targets ) ) { $targets[self::canonical( $title )] = is_string( $technical ) && '' !== V::trim( $technical ) ? V::trim( $technical ) : V::trim( $title ); } }
		$out = array(); foreach ( $aliases as $alias ) { $out[$alias] = $targets[self::canonical( $alias )] ?? $alias; } return (object) $out;
	}
	public static function snapshot_data( array $aliases, $targets, array $cities ): array {
		$actual = array(); foreach ( $cities as $city ) { foreach ( (array) $city->availability as $key => $_ ) { $canon = self::canonical( (string) $key ); if ( ! isset( $actual[$canon] ) ) { $actual[$canon] = (string) $key; } } }
		$data = $missing = array(); foreach ( $aliases as $alias ) {
			$key = $actual[self::canonical( V::get( $targets, $alias, $alias ) )] ?? ( $actual[self::canonical( $alias )] ?? null ); if ( null === $key ) { $missing[] = $alias; continue; } $grouped = array();
			foreach ( $cities as $city ) { if ( ! V::truth( V::get( $city->availability, $key ) ) ) { continue; } $pk = $city->province_pk; if ( ! isset( $grouped[$pk] ) ) { $grouped[$pk] = array( 'province_name' => $city->province_name, 'cities' => array() ); } $grouped[$pk]['province_name'] = $city->province_name; $grouped[$pk]['cities'][] = array( 'value' => $city->city_pk, 'label' => $city->city_name ); }
			if ( $grouped ) { $data[$alias] = (object) $grouped; } else { $missing[] = $alias; }
		} return array( (object) $data, $missing );
	}
	public static function custom( $custom ): array {
		$provinces = $cities = $seen_p = $seen_c = array(); if ( ! V::mapping( $custom ) ) { return array( array(), new \stdClass() ); }
		foreach ( V::fallback( V::get( $custom, 'provinces' ), array() ) as $item ) { if ( ! V::mapping( $item ) ) { continue; } $code = V::text( V::fallback( V::get( $item, 'code' ), '' ) ); if ( '' === $code || in_array( $code, $seen_p, true ) ) { continue; } $seen_p[] = $code; $provinces[] = array( 'value' => $code, 'label' => V::fallback( V::text( V::fallback( V::get( $item, 'title' ), $code ) ), $code ) ); }
		foreach ( V::fallback( V::get( $custom, 'cities' ), array() ) as $item ) { if ( ! V::mapping( $item ) ) { continue; } $code = V::text( V::fallback( V::get( $item, 'code' ), '' ) ); $parent = V::text( V::fallback( V::get( $item, 'province_code' ), '' ) ); if ( '' === $code || '' === $parent || in_array( $code, $seen_c, true ) ) { continue; } $seen_c[] = $code; $cities[$parent][] = array( 'value' => $code, 'label' => V::fallback( V::text( V::fallback( V::get( $item, 'title' ), $code ) ), $code ) ); }
		return array( $provinces, (object) $cities );
	}
	public static function merge( array $base_p, $base_c, array $extra_p, $extra_c ): array {
		$provinces = $cities = $seen_base = $seen = array(); foreach ( array_merge( $base_p, $extra_p ) as $item ) { $value = V::get( $item, 'value' ); if ( V::mapping( $item ) && null !== $value && '' !== $value && ! array_key_exists( V::string( $value ), $provinces ) ) { $provinces[V::string( $value )] = V::string( V::fallback( V::get( $item, 'label' ), $value ) ); } }
		foreach ( array( $base_c, $extra_c ) as $index => $groups ) { foreach ( (array) $groups as $parent => $group ) { if ( ! V::listing( $group ) ) { continue; } foreach ( $group as $item ) { $value = V::get( $item, 'value' ); if ( ! V::mapping( $item ) || null === $value || '' === $value ) { continue; } $value = V::string( $value ); if ( 0 === $index ) { $seen_base[$value] = true; } elseif ( isset( $seen_base[$value] ) ) { continue; } $key = json_encode( array( (string) $parent, $value ) ); if ( isset( $seen[$key] ) ) { continue; } $seen[$key] = true; $cities[$parent][] = array( 'value' => $value, 'label' => V::string( V::fallback( V::get( $item, 'label' ), $value ) ) ); } } }
		$out = array(); foreach ( $provinces as $value => $label ) { $out[] = array( 'value' => (string) $value, 'label' => $label ); } return array( $out, (object) $cities );
	}
	public static function dataset( array $base_p, $base_c, array $custom_p, $custom_c, $source, $mode, string $status ): array {
		if ( 'custom' !== $source ) { return array( $base_p, $base_c, $status ); } $has = (bool) $custom_p || V::truth( $custom_c );
		if ( 'only' === V::fallback( $mode, 'merge' ) ) { return array( $custom_p, $custom_c, $has ? 'fresh' : 'unavailable' ); }
		list( $provinces, $cities ) = self::merge( $base_p, $base_c, $custom_p, $custom_c ); return array( $provinces, $cities, $base_p || V::truth( $base_c ) ? $status : ( $has ? 'fresh' : $status ) );
	}
	public static function blocked_sets( $config ): array { list( $provinces, $cities ) = self::custom( V::get( $config, 'blocked_locations' ) ); $p = array_column( $provinces, 'value' ); $c = array(); foreach ( (array) $cities as $group ) { foreach ( $group as $item ) { $c[] = $item['value']; } } return array( array_values( array_unique( $p ) ), array_values( array_unique( $c ) ) ); }
	public static function blocked( array $provinces, $groups, array $blocked_p, array $blocked_c ): array {
		if ( ! $blocked_p && ! $blocked_c ) { $unchanged = array(); foreach ( (array) $groups as $parent => $group ) { if ( V::listing( $group ) ) { $unchanged[$parent] = $group; } } return array( $provinces, (object) $unchanged ); }
		$cities = $out = array(); foreach ( (array) $groups as $parent => $group ) { if ( ! V::listing( $group ) || in_array( (string) $parent, $blocked_p, true ) ) { continue; } $kept = array(); foreach ( $group as $item ) { $value = V::get( $item, 'value' ); if ( V::mapping( $item ) && '' !== V::text( V::fallback( $value, '' ) ) && ! in_array( V::string( $value ), $blocked_c, true ) ) { $kept[] = array( 'value' => V::string( $value ), 'label' => V::string( V::fallback( V::get( $item, 'label' ), $value ) ) ); } } if ( $kept ) { $cities[$parent] = $kept; } }
		foreach ( $provinces as $item ) { $value = V::get( $item, 'value' ); if ( V::mapping( $item ) && '' !== V::text( V::fallback( $value, '' ) ) && ! in_array( V::string( $value ), $blocked_p, true ) && ( ! V::has( $groups, V::string( $value ) ) || isset( $cities[V::string( $value )] ) ) ) { $out[] = array( 'value' => V::string( $value ), 'label' => V::string( V::fallback( V::get( $item, 'label' ), $value ) ) ); } } return array( $out, (object) $cities );
	}
	public static function pairs( array $provinces, array $cities ): array {
		$origin_p = $origin_c = null; $rest_p = $rest_c = array(); foreach ( $provinces as $param ) { if ( null === $origin_p && ProviderDefinition::ORIGIN_PROVINCE === V::get( $param, 'name' ) ) { $origin_p = $param; } else { $rest_p[] = $param; } } foreach ( $cities as $param ) { if ( null === $origin_c && ProviderDefinition::ORIGIN_CITY === V::get( $param, 'name' ) ) { $origin_c = $param; } else { $rest_c[] = $param; } }
		$out = array(); if ( null !== $origin_p || null !== $origin_c ) { $out[] = array( $origin_p, $origin_c ); } foreach ( $rest_p as $index => $param ) { $out[] = array( $param, $rest_c[$index] ?? null ); } foreach ( array_slice( $rest_c, count( $rest_p ) ) as $param ) { $out[] = array( null, $param ); } return $out;
	}
}
