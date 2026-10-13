<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Shared boundary for admin rendering and later REST permission callbacks. */
final class Access {

	const CAPABILITY = 'manage_options';

	public static function can_manage(): bool {
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}

	public static function require_admin(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'دسترسی به مدیریت استعلام قیمت مجاز نیست.', 'tapin-service-point-locator' ), '', array( 'response' => 403 ) );
		}
	}
}
