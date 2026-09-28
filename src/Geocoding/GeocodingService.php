<?php
namespace Tapin\ServicePointLocator\Geocoding;

defined( 'ABSPATH' ) || exit;

/** Provider-independent query, caching and conservative acceptance policy. */
final class GeocodingService {
	private GeocoderInterface $provider;
	public function __construct( GeocoderInterface $provider ) { $this->provider = $provider; }

	public function resolve( array $query ) {
		if ( ! $this->provider->configured() ) { return new \WP_Error( 'not_configured', 'Configure a server-side geocoder credential.' ); }
		$key = 'tapin_geo_' . AddressQuery::hash( $query, $this->provider->name() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['error'] ) ) { return new \WP_Error( $cached['error'], 'Address requires review.' ); }
		$candidates = is_array( $cached ) ? $cached : $this->provider->geocode( $query );
		if ( is_wp_error( $candidates ) ) { return $candidates; }
		if ( ! is_array( $candidates ) ) { return new \WP_Error( 'invalid_response', 'Invalid geocoder response.' ); }
		$accepted = array(); $reason = 'no_match';
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || ! CoordinatePolicy::valid( $candidate ) ) { $reason = 'invalid_coordinates'; continue; }
			if ( 'matched' !== ( $candidate['quality'] ?? '' ) ) { $reason = 'low_quality'; continue; }
			$province = IranBoundary::province( (float) $candidate['latitude'], (float) $candidate['longitude'] );
			if ( null === $province ) { $reason = 'outside_iran'; continue; }
			if ( IranBoundary::key( $province ) !== IranBoundary::key( $query['province'] ) ) { $reason = 'province_mismatch'; continue; }
			foreach ( array( 'province', 'city' ) as $field ) {
				if ( ! is_string( $candidate[$field] ?? '' ) || ( ! empty( $query[$field] ) && ! empty( $candidate[$field] ) && IranBoundary::key( $query[$field] ) !== IranBoundary::key( $candidate[$field] ) ) ) { $reason = $field . '_mismatch'; continue 2; }
			}
			$accepted[sprintf( '%.8f,%.8f', $candidate['latitude'], $candidate['longitude'] )] = array_intersect_key( $candidate, array_flip( array( 'latitude', 'longitude', 'quality', 'province', 'city' ) ) );
		}
		if ( 1 !== count( $accepted ) ) {
			$reason = count( $accepted ) > 1 ? 'ambiguous' : $reason;
			set_transient( $key, array( 'error' => $reason ), HOUR_IN_SECONDS );
			return new \WP_Error( $reason, 'No unambiguous, geographically consistent match.' );
		}
		// Revalidate cached candidates against current query/geometry before every use.
		set_transient( $key, array_values( $accepted ), 30 * DAY_IN_SECONDS );
		return array_values( $accepted )[0];
	}
}
