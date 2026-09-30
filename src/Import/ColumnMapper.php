<?php

namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Normalization\DataNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Maps raw import columns (Persian and English headers) to canonical service point fields.
 */
final class ColumnMapper {

	/**
	 * Canonical field mapping dictionary with known Persian and English aliases.
	 */
	private const SYNONYMS = array(
		'provider'    => array( 'ارائه‌دهنده', 'ارائه دهنده', 'نام ارائه‌دهنده', 'provider', 'provider_name', 'provider_slug', 'provider_id' ),
		'code'        => array( 'کد', 'کد شعبه', 'کد باجه', 'کد نمایندگی', 'شناسه', 'شناسه شعبه', 'code', 'branch_code', 'id', 'branch_id' ),
		'name'        => array( 'نام', 'نام شعبه', 'نام باجه', 'عنوان', 'نام نمایندگی', 'نام نقطه', 'name', 'title', 'branch_name' ),
		'province'    => array( 'استان', 'نام استان', 'province', 'state', 'ostan' ),
		'city'        => array( 'شهر', 'شهرستان', 'نام شهر', 'city', 'shahr', 'town' ),
		'address'     => array( 'آدرس', 'نشانی', 'آدرس کامل', 'محل', 'نشانی دقیق', 'address', 'full_address' ),
		'postal_code' => array( 'کد پستی', 'کدپستی', 'زیپ کد', 'postal_code', 'zip', 'zip_code', 'postalcode' ),
		'mobile_phone' => array( 'mobile_phone', 'mobile', 'موبایل', 'تلفن همراه' ),
		'landline_phone' => array( 'landline_phone', 'landline', 'تلفن ثابت' ),
		'source' => array( 'source', 'منبع' ),
		'metadata' => array( 'metadata', 'اطلاعات تکمیلی' ),
		'phone'       => array( 'تلفن', 'شماره تماس', 'شماره تلفن', 'تلفن تماس', 'موبایل', 'phone', 'tel', 'telephone', 'mobile' ),
		'latitude'    => array( 'عرض جغرافیایی', 'عرض', 'latitude', 'lat', 'y', 'geo_lat' ),
		'longitude'   => array( 'طول جغرافیایی', 'طول', 'longitude', 'lng', 'lon', 'x', 'geo_lng' ),
		'status'      => array( 'وضعیت', 'وضعیت فعالیت', 'status' ),
			'coordinates' => array( 'مختصات', 'مختصات جغرافیایی', 'موقعیت جغرافیایی', 'موقعیت مکانی', 'لوکیشن', 'coordinates', 'coords', 'coord', 'lat lng', 'lat long', 'geo' ),
	);

	/**
	 * Resolved map: raw header key => canonical field name.
	 *
	 * @var array<string, string>
	 */
	private array $mapping = array();

	/**
	 * @param array<string, string> $custom_mapping Optional explicit mapping (raw_header => canonical_field).
	 */
	public function __construct( array $custom_mapping = array() ) {
		$this->mapping = $custom_mapping;
	}

	/**
	 * Automatically resolves header names by matching against synonyms dictionary.
	 *
	 * @param array<int, string> $headers Raw headers from CSV or spreadsheet.
	 * @return array<string, string> Auto-detected mapping (raw_header => canonical_field).
	 */
	public function auto_detect_headers( array $headers ): array {
		$detected = array();

		foreach ( $headers as $raw_header ) {
			$normalized_header = self::clean_header_name( $raw_header );
			if ( '' === $normalized_header ) {
				continue;
			}

			// Check explicit custom mapping first.
			if ( isset( $this->mapping[ $raw_header ] ) ) {
				$detected[ $raw_header ] = $this->mapping[ $raw_header ];
				continue;
			}

			// Search synonyms dictionary.
			foreach ( self::SYNONYMS as $canonical_field => $aliases ) {
				if ( in_array( $canonical_field, $detected, true ) ) { continue; }
				foreach ( $aliases as $alias ) {
					if ( self::clean_header_name( $alias ) === $normalized_header ) {
						$detected[ $raw_header ] = $canonical_field;
						break 2;
					}
				}
			}
		}

		$this->mapping = array_merge( $detected, $this->mapping );

		return $this->mapping;
	}

	/**
	 * Maps a single raw row (header => value) to canonical service point fields.
	 *
	 * @param array<string, mixed> $raw_row
	 * @return array<string, mixed>
	 */
	public function map_row( array $raw_row ): array {
		$mapped = array();

		foreach ( $raw_row as $raw_header => $value ) {
			$canonical_key = $this->mapping[ $raw_header ] ?? null;
			if ( $canonical_key ) {
				$mapped[ $canonical_key ] = $value;
			}
		}

		return $mapped;
	}

	/**
	 * Standardizes header names for comparison.
	 */
	private static function clean_header_name( string $name ): string {
		$clean = DataNormalizer::normalize_persian_text( $name ) ?? '';
		$clean = DataNormalizer::strtolower( $clean );
		$clean = str_replace( array( '_', '-', '.', ':', '‌' ), ' ', $clean ); // Strip separators and ZWNJ
		$clean = preg_replace( '/\s+/u', ' ', $clean );

		return trim( (string) $clean );
	}
}
