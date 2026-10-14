<?php
/** Actual admin callbacks and protected preview renderer; WordPress identity/enqueue doubles only. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH ); define( 'TAPIN_PLUGIN_URL', 'https://wp.example/site/plugin/' );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register(); require __DIR__ . '/support/PriceCheckInterface.php';
$identity = array( false, false ); $menus = array(); $actions = array(); $checks = 0;
function __( $text, ...$args ) { return $text; } function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $text ) { return esc_html( $text ); }
function is_user_logged_in() { return $GLOBALS['identity'][0]; } function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['identity'][1]; }
function wp_die( $text, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . $args['response'] ); }
function add_submenu_page( $parent, $title, $label, $capability, $slug, $callback ) { $GLOBALS['menus'][$slug] = compact( 'parent', 'capability', 'callback' ); return 'tapin_page_' . $slug; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['actions'][$hook][] = $callback; }
function ui_admin_check( $condition ) { if ( ! $condition ) { throw new RuntimeException( 'Admin UI assertion ' . $GLOBALS['checks'] . ' failed.' ); } $GLOBALS['checks']++; }
( new \Tapin\ServicePointLocator\PriceCheck\Admin() )->register(); ui_admin_check( 6 === count( $menus ) );
foreach ( $menus as $slug => $menu ) {
	ui_admin_check( 'manage_options' === $menu['capability'] && 'tapin-locator' === $menu['parent'] );
	foreach ( array( array( false, false ), array( true, false ), array( false, true ) ) as $identity ) { ob_start(); try { $menu['callback'](); throw new LogicException( 'Unauthorized admin UI rendered.' ); } catch ( RuntimeException $e ) { ui_admin_check( 'denied:403' === $e->getMessage() ); } finally { ui_admin_check( '' === ob_get_clean() ); } }
	$identity = array( true, true ); ob_start(); $menu['callback'](); $html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' ); ui_admin_check( false !== strpos( $html, 'test-rest-nonce' ) && false !== strpos( $html, '/admin/' . substr( $slug, 12 ) ) ); ui_admin_check( false === strpos( $html, 'ADMIN_TOKEN' ) );
}
foreach ( array( 'provider', 'bulk' ) as $kind ) { $_GET = array( 'tapin_price_preview' => $kind ); ob_start(); $menus['tapin-price-providers']['callback'](); $html = html_entity_decode( ob_get_clean(), ENT_QUOTES, 'UTF-8' ); ui_admin_check( false !== strpos( $html, '/preview/' . $kind ) && false !== strpos( $html, 'index.js' ) && false !== strpos( $html, 'test-rest-nonce' ) ); }
$_GET = array(); $interface_styles = array(); foreach ( $actions['admin_enqueue_scripts'] as $action ) { $action( 'toplevel_page_tapin-locator' ); } ui_admin_check( array() === $interface_styles );
foreach ( $actions['admin_enqueue_scripts'] as $action ) { $action( 'tapin_page_tapin-price-shops' ); } ui_admin_check( array_keys( $interface_styles ) === array( 'tapin-price-frame' ) );
$identity = array( true, false ); $interface_styles = array(); foreach ( $actions['admin_enqueue_scripts'] as $action ) { $action( 'tapin_page_tapin-price-shops' ); } ui_admin_check( array() === $interface_styles );
echo "Protected admin interface: {$checks} assertions passed (capability/preview/enqueue doubles).\n";
