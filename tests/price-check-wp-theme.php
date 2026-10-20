<?php
/** Optional loopback PHP-server router: real installed WP/theme, read-only page fixture. */
$wp_root = getenv( 'TAPIN_WP_ROOT' );
if ( 'cli-server' !== PHP_SAPI || ! in_array( $_SERVER['REMOTE_ADDR'] ?? '', array( '127.0.0.1', '::1' ), true ) || ! $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) { http_response_code( 404 ); exit; }
$repo = dirname( __DIR__ );
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$asset = null;
if ( 0 === strpos( $path, '/fixture-plugin/assets/' ) && false === strpos( $path, '..' ) ) { $asset = $repo . substr( $path, strlen( '/fixture-plugin' ) ); }
elseif ( preg_match( '~^/(wp-includes|wp-content/themes)/~', $path ) && false === strpos( $path, '..' ) ) { $asset = $wp_root . $path; }
if ( $asset && is_file( $asset ) ) {
	$types = array( 'css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon' );
	$type = $types[pathinfo( $asset, PATHINFO_EXTENSION )] ?? null;
	if ( ! $type ) { http_response_code( 404 ); exit; }
	header( 'Content-Type: ' . $type ); readfile( $asset ); exit;
}
if ( ! in_array( $path, array( '/single/', '/bulk/' ), true ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', rtrim( $wp_root, '/\\' ) . '/' );
define( 'WP_USE_THEMES', true );
define( 'DISABLE_WP_CRON', true );
require_once ABSPATH . 'wp-includes/plugin.php';
// Disable installed plugins in this request only; no activation, schema or data changes.
add_filter( 'pre_option_active_plugins', static fn() => array() );
add_filter( 'pre_site_option_active_sitewide_plugins', static fn() => array() );
$base = 'http://' . $_SERVER['HTTP_HOST'];
add_filter( 'pre_option_home', static fn() => $base );
add_filter( 'pre_option_siteurl', static fn() => $base );
require ABSPATH . 'wp-load.php';
define( 'TAPIN_PLUGIN_DIR', $repo . '/' );
define( 'TAPIN_PLUGIN_URL', $base . '/fixture-plugin/' );
require $repo . '/src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
( new \Tapin\ServicePointLocator\PriceCheck\PublicPages() )->boot();
add_filter( 'tapin_price_page_urls', static fn() => array( 'single' => $base . '/single/', 'bulk' => $base . '/bulk/' ) );
$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1 ) );
if ( ! $pages ) { throw new RuntimeException( 'A published local WordPress page is required.' ); }
$shortcode = '/bulk/' === $path ? '[tapin_bulk_quote]' : '[tapin_price_check]';
add_filter( 'the_posts', static function( $posts, $query ) use ( $shortcode ) {
	if ( $query->is_main_query() ) {
		foreach ( $posts as &$post ) {
			$post = clone $post;
			$post->post_password = '';
			$post->post_content = '<p id="fixture-before">Before quotation</p>' . $shortcode . '<p id="fixture-after">After quotation</p>';
		}
	}
	return $posts;
}, 10, 2 );
$_SERVER['REQUEST_URI'] = '/?page_id=' . $pages[0]->ID;
wp( array( 'page_id' => $pages[0]->ID ) );
remove_action( 'template_redirect', 'redirect_canonical' );
require ABSPATH . WPINC . '/template-loader.php';
