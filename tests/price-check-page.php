<?php
/** Template decisions using the installed WordPress shortcode parser; no database needed. */
$wp_root = getenv( 'TAPIN_WP_ROOT' );
if ( ! $wp_root || ! is_file( $wp_root . '/wp-includes/shortcodes.php' ) ) { fwrite( STDERR, "Set TAPIN_WP_ROOT to installed WordPress files.\n" ); exit( 1 ); }
define( 'ABSPATH', $wp_root . '/' );
require ABSPATH . 'wp-includes/shortcodes.php';
require ABSPATH . 'wp-includes/class-wp-post.php';
require dirname( __DIR__ ) . '/src/PriceCheck/PublicPages.php';
function is_admin() { return $GLOBALS['admin'] ?? false; }
function is_page() { return $GLOBALS['page'] ?? true; }
function get_queried_object() { return $GLOBALS['post']; }
function post_password_required( $post ) { return '' !== $post->post_password; }
foreach ( array( 'tapin_price_check', 'tapin_bulk_quote', 'tapin_service_points' ) as $tag ) { add_shortcode( $tag, static fn() => '' ); }
$pages = new \Tapin\ServicePointLocator\PriceCheck\PublicPages();
$post = new WP_Post( (object) array( 'ID' => 1, 'post_content' => '', 'post_password' => '' ) );
$checks = 0;
function page_check( $condition, $case = '' ) { if ( ! $condition ) { throw new RuntimeException( 'Page assertion ' . $GLOBALS['checks'] . ' failed: ' . $case ); } $GLOBALS['checks']++; }
foreach ( array( 'tapin_price_check', 'tapin_bulk_quote' ) as $tag ) {
	// Existing shortcode-only pages become dedicated without relying on their IDs.
	foreach ( array( "[$tag]", " \n[$tag]\n ", "[$tag /]", "<!-- wp:shortcode -->[$tag]<!-- /wp:shortcode -->", "<p>[$tag]</p>", "<!-- wp:paragraph --><p>[$tag]</p><!-- /wp:paragraph -->" ) as $content ) {
		$post->post_content = $content;
		page_check( str_ends_with( $pages->page_template( 'theme.php' ), '/views/page.php' ), $content );
	}
	// Explicit embeds, escaped examples and surrounding site content keep the theme.
	foreach ( array( "[$tag layout=embedded]", "[$tag layout='embedded']", "[$tag layout=\"embedded\"]", "[$tag layout=unknown]", "[[$tag]]", "[[$tag layout=standalone]]", "<p>Before quotation</p>[$tag]", "[$tag]<p>After quotation</p>", "<img src=\"example.png\" alt=\"\">[$tag]", "[$tag][$tag]", "[tapin_price_check][tapin_bulk_quote]", "[[$tag]][$tag]" ) as $content ) {
		$post->post_content = $content;
		page_check( 'theme.php' === $pages->page_template( 'theme.php' ), $content );
	}
	foreach ( array( "[$tag layout=standalone]", "[$tag layout='standalone']", "[$tag layout=\"standalone\"]" ) as $content ) {
		$post->post_content = $content;
		page_check( str_ends_with( $pages->page_template( 'theme.php' ), '/views/page.php' ), $content );
	}
	$post->post_content = '<p>Explicit standalone content</p>' . "[$tag layout=standalone]";
	page_check( str_ends_with( $pages->page_template( 'theme.php' ), '/views/page.php' ), 'Explicit standalone opt-in' );
	$post->post_content = "[$tag]";
	$post->post_password = 'protected'; page_check( 'theme.php' === $pages->page_template( 'theme.php' ) ); $post->post_password = '';
	$admin = true; page_check( 'theme.php' === $pages->page_template( 'theme.php' ) ); $admin = false;
	$page = false; page_check( 'theme.php' === $pages->page_template( 'theme.php' ) ); $page = true;
	$post->post_content .= '[tapin_service_points]'; page_check( 'locator.php' === $pages->page_template( 'locator.php' ) );
	$post->post_content = "[$tag layout=standalone][tapin_service_points]"; page_check( 'locator.php' === $pages->page_template( 'locator.php' ) );
	page_check( $pages::contains( "[$tag]", $tag ) && ! $pages::contains( "[[$tag]]", $tag ) );
}
$post = null; page_check( 'theme.php' === $pages->page_template( 'theme.php' ), 'No queried page' );
echo "Price Check page templates: {$checks} assertions passed with the real WordPress shortcode parser.\n";
