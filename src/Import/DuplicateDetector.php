<?php

namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Database\Schema;
use Tapin\ServicePointLocator\Normalization\DataNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Practical duplicate detection strategy distinguishing:
 * 1. Definite duplicates (exact code, or same phone + city, or exact coords + city)
 * 2. Probable duplicates (similar branch name in same city, or shared phone with differing branch)
 * 3. Legitimate similar records (different providers, or multiple distinct branches in same city)
 */
class DuplicateDetector {

	private int $provider_id;

	/**
	 * In-memory index of branch codes for O(1) matching: code => id
	 *
	 * @var array<string, int>
	 */
	private array $code_index = array();

	/**
	 * In-memory index of phone numbers: phone => list of {id, city, name}
	 *
	 * @var array<string, array<int, array{id: int, city: string, name: string}>>
	 */
	private array $phone_index = array();

	/**
	 * In-memory index of branch locations: city => list of {id, name, address, lat, lng, phone}
	 *
	 * @var array<string, array<int, array{id: int, name: string, simplified_name: string, address: string, lat: ?float, lng: ?float, phone: ?string}>>
	 */
	private array $city_index = array();

	public function __construct( int $provider_id ) {
		$this->provider_id = $provider_id;
	}

	/** Indexed lookups keep web imports independent of total dataset size. */
	public function find_existing( array $record ): ?array {
		global $wpdb;
		$table = Schema::get_service_points_table();
		if ( ! empty( $record['code'] ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE provider_id = %d AND code = %s ORDER BY id LIMIT 2", $this->provider_id, $record['code'] ) );
			if ( $ids ) { return array( 'id' => (int) $ids[0], 'code_match' => count( $ids ) === 1 ); }
		}
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE provider_id = %d AND city = %s AND ((name = %s AND address = %s) OR (phone <> '' AND phone = %s)) ORDER BY id LIMIT 1", $this->provider_id, $record['city'], $record['name'], $record['address'], $record['phone'] ?? '' ) );
		return $id ? array( 'id' => (int) $id, 'code_match' => false ) : null;
	}

	/**
	 * Preloads existing records for the given provider into memory for fast batch comparison.
	 */
	public function preload_existing_records(): void {
		global $wpdb;

		$table = Schema::get_service_points_table();

		// Fetch key identifiers for existing provider records.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, code, name, city, address, phone, latitude, longitude FROM {$table} WHERE provider_id = %d",
				$this->provider_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$this->register(
				array(
					'code'      => $row['code'],
					'name'      => $row['name'],
					'city'      => $row['city'],
					'address'   => $row['address'],
					'phone'     => $row['phone'],
					'latitude'  => null !== $row['latitude'] ? (float) $row['latitude'] : null,
					'longitude' => null !== $row['longitude'] ? (float) $row['longitude'] : null,
				),
				(int) $row['id']
			);
		}
	}

	/**
	 * Registers a record into the in-memory indexes (supports both preloaded and newly processed rows).
	 */
	public function register( array $record, int $id ): void {
		$code = DataNormalizer::normalize_empty( $record['code'] ?? null );
		if ( null !== $code ) {
			$this->code_index[ DataNormalizer::strtolower( $code ) ] = $id;
		}

		$phone = DataNormalizer::normalize_phone( $record['phone'] ?? null );
		$city  = DataNormalizer::normalize_persian_text( $record['city'] ?? null ) ?? '';
		$name  = DataNormalizer::normalize_persian_text( $record['name'] ?? null ) ?? '';

		if ( null !== $phone ) {
			$this->phone_index[ $phone ][] = array(
				'id'   => $id,
				'city' => $city,
				'name' => $name,
			);
		}

		if ( '' !== $city ) {
			$this->city_index[ $city ][] = array(
				'id'              => $id,
				'name'            => $name,
				'simplified_name' => self::simplify_branch_name( $name ),
				'address'         => (string) ( $record['address'] ?? '' ),
				'lat'             => isset( $record['latitude'] ) && null !== $record['latitude'] ? (float) $record['latitude'] : null,
				'lng'             => isset( $record['longitude'] ) && null !== $record['longitude'] ? (float) $record['longitude'] : null,
				'phone'           => $phone,
			);
		}
	}

