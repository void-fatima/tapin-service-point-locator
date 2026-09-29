<?php
namespace Tapin\ServicePointLocator\UI;

defined( 'ABSPATH' ) || exit;

final class App {
	public function boot(): void {
		add_action( 'admin_menu', function() {
			add_menu_page( 'نقاط خدماتی تاپین', 'تاپین', 'manage_options', 'tapin-locator', array( $this, 'admin' ), 'dashicons-location-alt', 30 );
		} );
		add_action( 'admin_enqueue_scripts', function( $hook ) { if ( 'toplevel_page_tapin-locator' === $hook ) { $this->assets( true ); } } );
		add_action( 'wp_enqueue_scripts', function() {
			global $post;
			if ( $post instanceof \WP_Post && has_shortcode( $post->post_content, 'tapin_service_points' ) ) { $this->assets( false ); }
		} );
		add_shortcode( 'tapin_service_points', function() {
			$this->assets( false );
			// Template/widget shortcodes may render after wp_head; print their styles once.
			ob_start();
			if ( ! wp_style_is( 'tapin-app', 'done' ) ) { wp_print_styles( array( 'tapin-leaflet', 'tapin-app' ) ); }
			$styles = ob_get_clean();
			return $styles . '<div class="tapin-app tapin-public alignwide" dir="rtl" lang="fa"><div class="tapin-public-root"><p role="status">در حال بارگذاری نقشه…</p></div><noscript>برای استفاده از نقشه، جاوااسکریپت مرورگر را فعال کنید.</noscript></div>';
		} );
	}
	public function admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		echo '<div id="tapin-admin" class="tapin-app" dir="rtl" lang="fa"><p role="status">در حال بارگذاری داشبورد…</p><noscript>برای مدیریت نقاط خدماتی، جاوااسکریپت مرورگر را فعال کنید.</noscript></div>';
	}
	private function assets( bool $admin ): void {
		// Development stages retain the release version until validation is authorized.
		// Content timestamps prevent browsers retaining Phase 1 scripts/styles meanwhile.
		$version = static fn( $file ) => TAPIN_VERSION . '.' . (string) filemtime( TAPIN_PLUGIN_DIR . 'assets/' . $file );
		$tiles = str_replace( array( '%7B', '%7D' ), array( '{', '}' ), esc_url_raw( str_replace( array( '{', '}' ), array( '%7B', '%7D' ), apply_filters( 'tapin_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ) ) ) );
		wp_enqueue_style( 'tapin-leaflet', TAPIN_PLUGIN_URL . 'assets/vendor/leaflet.css', array(), '1.9.4' );
		wp_enqueue_style( 'tapin-app', TAPIN_PLUGIN_URL . 'assets/app.css', array( 'tapin-leaflet' ), $version( 'app.css' ) );
		if ( $admin ) { wp_enqueue_style( 'tapin-dashboard', TAPIN_PLUGIN_URL . 'assets/dashboard.css', array( 'tapin-app' ), $version( 'dashboard.css' ) ); }
		wp_enqueue_script( 'tapin-leaflet', TAPIN_PLUGIN_URL . 'assets/vendor/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'tapin-locations', TAPIN_PLUGIN_URL . 'assets/iran-locations.js', array(), $version( 'iran-locations.js' ), true );
		wp_enqueue_script( 'tapin-map', TAPIN_PLUGIN_URL . 'assets/map.js', array( 'tapin-leaflet', 'tapin-locations' ), $version( 'map.js' ), true );
		$api_url = rest_url( 'tapin/v1/' );
			if ( is_ssl() || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) { $api_url = set_url_scheme( $api_url, 'https' ); }
			wp_localize_script( 'tapin-map', 'TapinConfig', array( 'api' => esc_url_raw( $api_url ), 'nonce' => $admin ? wp_create_nonce( 'wp_rest' ) : '', 'assets' => TAPIN_PLUGIN_URL . 'assets/', 'adminUrl' => admin_url( 'admin.php?page=tapin-locator' ), 'tiles' => $tiles, 'attribution' => wp_kses_post( apply_filters( 'tapin_tile_attribution', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' ) ) ) );
		if ( $admin ) { wp_enqueue_script( 'tapin-admin', TAPIN_PLUGIN_URL . 'assets/admin.js', array( 'tapin-map' ), $version( 'admin.js' ), true ); }
	}
}
