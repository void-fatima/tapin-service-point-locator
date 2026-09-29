<?php
namespace Tapin\ServicePointLocator\Http;

use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Service\PointService;
use Tapin\ServicePointLocator\Import\ImportJobs;

defined( 'ABSPATH' ) || exit;

final class Api {
	public function register(): void {
		add_filter( 'rest_pre_serve_request', array( \Tapin\ServicePointLocator\Export\DownloadResponse::class, 'serve' ), 10, 4 );
		$this->route( '/exports/points', 'POST', array( $this, 'export_points' ) );
		$this->route( '/exports', 'GET', static fn() => \Tapin\ServicePointLocator\Service\OperationalLog::recent_exports() );
		$this->route( '/geocoding', 'GET', array( $this, 'geocoding_status' ) );
		$this->route( '/geocoding/retry', 'POST', array( $this, 'retry_geocoding' ) );
		$this->route( '/points', 'GET', array( $this, 'points' ) );
		$this->route( '/points/(?P<id>\d+)', 'GET', static fn( $r ) => ( new ServicePointRepository() )->get_by_id( (int) $r['id'] ) ?: new \WP_Error( 'not_found', 'نقطه خدماتی پیدا نشد.', array( 'status' => 404 ) ) );
		$this->route( '/points/(?P<id>\d+)/details', 'GET', fn( $r ) => $this->point_details( $r, false ) );
		$this->route( '/points', 'POST', static fn( $r ) => PointService::save( (array) $r->get_json_params() ) );
		$this->route( '/points/(?P<id>\d+)', 'POST', static fn( $r ) => PointService::save( (array) $r->get_json_params(), (int) $r['id'] ) );
		$this->route( '/points/(?P<id>\d+)', 'DELETE', static fn( $r ) => ( new ServicePointRepository() )->delete( (int) $r['id'] ) ? array( 'deleted' => true ) : new \WP_Error( 'not_found', 'رکورد پیدا نشد یا حذف نشد.', array( 'status' => 404 ) ) );
		$this->route( '/providers', 'GET', static fn() => self::providers() );
		$this->route( '/providers', 'POST', array( $this, 'save_provider' ) );
		$this->route( '/providers/(?P<id>\d+)', 'DELETE', array( $this, 'delete_provider' ) );
		$this->route( '/dashboard', 'GET', static fn() => array( 'summary' => ( new ServicePointRepository() )->summary(), 'recent' => ( new ServicePointRepository() )->query( array( 'status' => 'any', 'order' => 'DESC', 'per_page' => 5 ) )['items'], 'imports' => ImportJobs::recent() ) );
		$this->route( '/locations', 'GET', static fn() => ( new ServicePointRepository() )->locations() );
		$this->route( '/imports', 'GET', static fn() => ImportJobs::recent() );
		$this->route( '/imports', 'POST', static fn( $r ) => ImportJobs::upload( $r ) );
		$this->route( '/imports/(?P<id>\d+)', 'GET', static fn( $r ) => ImportJobs::get_public( (int) $r['id'] ) );
		$this->route( '/imports/(?P<id>\d+)/start', 'POST', static fn( $r ) => ImportJobs::start( (int) $r['id'], (array) $r->get_json_params() ) );
		$this->route( '/imports/(?P<id>\d+)/step', 'POST', static fn( $r ) => ImportJobs::step( (int) $r['id'] ) );
		$this->route( '/imports/(?P<id>\d+)/cancel', 'POST', static fn( $r ) => ImportJobs::cancel( (int) $r['id'] ) );
		$this->route( '/public/points', 'GET', array( $this, 'public_points' ), true );
		$this->route( '/public/points/(?P<id>\d+)', 'GET', array( $this, 'point_details' ), true );
		$this->route( '/public/directory', 'GET', fn( $r ) => $this->public_points( $r, true ), true );
		$this->route( '/public/filters', 'GET', static fn() => array( 'providers' => self::providers( true ), 'locations' => ( new ServicePointRepository() )->locations( true ) ), true );
	}

	private function route( string $path, string $methods, callable $callback, bool $public = false ): void {
		if ( 'GET' !== $methods && 0 !== strpos( $path, '/imports' ) && 0 !== strpos( $path, '/exports' ) ) {
			$original = $callback;
			$callback = static fn( $r ) => \Tapin\ServicePointLocator\Database\WriteLock::run( static fn() => $original( $r ) );
		}
		register_rest_route( 'tapin/v1', $path, array( 'methods' => $methods, 'callback' => $callback, 'permission_callback' => $public ? '__return_true' : static fn() => current_user_can( 'manage_options' ) ) );
	}

	public function geocoding_status( $r ) {
		$raw = $r['ids'];
		$ids = null === $raw || '' === $raw ? array() : ( is_string( $raw ) ? explode( ',', $raw ) : null );
		if ( ! $this->valid_point_ids( $ids ) ) { return new \WP_Error( 'invalid_ids', 'حداکثر ۱۰۰ شناسه معتبر ارسال کنید.', array( 'status' => 400 ) ); }
		return \Tapin\ServicePointLocator\Geocoding\Jobs::status( array_map( 'intval', $ids ) );
	}

