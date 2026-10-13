<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** JSON transport decoding without silently losing Python integers or non-finite values. */
final class QuoteJson {
	public static function utf8( string $raw ): string { return json_decode( json_encode( $raw, JSON_INVALID_UTF8_SUBSTITUTE ) ); }
	public static function decode( string $text ) {
		try { $value = ConfigurationJson::decode( $text ); }
		catch ( \InvalidArgumentException $error ) {
			if ( JSON_ERROR_UTF16 === json_last_error() ) { throw new \DomainException( 'Unpaired Python JSON surrogates need an explicit PHP compatibility decision.' ); }
			throw $error;
		}
		$outside = preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '""', $text );
		preg_match_all( '/(?<![\w.])[+-]?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/', $outside, $numbers );
		foreach ( $numbers[0] as $number ) { if ( false === strpbrk( $number, '.eE' ) ) { PythonValue::integer( $number ); } }
		return $value;
	}
}
