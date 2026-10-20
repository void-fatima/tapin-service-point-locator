<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** JSON value semantics for the pure port; no WP sanitizers or loose casts. */
final class PythonValue {
	public static function mapping( $value ): bool {
		return $value instanceof \stdClass || ( is_array( $value ) && ! self::listing( $value ) );
	}
	public static function listing( $value ): bool {
		return is_array( $value ) && ( ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
	}
	public static function get( $value, $key, $default = null ) {
		$map = $value instanceof \stdClass ? get_object_vars( $value ) : ( is_array( $value ) ? $value : array() );
		return array_key_exists( $key, $map ) ? $map[$key] : $default;
	}
	public static function has( $value, $key ): bool {
		return array_key_exists( $key, (array) $value );
	}
	public static function object( array $values = array() ): \stdClass { return (object) $values; }
	public static function truth( $value ): bool {
		if ( is_string( $value ) ) { return '' !== $value; }
		if ( $value instanceof \stdClass ) { return (bool) get_object_vars( $value ); }
		return (bool) $value;
	}
	public static function fallback( $value, $default ) { return self::truth( $value ) ? $value : $default; }
	public static function trim( string $value ): string { return preg_replace( '/^[\s\x{001c}-\x{001f}]+|[\s\x{001c}-\x{001f}]+$/u', '', $value ); }
	/** Python numeric constructors reject U+001c..001f even though str.strip removes them. */
	private static function numeric_trim( string $value ): string { return preg_replace( '/^\s+|\s+$/u', '', $value ); }
	public static function text( $value ): string { return null === $value ? '' : self::trim( self::string( $value ) ); }
	public static function length( string $value ): int { return preg_match_all( '/./us', $value ); }
	public static function slice( string $value, int $length ): string { preg_match_all( '/./us', $value, $chars ); return implode( '', array_slice( $chars[0], 0, $length ) ); }
	public static function string( $value ): string {
		if ( null === $value ) { return 'None'; }
		if ( is_bool( $value ) ) { return $value ? 'True' : 'False'; }
		if ( is_float( $value ) ) {
			if ( is_nan( $value ) ) { return 'nan'; }
			if ( is_infinite( $value ) ) { return $value < 0 ? '-inf' : 'inf'; }
			$serialize_precision = ini_get( 'serialize_precision' );
			$restore_precision = '-1' !== $serialize_precision && false !== @ini_set( 'serialize_precision', '-1' );
			try {
				$text = json_encode( $value, JSON_PRESERVE_ZERO_FRACTION );
			} finally {
				if ( $restore_precision ) { @ini_set( 'serialize_precision', $serialize_precision ); }
			}
			if ( 0.0 !== $value && abs( $value ) >= 1.0e16 && false === stripos( $text, 'e' ) ) {
				$digits = ltrim( preg_replace( '/\.0$/', '', $text ), '-' );
				$text = ( $value < 0 ? '-' : '' ) . $digits[0] . '.' . substr( $digits, 1 ) . 'e+' . ( strlen( $digits ) - 1 );
			}
			if ( false !== stripos( $text, 'e' ) ) {
				list( $mantissa, $exponent ) = explode( 'e', strtolower( $text ) );
				$power = (int) $exponent;
				if ( $power >= -4 && $power < 16 ) {
					$negative = '-' === $mantissa[0]; $unsigned = ltrim( $mantissa, '-' );
					$point = ( false === strpos( $unsigned, '.' ) ? strlen( $unsigned ) : strpos( $unsigned, '.' ) ) + $power;
					$digits = str_replace( '.', '', $unsigned );
					if ( $point <= 0 ) { $unsigned = '0.' . str_repeat( '0', -$point ) . $digits; }
					elseif ( $point >= strlen( $digits ) ) { $unsigned = $digits . str_repeat( '0', $point - strlen( $digits ) ) . '.0'; }
					else { $unsigned = substr( $digits, 0, $point ) . '.' . substr( $digits, $point ); }
					$text = ( $negative ? '-' : '' ) . $unsigned;
				}
				else { $text = rtrim( rtrim( $mantissa, '0' ), '.' ) . 'e' . ( $power < 0 ? '-' : '+' ) . str_pad( (string) abs( $power ), 2, '0', STR_PAD_LEFT ); }
			}
			return $text;
		}
		if ( self::mapping( $value ) ) {
			$parts = array(); foreach ( (array) $value as $key => $item ) { $parts[] = self::repr( (string) $key ) . ': ' . self::repr( $item ); }
			return '{' . implode( ', ', $parts ) . '}';
		}
		if ( is_array( $value ) ) { return '[' . implode( ', ', array_map( array( self::class, 'repr' ), $value ) ) . ']'; }
		return (string) $value;
	}
	private static function repr( $value ): string {
		if ( ! is_string( $value ) ) { return self::string( $value ); }
		$quote = false !== strpos( $value, "'" ) && false === strpos( $value, '"' ) ? '"' : "'";
		$text = str_replace( array( '\\', $quote ), array( '\\\\', '\\' . $quote ), $value );
		$text = preg_replace_callback( '/[\p{C}\p{Z}]/u', static function( $match ) {
			if ( ' ' === $match[0] ) { return ' '; }
			if ( "\n" === $match[0] ) { return '\\n'; }
			if ( "\r" === $match[0] ) { return '\\r'; }
			if ( "\t" === $match[0] ) { return '\\t'; }
			$code = self::codepoint( $match[0] );
			return ( $code <= 0xff ? '\\x' : ( $code <= 0xffff ? '\\u' : '\\U' ) ) . str_pad( dechex( $code ), $code <= 0xff ? 2 : ( $code <= 0xffff ? 4 : 8 ), '0', STR_PAD_LEFT );
		}, $text );
		return $quote . $text . $quote;
	}
	private static function codepoint( string $character ): int {
		$bytes = array_values( unpack( 'C*', $character ) );
		$code = $bytes[0] < 128 ? $bytes[0] : ( $bytes[0] & ( 0x7f >> count( $bytes ) ) );
		for ( $i = 1; $i < count( $bytes ); $i++ ) { $code = ( $code << 6 ) | ( $bytes[$i] & 63 ); }
		return $code;
	}
	private static function digits( string $text ): string {
		// Decimal-digit blocks admitted by Python int()/float(); PCRE supplies Unicode matching.
		$starts = array( 0x30, 0x660, 0x6f0, 0x7c0, 0x966, 0x9e6, 0xa66, 0xae6, 0xb66, 0xbe6, 0xc66, 0xce6, 0xd66, 0xde6, 0xe50, 0xed0, 0xf20, 0x1040, 0x1090, 0x17e0, 0x1810, 0x1946, 0x19d0, 0x1a80, 0x1a90, 0x1b50, 0x1bb0, 0x1c40, 0x1c50, 0xa620, 0xa8d0, 0xa900, 0xa9d0, 0xa9f0, 0xaa50, 0xabf0, 0xff10 );
		return preg_replace_callback( '/\p{Nd}/u', static function( $match ) use ( $starts ) {
			$code = self::codepoint( $match[0] );
			foreach ( $starts as $start ) { if ( $code >= $start && $code < $start + 10 ) { return (string) ( $code - $start ); } }
			throw new \DomainException( 'This Unicode digit block is outside the executed PHP compatibility corpus.' );
		}, $text );
	}
	public static function integer( $value ): int {
		if ( is_int( $value ) ) { return $value; }
		if ( is_bool( $value ) ) { return $value ? 1 : 0; }
		if ( is_float( $value ) ) { return self::bounded_integer( $value ); }
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'invalid integer' ); }
		$text = self::digits( self::numeric_trim( $value ) );
		if ( ! preg_match( '/^[+-]?[0-9](?:_?[0-9])*$/D', $text ) ) { throw new \InvalidArgumentException( 'invalid integer' ); }
		$text = str_replace( '_', '', $text );
		$negative = '-' === substr( $text, 0, 1 );
		$digits = ltrim( ltrim( $text, '+-' ), '0' );
		$limit = $negative ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) { throw new \DomainException( 'Python integers beyond PHP_INT range need an explicit compatibility decision.' ); }
		return (int) $text;
	}
	public static function bounded_integer( float $value ): int {
		if ( is_nan( $value ) ) { throw new \InvalidArgumentException( 'cannot convert float NaN to integer' ); }
		if ( is_infinite( $value ) || $value >= (float) PHP_INT_MAX || $value < (float) PHP_INT_MIN ) { throw new \DomainException( 'Python integer result cannot be represented by PHP.' ); }
		return (int) $value;
	}
	public static function number( $value ): float {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { $number = (float) $value; }
		elseif ( is_string( $value ) ) {
			$text = self::digits( self::numeric_trim( $value ) );
			if ( preg_match( '/^([+-]?)(inf(?:inity)?|nan)$/iD', $text, $special ) ) { return 'nan' === strtolower( $special[2] ) ? NAN : ( '-' === $special[1] ? -INF : INF ); }
			if ( ! preg_match( '/^[+-]?(?:(?:[0-9](?:_?[0-9])*)(?:\.(?:[0-9](?:_?[0-9])*)?)?|\.[0-9](?:_?[0-9])*)(?:[eE][+-]?[0-9](?:_?[0-9])*)?$/D', $text ) ) { throw new \InvalidArgumentException( 'invalid number' ); }
			$number = (float) str_replace( '_', '', $text );
		} else { throw new \InvalidArgumentException( 'invalid number' ); }
		return $number;
	}
	public static function digit_string( string $text ): bool { return 1 === preg_match( '/^\p{Nd}+$/uD', $text ); }
	public static function http_url( string $text ): bool {
		$text = str_replace( array( "\n", "\r", "\t" ), '', $text );
		$text = preg_replace( '/^[\x00-\x20]+/', '', $text );
		// urlsplit().hostname does not validate the port. parse_url() would change acceptance.
		if ( ! preg_match( '~^([A-Za-z][A-Za-z0-9+.-]*):\/\/([^/?#]*)~', $text, $parts ) ) { return false; }
		$authority = $parts[2]; $position = strrpos( $authority, '@' ); if ( false !== $position ) { $authority = substr( $authority, $position + 1 ); }
		if ( false !== strpos( $authority, '[' ) || false !== strpos( $authority, ']' ) ) {
			if ( ! preg_match( '/\[([^\]]+)\]/', $authority, $host ) ) { throw new \InvalidArgumentException( 'Invalid IPv6 URL' ); }
			if ( ! filter_var( $host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) && ! preg_match( '/^v[0-9a-fA-F]+\..+$/', $host[1] ) ) { throw new \InvalidArgumentException( 'Invalid bracketed URL host' ); }
			$hostname = $host[1];
		} else { $hostname = explode( ':', $authority, 2 )[0]; }
		return in_array( strtolower( $parts[1] ), array( 'http', 'https' ), true ) && '' !== $hostname;
	}
}
