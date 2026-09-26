<?php

namespace Tapin\ServicePointLocator\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Validates normalized service point records.
 */
final class ServicePointValidator {

	/**
	 * Validates a normalized record and returns a structured ValidationResult.
	 *
	 * @param array $record Normalized record data.
	 * @param bool $check_iran_bounds Whether to warn if coordinates fall outside Iran.
	 * @return ValidationResult
	 */
	public static function validate( array $record, bool $check_iran_bounds = true ): ValidationResult {
		$result = new ValidationResult();

		// 1. Provider validation (Required).
		$provider_id = (int) ( $record['provider_id'] ?? 0 );
		if ( $provider_id <= 0 ) {
			$result->add_error( 'provider_id', __( 'Provider ID is required and must be a positive integer.', 'tapin-service-point-locator' ) );
		}

		// 2. Name validation (Required).
		$name = trim( (string) ( $record['name'] ?? '' ) );
		if ( '' === $name ) {
			$result->add_error( 'name', __( 'Service point name is required.', 'tapin-service-point-locator' ) );
		}

		// 3. Province validation (Required).
		$province = trim( (string) ( $record['province'] ?? '' ) );
		if ( '' === $province ) {
			$result->add_error( 'province', __( 'Province is required.', 'tapin-service-point-locator' ) );
		}

		// 4. City validation (Required).
		$city = trim( (string) ( $record['city'] ?? '' ) );
		if ( '' === $city ) {
			$result->add_error( 'city', __( 'City is required.', 'tapin-service-point-locator' ) );
		}

		// 5. Address validation (Required).
		$address = trim( (string) ( $record['address'] ?? '' ) );
		if ( '' === $address ) {
			$result->add_error( 'address', __( 'Physical address is required.', 'tapin-service-point-locator' ) );
		}

		// 6. Coordinates validation (Optional, but strict if partially present or invalid).
		$lat = $record['latitude'] ?? null;
		$lng = $record['longitude'] ?? null;

		$has_lat = null !== $lat && '' !== (string) $lat;
		$has_lng = null !== $lng && '' !== (string) $lng;

		if ( ! $has_lat && ! $has_lng ) {
			// Address-only record: Gracefully allowed with an informative warning.
			$result->add_warning(
				'coordinates',
				__( 'Address is provided without latitude/longitude coordinates.', 'tapin-service-point-locator' )
			);
		} elseif ( $has_lat !== $has_lng ) {
			// Inconsistent coordinates.
			$result->add_error(
				'coordinates',
				__( 'Both latitude and longitude must be provided together.', 'tapin-service-point-locator' )
			);
		} else {
			$lat_val = (float) $lat;
			$lng_val = (float) $lng;

			if ( $lat_val < -90.0 || $lat_val > 90.0 ) {
				$result->add_error( 'latitude', __( 'Latitude must be between -90 and +90 degrees.', 'tapin-service-point-locator' ) );
			}

			if ( $lng_val < -180.0 || $lng_val > 180.0 ) {
				$result->add_error( 'longitude', __( 'Longitude must be between -180 and +180 degrees.', 'tapin-service-point-locator' ) );
			}

			// Geographic sanity check for Iran (approx 24° to 40° N, 44° to 64° E).
			if ( $check_iran_bounds && $result->is_valid() ) {
				$is_in_iran = ( $lat_val >= 24.0 && $lat_val <= 41.0 && $lng_val >= 43.0 && $lng_val <= 65.0 );
				if ( ! $is_in_iran ) {
					$result->add_warning(
						'coordinates_bounds',
						__( 'Coordinates appear to be outside the geographic boundaries of Iran.', 'tapin-service-point-locator' )
					);
				}
			}
		}

		// 7. Status validation.
		$status = $record['status'] ?? 'active';
		if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$result->add_error( 'status', __( 'Status must be either active or inactive.', 'tapin-service-point-locator' ) );
		}

		// 8. Postal code check (Non-blocking warning).
		if ( ! empty( $record['postal_code'] ) ) {
			$clean_postal = preg_replace( '/\D/', '', (string) $record['postal_code'] );
			if ( strlen( $clean_postal ) !== 10 ) {
				$result->add_warning(
					'postal_code',
					__( 'Postal code should typically be 10 digits for Iranian addresses.', 'tapin-service-point-locator' )
				);
			}
		}

		// 9. Phone check (Non-blocking warning).
		if ( empty( $record['phone'] ) ) {
			$result->add_warning( 'phone', __( 'Phone number is not provided.', 'tapin-service-point-locator' ) );
		}

		return $result;
	}
}
