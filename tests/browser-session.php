<?php
/** Local-only CLI helper. Writes a short-lived session to a private file, never stdout. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) || ! getenv( 'TAPIN_SESSION_FILE' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
$file = getenv( 'TAPIN_SESSION_FILE' );
if ( ( $argv[1] ?? '' ) === 'cleanup' ) {
	if ( is_file( $file ) ) {
		$data = json_decode( file_get_contents( $file ), true );
		global $wpdb;
		foreach ( $data['test_points'] ?? array() as $point_id ) {
			$wpdb->delete( $wpdb->prefix . 'tapin_service_points', array( 'id' => (int) $point_id ), array( '%d' ) );
		}
		foreach ( $data['test_jobs'] ?? array() as $job_id ) {
			$wpdb->delete( $wpdb->prefix . 'tapin_imports', array( 'id' => (int) $job_id, 'user_id' => (int) $data['user_id'] ), array( '%d', '%d' ) );
		}
		WP_Session_Tokens::get_instance( $data['user_id'] )->destroy( $data['token'] );
		unlink( $file );
	}
	exit;
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'tapin-service-point-locator/tapin-service-point-locator.php';
if ( ! is_plugin_active( $plugin ) ) { $result = activate_plugin( $plugin ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } }
$users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $users ) { throw new RuntimeException( 'No local administrator.' ); }
$user = $users[0]; $expiration = time() + HOUR_IN_SECONDS;
$token = WP_Session_Tokens::get_instance( $user->ID )->create( $expiration );
$url = home_url(); $domain = wp_parse_url( $url, PHP_URL_HOST );
$review = get_page_by_path( 'tapin-service-points' );
if ( ! $review ) {
	$review_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'نقاط خدماتی تاپین', 'post_name' => 'tapin-service-points', 'post_content' => '[tapin_service_points]', 'post_author' => $user->ID ), true );
	if ( is_wp_error( $review_id ) ) { throw new RuntimeException( $review_id->get_error_message() ); }
} else { $review_id = $review->ID; }
$cookies = array();
foreach ( array( LOGGED_IN_COOKIE => 'logged_in', AUTH_COOKIE => 'auth' ) as $name => $scheme ) {
	$cookies[] = array( 'name' => $name, 'value' => wp_generate_auth_cookie( $user->ID, $expiration, $scheme, $token ), 'domain' => $domain, 'path' => '/', 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax', 'expires' => $expiration );
}
file_put_contents( $file, wp_json_encode( array( 'url' => $url, 'public_url' => get_permalink( $review_id ), 'cookies' => $cookies, 'user_id' => $user->ID, 'token' => $token, 'test_jobs' => array() ) ) );
@chmod( $file, 0600 );
echo "Temporary local browser session prepared.\n";
