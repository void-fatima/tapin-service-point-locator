<?php
/** Run only against a development WordPress install: TAPIN_WP_ROOT=/path/to/wp php tests/integration.php */
$root = getenv( 'TAPIN_WP_ROOT' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) { fwrite( STDERR, "Set TAPIN_WP_ROOT to a development WordPress install.\n" ); exit( 1 ); }
require $root . '/wp-load.php';
if ( ! defined( 'TAPIN_PLUGIN_DIR' ) ) { require dirname( __DIR__ ) . '/tapin-service-point-locator.php'; }
\Tapin\ServicePointLocator\Database\Schema::migrate();

use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Service\PointService;
use Tapin\ServicePointLocator\Import\ImportJobs;

$passed = 0; $failed = 0;
function check( $condition, $name ) { global $passed, $failed; if ( $condition ) { $passed++; echo "PASS {$name}\n"; } else { $failed++; echo "FAIL {$name}\n"; } }
$providers = new ProviderRepository(); $points = new ServicePointRepository();
$provider = $providers->insert( array( 'slug' => 'test-' . wp_generate_password( 10, false ), 'name' => 'آزمایش خودکار', 'is_active' => 1 ) );
$ids = array(); $jobs = array(); $files = array(); $other_provider = 0;
try {
	$before = $points->summary();
	$base = array( 'provider_id' => $provider, 'name' => 'شعبه آزمایشی', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'نشانی آزمایشی', 'phone' => '02111111111', 'code' => 'A-1', 'latitude' => 35.7, 'longitude' => 51.4 );
	$saved = PointService::save( $base ); $id = $saved['item']['id']; $ids[] = $id;
	check( $id > 0 && $saved['item']['has_coordinates'], 'create located service point' );
	$base['latitude'] = null; $base['longitude'] = null;
	$cleared = PointService::save( $base, $id );
	check( 35.7 === $cleared['item']['latitude'] && $cleared['item']['has_coordinates'], 'blank edit preserves valid coordinates under Phase 2 policy' );
	// Explicitly prepare the address-only fixture through the repository; a blank
	// import/admin edit is intentionally no longer a coordinate deletion action.
	$points->update( $id, array( 'latitude' => null, 'longitude' => null ) );
	check( $points->summary()['missing'] === $before['missing'] + 1, 'real missing-coordinate metric' );
	check( 0 === $points->query( array( 'provider_id' => $provider, 'public' => true ) )['total'], 'address-only excluded from public query' );
	$bad = $base; $bad['latitude'] = 'garbage';
	check( is_wp_error( PointService::save( $bad ) ), 'malformed coordinates rejected' );
	$bad = $base; $bad['provider_id'] = 999999999;
	check( is_wp_error( PointService::save( $bad ) ), 'unknown provider rejected' );
	$file = tempnam( sys_get_temp_dir(), 'tapin-test-' ); $files[] = $file;
	$csv = fopen( $file, 'wb' );
	fputcsv( $csv, array( 'code', 'name', 'province', 'city', 'address', 'phone', 'latitude', 'longitude' ) );
	fputcsv( $csv, array( 'B-2', 'جدید', 'تهران', 'تهران', 'نشانی دوم', '02122222222', '', '' ) );
	fputcsv( $csv, array( 'B-2', 'جدید تکراری', 'تهران', 'تهران', 'نشانی سوم', '02122222222', '', '' ) );
	fputcsv( $csv, array( 'C-3', 'نامعتبر', 'تهران', 'تهران', 'نشانی', '', 'bad', 'bad' ) );
	fputcsv( $csv, array( 'D-4', 'ستون ناقص' ) ); fclose( $csv );
	$job = ImportJobs::stage( $file, 'test.csv' ); check( ! is_wp_error( $job ), 'stage CSV and preview' );
	$jobs[] = (int) $job['id'];
	$started = ImportJobs::start( (int) $job['id'], array( 'provider_id' => $provider, 'mapping' => $job['data']['mapping'], 'duplicate_action' => 'skip' ) );
	check( 'running' === $started['status'], 'mapping starts import' );
	$done = ImportJobs::step( (int) $job['id'] );
	check( ! is_wp_error( $done ) && 'completed' === $done['status'], 'import checkpoint commits' );
	check( $done['data']['inserted'] === 1 && $done['data']['skipped'] === 1 && $done['data']['failed'] === 2, 'exact row outcome accounting' );
	check( ImportJobs::step( (int) $job['id'] )['data']['processed'] === 4, 'repeated completed request is idempotent' );
	check( ! isset( $done['data']['path'] ), 'private path never exposed' );
	$match = $points->get_by_code( $provider, 'B-2' );
	$update = \Tapin\ServicePointLocator\Import\RowProcessor::process( array( 'code' => 'B-2', 'name' => 'ویرایش واردشده', 'province' => 'تهران', 'city' => 'تهران', 'address' => 'نشانی تازه', 'phone' => '' ), $provider, 'update' );
	check( $update['result'] === 'updated' && $points->get_by_id( $match['id'] )['phone'] === null, 'exact-code update clears optional phone' );
	$shared_phone = $base; $shared_phone['code'] = 'OTHER-CODE'; $shared_phone['name'] = 'شعبه متفاوت';
	$shared = \Tapin\ServicePointLocator\Import\RowProcessor::process( $shared_phone, $provider, 'update' );
	check( $shared['result'] === 'skipped' && $points->get_by_id( $id )['name'] === $base['name'], 'shared phone cannot overwrite different branch' );
	// At least two requests are needed; checkpoints survive page reloads.
	$large = tempnam( sys_get_temp_dir(), 'tapin-large-' ); $files[] = $large;
	$h = fopen( $large, 'wb' ); fputcsv( $h, array( 'code', 'name', 'province', 'city', 'address' ) );
	for ( $i = 0; $i < 125; $i++ ) { fputcsv( $h, array( 'L-' . $i, 'Bulk ' . $i, 'Test', 'Test', 'Address ' . $i ) ); } fclose( $h );
	$j = ImportJobs::stage( $large, 'large.csv' ); $jobs[] = (int) $j['id'];
	ImportJobs::start( (int) $j['id'], array( 'provider_id' => $provider, 'mapping' => $j['data']['mapping'], 'duplicate_action' => 'skip' ) );
	$partial = ImportJobs::step( (int) $j['id'] );
	check( $partial['data']['processed'] > 0 && $partial['data']['processed'] <= 50 && $partial['status'] === 'running', 'bounded batch remains resumable' );
	while ( $partial['status'] === 'running' ) { $partial = ImportJobs::step( (int) $j['id'] ); }
	check( $partial['data']['inserted'] === 125, 'multi-request import inserts every row once' );
	$cancelled = ImportJobs::stage( $file, 'cancel.csv' ); $jobs[] = (int) $cancelled['id'];
	check( ImportJobs::cancel( (int) $cancelled['id'] )['status'] === 'cancelled', 'cancel preview without writing rows' );
	// XLSX: first worksheet, shared strings, Persian values and blank trailing cells.
	$xlsx = tempnam( sys_get_temp_dir(), 'tapin-xlsx-' ); $files[] = $xlsx;
	$z = new ZipArchive(); $z->open( $xlsx, ZipArchive::OVERWRITE );
	$z->addFromString( 'xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="First" sheetId="1" r:id="rId1"/></sheets></workbook>' );
	$z->addFromString( 'xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>' );
	$z->addFromString( 'xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>شعبه Excel</t></si></sst>' );
	$sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';
	foreach ( array( 'A' => 'name', 'B' => 'province', 'C' => 'city', 'D' => 'address', 'E' => 'phone' ) as $col => $header ) { $sheet .= '<c r="' . $col . '1" t="inlineStr"><is><t>' . $header . '</t></is></c>'; }
	$sheet .= '</row><row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2" t="inlineStr"><is><t>فارس</t></is></c><c r="C2" t="inlineStr"><is><t>شیراز</t></is></c><c r="D2" t="inlineStr"><is><t>نشانی Excel</t></is></c></row></sheetData></worksheet>';
	$z->addFromString( 'xl/worksheets/sheet1.xml', $sheet ); $z->close();
	$xjob = ImportJobs::stage( $xlsx, 'test.xlsx' );
	check( ! is_wp_error( $xjob ) && $xjob['data']['preview'][0][0] === 'شعبه Excel', 'XLSX shared strings decoded' );
	if ( ! is_wp_error( $xjob ) ) {
		$jobs[] = (int) $xjob['id'];
		ImportJobs::start( (int) $xjob['id'], array( 'provider_id' => $provider, 'mapping' => $xjob['data']['mapping'], 'duplicate_action' => 'skip' ) );
		check( ImportJobs::step( (int) $xjob['id'] )['data']['inserted'] === 1, 'XLSX blank trailing fields and address-only import' );
	}
	$z->open( $xlsx ); $z->addFromString( 'xl/worksheets/sheet1.xml', str_replace( '<v>0</v>', '<f>1+1</f><v>2</v>', $sheet ) ); $z->close();
	check( is_wp_error( ImportJobs::stage( $xlsx, 'formula.xlsx' ) ), 'XLSX formulas rejected before import' );
	$z->open( $xlsx ); $z->addFromString( 'xl/worksheets/sheet1.xml', substr( $sheet, 0, -20 ) ); $z->close();
	check( is_wp_error( ImportJobs::stage( $xlsx, 'broken.xlsx' ) ), 'truncated worksheet rejected' );
	$z->open( $xlsx ); $z->addFromString( 'xl/workbook.xml', '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>' ); $z->close();
	check( is_wp_error( ImportJobs::stage( $xlsx, 'external-entity.xlsx' ) ), 'external entities rejected' );
	check( is_wp_error( ImportJobs::stage( $file, 'legacy.xls' ) ), 'unsupported legacy XLS has explicit error' );
	// A checkpoint failure must roll back inserted rows, allowing a safe retry.
	$retry_file = tempnam( sys_get_temp_dir(), 'tapin-retry-' ); $files[] = $retry_file;
	file_put_contents( $retry_file, "code,name,province,city,address\nRETRY-1,Retry,Test,Test,Retry address\n" );
	$retry = ImportJobs::stage( $retry_file, 'retry.csv' ); $jobs[] = (int) $retry['id'];
	ImportJobs::start( (int) $retry['id'], array( 'provider_id' => $provider, 'mapping' => $retry['data']['mapping'], 'duplicate_action' => 'skip' ) );
	global $wpdb;
	$fail_checkpoint = static function( $sql ) use ( $wpdb ) {
		return 0 === strpos( $sql, 'UPDATE `' . $wpdb->prefix . 'tapin_imports`' ) ? 'UPDATE tapin_intentionally_missing_table SET id = 1' : $sql;
	};
	$previous_suppression = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail_checkpoint );
	try { $failed_step = ImportJobs::step( (int) $retry['id'] ); }
	finally { remove_filter( 'query', $fail_checkpoint ); $wpdb->suppress_errors( $previous_suppression ); }
	check( is_wp_error( $failed_step ) && ! $points->get_by_code( $provider, 'RETRY-1' ) && ImportJobs::get_public( (int) $retry['id'] )['data']['processed'] === 0, 'checkpoint failure rolls back data and cursor together' );
	check( ImportJobs::step( (int) $retry['id'] )['data']['inserted'] === 1, 'retry after rollback inserts exactly once' );
	$points->delete( $points->get_by_code( $provider, 'RETRY-1' )['id'] );
	// Ambiguous exact codes remain candidates, never a random update target.
	$ambiguous = $base; $ambiguous['code'] = 'B-2'; $ambiguous['name'] = 'Ambiguous';
	$ambiguous_id = PointService::save( $ambiguous )['item']['id'];
	check( $points->summary()['duplicate'] === $before['duplicate'] + 2, 'dashboard counts both duplicate-code candidates' );
	check( \Tapin\ServicePointLocator\Import\RowProcessor::process( $ambiguous, $provider, 'update' )['result'] === 'skipped', 'ambiguous exact codes are not overwritten' );
	$points->delete( $ambiguous_id );
	$summary = $points->summary();
	check( $summary['total'] === $before['total'] + 128 && $summary['located'] === $before['located'], 'dashboard aggregation after all import paths' );
	// REST permissions and public field minimization.
	wp_set_current_user( 0 );
	$server = rest_get_server();
	$res = $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/points' ) );
	check( $res->get_status() >= 400, 'anonymous admin access denied' );
	$subscriber = wp_insert_user( array( 'user_login' => 'tapin-test-' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	wp_set_current_user( $subscriber );
	check( $server->dispatch( new WP_REST_Request( 'GET', '/tapin/v1/imports' ) )->get_status() === 403, 'subscriber denied import history' );
	require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $subscriber ); wp_set_current_user( 0 );
	$base['latitude'] = 35.7; $base['longitude'] = 51.4; $base['metadata'] = array( 'private' => 'hidden' );
	PointService::save( $base, $id );
	$req = new WP_REST_Request( 'GET', '/tapin/v1/public/points' ); $req->set_param( 'provider_id', $provider ); $req->set_param( 'status', 'any' );
	$res = $server->dispatch( $req ); $data = $res->get_data();
	check( count( $data['items'] ) === 1 && ! isset( $data['items'][0]['metadata'] ), 'public map returns located records without private metadata' );
	$directory = new WP_REST_Request( 'GET', '/tapin/v1/public/directory' );
	$directory->set_param( 'provider_id', $provider );
	$directory->set_param( 'status', 'any' );
	$entries = $server->dispatch( $directory )->get_data();
	check( $entries['total'] === 128 && array_key_exists( 'postal_code', $entries['items'][0] ) && ! isset( $entries['items'][0]['metadata'] ), 'public directory includes address-only branches and safe contact fields' );
	check( $points->summary()['public_mapped'] === $before['public_mapped'] + 1 && $points->summary()['public_directory'] === $before['public_directory'] + 128, 'publication metrics match directory and marker rules' );
	PointService::save( array_merge( $base, array( 'status' => 'inactive' ) ), $id );
	check( $server->dispatch( $req )->get_data()['total'] === 0 && $server->dispatch( $directory )->get_data()['total'] === 127, 'inactive point excluded from both public experiences' );
	$providers->update( $provider, array( 'is_active' => 0 ) );
	check( $server->dispatch( $req )->get_data()['total'] === 0, 'inactive provider excluded publicly' );
	check( $server->dispatch( $directory )->get_data()['total'] === 0, 'inactive provider also excluded from address directory' );
	$other_provider = $providers->insert( array( 'slug' => 'mixed-' . wp_generate_password( 8, false ), 'name' => 'ارائه‌دهنده آزمایشی دوم', 'is_active' => 1 ) );
	$mixed_file = tempnam( sys_get_temp_dir(), 'tapin-mixed-' ); $files[] = $mixed_file;
	$mixed_csv = fopen( $mixed_file, 'wb' );
	fputcsv( $mixed_csv, array( 'provider', 'code', 'name', 'province', 'city', 'address', 'source' ) );
	fputcsv( $mixed_csv, array( $providers->get_by_id( $provider )['slug'], 'MIX-A', 'شعبه اول', 'تهران', 'تهران', 'نشانی اول', '' ) );
	fputcsv( $mixed_csv, array( 'ارائه‌دهنده آزمایشی دوم', 'MIX-B', 'شعبه دوم', 'تهران', 'تهران', 'نشانی دوم', '' ) );
	fputcsv( $mixed_csv, array( 'ناشناخته', 'MIX-C', 'شعبه سوم', 'تهران', 'تهران', 'نشانی سوم', '' ) );
	fputcsv( $mixed_csv, array( '', 'MIX-D', 'شعبه چهارم', 'تهران', 'تهران', 'نشانی چهارم', '' ) );
	fputcsv( $mixed_csv, array( $providers->get_by_id( $provider )['slug'], 'MIX-E', 'شعبه پنجم', 'تهران', 'تهران', 'نشانی پنجم', 'https://tipaxco.com/branches/test' ) );
	fclose( $mixed_csv );
	$mixed_job = ImportJobs::stage( $mixed_file, 'mixed.csv' ); $jobs[] = (int) $mixed_job['id'];
	check( 'provider' === $mixed_job['data']['mapping']['provider'], 'provider column detected from spreadsheet header' );
	$mixed_start = ImportJobs::start( (int) $mixed_job['id'], array( 'mapping' => $mixed_job['data']['mapping'], 'duplicate_action' => 'skip' ) );
	check( 'running' === $mixed_start['status'] && 'column' === $mixed_start['data']['provider_mode'], 'mixed import uses provider column without one selected provider' );
	$mixed_done = ImportJobs::step( (int) $mixed_job['id'] );
	check( $mixed_done['data']['inserted'] === 2 && $mixed_done['data']['failed'] === 3, 'mixed provider rows and invalid labels counted independently' );
	check( $points->get_by_code( $provider, 'MIX-A' ) && $points->get_by_code( $other_provider, 'MIX-B' ) && ! $points->get_by_code( $provider, 'MIX-E' ), 'rows saved under their own provider and Tipax source cannot be assigned elsewhere' );
} finally {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \Tapin\ServicePointLocator\Geocoding\Jobs::table() . ' WHERE point_id IN (SELECT id FROM ' . $points->get_table_name() . ' WHERE provider_id = %d)', $provider ) );
	$wpdb->delete( $points->get_table_name(), array( 'provider_id' => $provider ), array( '%d' ) );
	$providers->delete( $provider );
	foreach ( $jobs as $job_id ) { ImportJobs::cancel( $job_id ); $wpdb->delete( ImportJobs::table(), array( 'id' => $job_id ), array( '%d' ) ); }
	foreach ( $files as $file ) { wp_delete_file( $file ); }
	if ( $other_provider ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . \Tapin\ServicePointLocator\Geocoding\Jobs::table() . ' WHERE point_id IN (SELECT id FROM ' . $points->get_table_name() . ' WHERE provider_id = %d)', $other_provider ) );
		$wpdb->delete( $points->get_table_name(), array( 'provider_id' => $other_provider ), array( '%d' ) );
		$providers->delete( $other_provider );
	}
}
echo "{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
