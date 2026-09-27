<?php

namespace Tapin\ServicePointLocator\Normalization;

defined( 'ABSPATH' ) || exit;

/**
 * Handles text, numeric, telephone, coordinate, and status normalization.
 * Tailored for Iranian logistics and Persian/Arabic character handling.
 */
final class DataNormalizer {

	/**
	 * Persian and Eastern Arabic digits mapping to standard ASCII digits.
	 */
	private const DIGIT_MAP = array(
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	);

	/**
	 * Converts Persian/Arabic digits to standard ASCII digits.
	 */
	public static function to_latin_digits( string $value ): string {
		return strtr( $value, self::DIGIT_MAP );
	}

	/**
	 * Safe lowercase supporting UTF-8 if mbstring extension is present.
	 */
	public static function strtolower( string $string ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $string, 'UTF-8' );
		}
		return strtolower( $string );
	}

	/**
	 * Safe string length supporting UTF-8 if mbstring extension is present.
	 */
	public static function strlen( string $string ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $string, 'UTF-8' );
		}
		return strlen( $string );
	}

	/**
	 * Normalizes empty and placeholder strings to null.
	 */
	public static function normalize_empty( $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$string = trim( (string) $value );
		if ( '' === $string ) {
			return null;
		}

		$placeholders = array( 'null', 'nil', 'none', 'n/a', '-', '--', '---', 'undefined' );
		if ( in_array( strtolower( $string ), $placeholders, true ) ) {
			return null;
		}

		return $string;
	}

	/**
	 * Cleans and standardizes Persian text:
	 * - Unifies Arabic Yeh/Kaf to standard Persian characters.
	 * - Strips redundant whitespace while preserving ZWNJ (نیم‌فاصله).
	 */
	public static function normalize_persian_text( ?string $text ): ?string {
		$clean = self::normalize_empty( $text );
		if ( null === $clean ) {
			return null;
		}

		// Replace Arabic Yeh (ي, ى) with Persian Yeh (ی).
		$clean = str_replace( array( "\xD9\x8A", "\xD9\x89" ), 'ی', $clean );

		// Replace Arabic Kaf (ك) with Persian Kaf (ک).
		$clean = str_replace( "\xD9\x83", 'ک', $clean );

		// Replace Arabic Ta Marbuta (ة) with standard Heh (ه) or Teh (ت) where appropriate.
		$clean = str_replace( "\xD8\xA9", 'ه', $clean );

		// Normalize multiple spaces (excluding newlines in addresses).
		$clean = preg_replace( '/[^\S\r\n]+/u', ' ', $clean );

		return trim( (string) $clean );
	}

	/**
	 * Normalizes Iranian landline or mobile phone numbers.
	 * Preserves meaningful extension or secondary details if present.
	 */
	public static function normalize_phone( ?string $phone ): ?string {
		$raw = self::normalize_empty( $phone );
		if ( null === $raw ) {
			return null;
		}

		// Convert Persian/Arabic digits first.
		$latin = self::to_latin_digits( $raw );

		// If phone contains extensions or notes (e.g. "021-88990011 داخلی 12"), preserve note.
		$has_note = preg_match( '/[\p{L}]/u', $latin );
		if ( $has_note ) {
			// Clean spaces and dashes around Persian notes.
			return trim( preg_replace( '/\s+/', ' ', $latin ) );
		}

		// Clean non-digit characters except leading plus.
		$digits = preg_replace( '/[^\d+]/', '', $latin );

		// Convert international prefix +98 or 0098 to national prefix 0.
		if ( 0 === strpos( $digits, '+98' ) ) {
			$digits = '0' . substr( $digits, 3 );
		} elseif ( 0 === strpos( $digits, '0098' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && strlen( $digits ) >= 10 ) {
			$digits = '0' . substr( $digits, 2 );
		}

		return $digits ?: null;
	}

	/**
	 * Normalizes postal codes to 10-digit clean string if possible.
	 */
	public static function normalize_postal_code( ?string $code ): ?string {
		$raw = self::normalize_empty( $code );
		if ( null === $raw ) {
			return null;
		}

		$digits = preg_replace( '/[^\d]/', '', self::to_latin_digits( $raw ) );

		return $digits ?: null;
	}

	/**
	 * Normalizes coordinate strings (latitude / longitude) into valid float or null.
	 */
	public static function normalize_coordinate( $coord ): ?float {
		$raw = self::normalize_empty( $coord );
		if ( null === $raw ) {
			return null;
		}

		// Convert Persian digits and replace Persian/Arabic decimal separators.
		$clean = self::to_latin_digits( $raw );
		$clean = str_replace( array( '٫', ',' ), '.', $clean );
		$clean = trim( $clean );

		if ( ! is_numeric( $clean ) ) {
			return NAN; // Preserve invalid input so validation cannot silently accept it as missing.
		}

		return (float) $clean;
	}

	/**
	 * Normalizes status values to 'active' or 'inactive'.
	 */
	public static function normalize_status( $status ): string {
		$raw = strtolower( trim( (string) ( self::normalize_empty( $status ) ?? 'active' ) ) );

		$inactive_terms = array(
			'inactive', '0', 'false', 'disabled', 'disable',
			'غیرفعال', 'غیر فعال', 'تعطیل', 'بسته'
		);

		if ( in_array( $raw, $inactive_terms, true ) ) {
			return 'inactive';
		}

		return in_array( $raw, array( 'active', '1', 'true', 'enabled', 'فعال' ), true ) ? 'active' : $raw;
	}

	/**
	 * Normalizes an entire service-point raw record array.
	 *
	 * @param array $raw
	 * @return array
	 */
	public static function normalize_service_point( array $raw ): array {
		$code = self::normalize_empty( $raw['code'] ?? null );
		if ( null !== $code ) {
			$code = self::to_latin_digits( $code );
		}

		return array(
			'provider_id' => isset( $raw['provider_id'] ) ? (int) $raw['provider_id'] : 0,
			'code'        => $code,
			'name'        => self::normalize_persian_text( $raw['name'] ?? null ) ?? '',
			'province'    => self::normalize_persian_text( $raw['province'] ?? null ) ?? '',
			'city'        => self::normalize_persian_text( $raw['city'] ?? null ) ?? '',
			'address'     => self::normalize_persian_text( $raw['address'] ?? null ) ?? '',
			'postal_code' => self::normalize_postal_code( $raw['postal_code'] ?? null ),
			'mobile_phone' => self::normalize_phone( $raw['mobile_phone'] ?? null ),
			'landline_phone' => self::normalize_phone( $raw['landline_phone'] ?? null ),
			'source' => self::normalize_empty( $raw['source'] ?? null ),
			'phone'       => self::normalize_phone( $raw['phone'] ?? null ),
			'latitude'    => self::normalize_coordinate( $raw['latitude'] ?? null ),
			'longitude'   => self::normalize_coordinate( $raw['longitude'] ?? null ),
			'status'      => self::normalize_status( $raw['status'] ?? 'active' ),
			'metadata'    => $raw['metadata'] ?? null,
		);
	}
}
