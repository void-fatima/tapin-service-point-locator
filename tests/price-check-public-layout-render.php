<?php
/** Execute the actual page-template choice and public template; WP hooks/content are doubles. */
define( 'ABSPATH', rtrim( getenv( 'TAPIN_PACKAGE_ROOT' ) ?: dirname( __DIR__ ), '/\\' ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$interface_site = $input['site'];
define( 'TAPIN_PLUGIN_URL', $interface_site . '/plugin/' );
require ABSPATH . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckInterface.php';
function add_shortcode( $tag, $callback ) { $GLOBALS['shortcodes'][$tag] = $callback; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['hooks'][$hook][] = $callback; }
function is_user_logged_in() { return false; }
function current_user_can( $cap ) { return false; }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return esc_attr( $text ); }
function language_attributes() { echo 'lang="fa" dir="rtl"'; }
function bloginfo( $key ) { echo 'UTF-8'; }
function current_theme_supports( $feature ) { return false; }
function wp_get_document_title() { return 'Quotation layout fixture'; }
function body_class( $classes ) { echo 'class="' . esc_attr( $classes . ( $GLOBALS['input']['toolbar'] ?? false ? ' admin-bar' : '' ) ) . '"'; }
function wp_body_open() { if ( $GLOBALS['input']['toolbar'] ?? false ) { echo '<div id="wpadminbar">WordPress toolbar fixture</div>'; } }
function wp_footer() {}
function wp_head() {
	echo '<style>body{margin:0}html{--wp--style--global--content-size:645px}#theme-header,#theme-footer{padding:32px}.is-layout-constrained{padding:0 24px}.is-layout-constrained>:where(:not(.alignleft):not(.alignright):not(.alignfull)){max-width:var(--wp--style--global--content-size);margin-left:auto!important;margin-right:auto!important}#theme-content{padding-top:70px;padding-bottom:70px}#theme-title{margin-bottom:40px}#wpadminbar{position:fixed;inset:0 0 auto;height:32px;background:#1d2327;color:white;z-index:9999}</style>';
	if ( $GLOBALS['input']['toolbar'] ?? false ) { echo '<style>html{margin-top:32px!important}@media(max-width:782px){html{margin-top:46px!important}#wpadminbar{height:46px}}</style>'; }
	foreach ( $GLOBALS['hooks']['wp_enqueue_scripts'] ?? array() as $callback ) { $callback(); }
	wp_print_styles( array_keys( $GLOBALS['interface_styles'] ) );
}
function have_posts() { static $calls = 0; return 0 === $calls++; }
function the_post() {}
function the_content() {
	$tag = 'single' === $GLOBALS['input']['mode'] ? 'tapin_price_check' : 'tapin_bulk_quote';
	$attributes = $GLOBALS['input']['attributes'] ?? array();
	if ( $GLOBALS['input']['mixed'] ?? false ) { echo '<p id="before-module">Content before quotation</p>'; }
	echo call_user_func( $GLOBALS['shortcodes'][$tag], $attributes );
	if ( $GLOBALS['input']['mixed'] ?? false ) { echo '<p id="after-module">Content after quotation</p>'; }
}
$mode = $input['mode'];
$tag = 'single' === $mode ? 'tapin_price_check' : 'tapin_bulk_quote';
$attrs = isset( $input['attributes']['layout'] ) ? ' layout="' . $input['attributes']['layout'] . '"' : '';
$post = new WP_Post( 123, ( $input['mixed'] ?? false ? '<p>Content before quotation</p>' : '' ) . '[' . $tag . $attrs . ']' );
$pages = new \Tapin\ServicePointLocator\PriceCheck\PublicPages();
$pages->boot();
$template = $pages->page_template( 'theme' );
if ( 'theme' !== $template ) { include $template; }
else {
	echo '<!doctype html><html lang="fa"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'; wp_head(); echo '</head><body>'; wp_body_open();
	echo '<header id="theme-header">Theme header and navigation</header><main id="theme-content" class="is-layout-constrained"><h1 id="theme-title">Quotation page title</h1>'; the_content();
	echo '</main><footer id="theme-footer">Theme footer</footer></body></html>';
}
