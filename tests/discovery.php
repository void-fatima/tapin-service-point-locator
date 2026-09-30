<?php
/** Isolated discovery queries against real WordPress and MySQL. */
if ( PHP_SAPI !== 'cli' || ! getenv( 'TAPIN_WP_ROOT' ) ) { exit( 1 ); }
require getenv( 'TAPIN_WP_ROOT' ) . '/wp-load.php';
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
$original = $wpdb->prefix;
$wpdb->prefix .= 'discovery_' . bin2hex( random_bytes( 4 ) ) . '_';
$passed = 0;
function discovery_check( bool $ok, string $name ): void { global $passed; if ( ! $ok ) { throw new RuntimeException( $name ); } $passed++; echo "PASS {$name}\n"; }
try {
 \Tapin\ServicePointLocator\Database\Schema::migrate();
 $providers = new ProviderRepository(); $points = new ServicePointRepository();
 $provider = $providers->insert( array( 'slug' => 'discovery-carrier', 'name' => 'حمل آزمایشی', 'is_active' => 1 ) );
 $base = array( 'provider_id' => $provider, 'name' => 'شعبه نمونه', 'province' => 'تهران', 'city' => 'ری', 'address' => 'نشانی ویژه', 'mobile_phone' => '09121112233', 'landline_phone' => '02122223333', 'latitude' => 35.6, 'longitude' => 51.4 );
 $points->insert( $base ); $points->insert( array_merge( $base, array( 'name' => 'فقط نشانی', 'latitude' => null, 'longitude' => null ) ) );
 foreach ( array( 'تهران', 'ري', 'حمل آزمایشی', 'discovery-carrier', 'نشانی ویژه', '09121112233', '02122223333' ) as $search ) {
  $args = array( 'public' => true, 'search' => $search );
  discovery_check( $points->query( $args )['total'] === 1 && $points->query( array_merge( $args, array( 'directory' => true ) ) )['total'] === 2, 'shared search: ' . $search );
 }
 discovery_check( $points->query( array( 'public' => true, 'search' => 'تهران', 'province' => 'سمنان' ) )['total'] === 0, 'search intersects province filter' );
 discovery_check( $points->query( array( 'search' => "%' OR 1=1 --" ) )['total'] === 0, 'search metacharacters remain literal' );
 $providers->update( $provider, array( 'is_active' => 0 ) );
 discovery_check( $points->query( array( 'public' => true, 'search' => 'discovery-carrier' ) )['total'] === 0, 'provider search respects publication' );
 echo "{$passed} discovery checks passed.\n";
} finally {
 foreach ( array( 'tapin_service_points', 'tapin_providers', 'tapin_imports', 'tapin_import_points', 'tapin_logs' ) as $suffix ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $suffix ); }
 $wpdb->prefix = $original;
}
