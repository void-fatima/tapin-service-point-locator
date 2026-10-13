<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** providers.py currency/weight helpers, including pass-through and rounding. */
final class Units {
	public static function currency( $value ): string { return is_string( $value ) && in_array( strtolower( PythonValue::trim( $value ) ), array( 'toman', 'تومان' ), true ) ? 'toman' : 'rial'; }
	public static function weight( $value ): string { return is_string( $value ) && in_array( strtolower( str_replace( 'K', 'k', PythonValue::trim( $value ) ) ), array( 'kilogram', 'کیلوگرم', 'kg', 'kilo' ), true ) ? 'kilogram' : 'gram'; }
	public static function currency_pair( $provider ): array { return array( self::currency( PythonValue::get( $provider, 'price_currency_in' ) ), self::currency( PythonValue::get( $provider, 'price_currency_out' ) ) ); }
	public static function weight_pair( $provider ): array { return array( self::weight( PythonValue::get( $provider, 'weight_unit_in' ) ), self::weight( PythonValue::get( $provider, 'weight_unit_out' ) ) ); }
	private static function numeric( $value ) {
		if ( ! is_string( $value ) || '' === PythonValue::trim( $value ) ) { return $value; }
		$text = PythonValue::trim( $value );
		try { return PythonValue::integer( $text ); }
		catch ( \InvalidArgumentException $e ) { try { return PythonValue::number( $text ); } catch ( \InvalidArgumentException $e ) { return $value; } }
	}
	public static function currency_amount( $value, $from, $to ) {
		if ( self::currency( $from ) === self::currency( $to ) ) { return $value; }
		$value = self::numeric( $value );
		if ( ! is_int( $value ) && ! is_float( $value ) ) { return $value; }
		if ( 'rial' === self::currency( $from ) ) {
			$divided = (float) $value / 10.0;
			return $divided >= 0 ? PythonValue::bounded_integer( $divided + 0.5 ) : -PythonValue::bounded_integer( -$divided + 0.5 );
		}
		$scaled = $value * 10;
		if ( is_int( $value ) && ! is_int( $scaled ) ) { throw new \DomainException( 'Currency integer multiplication exceeds PHP_INT range.' ); }
		return is_int( $value ) ? $scaled : (float) $scaled;
	}
	public static function weight_amount( $value, $from, $to ) {
		if ( self::weight( $from ) === self::weight( $to ) ) { return $value; }
		$value = self::numeric( $value );
		if ( ! is_int( $value ) && ! is_float( $value ) ) { return $value; }
		if ( 'kilogram' === self::weight( $from ) ) {
			$scaled = $value * 1000;
			if ( is_int( $value ) && ! is_int( $scaled ) ) { throw new \DomainException( 'Weight integer multiplication exceeds PHP_INT range.' ); }
			return is_int( $value ) ? $scaled : (float) $scaled;
		}
		$divided = (float) $value / 1000.0;
		return is_finite( $divided ) && floor( $divided ) === $divided ? PythonValue::bounded_integer( $divided ) : $divided;
	}
}
