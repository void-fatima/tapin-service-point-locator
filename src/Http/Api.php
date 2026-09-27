<?php
namespace Tapin\ServicePointLocator\Http;

use Tapin\ServicePointLocator\Repository\ServicePointRepository;
use Tapin\ServicePointLocator\Repository\ProviderRepository;
use Tapin\ServicePointLocator\Service\PointService;
use Tapin\ServicePointLocator\Import\ImportJobs;

defined( 'ABSPATH' ) || exit;

final class Api {
	public function register(): void {
		$this->route( '/points', 'GET', array( $this, 'points' ) );
		$this->route( '/points/(?P<id>\d+)', 'GET', static fn( $r ) => ( new ServicePointRepository() )->get_by_id( (int) $r['id'] ) ?: new \WP_Error( 'not_found', 'نقطه خدماتی پیدا نشد.', array( 'status' => 404 ) ) );
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
		$this->route( '/public/directory', 'GET', fn( $r ) => $this->public_points( $r, true ), true );
		$this->route( '/public/filters', 'GET', static fn() => array( 'providers' => self::providers( true ), 'locations' => ( new ServicePointRepository() )->locations( true ) ), true );
	}

	private function route( string $path, string $methods, callable $callback, bool $public = false ): void {
		if ( 'GET' !== $methods && 0 !== strpos( $path, '/imports' ) ) {
			$original = $callback;
			$callback = static fn( $r ) => \Tapin\ServicePointLocator\Database\WriteLock::run( static fn() => $original( $r ) );
		}
		register_rest_route( 'tapin/v1', $path, array( 'methods' => $methods, 'callback' => $callback, 'permission_callback' => $public ? '__return_true' : static fn() => current_user_can( 'manage_options' ) ) );
	}

	private function filters( $r ): array {
		$args = array( 'status' => 'any' );
		foreach ( array( 'provider_id', 'province', 'city', 'search', 'status', 'issue', 'page', 'per_page', 'order' ) as $key ) {
			if ( null !== $r[$key] && is_scalar( $r[$key] ) ) {
				$args[$key] = sanitize_text_field( (string) $r[$key] );
			}
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

	public function points( $r ): array {
		$args = $this->filters( $r );
		$args['include_summary'] = '1' === $r['include_summary'];
		return ( new ServicePointRepository() )->query( $args );
	}

	public function public_points( $r, bool $directory = false ): array {
		$args = $this->filters( $r );
		$args['status'] = 'active';
		$args['public'] = true;
		$args['directory'] = $directory;
		$args['has_coordinates'] = $directory ? null : 1;
		unset( $args['issue'] );
		$result = ( new ServicePointRepository() )->query( $args );
		$allowed = array_flip( array( 'id', 'provider_id', 'name', 'province', 'city', 'address', 'postal_code', 'phone', 'mobile_phone', 'landline_phone', 'latitude', 'longitude', 'has_coordinates' ) );
		$result['items'] = array_map( static fn( $row ) => array_intersect_key( $row, $allowed ), $result['items'] );
		return $result;
	}

	public static function providers( bool $public = false ): array {
		$styles = get_option( 'tapin_provider_styles', array() );
		return array_map( static function( $p ) use ( $styles ) {
			$defaults = array( 'post' => '#5694ff', 'tipax' => '#36cf8a' );
			$slug = $p['slug'];
			$p['color'] = sanitize_hex_color( $styles[$p['id']]['color'] ?? '' ) ?: ( $defaults[$slug] ?? '#b6a4e8' );
			$p['logo'] = esc_url_raw( ( $styles[$p['id']]['logo'] ?? '' ) ?: ( 'post' === $slug ? TAPIN_PLUGIN_URL . 'assets/brand/post.png' : ( 'tipax' === $slug ? TAPIN_PLUGIN_URL . 'assets/brand/tipax.svg' : '' ) ) );
			return array_intersect_key( $p, array_flip( array( 'id', 'slug', 'name', 'is_active', 'color', 'logo' ) ) );
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
