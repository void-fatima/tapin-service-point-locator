<?php

namespace Tapin\ServicePointLocator\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Value object representing the outcome of a bulk import operation.
 */
final class ImportResult {

	private int $total_rows     = 0;
	private int $processed_rows = 0;
	private int $inserted_rows  = 0;
	private int $updated_rows   = 0;
	private int $skipped_rows   = 0;

	/**
	 * @var array<int, array{row: int, field: string, message: string, data?: array}>
	 */
	private array $errors = array();

	/**
	 * @var array<int, array{row: int, field: string, message: string, data?: array}>
	 */
	private array $warnings = array();

	/**
	 * @var array<int, array{row: int, type: string, reason: string, existing_id?: int}>
	 */
	private array $duplicates = array();

	private float $start_time;
	private ?float $end_time = null;

	public function __construct() {
		$this->start_time = microtime( true );
	}

	public function finish(): void {
		$this->end_time = microtime( true );
	}

	public function increment_total( int $count = 1 ): void {
		$this->total_rows += $count;
	}

	public function increment_processed( int $count = 1 ): void {
		$this->processed_rows += $count;
	}

	public function increment_inserted( int $count = 1 ): void {
		$this->inserted_rows += $count;
	}

	public function increment_updated( int $count = 1 ): void {
		$this->updated_rows += $count;
	}

	public function increment_skipped( int $count = 1 ): void {
		$this->skipped_rows += $count;
	}

	public function add_error( int $row_index, string $field, string $message, ?array $data = null ): void {
		$this->errors[] = array(
			'row'     => $row_index,
			'field'   => $field,
			'message' => $message,
			'data'    => $data,
		);
	}

	public function add_warning( int $row_index, string $field, string $message, ?array $data = null ): void {
		$this->warnings[] = array(
			'row'     => $row_index,
			'field'   => $field,
			'message' => $message,
			'data'    => $data,
		);
	}

	public function add_duplicate( int $row_index, string $type, string $reason, ?int $existing_id = null ): void {
		$this->duplicates[] = array(
			'row'         => $row_index,
			'type'        => $type, // 'definite' | 'probable'
			'reason'      => $reason,
			'existing_id' => $existing_id,
		);
	}

	public function get_total_rows(): int {
		return $this->total_rows;
	}

	public function get_processed_rows(): int {
		return $this->processed_rows;
	}

	public function get_inserted_rows(): int {
		return $this->inserted_rows;
	}

	public function get_updated_rows(): int {
		return $this->updated_rows;
	}

	public function get_skipped_rows(): int {
		return $this->skipped_rows;
	}

	public function get_errors(): array {
		return $this->errors;
	}

	public function get_warnings(): array {
		return $this->warnings;
	}

	public function get_duplicates(): array {
		return $this->duplicates;
	}

	public function get_duration(): float {
		$end = $this->end_time ?? microtime( true );
		return round( $end - $this->start_time, 3 );
	}

	public function to_array(): array {
		return array(
			'total_rows'       => $this->total_rows,
			'processed_rows'   => $this->processed_rows,
			'inserted_rows'    => $this->inserted_rows,
			'updated_rows'     => $this->updated_rows,
			'skipped_rows'     => $this->skipped_rows,
			'errors_count'     => count( $this->errors ),
			'warnings_count'   => count( $this->warnings ),
			'duplicates_count' => count( $this->duplicates ),
			'duration_seconds' => $this->get_duration(),
			'errors'           => $this->errors,
			'warnings'         => $this->warnings,
			'duplicates'       => $this->duplicates,
		);
	}
}
