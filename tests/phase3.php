<?php
/** Real WordPress/MySQL; disposable plugin tables, no external requests. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\{Activator,Deactivator};
use Tapin\ServicePointLocator\Database\Schema;
use Tapin\ServicePointLocator\Repository\{ServicePointRepository,ProviderRepository};
use Tapin\ServicePointLocator\Export\{Workbook,DownloadResponse,ServicePoints};
use Tapin\ServicePointLocator\Import\TableReader;
use Tapin\ServicePointLocator\Service\OperationalLog;

$passed = 0; $failed = 0;
function p3check( bool $ok, string $label ): void { global $passed, $failed; $ok ? $passed++ : $failed++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n"; }
function p3request( array $filters = array(), bool $nonce = true ): WP_REST_Request {
	$r = new WP_REST_Request( 'POST', '/tapin/v1/exports/points' );
	$r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $filters ) );
	if ( $nonce ) { $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
	return $r;
}
function p3workbook( $response ): array {
	if ( ! $response instanceof DownloadResponse ) { throw new RuntimeException( 'Expected workbook response, status ' . $response->get_status() ); }
	$file = tempnam( sys_get_temp_dir(), 'tapin-p3-test-' );
	try {
		ob_start(); DownloadResponse::serve( false, $response, null, null ); $bytes = ob_get_clean();
		file_put_contents( $file, $bytes );
		$rows = iterator_to_array( TableReader::rows( $file, 'xlsx' ), false );
		$zip = new ZipArchive(); if ( true !== $zip->open( $file ) ) { throw new RuntimeException( 'Invalid ZIP' ); }
		$parts = array(); for ( $i = 0; $i < $zip->numFiles; $i++ ) { $parts[$zip->getNameIndex( $i )] = $zip->getFromIndex( $i ); }
		$zip->close();
		return array( $rows, $parts, $bytes );
	} finally { if ( is_file( $file ) ) { unlink( $file ); } }
}
$original = $wpdb->prefix; $prefix = $original . 'phase3_' . bin2hex( random_bytes( 4 ) ) . '_';
$real_rows = $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A );
$options = array(); foreach ( array( 'tapin_db_version', 'cron' ) as $key ) { $options[$key] = get_option( $key, null ); }
$http_calls = 0; $http_block = static function() use ( &$http_calls ) { $http_calls++; return new WP_Error( 'blocked', 'No external calls in export tests.' ); }; add_filter( 'pre_http_request', $http_block );
$wpdb->prefix = $prefix; $temp_before = glob( sys_get_temp_dir() . '/tapin-export-*' );
try {
	Activator::activate(); $repo = new ServicePointRepository(); $providers = new ProviderRepository();
	$post = (int) $providers->get_by_slug( 'post' )['id']; $tipax = (int) $providers->get_by_slug( 'tipax' )['id'];
	$base = array( 'provider_id' => $post, 'province' => 'تهران', 'city' => 'تهران', 'address' => 'خیابان آزادی پلاک ۱۲', 'postal_code' => '0012345678', 'phone' => '02100000001', 'landline_phone' => '02112345678', 'mobile_phone' => '09121234567', 'status' => 'active', 'latitude' => null, 'longitude' => null, 'metadata' => array( 'private' => 'PRIVATE_SECRET_SENTINEL' ) );
	for ( $i = 0; $i < 505; $i++ ) { $repo->insert( array_merge( $base, array( 'name' => 'آزادی ' . $i, 'code' => 'P3-' . $i ) ) ); }
	$located = $repo->insert( array_merge( $base, array( 'name' => 'مکان عددی', 'provider_id' => $tipax, 'province' => 'فارس', 'city' => 'شیراز', 'latitude' => 29.6, 'longitude' => 52.5, 'status' => 'inactive' ) ) );
	$providers->update( $tipax, array( 'is_active' => 0 ) );
	$repo->insert( array_merge( $base, array( 'name' => 'شهر دیگر', 'city' => 'ری' ) ) );
	foreach ( array( '=1+1', '+SUM(A1)', '-2+3', '@SUM(A1)' ) as $value ) { $repo->insert( array_merge( $base, array( 'name' => $value, 'address' => '<b>نشانی فارسی</b> & پلاک ۲' ) ) ); }
	$repo->insert( array_merge( $base, array( 'name' => 'uploaded', 'address' => 'نشانی بارگذاری', 'metadata' => array( 'tapin_reconciliation' => array( 'result' => 'verified', 'source_url' => 'https://tapin.ir/map/tehran.pdf', 'official' => array( 'name' => 'نام رسمی', 'address' => 'نشانی رسمی', 'postal_code' => '0099999999' ) ) ) ) ) );
	$server = rest_get_server(); $admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
	wp_set_current_user( 0 ); p3check( in_array( $server->dispatch( p3request() )->get_status(), array( 401, 403 ), true ), 'anonymous export denied' );
	wp_set_current_user( $admin->ID );
	$deny = static function( $caps ) { $caps['manage_options'] = false; return $caps; }; add_filter( 'user_has_cap', $deny );
	p3check( $server->dispatch( p3request() )->get_status() === 403, 'non-admin capability denied' ); remove_filter( 'user_has_cap', $deny );
	p3check( $server->dispatch( p3request( array(), false ) )->get_status() === 403, 'missing nonce denied' );
	$r = p3request(); $r->set_header( 'X-WP-Nonce', 'invalid' ); p3check( $server->dispatch( $r )->get_status() === 403, 'invalid nonce denied' );
	foreach ( array( array( 'provider_id' => '../1' ), array( 'provider_id' => '0' ), array( 'provider_id' => '99999999999999999999999' ), array( 'city' => array( 'a' ) ), array( 'status' => '0' ), array( 'issue' => 'bogus' ), array( 'has_coordinates' => '2' ), array( 'search' => str_repeat( 'a', 2001 ) ) ) as $bad ) { p3check( $server->dispatch( p3request( $bad ) )->get_status() === 400, 'malformed filter rejected: ' . array_key_first( $bad ) ); }
	$filters = array( 'provider_id' => (string) $post, 'province' => 'تهران', 'city' => 'تهران', 'search' => 'آزادی', 'status' => 'any' );
	$queries = array(); $query_log = static function( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; }; add_filter( 'query', $query_log );
	$start = microtime( true ); $response = $server->dispatch( p3request( $filters + array( 'page' => '2', 'per_page' => '1', 'path' => '../../wp-config.php' ) ) ); remove_filter( 'query', $query_log );
	list( $rows, $parts, $bytes ) = p3workbook( $response );
	p3check( count( $rows ) === 506, 'complete combined-filter export spans 500-row backend batch, ignores page and path' );
	p3check( count( array_unique( array_column( array_slice( $rows, 1 ), 1 ) ) ) === 505, 'each filtered record exported exactly once' );
	p3check( strncmp( $bytes, 'PK', 2 ) === 0 && count( $parts ) === 6, 'real OOXML ZIP parsed by existing XLSX reader' );
	p3check( $rows[0] === array( 'ارائه‌دهنده', 'نام شعبه', 'استان', 'شهر', 'آدرس', 'کد پستی', 'تلفن ثابت', 'تلفن همراه', 'تلفن عمومی', 'عرض جغرافیایی', 'طول جغرافیایی', 'وضعیت موقعیت' ), 'exact Persian headers' );
	p3check( $rows[1][5] === '0012345678' && $rows[1][6] === '02112345678' && $rows[1][7] === '09121234567' && $rows[1][8] === '02100000001', 'postal and all phone leading zeros preserved' );
	p3check( $rows[1][9] === '' && $rows[1][10] === '' && $rows[1][11] === 'بدون مختصات', 'address-only export has blank coordinates, never zero' );
	$sheet = simplexml_load_string( $parts['xl/worksheets/sheet1.xml'] );
	p3check( count( $sheet->xpath( '//*[local-name()="c"][@r="F2"][@t="inlineStr"]' ) ) === 1 && count( $sheet->xpath( '//*[local-name()="c"][@r="G2"][@t="inlineStr"]' ) ) === 1, 'postal and phone cells are explicit text' );
	p3check( strpos( $parts['xl/workbook.xml'], 'نقاط خدماتی' ) !== false && strpos( $parts['xl/worksheets/sheet1.xml'], 'rightToLeft="1"' ) !== false && strpos( $parts['xl/worksheets/sheet1.xml'], 'state="frozen"' ) !== false, 'Persian worksheet, RTL and frozen header' );
	p3check( strpos( implode( '', $parts ), 'PRIVATE_SECRET_SENTINEL' ) === false && strpos( implode( '', $parts ), 'tapin_reconciliation' ) === false, 'internal metadata excluded from workbook' );
	$headers = $response->get_headers(); p3check( $headers['Content-Type'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' && preg_match( '/filename="tapin-service-points-\d{4}-\d{2}-\d{2}\.xlsx"/', $headers['Content-Disposition'] ) && strpos( $headers['Cache-Control'], 'no-store' ) !== false && $headers['X-Content-Type-Options'] === 'nosniff', 'download MIME filename cache and sniffing headers' );
	p3check( $response->get_data() === null, 'REST response data never exposes server path' );
	$provider_queries = array_filter( $queries, static fn( $sql ) => preg_match( '/^\s*SELECT\s+\*\s+FROM\s+\S*tapin_providers\b/i', $sql ) );
	p3check( count( $provider_queries ) === 1, 'provider registry fetched once, no N+1 queries' );
	p3check( count( array_filter( $queries, static fn( $sql ) => strpos( $sql, 'LIMIT 500 OFFSET' ) !== false ) ) === 2, 'bounded 500-row repository queries' );
	$history = OperationalLog::recent_exports(); $event = $history[0];
	p3check( $event['event'] === 'export_completed' && $event['context']['rows'] === 505 && $event['context']['format'] === 'xlsx' && (int) $event['user_id'] === (int) $admin->ID, 'export event stores correct row count format and admin' );
	p3check( abs( time() - strtotime( $event['created_at'] . ' UTC' ) ) < 60 && $event['context']['filters']['province'] === 'تهران' && $event['context']['filters']['search_applied'] && ! isset( $event['context']['filters']['search'] ), 'event timestamp and filters preserved without search text' );
	foreach ( array( array( array(), 512 ), array( array( 'province' => 'فارس' ), 1 ), array( array( 'city' => 'ری' ), 1 ), array( array( 'provider_id' => (string) $tipax ), 1 ), array( array( 'status' => 'active' ), 511 ), array( array( 'has_coordinates' => '1' ), 1 ), array( array( 'has_coordinates' => '0' ), 511 ), array( array( 'search' => "' OR 1=1 --" ), 0 ) ) as $case ) {
		list( $out ) = p3workbook( $server->dispatch( p3request( $case[0] ) ) ); p3check( count( $out ) - 1 === $case[1], 'filtered row count ' . wp_json_encode( $case[0], JSON_UNESCAPED_UNICODE ) );
	}
	list( $out, $xml ) = p3workbook( $server->dispatch( p3request( array( 'has_coordinates' => '1' ) ) ) );
	$sheet = simplexml_load_string( $xml['xl/worksheets/sheet1.xml'] ); $cell = $sheet->xpath( '//*[local-name()="c"][@r="J2"]' )[0];
	p3check( (string) $cell['t'] !== 'inlineStr' && (float) $out[1][9] === 29.6 && (float) $out[1][10] === 52.5, 'coordinates numeric and unchanged' );
	p3check( $out[1][0] === $providers->get_by_id( $tipax )['name'], 'inactive provider name preserved in admin export' );
	list( $out, $xml ) = p3workbook( $server->dispatch( p3request() ) ); $by_name = array_column( array_slice( $out, 1 ), null, 1 );
	foreach ( array( '=1+1', '+SUM(A1)', '-2+3', '@SUM(A1)' ) as $value ) { p3check( isset( $by_name[$value] ) && $by_name[$value][4] === 'نشانی فارسی & پلاک ۲', 'formula-like text retained safely: ' . $value ); }
	$sheet = simplexml_load_string( $xml['xl/worksheets/sheet1.xml'] ); p3check( count( $sheet->xpath( '//*[local-name()="f"]' ) ) === 0, 'no executable formula cells anywhere' );
	p3check( $by_name['نام رسمی'][4] === 'نشانی رسمی' && $by_name['نام رسمی'][5] === '0099999999', 'trusted official presentation used without mutating uploaded data' );
	$too_long = $repo->insert( array_merge( $base, array( 'name' => 'Oversize', 'address' => str_repeat( 'a', 32768 ) ) ) );
	$error = $server->dispatch( p3request( array( 'search' => 'Oversize' ) ) );
	p3check( $error->get_status() === 503 && strpos( wp_json_encode( $error->get_data() ), ABSPATH ) === false && strpos( wp_json_encode( $error->get_data() ), 'RuntimeException' ) === false, 'oversized cell fails safely without path or exception leakage' );
	$history = OperationalLog::recent_exports(); p3check( $history[0]['event'] === 'export_failed' && ! isset( $history[0]['context']['rows'] ), 'failure logged without fabricated row count' ); $repo->delete( $too_long );
	$before = $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A );
	Deactivator::deactivate(); Activator::activate(); Schema::migrate();
	p3check( $before === $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A ), 'lifecycle and repeated migration preserve all records' );
	list( $out ) = p3workbook( $server->dispatch( p3request( array( 'city' => 'ری' ) ) ) ); p3check( count( $out ) === 2, 'Phase 3 export works after reactivation' );
	p3check( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $prefix . 'tapin_geocoding_jobs' ) === 0 && $http_calls === 0, 'export neither queues geocoding nor sends HTTP requests' );
	p3check( glob( sys_get_temp_dir() . '/tapin-export-*' ) === $temp_before, 'temporary workbook files cleaned on success and failure' );
	for ( $i = 0; $i < 55; $i++ ) { OperationalLog::record( 'export_completed', array( 'rows' => $i ) ); }
	p3check( count( OperationalLog::recent_exports() ) === 50, 'export history bounded to 50 newest events' );
	$wpdb->query( "UPDATE " . OperationalLog::table() . " SET created_at='2000-01-01 00:00:00'" ); OperationalLog::cleanup(); p3check( OperationalLog::recent_exports() === array(), 'existing retention removes old export events' );
	wp_set_current_user( 0 ); p3check( in_array( $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/exports' ) )->get_status(), array( 401, 403 ), true ), 'export history not public' );
	echo 'INFO 505-row workbook generation and validation stage: ' . round( microtime( true ) - $start, 3 ) . "s; peak PHP memory " . round( memory_get_peak_usage( true ) / 1048576, 1 ) . " MiB\n";
} finally {
	remove_filter( 'pre_http_request', $http_block ); wp_set_current_user( 0 );
	if ( preg_match( '/^' . preg_quote( $original, '/' ) . 'phase3_[a-f0-9]{8}_$/D', $prefix ) ) { foreach ( array( 'tapin_geocoding_jobs', 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_import_points', 'tapin_logs' ) as $suffix ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $prefix . $suffix ); } }
	$wpdb->prefix = $original; foreach ( $options as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value ); }
	p3check( $real_rows === $wpdb->get_results( 'SELECT * FROM ' . Schema::get_service_points_table() . ' ORDER BY id', ARRAY_A ), 'original site records unchanged' );
}
echo "RESULT Phase 3: {$passed} passed, {$failed} failed\n"; exit( $failed ? 1 : 0 );
