<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Source admin configuration API, behind RestApi's mandatory capability/nonce boundary. */
final class ConfigurationApiService {
	private PublicApiService $schemas;
	private LocationAdminService $locations;
	public function __construct( ?PublicApiService $schemas = null, ?LocationAdminService $locations = null ) { $this->schemas = $schemas ?? new PublicApiService(); $this->locations = $locations ?? new LocationAdminService(); }
	private function bulk_editor(): array {
		$providers = array(); foreach ( ProviderStorage::all() as $provider ) { $params = array(); foreach ( ProviderDefinition::params( $provider ) as $param ) {
			$row = array(); foreach ( array( 'id', 'name', 'label', 'input', 'type', 'visible', 'kind', 'default' ) as $key ) { $row[$key] = V::get( $param, $key ); } $row['options'] = ProviderSchema::options( $param ); $row['fields'] = array();
			foreach ( ProviderDefinition::fields( $param ) as $field ) { $sub = array(); foreach ( array( 'id', 'name', 'label', 'input', 'type', 'visible', 'default' ) as $key ) { $sub[$key] = V::get( $field, $key ); } $sub['options'] = ProviderSchema::options( (object) array( 'options' => array_values( array_filter( V::fallback( V::get( $field, 'options' ), array() ), array( V::class, 'mapping' ) ) ) ) ); $row['fields'][] = (object) $sub; } $params[] = (object) $row;
		} $providers[] = array( 'id' => $provider->id, 'name' => $provider->name, 'display_name' => $provider->display_name, 'active' => V::truth( V::get( $provider, 'active', true ) ), 'params' => $params ); } return array( 'config' => BulkStorage::get(), 'providers' => $providers );
	}
	public function call( string $operation, RestInput $input ): RestResult {
		if ( 'admin_get_bulk' === $operation ) { return new RestResult( $this->bulk_editor() ); }
		if ( 'admin_save_bulk' === $operation ) { try { $config = BulkConfig::normalise( $input->body(), ProviderStorage::all() ); } catch ( RecordError $error ) { throw new QuoteError( 422, 'اطلاعات استعلام گروهی معتبر نیست.', $error->errors ); } BulkStorage::save( $config ); return new RestResult( array( 'detail' => 'ذخیره شد.', 'config' => $config ) ); }
		if ( 'admin_list_providers' === $operation ) { $items = array(); foreach ( ProviderStorage::all() as $provider ) { $row = array(); foreach ( array( 'id', 'name', 'display_name', 'logo', 'check_price_url', 'shop_id', 'price_fields' ) as $key ) { $row[$key] = V::get( $provider, $key ); } $params = ProviderDefinition::params( $provider ); $row += array( 'active' => V::truth( V::get( $provider, 'active', true ) ), 'param_count' => count( $params ), 'visible_field_count' => count( array_filter( $params, static fn( $param ) => V::truth( V::get( $param, 'visible' ) ) ) ) ); $items[] = $row; } return new RestResult( array( 'providers' => $items ) ); }
		if ( in_array( $operation, array( 'admin_get_provider', 'admin_update_provider', 'admin_delete_provider', 'admin_unserved_locations' ), true ) ) { $provider = $this->schemas->provider( $input->path['provider_id'] ); }
		if ( 'admin_get_provider' === $operation ) { return new RestResult( $this->schemas->schema( $provider, true, 'true' === strtolower( $input->text( 'refresh' ) ) ) ); }
		if ( 'admin_unserved_locations' === $operation ) { try { return new RestResult( $this->locations->unserved( $provider ) ); } catch ( LocationError $error ) { throw new QuoteError( 503, $error->getMessage() ); } }
		if ( 'admin_delete_provider' === $operation ) { ProviderStorage::delete( $provider->id ); BulkStorage::prune_provider( $provider->id, $provider->name ); return new RestResult( array( 'id' => $provider->id, 'detail' => 'حذف شد.' ) ); }
		if ( 'admin_create_provider' === $operation || 'admin_update_provider' === $operation ) {
			try { $record = ProviderConfig::normalise( $input->body() ); $this->locations->validate_manual( $record ); } catch ( RecordError $error ) { throw new QuoteError( 422, 'اطلاعات ارسالی معتبر نیست.', $error->errors ); }
			if ( isset( $provider ) ) { $record->id = $provider->id; }
			try { $id = ProviderStorage::save( $record ); } catch ( \InvalidArgumentException $error ) { throw new QuoteError( 422, $error->getMessage() ); } catch ( StorageError $error ) { throw new QuoteError( 409, 'ذخیره در پایگاه داده ناموفق بود.' ); }
			return new RestResult( array( 'id' => $id, 'detail' => 'ذخیره شد.' ), 'admin_create_provider' === $operation ? 201 : 200 );
		}
		if ( 'admin_list_shops' === $operation ) { $rows = ShopStorage::list_rows( $input->query['province'] ?? null, $input->query['city'] ?? null ); return new RestResult( array( 'shops' => $rows, 'count' => count( $rows ) ) ); }
		if ( 'admin_create_shop' === $operation ) { $body = $input->body(); $rows = V::get( $body, 'shops' ) ?? array( $body ); if ( ! V::listing( $rows ) || ! $rows ) { throw new QuoteError( 400, 'حداقل یک شعبه باید ارسال شود.' ); } try { $written = ShopStorage::save( $rows ); } catch ( \InvalidArgumentException $error ) { throw new QuoteError( 422, $error->getMessage() ); } return new RestResult( array( 'written' => $written, 'shops' => ShopStorage::list_rows() ) ); }
		$id = V::trim( $input->path['shop_id'] ?? '' );
		if ( 'admin_get_shop' === $operation ) { $shop = '' === $id ? null : ShopStorage::get_private( $id ); if ( null === $shop ) { throw new QuoteError( 404, 'شعبه مورد نظر یافت نشد.' ); } return new RestResult( array( 'shop' => $shop ) ); }
		if ( 'admin_update_shop' === $operation ) { if ( '' === $id ) { throw new QuoteError( 422, 'شناسه شعبه الزامی است.' ); } $body = $input->body(); if ( V::has( $body, 'id' ) && V::text( V::string( $body->id ) ) !== $id ) { throw new QuoteError( 400, 'شناسه شعبه را نمی‌توان تغییر داد.' ); } $body->id = $id; try { ShopStorage::save( array( $body ) ); } catch ( \InvalidArgumentException $error ) { throw new QuoteError( 422, $error->getMessage() ); } $shop = ShopStorage::get_private( $id ); if ( null !== $shop ) { unset( $shop['token'] ); $shop['has_token'] = true; } return new RestResult( array( 'shop' => $shop, 'detail' => 'ذخیره شد.' ) ); }
		if ( 'admin_delete_shop' === $operation ) { if ( '' === $id ) { throw new QuoteError( 422, 'شناسه شعبه الزامی است.' ); } if ( ! ShopStorage::delete( $id ) ) { throw new QuoteError( 404, 'شعبه مورد نظر یافت نشد.' ); } return new RestResult( array( 'id' => $id, 'detail' => 'حذف شد.' ) ); }
		throw new \LogicException( 'Unknown configuration operation.' );
	}
}
