<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Isolated entry points; no quote endpoints or business logic in Stage 2. */
final class Module {

	public function boot(): void {
		add_action( 'init', array( Schema::class, 'maybe_migrate' ) );
		add_action( 'admin_menu', array( new Admin(), 'register' ), 20 );
		( new PublicPages() )->boot();
	}
}
