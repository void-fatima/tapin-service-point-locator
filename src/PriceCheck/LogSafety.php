<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Reject known source credential fields; callers must also sanitize opaque diagnostic text. */
final class LogSafety {
	public static function assert_safe( $value ): void {
		if ( PythonValue::mapping( $value ) || is_array( $value ) ) {
			foreach ( (array) $value as $key => $item ) {
				if ( in_array( strtolower( (string) $key ), array( 'token', 'authorization', 'proxy-authorization' ), true ) ) {
					throw new \InvalidArgumentException( 'Credential fields must be omitted from operational logs.' );
				}
				self::assert_safe( $item );
			}
		} elseif ( is_string( $value ) ) {
			if ( preg_match( '/\b(?:Bearer\s+\S+|Authorization\s*:)/i', $value ) ) { throw new \InvalidArgumentException( 'Authorization text must be omitted from operational logs.' ); }
			// Raw carrier responses and event detail can themselves be JSON strings.
			$decoded = json_decode( $value );
			if ( JSON_ERROR_NONE === json_last_error() && ( $decoded instanceof \stdClass || is_array( $decoded ) ) ) { self::assert_safe( $decoded ); }
		}
	}
	public static function endpoint( string $value ): void {
		$parts = parse_url( $value );
		if ( ! is_array( $parts ) ) { return; }
		parse_str( $parts['query'] ?? '', $query );
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || array_intersect( array_map( 'strtolower', array_keys( $query ) ), array( 'token', 'authorization' ) ) ) {
			throw new \InvalidArgumentException( 'Credential-bearing URLs must be omitted from operational logs.' );
		}
	}
}
