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
		if ( $id && ! $repo->get_by_id( $id ) ) {
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
			return new \WP_Error( 'invalid', implode( ' ', $validation->get_errors() ), array( 'status' => 400, 'fields' => $validation->get_errors() ) );
		}
		$saved = $id ? $repo->update( $id, $data ) : $repo->insert( $data );
		if ( ! $saved ) {
			return new \WP_Error( 'database', 'ذخیره اطلاعات انجام نشد. دوباره تلاش کنید.', array( 'status' => 500 ) );
		}
		return array( 'item' => $repo->get_by_id( $id ?: $saved ), 'warnings' => $validation->get_warnings() );
	}
}
