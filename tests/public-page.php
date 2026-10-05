<?php
/** Exercise public template selection with the real WordPress shortcode parser, without a database. */
$wp_root = getenv( 'TAPIN_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-includes/shortcodes.php' ) ) {
	fwrite( STDERR, "Set TAPIN_WP_ROOT to installed WordPress files; no database or session is required.\n" );
	exit( 1 );
}
define( 'ABSPATH', $wp_root . '/' );
define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_URL', 'http://locator.test/' );
define( 'TAPIN_VERSION', 'fixture' );
require $wp_root . '/wp-includes/shortcodes.php';
require $wp_root . '/wp-includes/class-wp-post.php';
require TAPIN_PLUGIN_DIR . 'src/UI/App.php';

$context = array( 'admin' => false, 'page' => true, 'protected' => false );
$hooks = array(); $printed_styles = array(); $loop_done = false;
function add_action( $name, $callback, ...$args ) {}
function add_filter( $name, $callback, ...$args ) { $GLOBALS['hooks'][$name] = $callback; }
function apply_filters( $name, $value, ...$args ) { return $value; }
function is_admin() { return $GLOBALS['context']['admin']; }
function is_page() { return $GLOBALS['context']['page']; }
function get_queried_object() { return $GLOBALS['fixture_post']; }
function post_password_required( $post ) { return $GLOBALS['context']['protected']; }
function wp_enqueue_style( ...$args ) {}
function wp_enqueue_script( ...$args ) {}
function wp_localize_script( ...$args ) {}
function wp_style_is( ...$args ) { return false; }
function wp_print_styles( $handles ) { $GLOBALS['printed_styles'] = $handles; }
function is_ssl() { return false; }
function rest_url( $path ) { return 'http://locator.test/api/' . $path; }
function admin_url( $path ) { return 'http://locator.test/wp-admin/' . $path; }
function esc_url_raw( $url ) { return $url; }
function wp_kses_post( $html ) { return $html; }
function language_attributes() { echo 'lang="fa" dir="rtl"'; }
function bloginfo( $key ) { echo 'UTF-8'; }
function current_theme_supports( $key ) { return false; }
function wp_get_document_title() { return 'Public locator'; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_head() { echo '<meta name="fixture-head">'; }
function wp_body_open() { echo '<!-- fixture-body-open -->'; }
function wp_footer() { echo '<!-- fixture-footer -->'; }
function body_class( $class ) { echo 'class="' . esc_html( $class ) . '"'; }
function have_posts() { return ! $GLOBALS['loop_done']; }
function the_post() { $GLOBALS['loop_done'] = true; }
function the_content() { echo '<p>Preserved page content</p>' . $GLOBALS['shortcode_tags']['tapin_service_points'](); }
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$app = new \Tapin\ServicePointLocator\UI\App();
$app->boot();
$original = '/theme/page.php';
$select = $hooks['template_include'];
foreach ( array( 22, 9876 ) as $id ) {
	$fixture_post = new WP_Post( (object) array( 'ID' => $id, 'post_content' => '<!-- wp:shortcode -->[tapin_service_points]<!-- /wp:shortcode -->' ) );
	check( $select( $original ) === TAPIN_PLUGIN_DIR . 'src/UI/public-page.php', 'Shortcode page did not select the reusable shell.' );
}
foreach ( array( 'Normal page', '[[tapin_service_points]]', '[tapin_service_points layout="embedded"]', "[tapin_service_points layout='embedded']", '[tapin_service_points layout=embedded]' ) as $content ) {
	$fixture_post->post_content = $content;
	check( $select( $original ) === $original, 'Unrelated, escaped, or embedded page lost its theme template.' );
}
$fixture_post->post_content = '[tapin_service_points]';
foreach ( array( 'admin', 'protected', 'page' ) as $flag ) {
	$context[$flag] = 'page' !== $flag;
	check( $select( $original ) === $original, 'Admin, protected, or non-page context changed template.' );
	$context[$flag] = 'page' === $flag;
}
ob_start(); require $select( $original ); $html = ob_get_clean();
foreach ( array( 'tapin-locator-page', 'tapin-public-root', 'Preserved page content', 'fixture-head', 'fixture-body-open', 'fixture-footer', 'lang="fa" dir="rtl"' ) as $value ) {
	check( str_contains( $html, $value ), 'Missing content or WordPress hook: ' . $value );
}
check( in_array( 'tapin-theme', $printed_styles, true ), 'Late shortcode rendering omitted the active theme styles.' );
echo "PASS public page: WordPress shortcode parsing, reusable page selection, embedded/escaped/unrelated/admin/protected exclusions, content/hooks and late theme styles.\n";
