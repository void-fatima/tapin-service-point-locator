<?php
/** Compose existing price-table and options SQL doubles for the default production guard. */
final class PriceCheckSecurityDatabase {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	public int $insert_id = 0;
	public PriceCheckDatabase $state;
	public PriceCheckRateDatabase $rates;
	public function __construct() { $this->state = new PriceCheckDatabase(); $this->rates = new PriceCheckRateDatabase(); }
	public function suppress_errors( bool $value ): bool { $previous = $this->state->suppress_errors( $value ); $this->rates->suppress_errors( $value ); return $previous; }
	public function prepare( string $sql, ...$args ): string { return $this->state->prepare( $sql, ...$args ); }
	public function esc_like( string $text ): string { return $this->rates->esc_like( $text ); }
	public function __call( string $method, array $args ) {
		$db = in_array( $method, array( 'get_results', 'query' ), true ) && false !== strpos( $args[0], 'wp_options' ) ? $this->rates : $this->state;
		$result = $db->$method( ...$args ); $this->last_error = $db->last_error; $this->insert_id = $this->state->insert_id; return $result;
	}
}
