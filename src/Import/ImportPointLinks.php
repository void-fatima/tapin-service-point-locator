<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Database\Schema;

defined( 'ABSPATH' ) || exit;

/** Tracks the exact point changes made by each resumable spreadsheet import. */
final class ImportPointLinks {
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tapin_import_points';
	}

	/** Return the unformatted database row so snapshots can be compared exactly. */
	public static function snapshot( int $point_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::get_service_points_table() . ' WHERE id = %d', $point_id ), ARRAY_A );
		return $row ?: null;
	}

	public static function record( int $job_id, int $point_id, string $operation, ?array $before ): void {
		global $wpdb;
		$after = self::snapshot( $point_id );
		if ( ! $after ) { throw new \RuntimeException( 'ثبت ارتباط رکورد واردشده ناموفق بود.' ); }
		$before_json = null === $before ? null : wp_json_encode( $before, JSON_UNESCAPED_UNICODE );
		$after_json = wp_json_encode( $after, JSON_UNESCAPED_UNICODE );
		$sql = 'INSERT INTO ' . self::table() . ' (job_id, point_id, operation, before_data, after_data) VALUES (%d, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE after_data = VALUES(after_data)';
		$result = $wpdb->query( $wpdb->prepare( $sql, $job_id, $point_id, $operation, $before_json, $after_json ) );
		if ( false === $result ) { throw new \RuntimeException( 'ثبت ارتباط رکورد واردشده ناموفق بود.' ); }
	}

	/** Remove points created by this import and restore unchanged updates. */
	public static function undo( int $job_id ): array {
		global $wpdb;
		$link_table = self::table();
		$points_table = Schema::get_service_points_table();
		$links = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$link_table} WHERE job_id = %d ORDER BY id", $job_id ), ARRAY_A );
		$summary = array( 'tracked_points' => count( $links ), 'deleted_points' => 0, 'restored_points' => 0, 'preserved_points' => 0 );
		foreach ( $links as $link ) {
			$next = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$link_table} WHERE point_id = %d AND id > %d ORDER BY id LIMIT 1", (int) $link['point_id'], (int) $link['id'] ), ARRAY_A );
			$expected = json_decode( $link['after_data'], true );
			if ( $next && is_array( $expected ) && json_decode( $next['before_data'], true ) === $expected ) {
				$inherit = in_array( $link['operation'], array( 'inserted', 'owned' ), true )
					? array( 'operation' => $link['operation'], 'before_data' => $link['before_data'] )
					: array( 'before_data' => $link['before_data'] );
				if ( false === $wpdb->update( $link_table, $inherit, array( 'id' => (int) $next['id'] ) ) ) { throw new \RuntimeException( 'حفظ زنجیره تغییرات فایل‌ها انجام نشد.' ); }
				++$summary['preserved_points'];
				continue;
			}
			$current = self::snapshot( (int) $link['point_id'] );
			if ( ! $current ) { continue; }
			if ( ! is_array( $expected ) || $current !== $expected ) {
				++$summary['preserved_points'];
				continue;
			}
			if ( in_array( $link['operation'], array( 'inserted', 'owned' ), true ) ) {
				$geocoding_deleted = $wpdb->delete( $wpdb->prefix . 'tapin_geocoding_jobs', array( 'point_id' => (int) $link['point_id'] ), array( '%d' ) );
				if ( false === $geocoding_deleted ) { throw new \RuntimeException( 'پاک‌کردن صف موقعیت‌یابی یکی از شعب انجام نشد.' ); }
				$deleted = $wpdb->delete( $points_table, array( 'id' => (int) $link['point_id'] ), array( '%d' ) );
				if ( false === $deleted ) { throw new \RuntimeException( 'حذف یکی از شعب واردشده انجام نشد.' ); }
				if ( $deleted ) { ++$summary['deleted_points']; }
				continue;
			}
			$before = json_decode( $link['before_data'], true );
			if ( ! is_array( $before ) || ! isset( $before['id'] ) || (int) $before['id'] !== (int) $link['point_id'] ) {
				++$summary['preserved_points'];
				continue;
			}
			$id = (int) $before['id'];
			unset( $before['id'] );
			$restored = $wpdb->update( $points_table, $before, array( 'id' => $id ) );
			if ( false === $restored ) { throw new \RuntimeException( 'بازگرداندن یکی از شعب به اطلاعات قبلی انجام نشد.' ); }
			++$summary['restored_points'];
			$point = ( new \Tapin\ServicePointLocator\Repository\ServicePointRepository() )->get_by_id( $id );
			if ( $point ) { \Tapin\ServicePointLocator\Geocoding\Jobs::enqueue( $point ); }
		}
		if ( false === $wpdb->delete( $link_table, array( 'job_id' => $job_id ), array( '%d' ) ) ) { throw new \RuntimeException( 'پاک‌کردن پیوندهای فایل با شعبه‌ها انجام نشد.' ); }
		return $summary;
	}
}
