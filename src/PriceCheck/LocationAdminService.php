<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source admin reference helpers; delegates all HTTP/cache/fallback work to Stage 8. */
final class LocationAdminService {
	private LocationService $locations;
	private $providers;
	public function __construct( ?LocationService $locations = null, ?callable $providers = null ) { $this->locations = $locations ?? new LocationService(); $this->providers = $providers ?? array( ProviderStorage::class, 'all' ); }
	private function snapshot( $record ): \stdClass {
		try { $credentials = $this->locations->credentials( $record ); } catch ( LocationError $error ) { $credentials = null; }
		return $this->locations->snapshot( LocationBindings::aliases( $record ), $credentials );
	}
	public function validate_manual( $record ): void {
		$custom = V::get( V::get( $record, 'location_config' ), 'custom_locations' ); $cities = V::fallback( V::get( $custom, 'cities' ), array() ); if ( ! $cities ) { return; }
		$codes = array(); foreach ( V::fallback( V::get( $custom, 'provinces' ), array() ) as $province ) { $code = V::text( V::fallback( V::get( $province, 'code' ), '' ) ); if ( '' !== $code ) { $codes[] = $code; } }
		$unknown = array(); foreach ( $cities as $i => $city ) { if ( V::mapping( $city ) && ! in_array( V::text( V::fallback( V::get( $city, 'province_code' ), '' ) ), $codes, true ) ) { $unknown[$i] = $city; } } if ( ! $unknown ) { return; }
		try { $this->snapshot( $record ); } catch ( LocationError $error ) {}
		$info = $this->locations->cache_info(); $service = array(); foreach ( V::get( $info['payload'] ?? null, 'cities', array() ) as $city ) { $code = V::text( V::fallback( V::get( $city, 'province_pk' ), '' ) ); if ( '' !== $code ) { $service[] = $code; } } if ( ! $service ) { return; }
		$errors = array(); foreach ( $unknown as $i => $city ) { $code = V::text( V::fallback( V::get( $city, 'province_code' ), '' ) ); if ( ! in_array( $code, $service, true ) ) { $title = V::text( V::fallback( V::get( $city, 'title' ), V::fallback( V::get( $city, 'code' ), '' ) ) ); $errors[] = array( 'field' => 'location_config.custom_locations.cities[' . $i . '].province_code', 'message' => 'کد استان «' . $code . '» برای شهر «' . $title . '» نه در لیست سرویس تاپین است و نه در لیست دستی.' ); } } if ( $errors ) { throw new RecordError( $errors ); }
	}
	public function unserved( $provider ): array {
		$aliases = LocationBindings::aliases( $provider ); if ( ! $aliases ) { throw new LocationError( 'نام این شرکت برای تطبیق لیست شهر و استان مشخص نیست.' ); } $targets = LocationData::targets( $aliases, ( $this->providers )() ); $snapshot = $this->snapshot( $provider ); $raw = V::get( ( $this->locations->cache_info()['payload'] ?? null ), 'cities', array() ); $actual = array();
		foreach ( $raw as $city ) { foreach ( (array) V::get( $city, 'availability', new \stdClass() ) as $key => $flag ) { $canonical = LocationData::canonical( $key ); if ( ! isset( $actual[$canonical] ) ) { $actual[$canonical] = $key; } } }
		$resolved = $used = null; foreach ( $aliases as $alias ) { $hit = $actual[LocationData::canonical( V::get( $targets, $alias, $alias ) )] ?? ( $actual[LocationData::canonical( $alias )] ?? null ); if ( null !== $hit ) { $resolved = $hit; $used = $alias; break; } }
		$groups = array(); foreach ( $raw as $city ) { if ( null !== $resolved && V::truth( V::get( V::get( $city, 'availability' ), $resolved ) ) ) { continue; } $p = V::text( V::fallback( V::get( $city, 'province_pk' ), '' ) ); $c = V::text( V::fallback( V::get( $city, 'city_pk' ), '' ) ); if ( '' === $p || '' === $c ) { continue; } $groups[$p]['label'] = V::text( V::fallback( V::get( $city, 'province_name' ), $p ) ); $groups[$p]['cities'][] = array( 'value' => $c, 'label' => V::text( V::fallback( V::get( $city, 'city_name' ), $c ) ) ); }
		$provinces = array(); $cities = new \stdClass(); $total = 0; foreach ( $groups as $p => $group ) { $provinces[] = array( 'value' => (string) $p, 'label' => $group['label'] ); $cities->{(string) $p} = $group['cities']; $total += count( $group['cities'] ); }
		$message = ! $groups ? 'همه شهرهای لیست سرویس‌دهنده برای این شرکت سرویس داده می‌شوند.' : ( null === $resolved ? 'این شرکت در لیست سرویس‌دهنده شناخته نشد؛ همه شهرها به‌عنوان خارج از سرویس نمایش داده می‌شود.' . ( V::truth( $snapshot->message ) ? ' ' . $snapshot->message : '' ) : $snapshot->message );
		return array( 'provider' => V::get( $provider, 'name' ), 'provider_key' => $resolved, 'used_alias' => $used, 'requested' => $aliases, 'status' => $groups ? $snapshot->status : 'unavailable', 'message' => $message, 'total_provinces' => count( $provinces ), 'total_cities' => $total, 'provinces' => $provinces, 'cities_by_province' => $cities );
	}
}
