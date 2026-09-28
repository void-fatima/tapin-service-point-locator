<?php
namespace Tapin\ServicePointLocator\Service;

defined( 'ABSPATH' ) || exit;

/** Share trusted presentation/query fields without exposing private evidence. */
final class PointEvidence {
	public static function fields( array $point ): array {
		$evidence = $point['metadata']['tapin_reconciliation'] ?? array();
		if ( 'verified' !== ( $evidence['result'] ?? '' ) || ! is_string( $evidence['source_url'] ?? null ) || ! preg_match( '~^https://tapin\.ir/map/[a-z0-9-]+\.pdf$~D', $evidence['source_url'] ) ) { return $point; }
		foreach ( array( 'name', 'province', 'city', 'address', 'postal_code', 'landline_phone' ) as $field ) {
			$value = $evidence['official'][$field] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) { $point[$field] = $value; }
		}
		return $point;
	}
}
