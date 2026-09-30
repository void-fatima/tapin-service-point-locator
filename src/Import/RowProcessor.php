<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Normalization\DataNormalizer;
use Tapin\ServicePointLocator\Validation\ServicePointValidator;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Geocoding\CoordinatePolicy;
use Tapin\ServicePointLocator\Geocoding\Jobs;

defined( 'ABSPATH' ) || exit;

/** Shared row pipeline for resumable web imports and synchronous CLI callers. */
final class RowProcessor {
	public static function process( array $raw, int $provider, string $action, int $import_job_id = 0 ): array {
		$data = DataNormalizer::normalize_service_point( array_merge( $raw, array( 'provider_id' => $provider ) ) );
		$provider_record = ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_by_id( $provider );
		$source_host = strtolower( (string) wp_parse_url( (string) ( $raw['source'] ?? '' ), PHP_URL_HOST ) );
		if ( in_array( $source_host, array( 'tipaxco.com', 'www.tipaxco.com' ), true ) && 'tipax' !== ( $provider_record['slug'] ?? '' ) ) {
			return array( 'result' => 'failed', 'messages' => array( 'لینک منبع این ردیف متعلق به تیپاکس است؛ فایل را با ارائه‌دهندهٔ تیپاکس وارد کنید.' ) );
		}
		if ( 'post' === ( $provider_record['slug'] ?? '' ) ) {
			$data = TapinDirectory::reconcile( $data, TapinDirectory::records() );
			if ( is_array( $data['metadata'] ) ) {
				$data['metadata']['tapin_reconciliation']['original_uploaded'] = array_intersect_key( $raw, array_flip( array( 'name', 'province', 'city', 'address', 'postal_code', 'landline_phone', 'source' ) ) );
			}
		}
		$validation = ServicePointValidator::validate( $data );
		if ( ! $validation->is_valid() ) { return array( 'result' => 'failed', 'messages' => array_values( $validation->get_errors() ) ); }
		$repo = new ServicePointRepository();
		$match = ( new DuplicateDetector( $provider ) )->find_existing( $data );
		$warnings = array_values( $validation->get_warnings() );
		$outcome = $data['metadata']['tapin_reconciliation']['result'] ?? null;
		if ( in_array( $outcome, array( 'conflict', 'probable_match' ), true ) ) { $warnings[] = 'Tapin directory: ' . $outcome . ' — uploaded values preserved; review source evidence.'; }
		if ( $match ) {
			if ( 'update' === $action && $match['code_match'] ) {
				$before = $import_job_id ? ImportPointLinks::snapshot( (int) $match['id'] ) : null;
				if ( $import_job_id && ! $before ) { throw new \RuntimeException( 'ذخیره نسخه قبلی شعبه ناموفق بود؛ فایل را دوباره تلاش کنید.' ); }
				$data = CoordinatePolicy::prepare( $data, $repo->get_by_id( $match['id'] ), 'uploaded' );
				$ok = $repo->update( $match['id'], $data );
				if ( $ok ) {
					self::queue( $repo, (int) $match['id'], $warnings );
					if ( $import_job_id ) { ImportPointLinks::record( $import_job_id, (int) $match['id'], 'updated', $before ); }
				}
				return array( 'result' => $ok ? 'updated' : 'failed', 'messages' => $ok ? $warnings : array( 'به‌روزرسانی رکورد در پایگاه داده ناموفق بود.' ), 'existing_id' => $match['id'] );
			}
			if ( $import_job_id ) {
				$before = ImportPointLinks::snapshot( (int) $match['id'] );
				if ( $before ) { ImportPointLinks::record( $import_job_id, (int) $match['id'], 'owned', $before ); }
			}
			return array( 'result' => 'skipped', 'messages' => array( 'احتمال تکرار؛ رکورد موجود تغییر نکرد. شناسه: ' . $match['id'] ), 'existing_id' => $match['id'] );
		}
		$data = CoordinatePolicy::prepare( $data, null, 'uploaded' );
		$id = $repo->insert( $data );
		if ( $id ) {
			self::queue( $repo, $id, $warnings );
			if ( $import_job_id ) { ImportPointLinks::record( $import_job_id, $id, 'inserted', null ); }
		}
		return array( 'result' => $id ? 'inserted' : 'failed', 'messages' => $id ? $warnings : array( 'ثبت رکورد در پایگاه داده ناموفق بود.' ) );
	}
	private static function queue( ServicePointRepository $repo, int $id, array &$warnings ): void {
		try {
			$point = $repo->get_by_id( $id );
			$result = $point ? Jobs::enqueue( $point ) : null;
			if ( is_wp_error( $result ) ) { $warnings[] = $result->get_error_message(); }
		} catch ( \Throwable $e ) { $warnings[] = 'رکورد ذخیره شد؛ صف موقعیت‌یابی در دسترس نیست. بعداً دوباره تلاش کنید.'; }
	}
}
