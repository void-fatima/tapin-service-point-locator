<?php

namespace Tapin\ServicePointLocator\PriceCheck;

defined( 'ABSPATH' ) || exit;

/** Public single/bulk entry points; only their own documents/styles are loaded. */
final class PublicPages {

	public function boot(): void {
		add_shortcode( 'tapin_price_check', array( $this, 'single' ) );
		add_shortcode( 'tapin_bulk_quote', array( $this, 'bulk' ) );
		add_action( 'wp_enqueue_scripts', function() { global $post; if ( $post instanceof \WP_Post && ! post_password_required( $post ) && ( self::contains( $post->post_content, 'tapin_price_check' ) || self::contains( $post->post_content, 'tapin_bulk_quote' ) ) ) { InterfaceScreen::enqueue(); } } );
		add_filter( 'template_include', array( $this, 'page_template' ), 100 );
	}

	public function single(): string {
		return InterfaceScreen::frame( 'single' );
	}

	public function bulk(): string {
		return InterfaceScreen::frame( 'bulk' );
	}

	public function page_template( string $template ): string {
		if ( is_admin() || ! is_page() ) { return $template; } $post = get_queried_object();
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) || has_shortcode( $post->post_content, 'tapin_service_points' ) ) { return $template; }
		preg_match_all( '/' . get_shortcode_regex( array( 'tapin_price_check', 'tapin_bulk_quote' ) ) . '/s', $post->post_content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) { if ( '[' === $match[1] && ']' === $match[6] ) { continue; } $attributes = shortcode_parse_atts( $match[3] ); if ( 'embedded' === ( $attributes['layout'] ?? '' ) ) { continue; } return __DIR__ . '/views/page.php'; } return $template;
	}
	public static function contains( string $content, string $tag ): bool {
		preg_match_all( '/' . get_shortcode_regex( array( $tag ) ) . '/s', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) { if ( '[' !== $match[1] || ']' !== $match[6] ) { return true; } } return false;
	}
}
