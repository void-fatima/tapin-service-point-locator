<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** storage.py::cache_stats; counts do not prune or mask a failed table read as zero. */
final class OperationalStats {
	public static function get(): array {
		$out = array();
		foreach ( array( 'price_rows' => 'price_cache', 'quote_logs_rows' => 'quote_logs', 'security_events_rows' => 'security_events' ) as $key => $suffix ) {
			$rows = ConfigurationDatabase::rows( 'SELECT COUNT(*) AS row_count FROM ' . ConfigurationDatabase::table( $suffix ) );
			if ( ! $rows ) { throw new StorageError( 'count' ); } $out[$key] = $rows[0]['row_count'];
		}
		return $out;
	}
}
