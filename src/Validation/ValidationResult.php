<?php

namespace Tapin\ServicePointLocator\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * Value object representing the outcome of data validation.
 * Distinguishes fatal validation errors from non-blocking warnings.
 */
final class ValidationResult {

	/**
	 * @var array<string, string> Field-keyed fatal validation errors.
	 */
	private array $errors = array();

	/**
	 * @var array<string, string> Field-keyed non-blocking warnings.
	 */
	private array $warnings = array();

	/**
	 * Adds a fatal error.
	 */
	public function add_error( string $field, string $message ): self {
		$this->errors[ $field ] = $message;
		return $this;
	}

	/**
	 * Adds a non-blocking warning.
	 */
	public function add_warning( string $field, string $message ): self {
		$this->warnings[ $field ] = $message;
		return $this;
	}

	/**
	 * Returns true if there are zero fatal errors.
	 */
	public function is_valid(): bool {
		return empty( $this->errors );
	}

	/**
	 * Returns true if any fatal errors exist.
	 */
	public function has_errors(): bool {
		return ! empty( $this->errors );
	}

	/**
	 * Returns true if any warnings exist.
	 */
	public function has_warnings(): bool {
		return ! empty( $this->warnings );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * @return array<string, string>
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}

	/**
	 * Serializes result to array.
	 */
	public function to_array(): array {
		return array(
			'is_valid' => $this->is_valid(),
			'errors'   => $this->errors,
			'warnings' => $this->warnings,
		);
	}
}
