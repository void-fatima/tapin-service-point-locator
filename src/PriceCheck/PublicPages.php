<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Public placeholders only; no assets, page creation, or theme replacement. */
final class PublicPages {

	public function boot(): void {
		add_shortcode( 'tapin_price_check', array( $this, 'single' ) );
		add_shortcode( 'tapin_bulk_quote', array( $this, 'bulk' ) );
	}

	public function single(): string {
		return $this->placeholder( 'single', __( 'استعلام قیمت تکی', 'tapin-service-point-locator' ) );
	}

	public function bulk(): string {
		return $this->placeholder( 'bulk', __( 'استعلام قیمت گروهی', 'tapin-service-point-locator' ) );
	}

	private function placeholder( string $mode, string $title ): string {
		return '<div class="tapin-price-public alignwide" dir="rtl" lang="fa" data-quote-mode="' . esc_attr( $mode ) . '"><h2>' . esc_html( $title ) . '</h2><p role="status">' . esc_html__( 'مرحله ۲: این بخش در حال آماده‌سازی است؛ استعلام قیمت هنوز فعال نیست.', 'tapin-service-point-locator' ) . '</p></div>';
	}
}
