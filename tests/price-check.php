<?php
/** Standalone module checks with WP/DB doubles; no installed site or credentials. */
define( 'TAPIN_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ABSPATH', sys_get_temp_dir() . '/tapin-price-test-' . bin2hex( random_bytes( 6 ) ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'WP_UNINSTALL_PLUGIN', true );
define( 'TAPIN_PRICE_UNINSTALL_DROP_DATA', in_array( '--drop', $argv, true ) );
mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', '<?php // dbDelta double is defined by the test.' );
require TAPIN_PLUGIN_DIR . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();

use Tapin\ServicePointLocator\PriceCheck\Schema;
use Tapin\ServicePointLocator\PriceCheck\Module;
use Tapin\ServicePointLocator\PriceCheck\Access;

$options = array( 'tapin_db_version' => 9 );
$actions = array(); $menus = array(); $shortcodes = array( 'tapin_service_points' => 'existing-locator' );
$identity = array( 'logged_in' => false, 'capable' => false );
$passed = 0;
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } $GLOBALS['passed']++; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); }
function add_action( $name, $callback, $priority = 10 ) { $GLOBALS['actions'][$name][$priority][] = $callback; }
function add_shortcode( $name, $callback ) { $GLOBALS['shortcodes'][$name] = $callback; }
function add_submenu_page( $parent, $title, $label, $capability, $slug, $callback ) { $GLOBALS['menus'][$slug] = compact( 'parent', 'title', 'capability', 'callback' ); }
function is_user_logged_in() { return $GLOBALS['identity']['logged_in']; }
function current_user_can( $capability ) { check( 'manage_options' === $capability, 'Unexpected capability.' ); return $GLOBALS['identity']['capable']; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( 'denied:' . ( $args['response'] ?? 0 ) ); }
function __( $text, ...$args ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_html__( $text, ...$args ) { return esc_html( $text ); }
function wp_clear_scheduled_hook( $hook ) {}

/** Records DDL and supplies inspection results, not a SQL-engine simulation. */
class PriceTestDatabase {
	public $prefix = 'fixture_';
	public $tables = array();
	public $foreign_keys = array();
	public $queries = array();
	public $creates = 0;
	public $missing_column = false;
	public $wrong_type = false;
	public $missing_index = false;
	public $fail_foreign_key = false;
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function prepare( $sql, ...$values ) { foreach ( $values as $value ) { $sql = preg_replace( '/%s/', "'" . $value . "'", $sql, 1 ); } return $sql; }
	public function get_results( $sql, $mode ) {
		preg_match( '/FROM `([^`]+)`/', $sql, $match );
		if ( 0 === strpos( $sql, 'SHOW COLUMNS' ) ) {
			$columns = $this->tables[$match[1]]['columns'] ?? array();
			if ( $this->missing_column ) { array_pop( $columns ); }
			if ( $this->wrong_type ) { $columns[0]['Type'] = 'varchar(5)'; }
			return $columns;
		}
		$indexes = $this->tables[$match[1]]['indexes'] ?? array();
		if ( $this->missing_index ) { array_pop( $indexes ); }
		return $indexes;
	}
	public function get_row( $sql, $mode ) {
		preg_match( "/k.TABLE_NAME = '([^']+)' AND k.COLUMN_NAME = '([^']+)'/", $sql, $match );
		return $this->foreign_keys[$match[1] . ':' . $match[2]] ?? null;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( '/ALTER TABLE `([^`]+)` ADD CONSTRAINT `([^`]+)` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)`/', $sql, $match ) ) {
			if ( $this->fail_foreign_key ) { return false; }
			$this->foreign_keys[$match[1] . ':' . $match[3]] = array( 'parent_table' => $match[4], 'parent_column' => 'id', 'delete_rule' => 'CASCADE' );
		}
		return 1;
	}
}
function dbDelta( $sql ) {
	global $wpdb;
	check( ! preg_match( '/\b(?:INSERT|UPDATE|DELETE|DROP)\b/i', $sql ), 'Migration seeded or deleted data.' );
	check( false !== strpos( $sql, 'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4' ), 'Migration lost engine/collation.' );
	preg_match( '/CREATE TABLE (\w+) \(\n(.*)\n\) ENGINE=/s', $sql, $match );
	$columns = array(); $indexes = array();
	foreach ( explode( "\n", $match[2] ) as $line ) {
		if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:(\w+)\s+)?\(([^)]+)\)/', $line, $key ) ) {
			foreach ( explode( ',', $key[3] ) as $position => $column ) {
				$indexes[] = array( 'Key_name' => 'PRIMARY KEY' === $key[1] ? 'PRIMARY' : $key[2], 'Seq_in_index' => $position + 1, 'Column_name' => trim( $column ), 'Non_unique' => 'KEY' === $key[1] ? 1 : 0 );
			}
		} else {
			preg_match( '/^(\w+)\s+(\w+(?:\(\d+\))?)(.*)$/', $line, $column );
			preg_match( "/DEFAULT ('[^']*'|[0-9]+)/", $column[3], $default );
			$columns[] = array( 'Field' => $column[1], 'Type' => $column[2], 'Null' => false !== strpos( $column[3], 'NOT NULL' ) ? 'NO' : 'YES', 'Default' => isset( $default[1] ) ? trim( $default[1], "'" ) : null, 'Extra' => false !== strpos( $column[3], 'AUTO_INCREMENT' ) ? 'auto_increment' : '' );
		}
	}
	$wpdb->tables[$match[1]] = compact( 'columns', 'indexes' );
	$wpdb->creates++;
}
$wpdb = new PriceTestDatabase();
try {
	$expected = array( 'providers', 'provider_params', 'provider_fields', 'shops', 'bulk_config', 'security_settings', 'cache_settings', 'route_limits', 'quote_logs', 'price_cache', 'blocked_clients', 'security_events', 'clients', 'security_allowlist' );
	check( array_keys( Schema::tables() ) === $expected, 'Unexpected table set/order.' );
	foreach ( Schema::tables() as $suffix => $table ) { check( $table === 'fixture_tapin_price_' . $suffix && $table !== 'fixture_tapin_providers', 'Table namespace collision.' ); }
	$wpdb->prefix = 'custom_site_';
	check( 'custom_site_tapin_price_providers' === Schema::tables()['providers'], 'wpdb prefix ignored.' );
	$wpdb->prefix = 'fixture_';
	check( 'tapin_price_db_version' === Schema::DB_VERSION_OPTION, 'Schema version not isolated.' );
	( new Module() )->boot();
	check( isset( $actions['init'][10], $actions['admin_menu'][20] ) && isset( $actions['rest_api_init'][10] ), 'Module migration, admin and isolated REST hooks are required.' );
	foreach ( $actions['init'][10] as $callback ) { $callback(); }
	check( 1 === get_option( Schema::DB_VERSION_OPTION ) && 9 === get_option( 'tapin_db_version' ), 'Migration version crossed locator boundary.' );
	check( 14 === $wpdb->creates && 3 === count( $wpdb->foreign_keys ), 'Missing tables or cascading relationships.' );
	$queries = count( $wpdb->queries );
	Schema::maybe_migrate();
	check( 14 === $wpdb->creates, 'Current schema re-migrated during init.' );
	check( Schema::migrate() && 28 === $wpdb->creates && $queries === count( $wpdb->queries ), 'Repeated activation duplicated constraints.' );
	$options[Schema::DB_VERSION_OPTION] = Schema::VERSION + 1;
	Schema::maybe_migrate();
	check( ! Schema::migrate() && 28 === $wpdb->creates, 'Newer schema downgraded.' );
	foreach ( array( 'missing_column', 'wrong_type', 'missing_index', 'fail_foreign_key' ) as $failure ) {
		$options[Schema::DB_VERSION_OPTION] = 0;
		$wpdb->foreign_keys = array();
		$wpdb->$failure = true;
		check( ! Schema::migrate() && 0 === get_option( Schema::DB_VERSION_OPTION ), 'Failed migration marked current: ' . $failure );
		$wpdb->$failure = false;
		check( Schema::migrate(), 'Partial migration not retryable: ' . $failure );
	}
	check( 'existing-locator' === $shortcodes['tapin_service_points'], 'Locator shortcode replaced.' );
	foreach ( array( 'tapin_price_check' => 'single', 'tapin_bulk_quote' => 'bulk' ) as $tag => $mode ) {
		$html = $shortcodes[$tag]();
		check( false !== strpos( $html, 'data-quote-mode="' . $mode . '"' ) && false !== strpos( $html, 'مرحله ۲' ) && false === strpos( $html, '<form' ), 'Shortcode is not a marked placeholder.' );
		check( false === strpos( $html, '/admin' ) && false === strpos( $html, '<script' ), 'Public placeholder exposes admin navigation or assets.' );
	}
	foreach ( $actions['admin_menu'][20] as $callback ) { $callback(); }
	check( 6 === count( $menus ), 'Missing documented admin sections.' );
	foreach ( $menus as $slug => $menu ) {
		check( 0 === strpos( $slug, 'tapin-price-' ) && 'tapin-locator' === $menu['parent'] && 'manage_options' === $menu['capability'], 'Menu namespace/capability mismatch.' );
		foreach ( array( array( false, false ), array( true, false ), array( false, true ) ) as $state ) {
			$identity = array( 'logged_in' => $state[0], 'capable' => $state[1] );
			check( ! Access::can_manage(), 'Admin permission helper allowed unauthorized read/write.' );
			ob_start();
			try { $menu['callback'](); throw new LogicException( 'Unauthorized callback rendered.' ); }
			catch ( RuntimeException $e ) { check( 'denied:403' === $e->getMessage(), 'Unexpected denial.' ); }
			finally { check( '' === ob_get_clean(), 'Unauthorized callback leaked output.' ); }
		}
		$identity = array( 'logged_in' => true, 'capable' => true );
		check( Access::can_manage(), 'Administrator denied.' );
		ob_start(); $menu['callback'](); $html = ob_get_clean();
		check( false !== strpos( $html, esc_html( $menu['title'] ) ) && false !== strpos( $html, 'مرحله ۲' ), 'Admin placeholder absent.' );
	}
	$wpdb->queries = array();
	require TAPIN_PLUGIN_DIR . 'uninstall.php';
	if ( TAPIN_PRICE_UNINSTALL_DROP_DATA ) {
		check( 14 === count( $wpdb->queries ) && ! isset( $options[Schema::DB_VERSION_OPTION] ), 'Opt-in quotation cleanup incomplete.' );
		foreach ( $wpdb->queries as $query ) { check( false !== strpos( $query, '`fixture_tapin_price_' ) && false === strpos( $query, '`fixture_tapin_providers`' ), 'Uninstall crossed locator boundary.' ); }
		check( false !== strpos( end( $wpdb->queries ), 'tapin_price_providers`' ), 'Parent dropped before children.' );
	} else { check( empty( $wpdb->queries ) && isset( $options[Schema::DB_VERSION_OPTION] ), 'Uninstall did not preserve quotation data by default.' ); }
	check( 9 === get_option( 'tapin_db_version' ), 'Quotation uninstall changed locator options.' );
	echo "PASS price-check foundation: {$passed} assertions (WP/DB doubles; uninstall " . ( TAPIN_PRICE_UNINSTALL_DROP_DATA ? 'opt-in' : 'preserve' ) . ").\n";
} finally {
	unlink( ABSPATH . 'wp-admin/includes/upgrade.php' );
	rmdir( ABSPATH . 'wp-admin/includes' ); rmdir( ABSPATH . 'wp-admin' ); rmdir( ABSPATH );
}
