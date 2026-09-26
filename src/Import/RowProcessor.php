<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Normalization\DataNormalizer;
use Tapin\ServicePointLocator\Validation\ServicePointValidator;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;

defined( 'ABSPATH' ) || exit;

/** Shared row pipeline for resumable web imports and synchronous CLI callers. */
final class RowProcessor {
	public static function process( array $raw, int $provider, string $action ): array {
		$data = DataNormalizer::normalize_service_point( array_merge( $raw, array( 'provider_id' => $provider ) ) );
		$validation = ServicePointValidator::validate( $data );
		if ( ! $validation->is_valid() ) { return array( 'result' => 'failed', 'messages' => array_values( $validation->get_errors() ) ); }
		$repo = new ServicePointRepository();
		$match = ( new DuplicateDetector( $provider ) )->find_existing( $data );
		$warnings = array_values( $validation->get_warnings() );
		if ( $match ) {
			if ( 'update' === $action && $match['code_match'] ) {
				$ok = $repo->update( $match['id'], $data );
				return array( 'result' => $ok ? 'updated' : 'failed', 'messages' => $ok ? $warnings : array( 'به‌روزرسانی رکورد در پایگاه داده ناموفق بود.' ), 'existing_id' => $match['id'] );
			}
			return array( 'result' => 'skipped', 'messages' => array( 'احتمال تکرار؛ رکورد موجود تغییر نکرد. شناسه: ' . $match['id'] ), 'existing_id' => $match['id'] );
		}
		$id = $repo->insert( $data );
		return array( 'result' => $id ? 'inserted' : 'failed', 'messages' => $id ? $warnings : array( 'ثبت رکورد در پایگاه داده ناموفق بود.' ) );
	}
}
