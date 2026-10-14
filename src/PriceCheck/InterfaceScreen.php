<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Original document isolated from theme/locator CSS and duplicate document-wide controls. */
final class InterfaceScreen {
	public static function enqueue(): void {
		wp_enqueue_style( 'tapin-price-frame', TAPIN_PLUGIN_URL . 'assets/price-check/frame.css', array(), (string) filemtime( TAPIN_PLUGIN_DIR . 'assets/price-check/frame.css' ) );
	}
	public static function page_urls(): array {
		$urls = array( 'single' => '', 'bulk' => '' );
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 's' => 'tapin_', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $post ) {
			if ( '' !== $post->post_password ) { continue; }
			foreach ( array( 'single' => 'tapin_price_check', 'bulk' => 'tapin_bulk_quote' ) as $mode => $tag ) { if ( '' === $urls[$mode] && PublicPages::contains( $post->post_content, $tag ) ) { $urls[$mode] = get_permalink( $post ); } }
		}
		return (array) apply_filters( 'tapin_price_page_urls', $urls );
	}
	public static function document( string $mode, bool $admin = false, array $query = array() ): string {
		if ( $admin ) { Access::require_admin(); }
		$preview = $admin && in_array( $query['tapin_price_preview'] ?? null, array( 'provider', 'bulk' ), true ) ? $query['tapin_price_preview'] : null;
		$route = $admin ? '/admin/' . $mode : ( 'single' === $mode ? '/advanced' : '/bulk' );
		if ( $preview ) { $route = '/preview/' . $preview; }
		$config = array( 'restUrl' => rest_url( RestApi::NAMESPACE . '/' ), 'route' => $route, 'publicUrls' => self::page_urls(), 'admin' => $admin );
		if ( $admin ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' ); $config['adminUrls'] = array();
			foreach ( array( 'providers', 'shops', 'bulk', 'logs', 'cache', 'security' ) as $screen ) { $config['adminUrls'][$screen] = admin_url( 'admin.php?page=tapin-price-' . $screen ); }
		}
		ob_start(); include __DIR__ . '/views/' . ( $admin && ! $preview ? 'admin' : 'public' ) . '.php'; $html = ob_get_clean();
		$assets = TAPIN_PLUGIN_URL . 'assets/price-check/';
		$html = str_replace( '/static/', esc_url( $assets ), $html );
		$html = str_replace( '<head>', '<head><base href="about:srcdoc">', $html );
		if ( $admin && ! $preview ) { $html = str_replace( '{{public-url}}', esc_url( $config['publicUrls']['bulk'] ?: ( $config['publicUrls']['single'] ?: home_url( '/' ) ) ), $html ); foreach ( array( 'providers', 'shops' ) as $resource ) { $html = str_replace( '/api/admin/' . $resource . '</span>', esc_url( rest_url( RestApi::NAMESPACE . '/admin/' . $resource ) ) . '</span>', $html ); } }
		$html = str_replace( 'https://unpkg.com/leaflet@1.9.4/dist/', esc_url( TAPIN_PLUGIN_URL . 'assets/vendor/' ), $html );
		$html = preg_replace_callback( '~(src=")([^"<>]+/(?:index|admin)\.js)(")~', static fn( $m ) => $m[1] . $m[2] . '?v=' . filemtime( TAPIN_PLUGIN_DIR . 'assets/price-check/' . basename( $m[2] ) ) . $m[3], $html );
		return str_replace( '</head>', '<script>window.TapinPriceUI=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES ) . ';</script></head>', $html );
	}
	public static function frame( string $mode, bool $admin = false, array $query = array() ): string {
		$html = self::document( $mode, $admin, $query ); self::enqueue();
		ob_start(); if ( ! wp_style_is( 'tapin-price-frame', 'done' ) ) { wp_print_styles( array( 'tapin-price-frame' ) ); } $styles = ob_get_clean();
		$title = $admin ? 'مدیریت استعلام قیمت تاپین' : ( 'single' === $mode ? 'استعلام پیشرفته قیمت ارسال' : 'استعلام قیمت ارسال' );
		return $styles . '<div class="tapin-price-public alignwide" data-quote-mode="' . esc_attr( $mode ) . '"><iframe class="tapin-price-frame" title="' . esc_attr( $title ) . '" srcdoc="' . esc_attr( $html ) . '"></iframe><noscript>برای استفاده از استعلام قیمت، جاوااسکریپت مرورگر را فعال کنید.</noscript></div>';
	}
}
