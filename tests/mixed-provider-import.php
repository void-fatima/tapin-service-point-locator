<?php
/** Actual XLSX reader/import pipeline with an in-memory wpdb test double, not a WP/MySQL installation test. */
define( 'ABSPATH', __DIR__ . '/fixtures/' );
define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DB_NAME', 'isolated_import_fixture' );
function __( $text, $domain = '' ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function current_time( $type, $utc = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 0; }
function wp_delete_file( $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
function apply_filters( $name, $value ) { return $value; }
function get_option( $name, $default = false ) { return $default; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $message;
	public function __construct( $code, $message, $data = array() ) { $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
class ImportFixtureDb {
	public $prefix = 'fixture_', $insert_id = 0, $tables = array();
	public function prepare( $sql, ...$args ) {
		return preg_replace_callback( '/%[ds]/', static function( $match ) use ( &$args ) { $value = array_shift( $args ); return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'"; }, $sql );
	}
	public function insert( $table, $row, $formats = array() ) { $id = count( $this->tables[$table] ?? array() ) + 1; $this->tables[$table][$id] = array_merge( $row, array( 'id' => $id ) ); $this->insert_id = $id; return 1; }
	public function update( $table, $row, $where, ...$formats ) { $this->tables[$table][$where['id']] = array_merge( $this->tables[$table][$where['id']], $row ); return 1; }
	public function get_row( $sql, $mode = null ) { if ( preg_match( '/FROM (\w+) WHERE id = (\d+)/', $sql, $match ) ) { return $this->tables[$match[1]][(int) $match[2]] ?? null; } return null; }
	public function get_results( $sql, $mode = null ) { return strpos( $sql, 'fixture_tapin_providers' ) !== false ? array_values( $this->tables['fixture_tapin_providers'] ) : array(); }
	public function get_var( $sql ) { if ( strpos( $sql, 'GET_LOCK' ) !== false || strpos( $sql, 'RELEASE_LOCK' ) !== false ) { return 1; } return strpos( $sql, 'SELECT ENGINE' ) !== false ? 'InnoDB' : null; }
	public function get_col( $sql ) { return array(); }
	public function query( $sql ) { return 1; }
}
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php';
Tapin\ServicePointLocator\Autoloader::register();
use Tapin\ServicePointLocator\Import\ColumnMapper;
use Tapin\ServicePointLocator\Import\ImportJobs;
use Tapin\ServicePointLocator\Import\ImportManager;
use Tapin\ServicePointLocator\Import\ProviderResolver;
use Tapin\ServicePointLocator\Import\TableReader;
use Tapin\ServicePointLocator\Geocoding\CoordinatePolicy;

$wpdb = new ImportFixtureDb();
$providers = array(
	11 => array( 'id' => 11, 'slug' => 'tipax', 'name' => 'تیپاکس', 'is_active' => 1 ),
	22 => array( 'id' => 22, 'slug' => 'post', 'name' => 'شرکت ملی پست', 'is_active' => 1 ),
	33 => array( 'id' => 33, 'slug' => 'rail', 'name' => 'قطار بار', 'is_active' => 1 ),
);
$wpdb->tables['fixture_tapin_providers'] = $providers;
$passed = 0; $files = array();
function verify( $condition, $message ) { global $passed; if ( ! $condition ) { throw new RuntimeException( $message ); } $passed++; echo "PASS {$message}\n"; }
function csv_fixture( $rows ) { global $files; $path = tempnam( sys_get_temp_dir(), 'tapin-schema-' ); $files[] = $path; $handle = fopen( $path, 'wb' ); foreach ( $rows as $row ) { fputcsv( $handle, $row ); } fclose( $handle ); return $path; }
function complete_job( $job, $options = array() ) {
	$next = ImportJobs::start( (int) $job['id'], array_merge( array( 'provider_id' => 22, 'duplicate_action' => 'skip' ), $options ) );
	verify( ! is_wp_error( $next ), 'valid preview starts' );
	while ( 'running' === $next['status'] ) { $next = ImportJobs::step( (int) $job['id'] ); if ( is_wp_error( $next ) ) { throw new RuntimeException( $next->get_error_message() ); } }
	return $next;
}
try {
	$rows = iterator_to_array( TableReader::rows( __DIR__ . '/fixtures/mixed-provider-schema.csv', 'csv' ), false );
	$mapping = ( new ColumnMapper() )->auto_detect_headers( $rows[0] );
	verify( array_values( $mapping ) === array( 'name', 'postal_code', 'address', 'province', 'city', 'latitude', 'longitude', 'phone', 'provider' ), 'all nine workbook headers map to canonical fields' );
	verify( ( new ColumnMapper() )->auto_detect_headers( array( ' عنوان  نمايندگي ', 'عنوان سرویس‌دهنده', 'ارائه‌دهنده', 'name', 'address' ) )[' عنوان  نمايندگي '] === 'name', 'Arabic variants and repeated spaces normalize' );
	verify( ProviderResolver::resolve( 'پست', $providers ) === 22 && ProviderResolver::resolve( 'تيپاكس', $providers ) === 11, 'existing Post alias and normalized Tipax label remain supported' );
	verify( ProviderResolver::resolve( 'قطار بار', array_slice( $providers, 0, 2 ) ) === 0, 'unconfigured rail provider never becomes Post or Tipax' );
	$ambiguous = $providers; $ambiguous[] = array( 'id' => 44, 'slug' => 'second-rail', 'name' => 'قطار بار' );
	verify( ProviderResolver::resolve( 'قطار بار', $ambiguous ) === 0, 'ambiguous provider name fails closed' );
	// Minimal inline-string XLSX built only for this isolated regression.
	$xlsx = tempnam( sys_get_temp_dir(), 'tapin-schema-xlsx-' ); $files[] = $xlsx;
	$zip = new ZipArchive(); $zip->open( $xlsx, ZipArchive::OVERWRITE );
	$zip->addFromString( '[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' );
	$zip->addFromString( '_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
	$zip->addFromString( 'xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet" sheetId="1" r:id="rId1"/></sheets></workbook>' );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
	$xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
	foreach ( $rows as $i => $row ) { $xml .= '<row r="' . ( $i + 1 ) . '">'; foreach ( $row as $j => $value ) { $xml .= '<c r="' . chr( 65 + $j ) . ( $i + 1 ) . '" t="inlineStr"><is><t>' . htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>'; } $xml .= '</row>'; }
	$zip->addFromString( 'xl/worksheets/sheet1.xml', $xml . '</sheetData></worksheet>' ); $zip->close();
	$job = ImportJobs::stage( $xlsx, 'schema.xlsx' );
	verify( ! is_wp_error( $job ) && $job['data']['mapping'] === $mapping, 'actual XLSX staging recognizes the workbook schema' );
	verify( array_column( $job['data']['provider_values'], 'total', 'value' ) === array( 'تیپاکس' => 3, 'پست' => 2, 'قطار بار' => 1 ), 'preview counts include every provider across the full file' );
	$done = complete_job( $job );
	verify( $done['data']['provider_mode'] === 'column' && $done['data']['provider_id'] === 0 && $done['data']['inserted'] === 6 && $done['data']['failed'] === 0, 'global provider choice cannot override a mapped provider column' );
	$saved = array_values( $wpdb->tables['fixture_tapin_service_points'] ); $counts = array_count_values( array_column( $saved, 'provider_id' ) );
	verify( $counts === array( 11 => 3, 22 => 2, 33 => 1 ), 'stored provider counts preserve mixed-provider identities' );
	verify( count( array_filter( $saved, array( CoordinatePolicy::class, 'valid' ) ) ) === 3 && count( array_filter( $saved, static fn( $row ) => ! $row['has_coordinates'] ) ) === 3, 'address-only rows persist without map-capable coordinates' );
	unset( $wpdb->tables['fixture_tapin_providers'][33] );
	$unresolved = ImportJobs::stage( $xlsx, 'unresolved.xlsx' );
	verify( $unresolved['data']['provider_values'][2]['provider_id'] === 0, 'preview identifies the unconfigured provider explicitly' );
	$invalid = ImportJobs::start( (int) $unresolved['id'], array( 'duplicate_action' => 'skip', 'provider_mapping' => array( 'قطار بار' => 999 ) ) );
	verify( is_wp_error( $invalid ) && strpos( $invalid->get_error_message(), 'قطار بار' ) !== false, 'invalid per-label choice is rejected with the exact provider name' );
	$wpdb->tables['fixture_tapin_providers'][99] = array( 'id' => 99, 'slug' => 'rail-configured', 'name' => 'حمل ریلی آزمایشی', 'is_active' => 1 );
	$resolved = complete_job( $unresolved, array( 'provider_mapping' => array( 'قطار بار' => 99 ) ) );
	verify( $resolved['data']['inserted'] === 6 && count( array_filter( $wpdb->tables['fixture_tapin_service_points'], static fn( $p ) => $p['provider_id'] === 99 ) ) === 1, 'explicit rail mapping selects a valid configured provider per row' );
	$legacy = complete_job( ImportJobs::stage( $xlsx, 'unmapped.xlsx' ) );
	$provider_errors = array_filter( $legacy['data']['issues'], static fn( $issue ) => 'failed' === $issue['result'] && strpos( implode( ' ', $issue['messages'] ), 'قطار بار' ) !== false );
	verify( $legacy['data']['failed'] === 1 && count( $provider_errors ) === 1, 'unmapped API rows fail visibly instead of falling back globally' );
	$wpdb->tables['fixture_tapin_providers'] = $providers;
	foreach ( array( 0 => 'نام شعبه', 2 => 'نشانی' ) as $index => $label ) {
		$missing = array_map( static function( $row ) use ( $index ) { array_splice( $row, $index, 1 ); return $row; }, $rows );
		$bad = ImportJobs::stage( csv_fixture( $missing ), 'missing.csv' ); $error = ImportJobs::start( (int) $bad['id'], array( 'duplicate_action' => 'skip' ) );
		verify( is_wp_error( $error ) && strpos( $error->get_error_message(), $label ) !== false && strpos( $error->get_error_message(), 'استان' ) === false, 'missing ' . $label . ' column has a precise validation error' );
	}
	$no_provider = array_map( static function( $row ) { array_pop( $row ); return $row; }, $rows );
	$fallback = ImportJobs::stage( csv_fixture( $no_provider ), 'fallback.csv' );
	verify( is_wp_error( ImportJobs::start( (int) $fallback['id'], array( 'duplicate_action' => 'skip' ) ) ), 'no provider column requires an explicit global fallback' );
	$done = complete_job( $fallback, array( 'provider_id' => 11 ) );
	verify( $done['data']['provider_mode'] === 'selected' && $done['data']['provider_id'] === 11, 'explicit fallback works only without a provider column' );
	$before = count( $wpdb->tables['fixture_tapin_service_points'] );
	$result = ( new ImportManager() )->import_csv( __DIR__ . '/fixtures/mixed-provider-schema.csv', 22 );
	verify( $result->get_inserted_rows() === 6 && array_count_values( array_column( array_slice( array_values( $wpdb->tables['fixture_tapin_service_points'] ), $before ), 'provider_id' ) ) === array( 11 => 3, 22 => 2, 33 => 1 ), 'CLI compatibility import also honors the mapped provider column' );
	echo "$passed mixed-provider checks passed (isolated database double).\n";
} finally {
	foreach ( $wpdb->tables['fixture_tapin_imports'] ?? array() as $job ) { $data = json_decode( $job['data'], true ); wp_delete_file( $data['path'] ); }
	foreach ( $files as $file ) { wp_delete_file( $file ); }
}
