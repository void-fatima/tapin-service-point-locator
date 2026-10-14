<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private array $hooks = array();

	public function register(): void {
		foreach ( $this->screens() as $slug => $title ) {
			$hook = add_submenu_page( 'tapin-locator', $title, $title, Access::CAPABILITY, $slug, function() use ( $title, $slug ) {
				$this->render( $title, substr( $slug, strlen( 'tapin-price-' ) ) );
			} );
			if ( is_string( $hook ) ) { $this->hooks[] = $hook; }
		}
		add_action( 'admin_enqueue_scripts', function( $hook ) { if ( in_array( $hook, $this->hooks, true ) && Access::can_manage() ) { InterfaceScreen::enqueue(); } } );
	}

	/** Provider list/editor and protected draft preview share the providers entry. */
	private function screens(): array {
		return array(
			'tapin-price-providers' => __( 'استعلام قیمت: شرکت‌ها', 'tapin-service-point-locator' ),
			'tapin-price-shops'     => __( 'استعلام قیمت: شعب', 'tapin-service-point-locator' ),
			'tapin-price-bulk'      => __( 'استعلام قیمت: تنظیمات گروهی', 'tapin-service-point-locator' ),
			'tapin-price-logs'      => __( 'استعلام قیمت: گزارش خطاها', 'tapin-service-point-locator' ),
			'tapin-price-cache'     => __( 'استعلام قیمت: کش', 'tapin-service-point-locator' ),
			'tapin-price-security'  => __( 'استعلام قیمت: امنیت', 'tapin-service-point-locator' ),
		);
	}

	public function render( string $title, string $screen = 'providers' ): void {
		Access::require_admin();
		echo '<div class="wrap tapin-price-admin" dir="rtl" lang="fa"><h1 class="screen-reader-text">' . esc_html( $title ) . '</h1>' . InterfaceScreen::frame( $screen, true, wp_unslash( $_GET ) ) . '</div>';
	}
}
