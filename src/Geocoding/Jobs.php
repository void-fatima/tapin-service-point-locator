<?php
namespace Tapin\ServicePointLocator\Geocoding;

use Tapin\ServicePointLocator\Database\WriteLock;
use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;

defined( 'ABSPATH' ) || exit;

/** Durable queue on existing WP-Cron, with one unique job per service point. */
final class Jobs {
	public const HOOK = 'tapin_geocode_points';
	private const MAX_ATTEMPTS = 4;
	public static function table(): string { global $wpdb; return $wpdb->prefix . 'tapin_geocoding_jobs'; }

	public static function boot(): void {
		add_filter( 'cron_schedules', static function( $schedules ) {
			$schedules['tapin_minute'] = array( 'interval' => 60, 'display' => 'Tapin location queue' );
			return $schedules;
		} );
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', static function() {
			if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 60, 'tapin_minute', self::HOOK ); }
		} );
	}

	/** Caller holds the shared write lock (import transaction or admin write). No HTTP. */
	public static function enqueue( array $point, bool $retry = false ) {
		global $wpdb;
		$id = (int) $point['id'];
		$table = self::table();
		$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE point_id = %d", $id ), ARRAY_A );
		if ( CoordinatePolicy::valid( $point ) ) {
			if ( $old && in_array( $old['status'], array( 'pending', 'retry', 'processing' ), true ) ) {
				$wpdb->update( $table, array( 'status' => 'skipped', 'last_code' => 'already_located', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $id ) );
			}
			return array( 'status' => 'skipped', 'point_id' => $id );
		}
		$provider = Configuration::provider();
		$query = AddressQuery::build( $point );
		$hash = AddressQuery::hash( is_wp_error( $query ) ? array_intersect_key( $point, array_flip( array( 'province', 'city', 'address', 'metadata' ) ) ) : $query, $provider->name() );
		$shipping = ( new ProviderRepository() )->get_by_id( (int) $point['provider_id'] );
		$blocked = is_wp_error( $query ) || 'active' !== $point['status'] || empty( $shipping['is_active'] );
		$active_job = $old && in_array( $old['status'], array( 'pending', 'retry', 'processing' ), true );
		// Recheck eligibility even when the address is unchanged (activation/conflict edits).
		if ( $old && $old['query_hash'] === $hash && ! $blocked && ( $active_job || ( ! $retry && 'blocked' !== $old['status'] ) ) ) { return array( 'status' => $old['status'], 'point_id' => $id ); }
		$record = array(
			'point_id' => $id, 'query_hash' => $hash, 'provider' => $provider->name(),
			'status' => $blocked ? 'blocked' : 'pending', 'attempts' => 0,
			'next_attempt' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			'last_code' => is_wp_error( $query ) ? $query->get_error_code() : ( $blocked ? 'inactive' : '' ),
		);
		$ok = $old ? $wpdb->update( $table, $record, array( 'point_id' => $id ) ) : $wpdb->insert( $table, $record );
		if ( false === $ok ) { return new \WP_Error( 'queue_storage', 'ذخیره صف موقعیت‌یابی ناموفق بود؛ رکورد محفوظ است و می‌توانید دوباره تلاش کنید.', array( 'status' => 500 ) ); }
		return array( 'status' => $record['status'], 'point_id' => $id );
	}

	public static function retry( array $ids ): array {
		$repo = new ServicePointRepository(); $items = array();
		foreach ( array_unique( $ids ) as $id ) {
			$point = $repo->get_by_id( (int) $id );
			$result = $point ? self::enqueue( $point, true ) : new \WP_Error( 'not_found', 'Point not found.' );
			$items[] = is_wp_error( $result ) ? array( 'point_id' => (int) $id, 'status' => 'error', 'code' => $result->get_error_code() ) : $result;
		}
		return array( 'items' => $items, 'configured' => Configuration::provider()->configured() );
	}

	public static function status( array $ids = array() ): array {
		global $wpdb;
		$table = self::table(); $counts = array();
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) total FROM {$table} GROUP BY status", ARRAY_A ) ?: array() as $row ) { $counts[$row['status']] = (int) $row['total']; }
		$items = array();
		if ( $ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$items = $wpdb->get_results( $wpdb->prepare( "SELECT point_id, status, attempts, last_code, next_attempt, updated_at FROM {$table} WHERE point_id IN ({$placeholders})", $ids ), ARRAY_A ) ?: array();
		}
		$provider = Configuration::provider();
		return array( 'configured' => $provider->configured(), 'provider' => $provider->name(), 'counts' => $counts, 'items' => $items, 'max_attempts' => self::MAX_ATTEMPTS, 'next_run' => wp_next_scheduled( self::HOOK ) ?: null );
	}

	/** One bounded work item per minute; external requests never hold the import lock. */
	public static function run(): void {
		global $wpdb;
		$provider = Configuration::provider();
		if ( ! $provider->configured() || time() < (int) get_option( 'tapin_geocoding_next_request', 0 ) ) { return; }
		$lock = 'tapin-geo-' . md5( DB_NAME . $wpdb->prefix );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) { return; }
		try {
			// Recheck after acquiring the worker lock to serialize provider-wide throttling.
			if ( time() < (int) get_option( 'tapin_geocoding_next_request', 0 ) ) { return; }
			$job = WriteLock::run( static fn() => self::claim( $provider ) );
			if ( ! $job || is_wp_error( $job ) ) { return; }
			update_option( 'tapin_geocoding_next_request', time() + 60, false );
			try { $result = ( new GeocodingService( $provider ) )->resolve( $job['query'] ); }
			catch ( \Throwable $e ) { $result = new \WP_Error( 'internal_error', 'Location enrichment failed.' ); }
			if ( is_wp_error( $result ) ) {
				$info = (array) $result->get_error_data();
				if ( ! empty( $info['retryable'] ) ) {
					update_option( 'tapin_geocoding_next_request', time() + self::delay( $job, $info ), false );
				}
				// Invalid/unauthorized/exhausted credentials must not hammer every queued row.
				if ( in_array( $result->get_error_code(), array( 'http_401', 'http_403', 'http_480', 'http_481', 'http_483', 'http_484', 'http_485' ), true ) ) { update_option( 'tapin_geocoding_next_request', time() + HOUR_IN_SECONDS, false ); }
			}
			WriteLock::run( static function() use ( $job, $result, $provider ) { self::finish( $job, $result, $provider ); } );
		} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}

	private static function claim( GeocoderInterface $provider ): ?array {
		global $wpdb;
		$table = self::table();
		$job = $wpdb->get_row( "SELECT * FROM {$table} WHERE status IN ('pending','retry','processing') AND next_attempt <= UTC_TIMESTAMP() ORDER BY next_attempt, point_id LIMIT 1", ARRAY_A );
		if ( ! $job ) { return null; }
		$point = ( new ServicePointRepository() )->get_by_id( (int) $job['point_id'] );
		if ( ! $point || CoordinatePolicy::valid( $point ) ) {
			$wpdb->update( $table, array( 'status' => 'skipped', 'last_code' => $point ? 'already_located' : 'deleted', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $job['point_id'] ) );
			return null;
		}
		$query = AddressQuery::build( $point );
		$shipping = ( new ProviderRepository() )->get_by_id( (int) $point['provider_id'] );
		if ( 'active' !== $point['status'] || empty( $shipping['is_active'] ) ) {
			$wpdb->update( $table, array( 'status' => 'blocked', 'last_code' => 'inactive', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $job['point_id'] ) );
			return null;
		}
		if ( is_wp_error( $query ) || $job['query_hash'] !== AddressQuery::hash( $query, $provider->name() ) ) { self::enqueue( $point, true ); return null; }
		if ( (int) $job['attempts'] >= self::MAX_ATTEMPTS ) {
			$wpdb->update( $table, array( 'status' => 'failed', 'last_code' => 'attempts_exhausted', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $job['point_id'] ) );
			return null;
		}
		$job['attempts'] = (int) $job['attempts'] + 1;
		$ok = $wpdb->update( $table, array( 'status' => 'processing', 'attempts' => $job['attempts'], 'next_attempt' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $job['point_id'] ) );
		if ( false === $ok ) { return null; }
		$job['query'] = $query;
		return $job;
	}

	private static function delay( array $job, array $info ): int {
		return max( 60 * ( 2 ** max( 0, (int) $job['attempts'] - 1 ) ), (int) ( $info['retry_after'] ?? 0 ) );
	}

	private static function finish( array $job, $result, GeocoderInterface $provider ): void {
		global $wpdb;
		$table = self::table(); $repo = new ServicePointRepository(); $id = (int) $job['point_id'];
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE point_id = %d", $id ), ARRAY_A );
		if ( ! $current || 'processing' !== $current['status'] || $current['query_hash'] !== $job['query_hash'] ) { return; }
		$point = $repo->get_by_id( $id );
		if ( ! $point || CoordinatePolicy::valid( $point ) ) {
			$wpdb->update( $table, array( 'status' => 'skipped', 'last_code' => 'changed_during_request', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $id ) );
			return;
		}
		$query = AddressQuery::build( $point );
		$shipping = ( new ProviderRepository() )->get_by_id( (int) $point['provider_id'] );
		if ( 'active' !== $point['status'] || empty( $shipping['is_active'] ) ) { $result = new \WP_Error( 'inactive', 'Point or provider is inactive.' ); }
		elseif ( is_wp_error( $query ) || AddressQuery::hash( $query, $provider->name() ) !== $job['query_hash'] ) { self::enqueue( $point, true ); return; }
		$failed = is_wp_error( $result );
		$info = $failed ? (array) $result->get_error_data() : array();
		$status = $failed ? ( ! empty( $info['retryable'] ) && $job['attempts'] < self::MAX_ATTEMPTS ? 'retry' : 'failed' ) : 'succeeded';
		$code = $failed ? sanitize_key( $result->get_error_code() ) : '';
		$metadata = is_array( $point['metadata'] ) ? $point['metadata'] : array();
		$metadata['geocoding'] = array( 'status' => $status, 'provider' => $provider->name(), 'query_hash' => $job['query_hash'], 'attempts' => $job['attempts'], 'last_code' => $code, 'updated_at' => gmdate( 'c' ) );
		$data = array( 'metadata' => $metadata );
		if ( ! $failed ) {
			$data['latitude'] = $result['latitude']; $data['longitude'] = $result['longitude'];
			$data['metadata']['coordinate_source'] = 'geocoded';
			$data['metadata']['geocoding']['quality'] = $result['quality'];
			$data['metadata']['geocoding']['geocoded_at'] = gmdate( 'c' );
		}
		if ( ! $repo->update( $id, $data ) ) {
			$status = $job['attempts'] < self::MAX_ATTEMPTS ? 'retry' : 'failed'; $code = 'persistence_failed';
		}
		$wpdb->update( $table, array( 'status' => $status, 'last_code' => $code, 'next_attempt' => gmdate( 'Y-m-d H:i:s', time() + self::delay( $job, $info ) ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'point_id' => $id ) );
	}
}
