<?php

namespace Tapin\ServicePointLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin activation routines and requirements verification.
 */
final class Activator {

	const MIN_PHP_VERSION = '7.4';
	const MIN_WP_VERSION  = '6.0';

	/**
	 * Runs on plugin activation. Safe to execute multiple times (idempotent).
	 */
	public static function activate(): void {
		self::check_requirements();

		// Trigger database schema creation/migration if installer exists.
		if ( class_exists( __NAMESPACE__ . '\\Database\\Schema' ) ) {
			Database\Schema::migrate();
		}
	}

	/**
	 * Verifies system meets minimum environment constraints.
	 */
	private static function check_requirements(): void {
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( TAPIN_PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: %s: Minimum PHP version */
					esc_html__( 'Tapin Service Point Locator requires PHP version %s or higher.', 'tapin-service-point-locator' ),
					esc_html( self::MIN_PHP_VERSION )
				)
			);
		}

		global $wp_version;
		if ( version_compare( $wp_version, self::MIN_WP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( TAPIN_PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: %s: Minimum WordPress version */
					esc_html__( 'Tapin Service Point Locator requires WordPress version %s or higher.', 'tapin-service-point-locator' ),
					esc_html( self::MIN_WP_VERSION )
				)
			);
		}
	}
}
