<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** Lazy service composition: REST registration must not perform SQL, HTTP or quote work. */
final class ApiServices {
	public function call( string $operation, RestInput $input ): RestResult {
		if ( in_array( $operation, OperationalApiService::OPERATIONS, true ) ) { return ( new OperationalApiService() )->call( $operation, $input ); }
		if ( 0 === strpos( $operation, 'admin_' ) ) { return ( new ConfigurationApiService() )->call( $operation, $input ); }
		if ( in_array( $operation, array( 'check_price', 'bulk_check', 'bulk_validate', 'preview_provider_schema', 'preview_price_check', 'preview_bulk_schema', 'preview_bulk_check', 'preview_bulk_validate' ), true ) ) { return ( new QuoteApiService() )->call( $operation, $input ); }
		return ( new PublicApiService() )->call( $operation, $input );
	}
}
