<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private array $hooks = array();

	public function register(): void {
		foreach ( $this->screens() as $slug => $title ) {
			$visible = 'tapin-price-providers' === $slug;
			$parent  = $visible ? 'tapin-locator' : '';
			$label   = $visible ? __( 'استعلام قیمت', 'tapin-service-point-locator' ) : $title;
			$hook = add_submenu_page( $parent, $title, $label, Access::CAPABILITY, $slug, function() use ( $title, $slug ) {
				$this->render( $title, substr( $slug, strlen( 'tapin-price-' ) ) );
			} );
			if ( is_string( $hook ) ) { $this->hooks[] = $hook; }
		}
		add_action( 'admin_enqueue_scripts', function( $hook ) {
			if ( in_array( $hook, $this->hooks, true ) && Access::can_manage() ) {
				InterfaceScreen::enqueue();
				add_action( 'admin_head', array( InterfaceScreen::class, 'admin_head' ) );
			}
		} );
	}

	/** Provider list/editor and protected draft preview share the providers entry. */
	private function screens(): array {
		return array(
			'tapin-price-providers' => __( 'مدیریت استعلام قیمت تاپین', 'tapin-service-point-locator' ),
			'tapin-price-shops'     => __( 'شعب شرکت‌ها', 'tapin-service-point-locator' ),
			'tapin-price-bulk'      => __( 'تنظیمات گروهی استعلام قیمت', 'tapin-service-point-locator' ),
			'tapin-price-logs'      => __( 'گزارش خطاهای استعلام قیمت', 'tapin-service-point-locator' ),
			'tapin-price-cache'     => __( 'کش استعلام قیمت', 'tapin-service-point-locator' ),
			'tapin-price-security'  => __( 'امنیت استعلام قیمت', 'tapin-service-point-locator' ),
		);
	}

	public function render( string $title, string $screen = 'providers' ): void {
		Access::require_admin();
		echo '<div class="wrap tapin-price-admin" dir="rtl" lang="fa"><h1 class="screen-reader-text">' . esc_html( $title ) . '</h1>' . InterfaceScreen::frame( $screen, true, wp_unslash( $_GET ) ) . '</div>';
	}
}
