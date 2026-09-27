<?php
namespace Tapin\ServicePointLocator\Import;

defined( 'ABSPATH' ) || exit;

/** Explicit operator-run import. Activation never publishes an unreviewed dataset. */
final class SourceCommand {
	/** Import the reviewed Tapin postal directory sample: wp tapin import-reference */
	public static function run(): void {
		$provider = ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_by_slug( 'post' );
		if ( ! $provider ) { \WP_CLI::error( 'The post provider is missing.' ); }
		$result = \Tapin\ServicePointLocator\Database\WriteLock::run( static function() use ( $provider ) {
			return ( new ImportManager() )->import_csv( TAPIN_PLUGIN_DIR . 'assets/data/post-semnan.csv', (int) $provider['id'] );
		} );
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
		\WP_CLI::log( wp_json_encode( $result->to_array(), JSON_UNESCAPED_UNICODE ) );
		if ( $result->get_errors() ) { \WP_CLI::error( 'Import completed with errors; inspect the report.' ); }
		\WP_CLI::success( 'Reference directory imported. Address-only records have no map markers.' );
	}
}