	/**
	 * Checks whether a candidate record is a definite, probable, or legitimate similar record.
	 *
	 * @param array $candidate Normalized candidate record.
	 * @return array{type: 'definite'|'probable', reason: string, existing_id: int}|null Null if not a duplicate.
	 */
	public function detect( array $candidate ): ?array {
		$code  = DataNormalizer::normalize_empty( $candidate['code'] ?? null );
		$phone = DataNormalizer::normalize_phone( $candidate['phone'] ?? null );
		$city  = DataNormalizer::normalize_persian_text( $candidate['city'] ?? null ) ?? '';
		$name  = DataNormalizer::normalize_persian_text( $candidate['name'] ?? null ) ?? '';
		$lat   = isset( $candidate['latitude'] ) && null !== $candidate['latitude'] ? (float) $candidate['latitude'] : null;
		$lng   = isset( $candidate['longitude'] ) && null !== $candidate['longitude'] ? (float) $candidate['longitude'] : null;

		// 1. DEFINITE SIGNAL A: Exact branch code match within same provider.
		if ( null !== $code ) {
			$lower_code = DataNormalizer::strtolower( $code );
			if ( isset( $this->code_index[ $lower_code ] ) ) {
				return array(
					'type'        => 'definite',
					'reason'      => sprintf( 'Exact matching branch code "%s" found for this provider.', $code ),
					'existing_id' => $this->code_index[ $lower_code ],
				);
			}
		}

		// 2. DEFINITE SIGNAL B: Matching phone number in the exact same city.
		if ( null !== $phone && isset( $this->phone_index[ $phone ] ) ) {
			foreach ( $this->phone_index[ $phone ] as $existing ) {
				if ( '' !== $city && $existing['city'] === $city ) {
					return array(
						'type'        => 'definite',
						'reason'      => sprintf( 'Matching phone number "%s" and city "%s".', $phone, $city ),
						'existing_id' => $existing['id'],
					);
				}
			}
		}

		// 3. DEFINITE SIGNAL C: Coordinates match within ~10 meters in the same city.
		if ( null !== $lat && null !== $lng && '' !== $city && isset( $this->city_index[ $city ] ) ) {
			foreach ( $this->city_index[ $city ] as $existing ) {
				if ( null !== $existing['lat'] && null !== $existing['lng'] ) {
					$distance_deg = abs( $existing['lat'] - $lat ) + abs( $existing['lng'] - $lng );
					if ( $distance_deg < 0.00015 ) { // ~15 meters
						return array(
							'type'        => 'definite',
							'reason'      => sprintf( 'Matching physical coordinates (%f, %f) in city "%s".', $lat, $lng, $city ),
							'existing_id' => $existing['id'],
						);
					}
				}
			}
		}

		// 4. PROBABLE SIGNAL A: Simplified branch name match in same city.
		if ( '' !== $name && '' !== $city && isset( $this->city_index[ $city ] ) ) {
			$candidate_simplified = self::simplify_branch_name( $name );
			if ( DataNormalizer::strlen( $candidate_simplified ) >= 3 ) {
				foreach ( $this->city_index[ $city ] as $existing ) {
					if ( $existing['simplified_name'] === $candidate_simplified ) {
						return array(
							'type'        => 'probable',
							'reason'      => sprintf( 'Highly similar branch name "%s" in city "%s".', $name, $city ),
							'existing_id' => $existing['id'],
						);
					}
				}
			}
		}

		// 5. PROBABLE SIGNAL B: Same phone number, but registered in different city/branch.
		if ( null !== $phone && isset( $this->phone_index[ $phone ] ) ) {
			$first = $this->phone_index[ $phone ][0];
			return array(
				'type'        => 'probable',
				'reason'      => sprintf( 'Matching phone number "%s" with different city/branch name.', $phone ),
				'existing_id' => $first['id'],
			);
		}

		// Legitimate similar record (not a duplicate).
		return null;
	}

	/**
	 * Strips common Persian administrative/branch prefixes to extract the distinct core name.
	 */
	private static function simplify_branch_name( string $name ): string {
		$clean = DataNormalizer::normalize_persian_text( $name ) ?? '';
		$clean = DataNormalizer::strtolower( $clean );

		$prefixes = array(
			'/^باجه\s+پستی\s+/u',
			'/^دفتر\s+پستی\s+/u',
			'/^شعبه\s+پستی\s+/u',
			'/^نمایندگی\s+تیپاکس\s+/u',
			'/^باجه\s+/u',
			'/^دفتر\s+/u',
			'/^شعبه\s+/u',
			'/^نمایندگی\s+/u',
			'/^مرکز\s+/u',
		);

		$simplified = preg_replace( $prefixes, '', $clean );
		$simplified = preg_replace( '/\s+/u', '', (string) $simplified );

		return trim( (string) $simplified );
	}
}
