<?php
/**
 * Plugin Name:       Tapin Service Point Locator
 * Plugin URI:        https://github.com/void-fatima/tapin-service-point-locator
 * Description:       Backend foundation for managing shipping/logistics service points (Post, Tipax, and similar providers), with bulk import support and a future interactive public map.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            void-fatima
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tapin-service-point-locator
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'TAPIN_VERSION', '0.1.0' );
define( 'TAPIN_DB_VERSION', 2 );
define( 'TAPIN_PLUGIN_FILE', __FILE__ );
define( 'TAPIN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAPIN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TAPIN_PLUGIN_DIR . 'src/Autoloader.php';

Tapin\ServicePointLocator\Autoloader::register();

register_activation_hook( __FILE__, array( Tapin\ServicePointLocator\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Tapin\ServicePointLocator\Deactivator::class, 'deactivate' ) );

Tapin\ServicePointLocator\Plugin::instance()->boot();
