<?php

namespace Tapin\ServicePointLocator;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 style autoloader mapping Tapin\ServicePointLocator\* to src/*.
 */
final class Autoloader {

	const PREFIX = 'Tapin\\ServicePointLocator\\';

	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$file     = TAPIN_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
}