	public function retry_geocoding( $r ) {
		$data = (array) $r->get_json_params();
		$ids = $data['ids'] ?? null;
		if ( ! $this->valid_point_ids( $ids ) || ! $ids ) { return new \WP_Error( 'invalid_ids', 'بین ۱ تا ۱۰۰ شناسه معتبر ارسال کنید.', array( 'status' => 400 ) ); }
		return \Tapin\ServicePointLocator\Geocoding\Jobs::retry( array_map( 'intval', $ids ) );
	}

	private function valid_point_ids( $ids ): bool {
		if ( ! is_array( $ids ) || count( $ids ) > 100 ) { return false; }
		foreach ( $ids as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $id ) || false === filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) ) { return false; }
		}
		return true;
	}

	private function filters( $r ): array {
		$args = array( 'status' => 'any' );
		foreach ( array( 'provider_id', 'province', 'city', 'search', 'status', 'issue', 'page', 'per_page', 'order' ) as $key ) {
			if ( null === $r[$key] || ! is_scalar( $r[$key] ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $r[$key] );
			// A provider group arrives as "3,4"; "0" means an empty group. Both become
			// IN() lists so provider_id = 0 can never read as "no filter at all".
			if ( 'provider_id' === $key && '' !== $value && preg_match( '/^\d+(?:,\d+)*$/D', $value ) && ( false !== strpos( $value, ',' ) || '0' === $value ) ) {
				$args[ $key ] = array_map( 'intval', explode( ',', $value ) );
				continue;
			}
			$args[ $key ] = $value;
		}
		if ( in_array( $r['has_coordinates'], array( '0', '1' ), true ) ) {
			$args['has_coordinates'] = (int) $r['has_coordinates'];
		}
		$bounds = array();
		foreach ( array( 'north', 'south', 'east', 'west' ) as $key ) {
			if ( null !== $r[$key] && is_scalar( $r[$key] ) && is_numeric( $r[$key] ) && is_finite( (float) $r[$key] ) ) { $bounds[$key] = (float) $r[$key]; }
		}
		if ( count( $bounds ) === 4 && abs( $bounds['north'] ) <= 90 && abs( $bounds['south'] ) <= 90 && abs( $bounds['east'] ) <= 180 && abs( $bounds['west'] ) <= 180 ) { $args['bounds'] = $bounds; }
		return $args;
	}

	public function export_points( $r ) {
		if ( ! wp_verify_nonce( $r->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return new \WP_Error( 'export_nonce', 'درخواست معتبر نیست؛ صفحه را تازه کنید.', array( 'status' => 403 ) ); }
		foreach ( array( 'provider_id', 'province', 'city', 'search', 'status', 'issue', 'has_coordinates' ) as $key ) {
			if ( null !== $r[$key] && ! is_string( $r[$key] ) ) { return new \WP_Error( 'export_filter', 'فیلتر خروجی معتبر نیست.', array( 'status' => 400 ) ); }
			if ( is_string( $r[$key] ) && strlen( $r[$key] ) > 2000 ) { return new \WP_Error( 'export_filter', 'فیلتر خروجی بیش از حد طولانی است.', array( 'status' => 400 ) ); }
		}
		foreach ( array( 'status' => array( '', 'any', 'active', 'inactive' ), 'issue' => array( '', 'duplicate', 'incomplete' ), 'has_coordinates' => array( '', '0', '1' ) ) as $key => $allowed ) {
			if ( null !== $r[$key] && ! in_array( $r[$key], $allowed, true ) ) { return new \WP_Error( 'export_filter', 'فیلتر خروجی معتبر نیست.', array( 'status' => 400 ) ); }
		}
		$id = $r['provider_id'];
		// A single id, an explicit empty set ("0"), or a comma list of ids.
		if ( null !== $id && '' !== $id && ! preg_match( '/^(?:0|[1-9][0-9]*)(?:,(?:0|[1-9][0-9]*))*$/D', $id ) ) { return new \WP_Error( 'export_filter', 'ارائه‌دهنده خروجی معتبر نیست.', array( 'status' => 400 ) ); }
		$args = array_intersect_key( $this->filters( $r ), array_flip( array( 'provider_id', 'province', 'city', 'search', 'status', 'issue', 'has_coordinates' ) ) );
		return \Tapin\ServicePointLocator\Export\ServicePoints::download( $args );
	}

	public function points( $r ): array {
		$args = $this->filters( $r );
		$args['include_summary'] = '1' === $r['include_summary'];
		$result = ( new ServicePointRepository() )->query( $args );
		if ( '1' === $r['map_view'] ) { $result['items'] = array_map( array( self::class, 'public_fields' ), $result['items'] ); }
		return $result;
	}

	/** Only approved branch information crosses into marker/drawer responses. */
	public static function public_fields( array $point ): array {
		$point = \Tapin\ServicePointLocator\Service\PointEvidence::fields( $point );
		$allowed = array_flip( array( 'id', 'provider_id', 'name', 'province', 'city', 'address', 'postal_code', 'phone', 'mobile_phone', 'landline_phone', 'latitude', 'longitude', 'has_coordinates' ) );
		return array_intersect_key( $point, $allowed );
	}

	public function point_details( $r, bool $public = true ) {
		$point = ( new ServicePointRepository() )->get_by_id( (int) $r['id'] );
		$provider = $point ? ( new ProviderRepository() )->get_by_id( $point['provider_id'] ) : null;
		if ( ! $point || ( $public && ( 'active' !== $point['status'] || empty( $provider['is_active'] ) ) ) ) {
			return new \WP_Error( 'not_found', 'نقطه خدماتی در دسترس نیست.', array( 'status' => 404 ) );
		}
		return self::public_fields( $point );
	}

	public function public_points( $r, bool $directory = false ): array {
		$args = $this->filters( $r );
		$args['status'] = 'active';
		$args['public'] = true;
		$args['directory'] = $directory;
		$args['has_coordinates'] = $directory ? null : 1;
		unset( $args['issue'] );
		$result = ( new ServicePointRepository() )->query( $args );
		$result['items'] = array_map( array( self::class, 'public_fields' ), $result['items'] );
		return $result;
	}

	public static function providers( bool $public = false ): array {
		$styles = get_option( 'tapin_provider_styles', array() );
		return array_map( static function( $p ) use ( $styles ) {
			$defaults = array( 'post' => '#ffbd18', 'tipax' => '#00ba88' );
			$slug = $p['slug'];
			$p['color'] = sanitize_hex_color( $styles[$p['id']]['color'] ?? '' ) ?: ( $defaults[$slug] ?? '#7349ff' );
			$p['marker_color'] = $defaults[$slug] ?? '#7349ff';
			$p['logo'] = esc_url_raw( ( $styles[$p['id']]['logo'] ?? '' ) ?: ( 'post' === $slug ? TAPIN_PLUGIN_URL . 'assets/brand/post.png' : ( 'tipax' === $slug ? TAPIN_PLUGIN_URL . 'assets/brand/tipax.svg' : '' ) ) );
			return array_intersect_key( $p, array_flip( array( 'id', 'slug', 'name', 'is_active', 'color', 'marker_color', 'logo' ) ) );
		}, ( new ProviderRepository() )->get_all( ! $public ? false : true ) );
	}

	public function save_provider( $r ) {
		$data = (array) $r->get_json_params();
		foreach ( $data as $value ) {
			if ( ! is_scalar( $value ) ) { return new \WP_Error( 'invalid', 'داده نامعتبر.', array( 'status' => 400 ) ); }
		}
		$id = absint( $data['id'] ?? 0 );
		$name = sanitize_text_field( $data['name'] ?? '' );
		$slug = sanitize_key( $data['slug'] ?? '' );
		$repo = new ProviderRepository();
		$existing = $repo->get_by_slug( $slug );
		if ( ! $name || ! $slug || strlen( $slug ) > 50 || \Tapin\ServicePointLocator\Normalization\DataNormalizer::strlen( $name ) > 100 || ( $existing && (int) $existing['id'] !== $id ) || ( $id && ! $repo->get_by_id( $id ) ) ) {
			return new \WP_Error( 'invalid', 'نام و شناسه یکتای معتبر وارد کنید.', array( 'status' => 400 ) );
		}
		$record = array( 'name' => $name, 'slug' => $slug, 'is_active' => ! empty( $data['is_active'] ) );
		$result = $id ? $repo->update( $id, $record ) : $repo->insert( $record );
		if ( ! $result ) { return new \WP_Error( 'database', 'ذخیره ارائه‌دهنده انجام نشد.', array( 'status' => 500 ) ); }
		$id = $id ?: $result;
		$styles = get_option( 'tapin_provider_styles', array() );
		$styles[$id] = array( 'color' => sanitize_hex_color( $data['color'] ?? '' ) ?: '#b6a4e8', 'logo' => esc_url_raw( $data['logo'] ?? '', array( 'https', 'http' ) ) );
		update_option( 'tapin_provider_styles', $styles, false );
		return self::providers();
	}

	public function delete_provider( $r ) {
		$id = (int) $r['id'];
		if ( ( new ServicePointRepository() )->query( array( 'provider_id' => $id, 'status' => 'any', 'per_page' => 1 ) )['total'] ) {
			return new \WP_Error( 'in_use', 'این ارائه‌دهنده نقطه خدماتی دارد؛ ابتدا نقاط را منتقل یا حذف کنید.', array( 'status' => 409 ) );
		}
		if ( ! ( new ProviderRepository() )->delete( $id ) ) { return new \WP_Error( 'not_found', 'ارائه‌دهنده پیدا نشد.', array( 'status' => 404 ) ); }
		$styles = get_option( 'tapin_provider_styles', array() );
		unset( $styles[$id] );
		update_option( 'tapin_provider_styles', $styles, false );
		return array( 'deleted' => true );
	}
}
