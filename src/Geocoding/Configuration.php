<?php
namespace Tapin\ServicePointLocator\Geocoding;

defined( 'ABSPATH' ) || exit;

final class Configuration {
	public static function provider(): GeocoderInterface {
		$key = defined( 'TAPIN_NESHAN_API_KEY' ) ? (string) TAPIN_NESHAN_API_KEY : (string) getenv( 'TAPIN_NESHAN_API_KEY' );
		$plus = defined( 'TAPIN_NESHAN_PLUS' ) && TAPIN_NESHAN_PLUS;
		$default = new NeshanGeocoder( $key, $plus );
		$provider = apply_filters( 'tapin_geocoder', $default );
		return $provider instanceof GeocoderInterface ? $provider : $default;
	}
}
