<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Pure provider_location_section projection and shared origin/bulk unions. */
final class LocationBindings {
	public static function aliases( $provider ): array {
		$out = array(); foreach ( array( 'name', 'display_name' ) as $key ) { $value = V::get( $provider, $key ); if ( is_string( $value ) && '' !== V::trim( $value ) ) { $out[] = $value; } }
		if ( ! $out ) { throw new LocationError( 'نام این شرکت برای تطبیق لیست شهر و استان مشخص نیست.' ); } return $out;
	}
	public static function needed( $provider ): bool { foreach ( ProviderDefinition::params( $provider ) as $param ) { if ( V::mapping( $param ) && in_array( V::get( $param, 'kind' ), array( 'province', 'city' ), true ) ) { return true; } } return false; }
	public static function iso( $moment ): ?string {
		if ( null === $moment ) { return null; }
		try { $moment = V::number( $moment ); if ( ! is_finite( $moment ) || $moment < -62135596800 || $moment >= 253402300800 ) { return null; } $seconds = floor( $moment ); $micro = (int) round( ( $moment - $seconds ) * 1000000, 0, PHP_ROUND_HALF_EVEN ); if ( 1000000 === $micro ) { $seconds++; $micro = 0; } $date = \DateTimeImmutable::createFromFormat( 'U', (string) V::bounded_integer( $seconds ) ); if ( false === $date ) { return null; } $date = $date->setTimezone( new \DateTimeZone( 'UTC' ) ); return $date->format( 'Y-m-d\TH:i:s' ) . ( $micro ? '.' . str_pad( (string) $micro, 6, '0', STR_PAD_LEFT ) : '' ) . '+00:00'; }
		catch ( \InvalidArgumentException $error ) { return null; }
	}
	public static function section( $provider, \stdClass $snapshot ): \stdClass {
		$aliases = self::aliases( $provider ); $provider_key = null; foreach ( $aliases as $alias ) { if ( V::has( $snapshot->data, $alias ) ) { $provider_key = $alias; break; } }
		$grouped = null === $provider_key ? new \stdClass() : V::get( $snapshot->data, $provider_key ); $base_p = $base_c = array();
		foreach ( (array) $grouped as $pk => $group ) { $base_p[] = array( 'value' => (string) $pk, 'label' => V::get( $group, 'province_name' ) ); $base_c[$pk] = V::get( $group, 'cities' ); }
		$config = V::get( $provider, 'location_config' ); $origin = V::get( $config, 'origin' ); $destination = V::get( $config, 'destination' );
		list( $custom_p, $custom_c ) = LocationData::custom( V::get( $config, 'custom_locations' ) );
		list( $origin_p, $origin_c, $origin_status ) = LocationData::dataset( $base_p, (object) $base_c, $custom_p, $custom_c, V::get( $origin, 'source' ), V::get( $origin, 'custom_mode' ), $snapshot->status );
		list( $dest_p, $dest_c, $dest_status ) = LocationData::dataset( $base_p, (object) $base_c, $custom_p, $custom_c, V::get( $destination, 'source' ), V::get( $destination, 'custom_mode' ), $snapshot->status );
		list( $blocked_p, $blocked_c ) = LocationData::blocked_sets( $config );
		if ( $blocked_p || $blocked_c ) { list( $origin_p, $origin_c ) = LocationData::blocked( $origin_p, $origin_c, $blocked_p, $blocked_c ); list( $dest_p, $dest_c ) = LocationData::blocked( $dest_p, $dest_c, $blocked_p, $blocked_c ); }
		$params = array_values( array_filter( ProviderDefinition::params( $provider ), array( V::class, 'mapping' ) ) ); $bindings = array();
		$province_params = array_values( array_filter( $params, static fn( $p ) => 'province' === V::get( $p, 'kind' ) ) ); $city_params = array_values( array_filter( $params, static fn( $p ) => 'city' === V::get( $p, 'kind' ) ) );
		foreach ( LocationData::pairs( $province_params, $city_params ) as $pair ) {
			list( $province, $city ) = $pair; $is_origin = ProviderDefinition::ORIGIN_PROVINCE === V::get( $province, 'name' ) || ProviderDefinition::ORIGIN_CITY === V::get( $city, 'name' );
			self::bind( $bindings, '', $province, $city, $is_origin ? $origin_p : $dest_p, $is_origin ? $origin_c : $dest_c );
		}
		foreach ( $params as $param ) { if ( ! in_array( V::get( $param, 'input' ), array( 'list', 'dict' ), true ) ) { continue; } $provinces = $cities = array(); foreach ( ProviderDefinition::fields( $param ) as $field ) { if ( ! V::mapping( $field ) ) { continue; } if ( 'province' === V::get( $field, 'kind' ) ) { $provinces[] = $field; } elseif ( 'city' === V::get( $field, 'kind' ) ) { $cities[] = $field; } } foreach ( LocationData::pairs( $provinces, $cities ) as $pair ) { self::bind( $bindings, V::string( V::get( $param, 'name' ) ) . '.', $pair[0], $pair[1], $dest_p, $dest_c ); } }
		$notes = array(); foreach ( array( array( $origin, 'لیست دستی مبدا خالی است.' ), array( $destination, 'لیست دستی مقصد خالی است.' ) ) as $row ) { if ( 'custom' === V::get( $row[0], 'source' ) && 'only' === V::fallback( V::get( $row[0], 'custom_mode' ), 'merge' ) && ! $custom_p && ! V::truth( $custom_c ) ) { $notes[] = $row[1]; } }
		if ( V::truth( $snapshot->message ) ) { $notes[] = $snapshot->message; }
		$statuses = array( $origin_status, $dest_status ); $status = in_array( 'fresh', $statuses, true ) ? 'fresh' : ( in_array( 'stale', $statuses, true ) ? 'stale' : $snapshot->status ); $message = implode( '؛ ', $notes );
		if ( null === $provider_key && ! $custom_p && ! V::truth( $custom_c ) ) { $status = 'unavailable'; $message = V::truth( $snapshot->data ) ? 'برای ' . $aliases[0] . ' داده شهر و استان پیدا نشد.' : V::fallback( $message, 'برای این شرکت داده شهر و استان پیدا نشد.' ); }
		sort( $blocked_p, SORT_STRING ); sort( $blocked_c, SORT_STRING );
		return (object) array( 'provider' => V::get( $provider, 'name' ), 'provider_key' => $provider_key, 'requested' => $aliases, 'status' => $status, 'message' => $message, 'fetched_at' => $snapshot->fetched_at, 'fetched_at_iso' => self::iso( $snapshot->fetched_at ), 'expires_at' => $snapshot->expires_at, 'expires_at_iso' => self::iso( $snapshot->expires_at ), 'stale_expires_at' => $snapshot->stale_expires_at, 'stale_expires_at_iso' => self::iso( $snapshot->stale_expires_at ), 'missing' => $snapshot->missing, 'bindings' => (object) $bindings, 'blocked' => (object) array( 'provinces' => $blocked_p, 'cities' => $blocked_c, 'province_count' => count( $blocked_p ), 'city_count' => count( $blocked_c ) ) );
	}
	private static function bind( array &$bindings, string $prefix, $province, $city, array $items, $groups ): void {
		if ( null !== $province ) { $bindings[$prefix . V::string( V::get( $province, 'name' ) )] = (object) array( 'kind' => 'province', 'paired_param' => null !== $city ? $prefix . V::string( V::get( $city, 'name' ) ) : null, 'paired_visible' => null !== $city && V::truth( V::get( $city, 'visible' ) ), 'items' => $items ); }
		if ( null !== $city ) { $bindings[$prefix . V::string( V::get( $city, 'name' ) )] = (object) array( 'kind' => 'city', 'paired_param' => null !== $province ? $prefix . V::string( V::get( $province, 'name' ) ) : null, 'paired_visible' => null !== $province && V::truth( V::get( $province, 'visible' ) ), 'items_by_parent' => $groups ); }
	}
	public static function merge( array $sections, bool $origin_only = false ): \stdClass {
		$provinces = $cities = $seen = $statuses = $messages = array(); foreach ( $sections as $section ) {
			if ( ! V::mapping( $section ) ) { continue; } $statuses[] = V::string( V::fallback( V::get( $section, 'status' ), 'unavailable' ) ); if ( V::truth( V::get( $section, 'message' ) ) ) { $messages[] = V::string( V::get( $section, 'message' ) ); }
			foreach ( (array) V::fallback( V::get( $section, 'bindings' ), new \stdClass() ) as $name => $binding ) {
				if ( ! V::mapping( $binding ) ) { continue; }
				if ( 'province' === V::get( $binding, 'kind' ) && ( ! $origin_only || ProviderDefinition::ORIGIN_PROVINCE === $name ) ) { foreach ( V::fallback( V::get( $binding, 'items' ), array() ) as $item ) { $value = V::get( $item, 'value' ); if ( V::mapping( $item ) && null !== $value && '' !== $value && ! array_key_exists( V::string( $value ), $provinces ) ) { $provinces[V::string( $value )] = V::string( V::fallback( V::get( $item, 'label' ), $value ) ); } } }
				elseif ( 'city' === V::get( $binding, 'kind' ) && ( ! $origin_only || ProviderDefinition::ORIGIN_CITY === $name ) ) { foreach ( (array) V::fallback( V::get( $binding, 'items_by_parent' ), new \stdClass() ) as $parent => $group ) { if ( ! V::listing( $group ) ) { continue; } foreach ( $group as $item ) { $value = V::get( $item, 'value' ); $key = json_encode( array( (string) $parent, V::string( $value ) ) ); if ( V::mapping( $item ) && null !== $value && '' !== $value && ! isset( $seen[$key] ) ) { $seen[$key] = true; $cities[$parent][] = array( 'value' => V::string( $value ), 'label' => V::string( V::fallback( V::get( $item, 'label' ), $value ) ) ); } } } }
			}
		}
		$items = array(); foreach ( $provinces as $value => $label ) { $items[] = array( 'value' => (string) $value, 'label' => $label ); }
		$empty = ! $provinces && ! $cities; $status = $empty ? 'unavailable' : ( in_array( 'fresh', $statuses, true ) ? 'fresh' : ( in_array( 'stale', $statuses, true ) ? 'stale' : 'unavailable' ) );
		return (object) array( 'status' => $status, 'message' => $empty ? V::fallback( implode( '؛ ', $messages ), 'لیست شهر/استان در دسترس نیست.' ) : implode( '؛ ', $messages ), 'provinces' => $items, 'cities_by_province' => (object) $cities );
	}
}
