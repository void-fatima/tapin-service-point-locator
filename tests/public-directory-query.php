<?php
/** CLI adapter for the real API/repository: emits SQL and formats supplied query results. No database access. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'TAPIN_PLUGIN_DIR', ABSPATH );
define( 'ARRAY_A', 'ARRAY_A' );
require ABSPATH . 'src/Autoloader.php';
\Tapin\ServicePointLocator\Autoloader::register();
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
$input = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
$wpdb = new class( $input ) {
	public $prefix = 'qa_';
	public $queries = array();
	private $input;
	public function __construct( $input ) { $this->input = $input; }
	public function prepare( $sql, $args ) {
		return preg_replace_callback( '/%[dsf]/', static function( $match ) use ( &$args ) {
			$value = array_shift( $args );
			return '%s' === $match[0] ? "'" . str_replace( "'", "''", $value ) . "'" : (string) ( '%d' === $match[0] ? (int) $value : (float) $value );
		}, $sql );
	}
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function get_var( $sql ) { $this->queries['count'] = $sql; return $this->input['total'] ?? 0; }
	public function get_results( $sql, $format ) { $this->queries['rows'] = $sql; return $this->input['rows'] ?? array(); }
};
$request = new ArrayObject( array_merge( array_fill_keys( array( 'provider_id', 'province', 'city', 'search', 'status', 'issue', 'page', 'per_page', 'order', 'has_coordinates', 'north', 'south', 'east', 'west', 'include_summary', 'map_view' ), null ), $input['params'] ?? array() ) );
$api = new \Tapin\ServicePointLocator\Http\Api();
$result = ! empty( $input['admin'] ) ? $api->points( $request ) : $api->public_points( $request, $input['directory'] ?? true );
echo json_encode( array( 'result' => $result, 'queries' => $wpdb->queries ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
