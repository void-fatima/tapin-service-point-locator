<?php
/** Real Admin registration/rendering; identity, hooks and WordPress shell are doubles. */
define( 'ABSPATH', rtrim( getenv( 'TAPIN_PACKAGE_ROOT' ) ?: dirname( __DIR__ ), '/\\' ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$interface_site = $input['site'];
define( 'TAPIN_PLUGIN_URL', $interface_site . '/plugin/' );
require ABSPATH . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
require __DIR__ . '/support/PriceCheckInterface.php';
$actions = array();
function __( $text, ...$args ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function is_user_logged_in() { return true; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function add_submenu_page( $parent, $title, $label, $cap, $slug, $callback ) { return ( $parent ? 'tapin_page_' : 'admin_page_' ) . $slug; }
function add_action( $hook, $callback, $priority = 10 ) { $GLOBALS['actions'][$hook][] = $callback; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( $message ); }
$admin = new \Tapin\ServicePointLocator\PriceCheck\Admin();
$admin->register();
$screen = $input['mode'] ?? 'bulk';
$price = $input['price'] ?? true;
$hook = $price ? ( 'providers' === $screen ? 'tapin_page_' : 'admin_page_' ) . 'tapin-price-' . $screen : 'dashboard';
foreach ( $actions['admin_enqueue_scripts'] ?? array() as $callback ) { $callback( $hook ); }
$_GET = $input['query'] ?? array();
$dir = $input['dir'] ?? 'ltr';
echo '<!doctype html><html class="wp-toolbar" dir="' . esc_attr( $dir ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<link rel="stylesheet" href="' . esc_url( $interface_site . '/wp-admin/css/common' . ( 'rtl' === $dir ? '-rtl' : '' ) . '.css' ) . '">';
// The bars represent unrelated WP chrome. Geometry and perimeter come from core common.css.
echo '<style>:root{--wp-admin--admin-bar--height:32px}#wpadminbar{position:fixed;top:0;left:0;right:0;height:32px;background:#1d2327;color:#fff;z-index:99999}#adminmenumain{position:fixed;top:32px;left:0;bottom:0;width:160px;background:#1d2327;color:#fff}html[dir=rtl] #adminmenumain{left:auto;right:0}@media(max-width:782px){:root{--wp-admin--admin-bar--height:46px}#adminmenumain{display:none}#wpadminbar{height:46px}}</style>';
wp_print_styles( array_keys( $interface_styles ) );
foreach ( $actions['admin_head'] ?? array() as $callback ) { $callback(); }
echo '</head><body class="wp-admin wp-core-ui auto-fold"><div id="wpadminbar">WordPress toolbar</div><div id="wpwrap"><div id="adminmenumain">WordPress menu</div><div id="wpcontent"><div id="wpbody"><div id="wpbody-content">';
if ( $price ) { $admin->render( 'Price Check', $screen ); }
else { echo '<div class="wrap"><h1>Unrelated WordPress administration</h1></div>'; }
echo '<div class="clear"></div></div><div class="clear"></div></div><div class="clear"></div></div><div id="wpfooter"><p>WordPress footer</p></div></div></body></html>';
