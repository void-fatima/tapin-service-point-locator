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
		// Runtime hooks (REST, cron, admin UI, frontend) are added in later phases.
	}
}
