<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public function register(): void {
		foreach ( $this->screens() as $slug => $title ) {
			add_submenu_page( 'tapin-locator', $title, $title, Access::CAPABILITY, $slug, function() use ( $title ) {
				$this->render( $title );
			} );
		}
	}

	/** Provider list and editor share one screen; previews come with later UI work. */
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

	public function render( string $title ): void {
		Access::require_admin();
		echo '<div class="wrap tapin-price-admin" dir="rtl" lang="fa"><h1>' . esc_html( $title ) . '</h1><p role="status">' . esc_html__( 'مرحله ۲: زیرساخت استعلام قیمت آماده است؛ امکانات مدیریت هنوز فعال نشده‌اند.', 'tapin-service-point-locator' ) . '</p></div>';
	}
}
