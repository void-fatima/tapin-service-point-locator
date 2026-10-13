<?php
/** Dedicated wp_options SQL double with SQLite and controllable CAS interleaving. */
final class PriceCheckRateDatabase {
	public string $options = 'wp_options';
	public string $last_error = '';
	public PDO $pdo;
	public array $queries = array();
	public ?string $fail = null;
	public bool $error_only = false;
	public $interleave;
	public bool $suppressed = false;
	public function __construct() { $this->pdo = new PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION ); $this->pdo->exec( 'CREATE TABLE wp_options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)' ); }
	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }
	public function prepare( string $sql, ...$args ): string { $i = 0; return preg_replace_callback( '/%[sd]/', function( $match ) use ( &$i, $args ) { return '%d' === $match[0] ? (string) (int) $args[$i++] : $this->pdo->quote( (string) $args[$i++] ); }, $sql ); }
	public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	private function run( string $sql, bool $read ) {
		$this->last_error = ''; $this->queries[] = $sql;
		if ( ! preg_match( '/\bwp_options\b/', $sql ) || preg_match( '/tapin_providers/', $sql ) ) { throw new LogicException( 'Unexpected table.' ); }
		if ( $this->interleave && 0 === strpos( $sql, 'UPDATE' ) ) { $callback = $this->interleave; $this->interleave = null; $callback(); }
		if ( null !== $this->fail && 0 === strpos( $sql, $this->fail ) ) { $this->fail = null; $this->last_error = 'inert-test-marker'; return $this->error_only ? ( $read ? array() : 1 ) : false; }
		$sql = str_replace( array( 'INSERT IGNORE', 'BINARY option_value' ), array( 'INSERT OR IGNORE', 'option_value COLLATE BINARY' ), $sql );
		if ( false !== strpos( $sql, 'LIKE ' ) ) { $sql .= " ESCAPE '\\'"; }
		return $read ? $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ) : $this->pdo->exec( $sql );
	}
	public function get_results( string $sql, $format ) { return $this->run( $sql, true ); }
	public function query( string $sql ) { return $this->run( $sql, false ); }
}
