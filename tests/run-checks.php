<?php
/**
 * Automated smoke tests and test assertions for Tapin Service Point Locator.
 * Can be executed directly via PHP CLI or through WP-CLI eval.
 */

// If running outside full WordPress, bootstrap minimal environment.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	define( 'TAPIN_VERSION', '0.1.0' );
	define( 'TAPIN_DB_VERSION', 1 );

	// Mock minimal WordPress functions for standalone test execution.
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			return trim( strip_tags( (string) $str ) );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( $str ) {
			return trim( strip_tags( (string) $str ) );
		}
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}
	if ( ! function_exists( 'wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data, JSON_UNESCAPED_UNICODE );
		}
	}
}

require_once TAPIN_PLUGIN_DIR . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();

use Tapin\ServicePointLocator\Import\ColumnMapper;
use Tapin\ServicePointLocator\Import\DuplicateDetector;
use Tapin\ServicePointLocator\Normalization\DataNormalizer;
use Tapin\ServicePointLocator\Validation\ServicePointValidator;

global $passed, $failed;
$passed = 0;
$failed = 0;

function assert_test( string $name, bool $condition, string $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		echo " [PASS] {$name}\n";
		$passed++;
	} else {
		echo " [FAIL] {$name}: {$detail}\n";
		$failed++;
	}
}

echo "=== Running Tapin Service Point Locator Smoke Tests ===\n\n";

// --- Normalization Tests ---
echo "--- Testing Normalization ---\n";
$digits = DataNormalizer::to_latin_digits( '۰۹۱۲۳۴۵۶۷۸۹' );
assert_test( 'Persian digits to Latin', '09123456789' === $digits, "Got: {$digits}" );

$arabic_clean = DataNormalizer::normalize_persian_text( "باجه\xD9\x8A \xD9\x83 مرکزی" );
assert_test( 'Arabic Yeh/Kaf to Persian', 'باجهی ک مرکزی' === $arabic_clean, "Got: {$arabic_clean}" );

$phone1 = DataNormalizer::normalize_phone( '+989121112233' );
assert_test( 'Phone +98 prefix to 0', '09121112233' === $phone1, "Got: {$phone1}" );

$phone2 = DataNormalizer::normalize_phone( '۰۲۱-۸۸۹۹۰۰۱۱' );
assert_test( 'Persian phone with dashes', '02188990011' === $phone2, "Got: {$phone2}" );

$coord = DataNormalizer::normalize_coordinate( '۳۵٫۷۱۱۸' );
assert_test( 'Persian decimal coordinate', 35.7118 === $coord, 'Got: ' . var_export( $coord, true ) );

$status = DataNormalizer::normalize_status( 'غیرفعال' );
assert_test( 'Persian inactive status', 'inactive' === $status, "Got: {$status}" );

// --- Validation Tests ---
echo "\n--- Testing Validation ---\n";
$valid_record = array(
	'provider_id' => 1,
	'name'        => 'شعبه آزادی',
	'province'    => 'تهران',
	'city'        => 'تهران',
	'address'     => 'میدان آزادی',
	'latitude'    => 35.7000,
	'longitude'   => 51.3300,
	'status'      => 'active',
);
$val1 = ServicePointValidator::validate( $valid_record );
assert_test( 'Valid record passes with no errors', $val1->is_valid(), var_export( $val1->get_errors(), true ) );

// Address-only (no coordinates): MUST be valid with a warning.
$address_only = $valid_record;
$address_only['latitude']  = null;
$address_only['longitude'] = null;
$val2 = ServicePointValidator::validate( $address_only );
assert_test( 'Address-only record is valid', $val2->is_valid() );
assert_test( 'Address-only record yields warning', isset( $val2->get_warnings()['coordinates'] ) );

// Missing name: MUST fail with fatal error.
$missing_name = $valid_record;
$missing_name['name'] = '';
$val3 = ServicePointValidator::validate( $missing_name );
assert_test( 'Missing name fails validation', ! $val3->is_valid() );
assert_test( 'Missing name error key exists', isset( $val3->get_errors()['name'] ) );

// Invalid coordinates: latitude > 90 MUST fail.
$invalid_coords = $valid_record;
$invalid_coords['latitude'] = 99.9;
$val4 = ServicePointValidator::validate( $invalid_coords );
assert_test( 'Latitude > 90 fails validation', ! $val4->is_valid() );
assert_test( 'Latitude error exists', isset( $val4->get_errors()['latitude'] ) );

// Inconsistent coordinates (only one provided).
$inconsistent = $valid_record;
$inconsistent['longitude'] = null;
$val5 = ServicePointValidator::validate( $inconsistent );
assert_test( 'Inconsistent lat/lng fails validation', ! $val5->is_valid() );

