<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** security/canonical.py and fingerprint.py; UTF-8 JSON values, no HTTP or storage. */
final class SecurityFingerprint {
	const FIELDS = array( 'userAgent', 'language', 'languages', 'platform', 'screenWidth', 'screenHeight', 'colorDepth', 'devicePixelRatio', 'timezone', 'hardwareConcurrency', 'deviceMemory', 'maxTouchPoints' );
	public static function client_id( $raw ): string {
		if ( null === $raw || is_bool( $raw ) ) { return ''; }
		$text = strtolower( V::trim( V::string( $raw ) ) );
		return preg_match( '/^[0-9a-f]{32}$/D', $text ) ? $text : '';
	}
	public static function forged( $raw ): bool { return null !== $raw && ( is_bool( $raw ) || ( '' !== V::trim( V::string( $raw ) ) && '' === self::client_id( $raw ) ) ); }
	public static function coerce( $value ) {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) { return $value; }
		if ( V::listing( $value ) ) {
			$parts = array(); foreach ( $value as $item ) { if ( null !== $item ) { $text = V::trim( V::string( $item ) ); if ( '' !== $text ) { $parts[] = $text; } } }
			return $parts ? implode( ',', $parts ) : null;
		}
		$text = V::trim( V::string( $value ) ); if ( '' === $text ) { return null; }
		if ( preg_match( '/^-?\p{Nd}+$/uD', $text ) ) { return V::integer( $text ); }
		if ( preg_match( '/^-?\p{Nd}+\.\p{Nd}+$/uD', $text ) ) { return V::number( $text ); }
		return $text;
	}
	private static function escape( string $text ): string { return str_replace( array( '\\', "\n", "\r", '=' ), array( '\\\\', '\\n', '\\r', '\\=' ), $text ); }
	public static function scalar( $value ): string {
		if ( null === $value ) { return 'null'; }
		if ( is_bool( $value ) ) { return $value ? 'true' : 'false'; }
		if ( is_int( $value ) ) { return (string) $value; }
		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) { throw new \InvalidArgumentException( 'cannot canonicalize non-finite float' ); }
			$previous = ini_get( 'serialize_precision' );
			try { ini_set( 'serialize_precision', '-1' ); $text = json_encode( $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR ); }
			finally { ini_set( 'serialize_precision', $previous ); }
			$negative = '-' === $text[0]; $text = ltrim( strtolower( $text ), '-' );
			$parts = explode( 'e', $text ); $mantissa = $parts[0]; $power = (int) ( $parts[1] ?? 0 );
			$point = ( false === strpos( $mantissa, '.' ) ? strlen( $mantissa ) : strpos( $mantissa, '.' ) ) + $power; $digits = str_replace( '.', '', $mantissa );
			if ( $point <= 0 ) { $plain = '0.' . str_repeat( '0', -$point ) . $digits; }
			elseif ( $point >= strlen( $digits ) ) { $plain = $digits . str_repeat( '0', $point - strlen( $digits ) ); }
			else { $plain = substr( $digits, 0, $point ) . '.' . substr( $digits, $point ); }
			if ( false !== strpos( $plain, '.' ) ) { $plain = rtrim( rtrim( $plain, '0' ), '.' ); }
			return ( $negative && '0' !== $plain ? '-' : '' ) . $plain;
		}
		return self::escape( V::string( $value ) );
	}
	private static function walk( $node, string $prefix, array &$lines, int $depth, int &$budget ): void {
		if ( $depth > 10 ) { throw new \InvalidArgumentException( 'canonical document too deep' ); }
		if ( --$budget < 0 ) { throw new \InvalidArgumentException( 'canonical document too large' ); }
		if ( V::mapping( $node ) ) {
			$keys = array_keys( (array) $node ); sort( $keys, SORT_STRING );
			foreach ( $keys as $key ) { self::walk( V::get( $node, $key ), '' !== $prefix ? $prefix . '.' . $key : (string) $key, $lines, $depth + 1, $budget ); }
		} elseif ( V::listing( $node ) ) { foreach ( $node as $i => $item ) { self::walk( $item, $prefix . '[' . $i . ']', $lines, $depth + 1, $budget ); } }
		else { $lines[] = $prefix . '=' . self::scalar( $node ); }
	}
	public static function document( $value ): string { $lines = array(); $budget = 10000; self::walk( $value, '', $lines, 0, $budget ); return implode( "\n", $lines ); }
	/** fingerprint, coerced wire-field map, malformed flag, present count, canonical document. */
	public static function browser( $raw ): array {
		$malformed = ! V::mapping( $raw ); $signals = $lines = array(); $present = 0;
		foreach ( self::FIELDS as $name ) { $value = $malformed ? null : self::coerce( V::get( $raw, $name ) ); $signals[$name] = $value; if ( null !== $value ) { $present++; } $lines[] = $name . '=' . self::scalar( $value ); }
		$document = implode( "\n", $lines ); return array( hash( 'sha256', $document ), (object) $signals, $malformed, $present, $document );
	}
	public static function client( string $id, string $browser ): string { return hash( 'sha256', "browser={$browser}\nclient_id={$id}" ); }
	public static function request( $payload ): array { $doc = self::document( V::mapping( $payload ) ? $payload : new \stdClass() ); return array( hash( 'sha256', $doc ), $doc ); }
	public static function context( string $client, string $ip, string $agent, string $route, string $request ): string { return hash( 'sha256', "agent={$agent}\nclient={$client}\nip={$ip}\nrequest={$request}\nroute={$route}" ); }
}
