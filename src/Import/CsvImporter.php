<?php

namespace Tapin\ServicePointLocator\Import;

defined( 'ABSPATH' ) || exit;

/**
 * High-performance streaming CSV parser with Persian/UTF-8 BOM support.
 */
class CsvImporter {

	/**
	 * Detects delimiter by inspecting first non-empty lines.
	 */
	public static function detect_delimiter( string $file_path ): string {
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			return ',';
		}

		$delimiters = array( ',', ';', "\t" );
		$scores     = array( ',' => 0, ';' => 0, "\t" => 0 );

		for ( $i = 0; $i < 5 && ! feof( $handle ); $i++ ) {
			$line = fgets( $handle );
			if ( false === $line || '' === trim( $line ) ) {
				continue;
			}
			foreach ( $delimiters as $delim ) {
				$scores[ $delim ] += count( str_getcsv( $line, $delim ) );
			}
		}

		fclose( $handle );

		arsort( $scores );
		return array_key_first( $scores ) ?: ',';
	}

	/**
	 * Reads and yields CSV records in batches to keep memory usage flat.
	 *
	 * @param string $file_path
	 * @param int $batch_size
	 * @return \Generator<int, array{headers: array<int, string>, rows: array<int, array{row_index: int, data: array<string, string>}>}>
	 */
	public static function stream_batches( string $file_path, int $batch_size = 100 ): \Generator {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new \InvalidArgumentException( 'CSV file does not exist or is not readable: ' . $file_path );
		}

		$delimiter = self::detect_delimiter( $file_path );
		$handle    = fopen( $file_path, 'r' );

		if ( ! $handle ) {
			throw new \RuntimeException( 'Unable to open CSV file: ' . $file_path );
		}

		// Read header line.
		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( false === $headers || empty( $headers ) ) {
			fclose( $handle );
			return;
		}

		// Strip UTF-8 BOM from the first header if present.
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
		$headers    = array_map( 'trim', $headers );

		$header_count = count( $headers );
		$current_rows = array();
		$row_number   = 1; // 1-based data row counter (header was row 0).

		while ( ( $raw_line = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$row_number++;

			// Skip completely empty lines.
			if ( 1 === count( $raw_line ) && null === $raw_line[0] ) {
				continue;
			}

			$row_data = array();
			foreach ( $headers as $index => $header_name ) {
				$row_data[ $header_name ] = isset( $raw_line[ $index ] ) ? trim( (string) $raw_line[ $index ] ) : '';
			}

			$current_rows[] = array(
				'row_index' => $row_number,
				'data'      => $row_data,
			);

			if ( count( $current_rows ) >= $batch_size ) {
				yield array(
					'headers' => $headers,
					'rows'    => $current_rows,
				);
				$current_rows = array();
			}
		}

		fclose( $handle );

		if ( ! empty( $current_rows ) ) {
			yield array(
				'headers' => $headers,
				'rows'    => $current_rows,
			);
		}
	}
}
