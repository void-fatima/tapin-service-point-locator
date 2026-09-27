<?php
namespace Tapin\ServicePointLocator\Service;

defined( 'ABSPATH' ) || exit;

/** Operator-only cleanup. Never called by activation or normal requests. */
final class SyntheticCleanup {
	public static function candidates(): array {
		global $wpdb;
		$table = \Tapin\ServicePointLocator\Database\Schema::get_service_points_table();
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE (source IS NULL OR source = '') AND (metadata IS NULL OR metadata = '' OR metadata = 'null')", ARRAY_A );
		return array_values( array_filter( $rows, static fn( $row ) => preg_match( '/^(Trace|Debug) [0-9]{13}$/D', $row['name'] ) && preg_match( '/^DBG-[0-9]{13}$/D', $row['code'] ?? '' ) ) );
	}
	public static function remove( array $ids ): int {
		$removed = 0;
		$repo = new \Tapin\ServicePointLocator\Repository\ServicePointRepository();
		foreach ( self::candidates() as $row ) {
			if ( in_array( (int) $row['id'], $ids, true ) && $repo->delete( (int) $row['id'] ) ) { $removed++; }
		}
		return $removed;
	}
}
