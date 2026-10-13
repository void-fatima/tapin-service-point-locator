<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Python json.dumps(..., ensure_ascii=False) for finite JSON configuration values. */
final class ConfigurationJson {
	public static function encode( $value ): string {
		if ( PythonValue::mapping( $value ) ) {
			$parts = array();
			foreach ( (array) $value as $key => $item ) { $parts[] = self::encode( (string) $key ) . ': ' . self::encode( $item ); }
			return '{' . implode( ', ', $parts ) . '}';
		}
		if ( is_array( $value ) ) { return '[' . implode( ', ', array_map( array( self::class, 'encode' ), $value ) ) . ']'; }
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) { throw new \DomainException( 'Non-finite stored JSON needs an explicit Python/WordPress compatibility decision.' ); }
			return PythonValue::string( $value );
		}
		if ( ! is_scalar( $value ) && null !== $value ) { throw new \InvalidArgumentException( 'Configuration must contain JSON values.' ); }
		$result = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS );
		if ( false === $result ) { throw new \InvalidArgumentException( 'Invalid configuration JSON value.' ); }
		return $result;
	}

	public static function decode( $raw ) {
		if ( ! is_string( $raw ) ) { throw new \InvalidArgumentException( 'Invalid stored JSON.' ); }
		$value = json_decode( $raw );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// A source-accepted non-finite token must not silently become an empty configuration.
			$outside_strings = preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '""', $raw );
			if ( preg_match( '/\b(?:NaN|Infinity)\b/', $outside_strings ) ) { throw new \DomainException( 'Stored non-finite JSON needs an explicit compatibility decision.' ); }
			throw new \InvalidArgumentException( 'Invalid stored JSON.' );
		}
		return $value;
	}

	public static function optional( $value ): ?string {
		return PythonValue::truth( $value ) ? self::encode( $value ) : null;
	}
}
