<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'TAPIN_PLUGIN_URL', 'https://wp.example/site/plugin/' );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register(); require __DIR__ . '/support/PriceCheckInterface.php';
function is_user_logged_in() { return false; } function current_user_can( $cap ) { return false; } function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function wp_die( $text, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . $args['response'] ); }
function add_shortcode( $tag, $callback ) { $GLOBALS['interface_shortcodes'][$tag] = $callback; } function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['interface_actions'][$hook][] = $callback; }
use Tapin\ServicePointLocator\PriceCheck\PublicPages; use Tapin\ServicePointLocator\PriceCheck\InterfaceScreen;
$checks = 0; function ui_check( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Interface assertion ' . $GLOBALS['checks'] . ' failed.' ); } $GLOBALS['checks']++; }
$pages = new PublicPages(); $pages->boot(); ui_check( array_keys( $interface_shortcodes ) === array( 'tapin_price_check', 'tapin_bulk_quote' ) );
foreach ( array( 'tapin_price_check' => 'single', 'tapin_bulk_quote' => 'bulk' ) as $tag => $mode ) {
	$html = $interface_shortcodes[$tag](); ui_check( false !== strpos( $html, 'srcdoc=' ) && false !== strpos( $html, 'data-quote-mode="' . $mode . '"' ) );
	ui_check( false !== strpos( $html, 'tapin-price-embedded' ) && false === strpos( $html, 'alignwide' ) );
	$embedded = InterfaceScreen::document( $mode, false, array(), true );
	ui_check( false === strpos( $embedded, '<header class="topbar">' ) && false === strpos( $embedded, '<footer class="footer wrap">' ) && false === strpos( $embedded, 'class="brand"' ) );
	ui_check( false !== strpos( $embedded, 'id="nav-bulk"' ) && false !== strpos( $embedded, 'id="nav-advanced"' ) && false !== strpos( $embedded, '/embedded.css' ) && false !== strpos( $embedded, '/embedded.js' ) );
	ui_check( false !== strpos( $interface_shortcodes[$tag]( array( 'layout' => 'embedded' ) ), 'tapin-price-embedded' ) );
	ui_check( false === strpos( $interface_shortcodes[$tag]( array( 'layout' => 'standalone' ) ), 'tapin-price-embedded' ) );
	$doc = InterfaceScreen::document( $mode ); ui_check( false === strpos( $doc, 'مدیریت داده' ) && false === strpos( $doc, 'href="/admin"' ) ); ui_check( false === strpos( $doc, '"nonce"' ) && false === strpos( $doc, 'adminUrls' ) );
	ui_check( false !== strpos( $doc, '<html lang="fa" dir="rtl">' ) && false !== strpos( $doc, 'id="price-form"' ) && false !== strpos( $doc, 'id="bulk-form"' ) );
	ui_check( false !== strpos( $doc, '/site/wp-json/tapin-price-check/v1/' ) && false !== strpos( $doc, '/site/plugin/assets/vendor/leaflet.js' ) );
	ui_check( false !== strpos( $doc, '<header class="topbar">' ) && false !== strpos( $doc, '<footer class="footer wrap">' ) && false === strpos( $doc, '/embedded.js' ) );
}
ui_check( array_keys( $interface_styles ) === array( 'tapin-price-frame' ) );
$interface_styles = array(); $post = new WP_Post( 20, 'ordinary content' ); foreach ( $interface_actions['wp_enqueue_scripts'] as $action ) { $action(); } ui_check( array() === $interface_styles );
$post->post_content = '[tapin_price_check]'; foreach ( $interface_actions['wp_enqueue_scripts'] as $action ) { $action(); } ui_check( isset( $interface_styles['tapin-price-frame'] ) ); ui_check( 'theme.php' === $pages->page_template( 'theme.php' ) );
foreach ( array( '[tapin_bulk_quote]', '[tapin_bulk_quote layout="embedded"]', '[tapin_price_check layout="unknown"]' ) as $content ) { $post->post_content = $content; ui_check( 'theme.php' === $pages->page_template( 'theme.php' ) ); }
foreach ( array( '[tapin_price_check layout="standalone"]', '[tapin_bulk_quote layout=standalone]' ) as $content ) { $post->post_content = $content; ui_check( false !== strpos( $pages->page_template( 'theme.php' ), 'PriceCheck/views/page.php' ) ); }
$post->post_content = '[tapin_price_check layout="embedded"]'; ui_check( 'theme.php' === $pages->page_template( 'theme.php' ) );
$post->post_content = '[tapin_service_points][tapin_bulk_quote]'; ui_check( 'locator.php' === $pages->page_template( 'locator.php' ) );
$post->post_content = '[[tapin_price_check]]'; ui_check( 'theme.php' === $pages->page_template( 'theme.php' ) ); $interface_styles = array(); foreach ( $interface_actions['wp_enqueue_scripts'] as $action ) { $action(); } ui_check( array() === $interface_styles );
$post->post_content = '[tapin_bulk_quote]'; $post->post_password = 'test-password'; ui_check( 'theme.php' === $pages->page_template( 'theme.php' ) ); foreach ( $interface_actions['wp_enqueue_scripts'] as $action ) { $action(); } ui_check( array() === $interface_styles );
try { InterfaceScreen::document( 'providers', true ); throw new LogicException( 'Admin render accepted.' ); } catch ( RuntimeException $e ) { ui_check( 'denied:403' === $e->getMessage() ); }
echo "Public interface: {$checks} assertions passed (render/enqueue doubles).\n";
