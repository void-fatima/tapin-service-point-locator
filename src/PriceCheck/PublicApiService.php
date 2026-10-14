<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source schema endpoints, composing existing configuration and location services. */
final class PublicApiService {
	private $providers;
	private $provider;
	private $section;
	private $shops;
	private $config;
	private $stats;
	public function __construct( ?callable $providers = null, ?callable $provider = null, ?callable $section = null, ?callable $shops = null, ?callable $config = null, ?callable $stats = null ) {
		$this->providers = $providers ?? array( ProviderStorage::class, 'all' ); $this->provider = $provider ?? array( ProviderStorage::class, 'load' ); $this->section = $section ?? array( new LocationService(), 'provider_section' ); $this->shops = $shops ?? array( ShopStorage::class, 'list_rows' ); $this->config = $config ?? array( BulkStorage::class, 'get' );
		$this->stats = $stats ?? static function() { $counts = array(); foreach ( array( 'providers', 'shops' ) as $table ) { $rows = ConfigurationDatabase::rows( 'SELECT COUNT(*) AS row_count FROM ' . ConfigurationDatabase::table( $table ) ); if ( ! isset( $rows[0]['row_count'] ) ) { throw new StorageError( 'health_count' ); } $counts[$table] = $rows[0]['row_count']; } return $counts; };
	}
	public function provider( string $id, bool $display_name = false ) {
		$provider = ( $this->provider )( V::trim( $id ) );
		if ( null === $provider && $display_name && '' !== $id ) { foreach ( ( $this->providers )() as $item ) { if ( ProviderSchema::fold( V::trim( V::get( $item, 'display_name', '' ) ) ) === ProviderSchema::fold( $id ) ) { $provider = $item; break; } } }
		if ( null === $provider ) { throw new QuoteError( 404, 'شرکت مورد نظر یافت نشد.' ); } return $provider;
	}
	public function schema( $provider, bool $admin = false, bool $refresh = false ): \stdClass {
		$out = $admin ? ProviderSchema::admin_schema( $provider ) : ProviderSchema::public_schema( $provider );
		try { $section = ( $this->section )( $provider, $refresh ); }
		catch ( LocationError $error ) { $section = (object) array( 'provider' => V::get( $provider, 'name' ), 'provider_key' => null, 'requested' => array(), 'status' => 'unavailable', 'message' => $error->getMessage(), 'fetched_at' => null, 'fetched_at_iso' => null, 'expires_at' => null, 'expires_at_iso' => null, 'missing' => array(), 'bindings' => new \stdClass() ); }
		if ( null !== $section ) { $out->locations = $section; } return $out;
	}
	public function bulk( $config ): array {
		$providers = ( $this->providers )(); $active = BulkConfig::active_providers( $config, $providers ); usort( $active, static fn( $a, $b ) => $a->id <=> $b->id );
		$sections = $origins = $rows = array(); $mappings = new \stdClass();
		foreach ( $active as $provider ) { try { $section = ( $this->section )( $provider ); } catch ( LocationError $error ) { $section = null; } $sections[] = $section; if ( false !== V::get( V::get( V::get( $provider, 'location_config' ), 'origin' ), 'enabled', true ) ) { $origins[] = $section; }
			$currency = V::fallback( V::get( $provider, 'price_currency_out' ), 'rial' ); $weight = V::fallback( V::get( $provider, 'weight_unit_out' ), 'gram' );
			$rows[] = array( 'id' => $provider->id, 'name' => $provider->name, 'display_name' => $provider->display_name, 'logo' => $provider->logo, 'currency' => $currency, 'currency_label' => 'toman' === $currency ? 'تومان' : 'ریال', 'weight_unit' => $weight, 'weight_unit_label' => 'kilogram' === $weight ? 'کیلوگرم' : 'گرم', 'rules' => ProviderSchema::rules( $provider ) ); $mappings->{$provider->name} = BulkConfig::mappings_for( $config, $provider->id );
		}
		$fields = BulkSchemaFields::fields( $config, $providers, $active ); $location = LocationBindings::merge( $sections ); [$enabled, $display, $province, $city] = BulkMapping::destination( $config, $fields );
		$origin_config = V::get( V::get( $config, 'location' ), 'origin' ); $origin_display = V::get( $origin_config, 'display' ); $origin = (array) LocationBindings::merge( $origins, true ); $origin['wanted'] = (bool) $origins && false !== V::get( $origin_config, 'enabled', true ); $origin['display'] = in_array( $origin_display, array( 'separate', 'combined' ), true ) ? $origin_display : 'combined';
		$shops = array(); foreach ( ( $this->shops )() as $shop ) { $row = array(); foreach ( array( 'id', 'title', 'province', 'city' ) as $key ) { $row[$key] = V::get( $shop, $key ); } $shops[] = $row; }
		$units = array(); foreach ( array( 'price', 'weight' ) as $key ) { $unit = V::get( $config, $key ); $name = V::fallback( V::text( V::fallback( V::get( $unit, 'field' ), '' ) ), null ); if ( ! in_array( $name, array_map( static fn( $f ) => V::get( $f, 'name' ), $fields ), true ) ) { $name = null; } $value = 'price' === $key ? Units::currency( V::get( $unit, 'unit' ) ) : Units::weight( V::get( $unit, 'unit' ) ); $units[$key] = array( 'field' => $name, 'unit' => $value, 'label' => 'price' === $key ? ( 'toman' === $value ? 'تومان' : 'ریال' ) : ( 'kilogram' === $value ? 'کیلوگرم' : 'گرم' ) ); }
		return array( 'providers' => $rows, 'fields' => $fields, 'mappings' => $mappings, 'origin' => (object) $origin, 'destination' => array( 'enabled' => $enabled, 'display' => $display, 'province_field' => $province, 'city_field' => $city, 'status' => $location->status, 'message' => V::fallback( $location->message, '' ), 'provinces' => $location->provinces, 'cities_by_province' => $location->cities_by_province ), 'price' => $units['price'], 'weight' => $units['weight'], 'shops' => $shops );
	}
	public function call( string $operation, RestInput $input ): RestResult {
		if ( 'health' === $operation ) { return new RestResult( array( 'status' => 'ok' ) + ( $this->stats )() ); }
		if ( 'bulk_schema' === $operation ) { return new RestResult( $this->bulk( ( $this->config )() ) ); }
		if ( 'list_providers' === $operation ) { $rows = array(); foreach ( ( $this->providers )() as $provider ) { if ( ! V::truth( V::get( $provider, 'active', true ) ) ) { continue; } $visible = array_values( array_filter( ProviderDefinition::params( $provider ), static fn( $param ) => V::truth( V::get( $param, 'visible' ) ) ) ); $rows[] = array( 'id' => $provider->id, 'name' => $provider->name, 'display_name' => $provider->display_name, 'logo' => $provider->logo, 'active' => V::get( $provider, 'active', true ), 'visible_field_count' => count( $visible ), 'visible_fields' => array_map( static fn( $p ) => array( 'label' => $p->label ), $visible ) ); } return new RestResult( array( 'providers' => $rows ) ); }
		if ( 'provider_schema' === $operation ) { $provider = $this->provider( $input->path['provider_id'] ); if ( ! V::truth( V::get( $provider, 'active', true ) ) ) { throw new QuoteError( 404, 'این شرکت غیرفعال است.' ); } return new RestResult( $this->schema( $provider ) ); }
		if ( 'location_lookup' === $operation ) { $id = $input->text( 'provider' ); if ( '' === $id ) { throw new QuoteError( 400, 'فیلد provider الزامی است.' ); } $provider = $this->provider( $id, true ); try { $section = ( $this->section )( $provider, 'true' === strtolower( $input->text( 'refresh' ) ) ); } catch ( LocationError $error ) { throw new QuoteError( 503, $error->getMessage() ); } return new RestResult( $section ?? array( 'provider' => $provider->name, 'status' => 'unused', 'message' => 'این شرکت پارامتر شهر یا استان ندارد.', 'bindings' => new \stdClass() ) ); }
		throw new \LogicException( 'Unknown public operation.' );
	}
}
