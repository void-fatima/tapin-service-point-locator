<?php
/** A wpdb-shaped SQLite memory double: SQL execution, nulls, IDs, cascades and injected failures. */
final class PriceCheckDatabase {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $queries = array();
	public ?array $failure = null;
	public PDO $pdo;
	private bool $suppressed = false;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'PRAGMA foreign_keys = ON' );
		$definitions = \Tapin\ServicePointLocator\PriceCheck\Schema::definitions();
		foreach ( array( 'providers', 'provider_params', 'provider_fields', 'shops', 'bulk_config', 'price_cache' ) as $suffix ) {
			$lines = array(); $auto = false;
			foreach ( explode( "\n", $definitions[$suffix] ) as $line ) {
				$line = trim( $line, " \t," );
				if ( 0 === strpos( $line, 'KEY ' ) ) { continue; }
				if ( false !== strpos( $line, 'AUTO_INCREMENT' ) ) { $line = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; $auto = true; }
				if ( $auto && 0 === strpos( $line, 'PRIMARY KEY' ) ) { continue; }
				$line = preg_replace( '/^UNIQUE KEY \w+/', 'UNIQUE', $line ); $lines[] = $line;
			}
			if ( 'provider_params' === $suffix ) { $lines[] = 'FOREIGN KEY(provider_id) REFERENCES wp_tapin_price_providers(id) ON DELETE CASCADE'; }
			if ( 'provider_fields' === $suffix ) { $lines[] = 'FOREIGN KEY(param_id) REFERENCES wp_tapin_price_provider_params(id) ON DELETE CASCADE'; }
			if ( 'price_cache' === $suffix ) { $lines[] = 'FOREIGN KEY(provider_id) REFERENCES wp_tapin_price_providers(id) ON DELETE CASCADE'; }
			$this->pdo->exec( 'CREATE TABLE wp_tapin_price_' . $suffix . ' (' . implode( ', ', $lines ) . ')' );
		}
		$this->pdo->exec( 'CREATE TABLE wp_tapin_providers (id INTEGER PRIMARY KEY, sentinel TEXT)' );
		$this->pdo->exec( "INSERT INTO wp_tapin_providers VALUES(1, 'locator-preserved')" );
	}

	public function suppress_errors( bool $value ): bool { $old = $this->suppressed; $this->suppressed = $value; return $old; }

	public function prepare( string $sql, ...$values ): string {
		$index = 0;
		return preg_replace_callback( '/%[ds]/', function( $match ) use ( $values, &$index ) {
			$value = $values[$index++]; return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
		}, $sql );
	}

	private function execute( string $sql ) {
		$this->last_error = ''; $this->queries[] = $sql;
		$error_only = false;
		if ( preg_match( '/\bwp_tapin_(?!price_)/', $sql ) ) { throw new LogicException( 'Repository touched a locator table.' ); }
		if ( $this->failure && preg_match( '/^' . $this->failure['kind'] . '\b/i', $sql ) && false !== strpos( $sql, 'wp_tapin_price_' . $this->failure['table'] ) ) {
			if ( ( $this->failure['skip'] ?? 0 ) > 0 ) { $this->failure['skip']--; }
			else {
				$error_only = 'error_only' === ( $this->failure['mode'] ?? null ); $this->failure = null;
				if ( ! $error_only ) { $this->last_error = 'Synthetic private database failure'; return false; }
			}
		}
		$sql = preg_replace( '/ON DUPLICATE KEY UPDATE (.*)$/s', 'ON CONFLICT(id) DO UPDATE SET $1', $sql );
		$sql = preg_replace( '/VALUES\((`[a-z_]+`)\)/', 'excluded.$1', $sql );
		try { $result = $this->pdo->query( $sql ); if ( $error_only ) { $this->last_error = 'Synthetic private database failure'; } return $result; }
		catch ( PDOException $error ) { $this->last_error = 'Synthetic SQL error'; return false; }
	}

	public function get_results( string $sql, $format ) {
		$cursor = $this->execute( $sql ); if ( false === $cursor ) { return null; }
		$rows = $cursor->fetchAll( PDO::FETCH_ASSOC );
		// WordPress returns strings for numeric columns; retain null and varchar IDs.
		foreach ( $rows as &$row ) { foreach ( $row as &$value ) { if ( null !== $value ) { $value = is_float( $value ) ? json_encode( $value, JSON_PRESERVE_ZERO_FRACTION ) : (string) $value; } } unset( $value ); } unset( $row );
		return $rows;
	}

	public function query( string $sql ) {
		$cursor = $this->execute( $sql ); if ( false === $cursor ) { return false; }
		$this->insert_id = (int) $this->pdo->lastInsertId(); return $cursor->rowCount();
	}

	private function literal( $value, string $format ): string { return null === $value ? 'NULL' : $this->prepare( $format, $value ); }

	public function insert( string $table, array $data, array $formats ) {
		$values = array(); $index = 0;
		foreach ( $data as $value ) { $values[] = $this->literal( $value, $formats[$index++] ); }
		return $this->query( "INSERT INTO `{$table}` (`" . implode( '`, `', array_keys( $data ) ) . '`) VALUES (' . implode( ', ', $values ) . ')' );
	}

	public function update( string $table, array $data, array $where, array $formats, array $where_formats ) {
		$set = $same = $conditions = array(); $index = 0;
		foreach ( $data as $key => $value ) { $literal = $this->literal( $value, $formats[$index++] ); $set[] = "`{$key}` = {$literal}"; $same[] = "`{$key}` IS {$literal}"; }
		$index = 0; foreach ( $where as $key => $value ) { $conditions[] = "`{$key}` IS " . $this->literal( $value, $where_formats[$index++] ); }
		// MySQL/WordPress changed-row semantics: unchanged rows count as zero.
		return $this->query( "UPDATE `{$table}` SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) . ' AND NOT (' . implode( ' AND ', $same ) . ')' );
	}

	public function delete( string $table, array $where, array $formats ) {
		$conditions = array(); $index = 0;
		foreach ( $where as $key => $value ) { $conditions[] = "`{$key}` IS " . $this->literal( $value, $formats[$index++] ); }
		return $this->query( "DELETE FROM `{$table}` WHERE " . implode( ' AND ', $conditions ) );
	}
}
