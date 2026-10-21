<?php
/** Actual admin callbacks and protected preview renderer; WordPress identity/enqueue doubles only. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'TAPIN_PLUGIN_URL', 'https://wp.example/site/plugin/' );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register(); require __DIR__ . '/support/PriceCheckInterface.php';
$identity = array( false, false ); $menus = array(); $top_menus = array(); $actions = array(); $checks = 0;
function __( $text, ...$args ) { return $text; } function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $text ) { return esc_html( $text ); }
function is_user_logged_in() { return $GLOBALS['identity'][0]; } function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['identity'][1]; }
function wp_die( $text, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . $args['response'] ); }
function add_menu_page( $title, $label, $capability, $slug, $callback ) { $GLOBALS['top_menus'][$slug] = compact( 'title', 'label', 'capability', 'callback' ); return 'toplevel_page_' . $slug; }
function add_submenu_page( $parent, $title, $label, $capability, $slug, $callback ) { $GLOBALS['menus'][$slug] = compact( 'parent', 'title', 'label', 'capability', 'callback' ); return ( '' === $parent ? 'admin_page_' : 'tapin_page_' ) . $slug; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['actions'][$hook][] = $callback; }
function add_shortcode( $tag, $callback ) {}
function ui_admin_check( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Admin UI assertion ' . $GLOBALS['checks'] . ' failed.' ); } $GLOBALS['checks']++; }
( new \Tapin\ServicePointLocator\UI\App() )->boot(); $actions['admin_menu'][0]();
$price_admin = new \Tapin\ServicePointLocator\PriceCheck\Admin(); $price_admin->register();
$price_menus = array_filter( $menus, static fn( $slug ) => 0 === strpos( $slug, 'tapin-price-' ), ARRAY_FILTER_USE_KEY ); ui_admin_check( 6 === count( $price_menus ) );
$visible = array_filter( $menus, static fn( $menu ) => 'tapin-locator' === $menu['parent'] ); ui_admin_check( 1 === count( $top_menus ) && 'tapin-locator' === array_key_first( $top_menus ) && 'تاپین' === $top_menus['tapin-locator']['label'] ); ui_admin_check( 2 === count( $visible ) && isset( $visible['tapin-locator'], $visible['tapin-price-providers'] ) ); ui_admin_check( 'نقشه' === $visible['tapin-locator']['label'] && 'استعلام قیمت' === $visible['tapin-price-providers']['label'] );
foreach ( $price_menus as $slug => $menu ) {
	ui_admin_check( 'manage_options' === $menu['capability'] && ( 'tapin-price-providers' === $slug ? 'tapin-locator' === $menu['parent'] : '' === $menu['parent'] ) );
	foreach ( array( array( false, false ), array( true, false ), array( false, true ) ) as $identity ) { ob_start(); try { $menu['callback'](); throw new LogicException( 'Unauthorized admin UI rendered.' ); } catch ( RuntimeException $e ) { ui_admin_check( 'denied:403' === $e->getMessage() ); } finally { ui_admin_check( '' === ob_get_clean() ); } }
	$identity = array( true, true ); ob_start(); $menu['callback'](); $html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' ); ui_admin_check( false !== strpos( $html, 'test-rest-nonce' ) && false !== strpos( $html, '/admin/' . substr( $slug, 12 ) ) ); ui_admin_check( false === strpos( $html, 'ADMIN_TOKEN' ) ); foreach ( array( 'providers', 'shops', 'bulk', 'logs', 'cache', 'security' ) as $screen ) { ui_admin_check( false !== strpos( $html, 'admin.php?page=tapin-price-' . $screen ) ); }
}
foreach ( array( 'provider', 'bulk' ) as $kind ) { $_GET = array( 'tapin_price_preview' => $kind ); ob_start(); $menus['tapin-price-providers']['callback'](); $html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' ); ui_admin_check( false !== strpos( $html, '/preview/' . $kind ) && false !== strpos( $html, 'index.js' ) && false !== strpos( $html, 'test-rest-nonce' ) ); }
$_GET = array(); $price_enqueue = end( $actions['admin_enqueue_scripts'] ); $interface_styles = array(); $price_enqueue( 'toplevel_page_tapin-locator' ); ui_admin_check( array() === $interface_styles );
$price_enqueue( 'admin_page_tapin-price-shops' ); ui_admin_check( array_keys( $interface_styles ) === array( 'tapin-price-frame' ) );
$identity = array( true, false ); $interface_styles = array(); $price_enqueue( 'admin_page_tapin-price-shops' ); ui_admin_check( array() === $interface_styles );
$template = file_get_contents( ABSPATH . 'src/PriceCheck/views/admin.php' ); foreach ( array( 'providers', 'shops', 'bulk', 'logs', 'cache', 'security' ) as $screen ) { ui_admin_check( false !== strpos( $template, 'data-tab="' . $screen . '"' ) ); }
$identity = array( true, true );
$document = \Tapin\ServicePointLocator\PriceCheck\InterfaceScreen::document( 'providers', true );
ui_admin_check( false !== strpos( $document, 'style.css?v=' . hash_file( 'sha256', ABSPATH . 'assets/price-check/style.css' ) . '"' ) );
ui_admin_check( false !== strpos( $document, 'id="pc-sidebar-tooltip"' ) );
ui_admin_check( false !== strpos( $document, '<script type="importmap">' ) && false !== strpos( $document, 'wordpress.js?v=' . hash_file( 'sha256', ABSPATH . 'assets/price-check/wordpress.js' ) ) );
// Previews receive fresh CSS without admin navigation markup or adapter imports.
$preview_document = \Tapin\ServicePointLocator\PriceCheck\InterfaceScreen::document( 'providers', true, array( 'tapin_price_preview' => 'provider' ) );
ui_admin_check( false !== strpos( $preview_document, 'style.css?v=' . hash_file( 'sha256', ABSPATH . 'assets/price-check/style.css' ) ) && false === strpos( $preview_document, 'pc-sidebar-tooltip' ) );
ui_admin_check( false === strpos( $preview_document, 'type="importmap"' ) );
echo "Protected admin interface: {$checks} assertions passed (capability/preview/enqueue doubles).\n";
