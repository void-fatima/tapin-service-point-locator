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

	public function single( $attributes = array() ): string {
		return InterfaceScreen::frame( 'single', false, array(), $this->embedded( $attributes ) );
	}

	public function bulk( $attributes = array() ): string {
		return InterfaceScreen::frame( 'bulk', false, array(), $this->embedded( $attributes ) );
	}
	private function embedded( $attributes ): bool {
		$attributes = is_array( $attributes ) ? $attributes : array();
		if ( isset( $attributes['layout'] ) ) { return 'standalone' !== $attributes['layout']; }
		return $this->page_template( '' ) !== __DIR__ . '/views/page.php';
	}

	public function page_template( string $template ): string {
		if ( is_admin() || ! is_page() ) { return $template; } $post = get_queried_object();
		if ( ! $post instanceof \WP_Post || post_password_required( $post ) || has_shortcode( $post->post_content, 'tapin_service_points' ) ) { return $template; }
		preg_match_all( '/' . get_shortcode_regex( array( 'tapin_price_check', 'tapin_bulk_quote' ) ) . '/s', $post->post_content, $matches, PREG_SET_ORDER );
		$quotes = array_filter( $matches, static fn( $match ) => '[' !== $match[1] || ']' !== $match[6] );
		foreach ( $quotes as $match ) {
			$attributes = shortcode_parse_atts( $match[3] );
			if ( 'standalone' === ( $attributes['layout'] ?? '' ) ) { return __DIR__ . '/views/page.php'; }
			if ( isset( $attributes['layout'] ) || 1 !== count( $quotes ) || ! empty( $match[5] ) ) { continue; }
			// A quotation-only page is dedicated; surrounding site content stays embedded.
			$remainder = str_replace( $match[0], '', $post->post_content );
			$remainder = preg_replace( '~<!--\s*/?wp:(?:shortcode|paragraph)(?:\s+.*?)?\s*-->|</?p(?:\s[^>]*)?>~is', '', $remainder );
			if ( '' === trim( $remainder ) ) { return __DIR__ . '/views/page.php'; }
		}
		return $template;
	}
	public static function contains( string $content, string $tag ): bool {
		preg_match_all( '/' . get_shortcode_regex( array( $tag ) ) . '/s', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) { if ( '[' !== $match[1] || ']' !== $match[6] ) { return true; } } return false;
	}
}
