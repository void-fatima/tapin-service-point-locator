<?php
/** Isolated tables; no production fixture writes. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Import\TapinDirectory;
use Tapin\ServicePointLocator\Normalization\DataNormalizer as N;
use Tapin\ServicePointLocator\Validation\ServicePointValidator as V;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Service\SyntheticCleanup;
use Tapin\ServicePointLocator\Database\Schema;
$original = $wpdb->prefix; $wpdb->prefix .= 'phase1_' . bin2hex( random_bytes( 4 ) ) . '_'; $passed = 0;
function phase_check( $ok, $message ) { global $passed; if ( ! $ok ) { throw new RuntimeException( $message ); } $passed++; echo "PASS {$message}\n"; }
try {
	Schema::migrate(); $repo = new ServicePointRepository(); $providers = new ProviderRepository(); $post = (int) $providers->get_by_slug( 'post' )['id'];
	$ref = N::normalize_service_point( array( 'provider_id' => $post, 'name' => 'دفتر پست', 'province' => 'تهران', 'city' => 'ری', 'address' => 'نشانی رسمی', 'postal_code' => '1234567890', 'landline_phone' => '02112345678', 'source' => 'https://tapin.ir/map/tehran.pdf' ) );
	$raw = array_merge( $ref, array( 'name' => 'نام بارگذاری', 'address' => 'نشانی بارگذاری', 'landline_phone' => '', 'mobile_phone' => '09121234567', 'source' => 'uploaded.csv', 'latitude' => 35.7, 'longitude' => 51.4, 'metadata' => array( 'coordinate_source' => 'survey' ) ) );
	$r = TapinDirectory::reconcile( $raw, array( $ref ) );
	phase_check( 'verified' === $r['metadata']['tapin_reconciliation']['result'], 'postal code verified' );
	phase_check( $r['name'] === $raw['name'] && $r['address'] === $raw['address'], 'uploaded values never silently overwritten' );
	phase_check( '02112345678' === $r['landline_phone'] && '09121234567' === $r['mobile_phone'], 'landline enrichment preserves mobile' );
	phase_check( 35.7 === $r['latitude'] && 51.4 === $r['longitude'] && 'survey' === $r['metadata']['coordinate_source'], 'uploaded coordinates and their provenance preserved' );
	phase_check( 'uploaded.csv' === $r['source'] && $ref['source'] === $r['metadata']['tapin_reconciliation']['source_url'], 'both upload and exact official source retained' );
	phase_check( $r['metadata']['tapin_reconciliation']['uploaded']['address'] === $raw['address'] && $r['metadata']['tapin_reconciliation']['official']['address'] === $ref['address'], 'original and official field evidence retained' );
	$phone = $ref; $phone['postal_code'] = null;
	phase_check( 'landline_phone' === TapinDirectory::reconcile( $phone, array( $ref ) )['metadata']['tapin_reconciliation']['signal'], 'phone match with compatible locality' );
	$name = $phone; $name['landline_phone'] = null; $name['city'] = 'ري';
	phase_check( 'probable_match' === TapinDirectory::reconcile( $name, array( $ref ) )['metadata']['tapin_reconciliation']['result'], 'normalized branch name is probable, not guaranteed' );
	$conflict = $raw; $conflict['province'] = 'فارس';
	phase_check( 'conflict' === TapinDirectory::reconcile( $conflict, array( $ref ) )['metadata']['tapin_reconciliation']['result'], 'postal match with contradictory locality conflicts' );
	$other = $ref; $other['name'] = 'دفتر دیگر';
	phase_check( 'conflict' === TapinDirectory::reconcile( $raw, array( $ref, $other ) )['metadata']['tapin_reconciliation']['result'], 'ambiguous postal identity conflicts' );
	$unknown = $name; $unknown['name'] = 'یافت نشده';
	phase_check( 'not_found' === TapinDirectory::reconcile( $unknown, array( $ref ) )['metadata']['tapin_reconciliation']['result'], 'address similarity alone never verifies' );
	$r = TapinDirectory::reconcile( $ref, array( $ref ) );
	phase_check( null === $r['latitude'] && null === $r['longitude'] && V::validate( $r )->is_valid(), 'source text remains valid address-only data' );
	$r['latitude'] = 35; phase_check( ! V::validate( $r )->is_valid(), 'partial pair rejected' );
	$r['longitude'] = 190; phase_check( ! V::validate( $r )->is_valid(), 'out-of-range pair rejected' );
	$r = $ref; $r['city'] = ''; phase_check( V::validate( $r )->is_valid(), 'unknown city allowed without guessing' );
	$urls = TapinDirectory::discover( '<a href="tehran.pdf"><a href="semnan.pdf "><a href="https://evil.test/a.pdf"><a href="tehran.pdf">' );
	phase_check( $urls === array( 'https://tapin.ir/map/tehran.pdf', 'https://tapin.ir/map/semnan.pdf' ), 'source discovery trims, deduplicates, rejects third parties' );
	$legitimate = $repo->insert( $ref );
	$debug = $repo->insert( array_merge( $ref, array( 'name' => 'Debug 1790536145363', 'code' => 'DBG-1790536145807', 'source' => null ) ) );
	$trace = $repo->insert( array_merge( $ref, array( 'name' => 'Trace 1790536355201', 'code' => 'DBG-1790536355679', 'source' => null ) ) );
	$protected = $repo->insert( array_merge( $ref, array( 'name' => 'Debug 1790536145363', 'code' => 'DBG-1790536145807' ) ) );
	phase_check( count( SyntheticCleanup::candidates() ) === 2, 'only unmistakable sourceless fixtures selected' );
	phase_check( SyntheticCleanup::remove( array( $debug, $trace, $protected, $legitimate ) ) === 2, 'Trace and Debug removed' );
	phase_check( $repo->get_by_id( $legitimate ) && $repo->get_by_id( $protected ), 'source-backed Tehran records protected' );
	phase_check( count( SyntheticCleanup::candidates() ) === 0, 'cleanup idempotent' );
	$repo->delete( $protected );
	$located = $repo->insert( array_merge( $ref, array( 'name' => 'مکان روی نقشه', 'latitude' => 35.7, 'longitude' => 51.4, 'city' => 'ري' ) ) );
	$repo->insert( array_merge( $ref, array( 'province' => 'فارس', 'city' => 'شیراز', 'name' => 'فارس' ) ) );
	$rows = $repo->locations();
	phase_check( count( array_filter( $rows, static fn( $r ) => $r['province'] === 'تهران' && $r['city'] === 'ری' ) ) === 1, 'Arabic/Persian city variants deduplicated' );
	$q = $repo->query( array( 'province' => 'تهران', 'city' => 'ری', 'provider_id' => $post, 'include_summary' => true ) );
	phase_check( 2 === $q['total'] && 1 === (int) $q['summary']['located'] && 1 === $q['summary']['missing'], 'combined location/provider summary includes address-only' );
	phase_check( 1 === $repo->query( array( 'province' => 'تهران', 'search' => 'مکان' ) )['total'], 'search intersects province' );
	phase_check( 0 === $repo->query( array( 'province' => 'فارس', 'city' => 'ری' ) )['total'], 'incompatible city returns empty results' );
	phase_check( 1 === $repo->query( array( 'province' => 'تهران', 'has_coordinates' => 1 ) )['total'], 'markers exclude address-only branches' );
	phase_check( $post === (int) $q['summary']['distribution'][0]['provider_id'] && 2 === (int) $q['summary']['distribution'][0]['total'], 'provider counts use identical filter predicates' );
	$uploaded = array( 'code' => 'phase1-import', 'name' => 'نام بارگذاری', 'postal_code' => '۳۵۱۳۶۷۴۶۸۶', 'address' => 'نشانی بارگذاری', 'source' => 'upload.csv', 'latitude' => '35.7', 'longitude' => '51.4', 'metadata' => array( 'coordinate_source' => 'uploaded survey' ) );
	$outcome = \Tapin\ServicePointLocator\Import\RowProcessor::process( $uploaded, $post, 'skip' );
	phase_check( 'inserted' === $outcome['result'], 'shared upload pipeline reconciles before required province validation' );
	$saved = $repo->query( array( 'search' => 'phase1-import' ) )['items'][0];
	phase_check( 'سمنان' === $saved['province'] && 'سمنان' === $saved['city'] && $saved['has_coordinates'], 'official province enrichment preserves uploaded coordinates' );
	phase_check( '۳۵۱۳۶۷۴۶۸۶' === $saved['metadata']['tapin_reconciliation']['original_uploaded']['postal_code'], 'raw uploaded digits retained before normalization' );
	phase_check( 'verified' === $saved['metadata']['tapin_reconciliation']['result'] && 'uploaded' === $saved['metadata']['coordinate_source'], 'persisted reconciliation and trusted upload provenance retained' );
	foreach ( array( 'منطقه ۱', 'منطقه ١', 'منطقه 1' ) as $city ) { $repo->insert( array_merge( $ref, array( 'city' => $city ) ) ); }
	phase_check( 3 === $repo->query( array( 'city' => 'منطقه ۱' ) )['total'], 'digit variants filter legacy location values consistently' );
	phase_check( 1 === count( array_filter( $repo->locations(), static fn( $r ) => $r['city'] === 'منطقه 1' ) ), 'digit variants do not duplicate dropdown labels' );
	echo "{$passed} phase 1 checks passed.\n";
} finally {
	foreach ( array( 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_logs' ) as $suffix ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix ); }
	$wpdb->prefix = $original;
}
