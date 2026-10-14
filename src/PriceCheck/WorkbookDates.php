<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** openpyxl date/time values rendered as Python str(), as the import-preview API consumes them. */
final class WorkbookDates {
	public static function format( string $format ): array {
		$format = explode( ';', $format, 2 )[0]; $elapsed = (bool) preg_match( '/\[(?:hh?|mm?|ss?)\]/i', $format );
		$text = preg_replace( '/".*?"|\[(?!hh?\]|mm?\]|ss?\])[^\]]*\]/', '', $format );
		return array( (bool) preg_match( '/(?<![_\\\\])[dmhys]/i', $text ), $elapsed );
	}
	private static function time( int $milliseconds ): string { $seconds = intdiv( $milliseconds, 1000 ); return sprintf( '%02d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds, 60 ) % 60, $seconds % 60 ) . ( $milliseconds % 1000 ? sprintf( '.%06d', ( $milliseconds % 1000 ) * 1000 ) : '' ); }
	private static function duration( float $seconds ): string {
		$micro = PythonValue::bounded_integer( round( $seconds * 1000000, 0, PHP_ROUND_HALF_EVEN ) ); $days = intdiv( $micro, 86400000000 ); $rest = $micro % 86400000000; if ( $rest < 0 ) { $days--; $rest += 86400000000; } $whole = intdiv( $rest, 1000000 );
		return ( $days ? $days . ( 1 === $days || -1 === $days ? ' day, ' : ' days, ' ) : '' ) . intdiv( $whole, 3600 ) . sprintf( ':%02d:%02d', intdiv( $whole, 60 ) % 60, $whole % 60 ) . ( $rest % 1000000 ? sprintf( '.%06d', $rest % 1000000 ) : '' );
	}
	public static function serial( $value, bool $mac = false, bool $elapsed = false ): string {
		$value = (float) $value; if ( ! is_finite( $value ) ) { throw new \DomainException( 'Workbook date must be finite.' ); }
		if ( $elapsed ) { return self::duration( round( $value * 86400000, 0, PHP_ROUND_HALF_EVEN ) / 1000 ); }
		$day = PythonValue::bounded_integer( floor( $value ) ); if ( $day < -700000 || $day > 3000000 ) { throw new \DomainException( 'Workbook date is outside the Python calendar range.' ); } $ms = (int) round( ( $value - $day ) * 86400000, 0, PHP_ROUND_HALF_EVEN );
		if ( $value >= 0 && $value < 1 && $ms < 86400000 ) { return self::time( $ms ); }
		if ( ! $mac && $value > 0 && $value < 60 ) { $day++; }
		$moment = ( new \DateTimeImmutable( $mac ? '1904-01-01' : '1899-12-30', new \DateTimeZone( 'UTC' ) ) )->modify( (string) ( $day + intdiv( $ms, 86400000 ) ) . ' days' );
		$year = (int) $moment->format( 'Y' ); if ( $year < 1 || $year > 9999 ) { throw new \DomainException( 'Workbook date is outside the Python calendar range.' ); }
		return $moment->format( 'Y-m-d' ) . ' ' . self::time( $ms % 86400000 );
	}
	public static function iso( string $text ): ?string {
		if ( '' === $text ) { return null; }
		if ( preg_match( '/^(?:(\p{Nd}{4})-(\p{Nd}{2})-(\p{Nd}{2}))?T?(?:(\p{Nd}{2}):(\p{Nd}{2})(?::(\p{Nd}{2})(\.\p{Nd}{1,3})?)?)?Z?/u', $text, $m ) && ( ! empty( $m[1] ) || ! empty( $m[4] ) ) ) {
			$parts = array(); for ( $i = 1; $i <= 6; $i++ ) { $parts[$i] = PythonValue::integer( '' === ( $m[$i] ?? '' ) ? '0' : $m[$i] ); } [$year, $month, $day, $hour, $minute, $second] = array_values( $parts ); $has_date = '' !== ( $m[1] ?? '' ); $has_time = '' !== ( $m[4] ?? '' );
			if ( $hour > 23 || $minute > 59 || $second > 59 || ( $has_date && ( $year < 1 || ! checkdate( $month, $day, $year ) ) ) ) { throw new \RuntimeException( 'Invalid workbook ISO date.' ); } $time = sprintf( '%02d:%02d:%02d', $hour, $minute, $second ); $micro = empty( $m[7] ) ? 0 : (int) ( PythonValue::number( $m[7] ) * 1000000 ); if ( $micro ) { $time .= sprintf( '.%06d', $micro ); } return $has_date ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) . ( $has_time ? ' ' . $time : '' ) : $time;
		}
		if ( preg_match( '/^PT(?:(\p{Nd}+)H)?(?:(\p{Nd}+)M)?(?:(\p{Nd}+(?:\.\p{Nd}{1,3})?)S)?/u', $text, $m ) && count( $m ) > 1 ) { return self::duration( PythonValue::number( '' === ( $m[1] ?? '' ) ? '0' : $m[1] ) * 3600 + PythonValue::number( '' === ( $m[2] ?? '' ) ? '0' : $m[2] ) * 60 + PythonValue::number( '' === ( $m[3] ?? '' ) ? '0' : $m[3] ) ); }
		throw new \RuntimeException( 'Invalid workbook ISO date.' );
	}
}
