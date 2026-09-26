<?php

namespace Tapin\ServicePointLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin deactivation routines.
 *
 * All user data, database tables, and settings are intentionally preserved.
 * Any permanent cleanup is deferred to uninstall.php.
 */
final class Deactivator {

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'tapin_cleanup_imports' );
		// Clean up any temporary transients or cached calculations if present.
		delete_transient( 'tapin_service_points_cache' );
	}
}
