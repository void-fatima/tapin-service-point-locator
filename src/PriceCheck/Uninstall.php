<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

final class Uninstall {

	/** Independent opt-in; the locator's deletion flag does not control these tables. */
	public static function run(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'TAPIN_PRICE_UNINSTALL_DROP_DATA' ) || true !== TAPIN_PRICE_UNINSTALL_DROP_DATA ) {
			return;
		}
		global $wpdb;
		// Children and cache precede parents so cascade constraints remain valid.
		foreach ( array_reverse( Schema::tables() ) as $table ) {
			if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' ) ) {
				return;
			}
		}
		delete_option( Schema::DB_VERSION_OPTION );
	}
}
