<?php
namespace Tapin\ServicePointLocator\Database;
defined( 'ABSPATH' ) || exit;

/** Serialize admin writes with import workers to avoid orphan/provider and duplicate races. */
final class WriteLock {
	public static function run( callable $callback ) {
		global $wpdb;
		$key = 'tapin-' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $key ) ) ) {
			return new \WP_Error( 'busy', 'عملیات دیگری در حال اجراست؛ دوباره تلاش کنید.', array( 'status' => 409 ) );
		}
		try { return $callback(); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); }
	}
}
