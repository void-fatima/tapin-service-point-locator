<?php
namespace Tapin\ServicePointLocator\Export;

use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Service\PointEvidence;
use Tapin\ServicePointLocator\Service\OperationalLog;

defined( 'ABSPATH' ) || exit;

final class ServicePoints {
	public static function download( array $filters ) {
		global $wpdb;
		$previous_errors = $wpdb->suppress_errors( true );
		$workbook = null; $transaction = false;
		try {
			$workbook = new Workbook();
			// One read-only snapshot prevents skipped/duplicated rows during concurrent edits.
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' ) ) { throw new \RuntimeException( 'snapshot_failed' ); }
			$transaction = true;
			$providers = array_column( ( new ProviderRepository() )->get_all(), 'name', 'id' );
			if ( $wpdb->last_error ) { throw new \RuntimeException( 'query_failed' ); }
			$repo = new ServicePointRepository(); $count = 0; $started = microtime( true );
			$php_limit = (int) ini_get( 'max_execution_time' );
			$seconds = $php_limit > 0 ? max( 1, min( 45, $php_limit - 5 ) ) : 45;
			$args = array_merge( $filters, array( 'page' => 1, 'per_page' => 500, 'order' => 'ASC', 'orderby' => 'id', 'include_summary' => false ) );
			$workbook->row( array( 'ارائه‌دهنده', 'نام شعبه', 'استان', 'شهر', 'آدرس', 'کد پستی', 'تلفن ثابت', 'تلفن همراه', 'تلفن عمومی', 'عرض جغرافیایی', 'طول جغرافیایی', 'وضعیت موقعیت' ), true );
			do {
				$result = $repo->query( $args );
				if ( $wpdb->last_error ) { throw new \RuntimeException( 'query_failed' ); }
				if ( $result['total'] > 100000 || microtime( true ) - $started > $seconds ) { throw new \RuntimeException( 'export_limit' ); }
				foreach ( $result['items'] as $point ) {
					if ( microtime( true ) - $started > $seconds ) { throw new \RuntimeException( 'export_limit' ); }
					$p = PointEvidence::fields( $point );
					$located = ! empty( $p['has_coordinates'] ) && \Tapin\ServicePointLocator\Geocoding\CoordinatePolicy::valid( $p );
					$phone = in_array( $p['phone'], array( $p['landline_phone'], $p['mobile_phone'] ), true ) ? '' : $p['phone'];
					$workbook->row( array( $providers[$p['provider_id']] ?? '—', $p['name'], $p['province'], $p['city'], $p['address'], $p['postal_code'], $p['landline_phone'], $p['mobile_phone'], $phone, $located ? (float) $p['latitude'] : null, $located ? (float) $p['longitude'] : null, $located ? 'دارای موقعیت' : 'بدون مختصات' ) );
					$count++;
				}
				$args['page']++;
			} while ( $args['page'] <= $result['total_pages'] );
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'snapshot_failed' ); }
			$transaction = false;
			$file = $workbook->finish();
			OperationalLog::record( 'export_completed', array( 'rows' => $count, 'filters' => $filters ) );
			return new DownloadResponse( $workbook, $file );
		} catch ( \Throwable $e ) {
			if ( $transaction ) { $wpdb->query( 'ROLLBACK' ); }
			if ( $workbook ) { $workbook->cleanup(); }
			OperationalLog::record( 'export_failed', array( 'filters' => $filters ) );
			$message = 'تهیه خروجی اکسل انجام نشد. فیلترها را محدودتر کنید یا با مدیر سرور تماس بگیرید.';
			return new \WP_Error( 'export_failed', $message, array( 'status' => 503 ) );
		} finally { $wpdb->suppress_errors( $previous_errors ); }
	}
}
