<?php
namespace Tapin\ServicePointLocator\Service;

use Tapin\ServicePointLocator\Normalization\DataNormalizer;
use Tapin\ServicePointLocator\Validation\ServicePointValidator;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;

defined( 'ABSPATH' ) || exit;

final class PointService {
	public static function save( array $data, int $id = 0 ) {
		$repo = new ServicePointRepository();
		$existing = $id ? $repo->get_by_id( $id ) : null;
		if ( $id && ! $existing ) {
			return new \WP_Error( 'not_found', 'نقطه خدماتی پیدا نشد.', array( 'status' => 404 ) );
		}
		foreach ( $data as $key => $value ) {
			if ( 'metadata' !== $key && ! is_scalar( $value ) && null !== $value ) {
				return new \WP_Error( 'invalid', 'نوع داده نامعتبر است.', array( 'status' => 400 ) );
			}
		}
		$data = DataNormalizer::normalize_service_point( $data );
		$validation = ServicePointValidator::validate( $data );
		if ( ! ( new ProviderRepository() )->get_by_id( $data['provider_id'] ) ) {
			$validation->add_error( 'provider_id', 'ارائه‌دهنده معتبر را انتخاب کنید.' );
		}
		if ( null !== $data['metadata'] && ( ! is_array( $data['metadata'] ) || strlen( wp_json_encode( $data['metadata'] ) ) > 10000 ) ) {
			$validation->add_error( 'metadata', 'اطلاعات تکمیلی باید یک شیء JSON با حجم کمتر از ۱۰ کیلوبایت باشد.' );
		}
		if ( ! $validation->is_valid() ) {
			OperationalLog::record( 'validation_failed', array( 'provider_id' => $data['provider_id'] ) );
			return new \WP_Error( 'invalid', implode( ' ', $validation->get_errors() ), array( 'status' => 400, 'fields' => $validation->get_errors() ) );
		}
		$data = \Tapin\ServicePointLocator\Geocoding\CoordinatePolicy::prepare( $data, $existing, 'manual' );
		// Editing reconciled fields invalidates old evidence; never geocode a stale official address.
		if ( $existing && isset( $data['metadata']['tapin_reconciliation'] ) ) {
			foreach ( array( 'name', 'province', 'city', 'address', 'postal_code', 'landline_phone' ) as $field ) {
				if ( (string) ( $existing[$field] ?? '' ) !== (string) ( $data[$field] ?? '' ) ) {
					$data['metadata']['tapin_reconciliation']['result'] = 'stale'; break;
				}
			}
		}
		$saved = $id ? $repo->update( $id, $data ) : $repo->insert( $data );
		if ( ! $saved ) {
			OperationalLog::record( 'system_error', array( 'provider_id' => $data['provider_id'] ) );
			return new \WP_Error( 'database', 'ذخیره اطلاعات انجام نشد. دوباره تلاش کنید.', array( 'status' => 500 ) );
		}
		$item = $repo->get_by_id( $id ?: $saved );
		$warnings = $validation->get_warnings();
		try {
			$queued = \Tapin\ServicePointLocator\Geocoding\Jobs::enqueue( $item );
			if ( is_wp_error( $queued ) ) { $warnings['geocoding'] = $queued->get_error_message(); }
		} catch ( \Throwable $e ) { $warnings['geocoding'] = 'رکورد ذخیره شد؛ موقعیت‌یابی را بعداً دوباره درخواست کنید.'; }
		return array( 'item' => $item, 'warnings' => $warnings );
	}
}
