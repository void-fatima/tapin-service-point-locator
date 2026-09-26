<?php

namespace Tapin\ServicePointLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin runtime bootstrap. Registers WordPress hooks.
 *
 * No admin or frontend UI hooks are registered yet: the project is
 * still in the backend-foundation phase.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		add_action( 'init', static function() {
			if ( (int) get_option( Database\Schema::DB_VERSION_OPTION ) !== TAPIN_DB_VERSION ) {
				Database\Schema::migrate();
			}
		} );
		add_action( 'rest_api_init', array( new Http\Api(), 'register' ) );
		( new UI\App() )->boot();
		add_action( 'tapin_cleanup_imports', array( Import\ImportJobs::class, 'cleanup' ) );
		add_action( 'init', static function() {
			if ( ! wp_next_scheduled( 'tapin_cleanup_imports' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'tapin_cleanup_imports' ); }
		} );
	}
}
