<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Normalization\DataNormalizer;

defined( 'ABSPATH' ) || exit;

/** Exact configured identities, or an explicit per-label import choice. */
final class ProviderResolver {
	public static function resolve( string $value, array $providers, array $mapping = array() ): int {
		if ( '' === self::normalize( $value ) ) { return 0; }
		if ( isset( $mapping[$value] ) ) {
			if ( ! is_scalar( $mapping[$value] ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $mapping[$value] ) ) { return 0; }
			foreach ( $providers as $provider ) { if ( (int) $provider['id'] === (int) $mapping[$value] ) { return (int) $provider['id']; } }
			return 0;
		}
		$value = self::normalize( $value );
		if ( '' === $value ) { return 0; }
		$matches = array();
		foreach ( $providers as $provider ) {
			if ( $value === self::normalize( $provider['name'] ) || $value === $provider['slug'] || $value === (string) $provider['id'] ) { $matches[] = (int) $provider['id']; }
		}
		if ( ! $matches && 'پست' === $value ) {
			foreach ( $providers as $provider ) { if ( 'post' === $provider['slug'] ) { $matches[] = (int) $provider['id']; } }
		}
		return 1 === count( $matches ) ? $matches[0] : 0;
	}
	private static function normalize( string $value ): string {
		return DataNormalizer::to_latin_digits( DataNormalizer::strtolower( DataNormalizer::normalize_persian_text( str_replace( "\u{200c}", ' ', $value ) ) ?? '' ) );
	}
}
