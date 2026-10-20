<?php
/** CLI rendering adapter, also used by the localhost Playwright fixture server. */
define( 'ABSPATH', dirname( __DIR__ ) . '/' ); define( 'TAPIN_PLUGIN_DIR', ABSPATH );
$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$interface_site = $input['site'] ?? 'https://wp.example/site'; define( 'TAPIN_PLUGIN_URL', $interface_site . '/plugin/' );
require ABSPATH . 'src/Autoloader.php'; \Tapin\ServicePointLocator\Autoloader::register(); require __DIR__ . '/support/PriceCheckInterface.php';
function is_user_logged_in() { return $GLOBALS['input']['logged'] ?? false; }
function current_user_can( $cap ) { return 'manage_options' === $cap && ( $GLOBALS['input']['capable'] ?? false ); }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . ( $args['response'] ?? 0 ) ); }
function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
try {
	if ( $input['admin'] ?? false ) { echo \Tapin\ServicePointLocator\PriceCheck\InterfaceScreen::frame( $input['mode'] ?? 'providers', true, $input['query'] ?? array() ); }
	else { $pages = new \Tapin\ServicePointLocator\PriceCheck\PublicPages(); echo $pages->{ $input['mode'] ?? 'single' }( $input['attributes'] ?? array() ); }
} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 ); }
