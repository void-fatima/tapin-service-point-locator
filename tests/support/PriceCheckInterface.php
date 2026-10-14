<?php
/** Rendering/enqueue doubles only; never boots WordPress or accesses its database. */
class WP_Post { public $ID; public $post_content; public $post_password = ''; public function __construct( $id, $content ) { $this->ID = $id; $this->post_content = $content; } }
$interface_styles = array(); $interface_printed = array(); $interface_pages = array( new WP_Post( 11, '[tapin_price_check]' ), new WP_Post( 12, '[tapin_bulk_quote]' ) );
function get_posts( $args ) { return $GLOBALS['interface_pages']; }
function has_shortcode( $text, $tag ) { return false !== strpos( $text, '[' . $tag ); }
function get_permalink( $post = null ) { return ( $GLOBALS['interface_site'] ?? 'https://wp.example/site' ) . ( 12 === ( $post->ID ?? 11 ) ? '/bulk/' : '/single/' ); }
function rest_url( $path ) { return ( $GLOBALS['interface_site'] ?? 'https://wp.example/site' ) . '/wp-json/' . $path; }
function admin_url( $path ) { return ( $GLOBALS['interface_site'] ?? 'https://wp.example/site' ) . '/wp-admin/' . $path; }
function home_url( $path ) { return ( $GLOBALS['interface_site'] ?? 'https://wp.example/site' ) . $path; }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $value ) { return $value; } }
function wp_create_nonce( $action ) { if ( 'wp_rest' !== $action ) { throw new LogicException( 'Wrong nonce action.' ); } return 'test-rest-nonce'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function esc_url( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function wp_enqueue_style( $handle, $url, $deps = array(), $version = null ) { $GLOBALS['interface_styles'][$handle] = $url; }
function wp_style_is( $handle, $state ) { return isset( $GLOBALS['interface_printed'][$handle] ); }
function wp_print_styles( $handles ) { foreach ( $handles as $handle ) { $GLOBALS['interface_printed'][$handle] = true; echo '<link rel="stylesheet" href="' . esc_url( $GLOBALS['interface_styles'][$handle] ) . '">'; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $hook, $callback, $priority = 10, $count = 1 ) { $GLOBALS['interface_filters'][$hook][] = $callback; } }
function apply_filters( $hook, $value ) { return $value; }
function is_admin() { return $GLOBALS['interface_admin'] ?? false; }
function is_page() { return true; }
function get_queried_object() { return $GLOBALS['post'] ?? null; }
function post_password_required( $post ) { return '' !== $post->post_password; }
function get_shortcode_regex( $tags ) { return '(\[?)\[(' . implode( '|', $tags ) . ')([^\]]*)\](?:(\/)|([^\[\]]*))?(\]?)'; }
function shortcode_parse_atts( $text ) { return false !== strpos( $text, 'embedded' ) ? array( 'layout' => 'embedded' ) : array(); }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $text, ...$args ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); } }
