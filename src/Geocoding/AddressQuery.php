<?php
namespace Tapin\ServicePointLocator\Geocoding;

use Tapin\ServicePointLocator\Normalization\DataNormalizer as N;
use Tapin\ServicePointLocator\Service\PointEvidence;

defined( 'ABSPATH' ) || exit;

final class AddressQuery {
	public static function build( array $point ) {
		$result = $point['metadata']['tapin_reconciliation']['result'] ?? '';
		if ( in_array( $result, array( 'conflict', 'probable_match', 'stale' ), true ) ) { return new \WP_Error( 'source_conflict', 'Resolve source evidence before geocoding.' ); }
		$point = PointEvidence::fields( $point );
		$fields = array();
		foreach ( array( 'province', 'city', 'address' ) as $key ) {
			$fields[$key] = trim( preg_replace( '/\s+/u', ' ', N::normalize_location( $point[$key] ?? '' ) ) );
		}
		// A city/province name alone is not a branch address.
		if ( '' === $fields['province'] || N::strlen( $fields['address'] ) < 12 || in_array( $fields['address'], array( $fields['province'], $fields['city'] ), true ) ) { return new \WP_Error( 'insufficient_address', 'A specific normalized address and province are required.' ); }
		$fields['address'] = implode( '، ', array_filter( array( 'ایران', $fields['province'], $fields['city'], $fields['address'] ) ) );
		return $fields;
	}
	public static function hash( array $query, string $provider ): string {
		return hash( 'sha256', 'policy-1|' . $provider . '|' . wp_json_encode( $query ) );
	}
}
