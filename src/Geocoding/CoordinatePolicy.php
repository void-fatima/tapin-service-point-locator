<?php
namespace Tapin\ServicePointLocator\Geocoding;

defined( 'ABSPATH' ) || exit;

final class CoordinatePolicy {
	public static function valid( array $point ): bool {
		foreach ( array( 'latitude' => 90, 'longitude' => 180 ) as $field => $limit ) {
			if ( ! isset( $point[$field] ) || ! is_numeric( $point[$field] ) || ! is_finite( (float) $point[$field] ) || abs( (float) $point[$field] ) > $limit ) { return false; }
		}
		return true;
	}

	/** Imports cannot erase known coordinates or provenance when columns are blank. */
	public static function prepare( array $data, ?array $existing, string $origin ): array {
		$metadata = is_array( $existing['metadata'] ?? null ) ? $existing['metadata'] : array();
		$incoming = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();
		$data['metadata'] = array_replace( $metadata, $incoming );
		// Only this policy/service may assign coordinate provenance, not uploaded JSON.
		unset( $data['metadata']['coordinate_source'], $data['metadata']['geocoding'] );
		foreach ( array( 'coordinate_source', 'geocoding' ) as $key ) {
			if ( isset( $metadata[$key] ) ) { $data['metadata'][$key] = $metadata[$key]; }
		}
		if ( self::valid( $data ) ) {
			$same = $existing && self::valid( $existing ) && (float) $data['latitude'] === (float) $existing['latitude'] && (float) $data['longitude'] === (float) $existing['longitude'];
			if ( ! $same || 'uploaded' === $origin ) {
				$data['metadata']['coordinate_source'] = $origin;
				unset( $data['metadata']['geocoding'] );
			}
		} elseif ( $existing && self::valid( $existing ) ) {
			$data['latitude'] = $existing['latitude'];
			$data['longitude'] = $existing['longitude'];
		} else {
			$data['latitude'] = null; $data['longitude'] = null;
			unset( $data['metadata']['coordinate_source'] );
		}
		return $data;
	}
}
