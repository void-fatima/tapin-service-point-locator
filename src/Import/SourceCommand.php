<?php
namespace Tapin\ServicePointLocator\Import;

defined( 'ABSPATH' ) || exit;

/** Explicit operator-run import. Activation never publishes an unreviewed dataset. */
final class SourceCommand {
	/** Import the reviewed Tapin postal directory sample: wp tapin import-reference */
	public static function run(): void {
		foreach ( array( 'post' => 'post-semnan.csv', 'tipax' => 'tipax-tehran.csv' ) as $slug => $file ) {
			$provider = ( new \Tapin\ServicePointLocator\Repository\ProviderRepository() )->get_by_slug( $slug );
			if ( ! $provider ) { \WP_CLI::error( 'Missing provider: ' . $slug ); }
			$result = \Tapin\ServicePointLocator\Database\WriteLock::run( static function() use ( $provider, $file ) {
				return ( new ImportManager() )->import_csv( TAPIN_PLUGIN_DIR . 'assets/data/' . $file, (int) $provider['id'] );
			} );
			if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_message() ); }
			\WP_CLI::log( $slug . ': ' . wp_json_encode( $result->to_array(), JSON_UNESCAPED_UNICODE ) );
			if ( $result->get_errors() ) { \WP_CLI::error( 'Import completed with errors; inspect the report.' ); }
		}
		\WP_CLI::success( 'Reference directories imported. Address-only records have no map markers.' );
	}
}