// --- Duplicate Detection Tests ---
echo "\n--- Testing Duplicate Detection ---\n";
$detector = new DuplicateDetector( 1 );
$detector->register(
	array(
		'code'      => 'P-100',
		'name'      => 'باجه پستی ونک',
		'city'      => 'تهران',
		'address'   => 'میدان ونک',
		'phone'     => '02188776655',
		'latitude'  => 35.7580,
		'longitude' => 51.3950,
	),
	42
);

// Definite duplicate by code
$dup1 = $detector->detect(
	array(
		'code'      => 'p-100',
		'name'      => 'دیگر باجه',
		'city'      => 'اصفهان',
		'phone'     => '03112345678',
		'latitude'  => null,
		'longitude' => null,
	)
);
assert_test( 'Exact branch code detected as definite duplicate', 'definite' === ( $dup1['type'] ?? '' ) );
assert_test( 'Definite duplicate references existing ID', 42 === ( $dup1['existing_id'] ?? 0 ) );

// Definite duplicate by phone + city
$dup2 = $detector->detect(
	array(
		'code'      => 'P-999',
		'name'      => 'نام متفاوت',
		'city'      => 'تهران',
		'phone'     => '02188776655',
		'latitude'  => null,
		'longitude' => null,
	)
);
assert_test( 'Same phone and city detected as definite duplicate', 'definite' === ( $dup2['type'] ?? '' ) );

// Probable duplicate by similar branch name in same city
$dup3 = $detector->detect(
	array(
		'code'      => 'P-888',
		'name'      => 'شعبه پستی ونک',
		'city'      => 'تهران',
		'phone'     => '02111111111',
		'latitude'  => null,
		'longitude' => null,
	)
);
assert_test( 'Similar branch name in same city detected as probable duplicate', 'probable' === ( $dup3['type'] ?? '' ) );

// Legitimate record: different city, code, phone
$legit = $detector->detect(
	array(
		'code'      => 'P-200',
		'name'      => 'شعبه پستی تجریش',
		'city'      => 'تهران',
		'phone'     => '02122223344',
		'latitude'  => 35.8050,
		'longitude' => 51.4310,
	)
);
assert_test( 'Legitimate distinct record is not marked duplicate', null === $legit );

// --- Column Mapper Tests ---
echo "\n--- Testing Column Mapper ---\n";
$mapper = new ColumnMapper();
$raw_headers = array( 'کد شعبه', 'نام باجه', 'استان', 'شهرستان', 'آدرس کامل', 'تلفن تماس', 'عرض جغرافیایی', 'طول جغرافیایی', 'وضعیت فعالیت' );
$detected = $mapper->auto_detect_headers( $raw_headers );

assert_test( 'Mapper detects code header', 'code' === ( $detected['کد شعبه'] ?? '' ) );
assert_test( 'Mapper detects name header', 'name' === ( $detected['نام باجه'] ?? '' ) );
assert_test( 'Mapper detects province header', 'province' === ( $detected['استان'] ?? '' ) );
assert_test( 'Mapper detects city header', 'city' === ( $detected['شهرستان'] ?? '' ) );
assert_test( 'Mapper detects address header', 'address' === ( $detected['آدرس کامل'] ?? '' ) );
assert_test( 'Mapper detects latitude header', 'latitude' === ( $detected['عرض جغرافیایی'] ?? '' ) );
assert_test( 'Mapper detects longitude header', 'longitude' === ( $detected['طول جغرافیایی'] ?? '' ) );

// --- Database & Repository Integration Tests (when in WordPress context) ---
global $wpdb;
if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
	echo "\n--- Testing Database & Repositories (WordPress Integration) ---\n";
	$p_repo    = new \Tapin\ServicePointLocator\Repository\ProviderRepository();
	$providers = $p_repo->get_all();
	assert_test( 'Default providers seeded and retrievable', count( $providers ) >= 2 );

	$sp_repo = new \Tapin\ServicePointLocator\Repository\ServicePointRepository();
	$test_id = $sp_repo->insert(
		array(
			'provider_id' => 1,
			'code'        => 'TEST-SHR-01',
			'name'        => 'شعبه آزمایشی شیراز',
			'province'    => 'فارس',
			'city'        => 'شیراز',
			'address'     => 'میدان ارم',
			'latitude'    => 29.6200,
			'longitude'   => 52.5300,
			'status'      => 'active',
		)
	);
	assert_test( 'ServicePointRepository insert succeeds', $test_id > 0 );

	$fetched = $sp_repo->get_by_id( $test_id );
	assert_test( 'ServicePointRepository get_by_id returns matching row', 'TEST-SHR-01' === ( $fetched['code'] ?? '' ) );

	$deleted = $sp_repo->delete( $test_id );
	assert_test( 'ServicePointRepository cleanup delete succeeds', $deleted );
}

echo "\n===========================================\n";
echo "Results: {$passed} passed, {$failed} failed.\n";

if ( $failed > 0 ) {
	exit( 1 );
}
