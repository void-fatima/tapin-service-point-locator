<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Isolated lifecycle, page and REST hook registration. */
final class Module {

	public function boot(): void {
		add_action( 'init', array( Schema::class, 'maybe_migrate' ) );
		add_action( 'admin_menu', array( new Admin(), 'register' ), 20 );
		( new PublicPages() )->boot();
		add_action( 'rest_api_init', array( new RestApi(), 'register' ) );
	}
}
