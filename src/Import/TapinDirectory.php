<?php
namespace Tapin\ServicePointLocator\Import;

use Tapin\ServicePointLocator\Normalization\DataNormalizer as N;

defined( 'ABSPATH' ) || exit;

/** Reviewed official directory snapshots, never a coordinate or geocoding source. */
final class TapinDirectory {
	public const INDEX_URL = 'https://tapin.ir/map/';

	/** Discover documents from the official page. No third-party URLs are accepted. */
	public static function discover( string $html ): array {
		preg_match_all( '~href\s*=\s*["\']([^"\']+\.pdf\s*)["\']~i', $html, $matches );
		$urls = array();
		foreach ( $matches[1] as $href ) {
			$url = 0 === strpos( trim( $href ), 'https://' ) ? trim( $href ) : self::INDEX_URL . ltrim( trim( $href ), '/' );
			if ( preg_match( '~^https://tapin\.ir/map/[a-z0-9-]+\.pdf$~D', $url ) ) { $urls[] = $url; }
		}
		return array_values( array_unique( $urls ) );
	}

	/** The existing reviewed Semnan snapshot remains the baseline; operators can add reviewed provinces. */
	public static function records(): array {
		static $rows;
		if ( null !== $rows ) { return $rows; }
		$rows = array();
		$headers = null;
		foreach ( TableReader::rows( TAPIN_PLUGIN_DIR . 'assets/data/post-semnan.csv', 'csv' ) as $row ) {
			if ( null === $headers ) { $headers = $row; continue; }
			if ( count( $headers ) === count( $row ) ) { $rows[] = N::normalize_service_point( array_combine( $headers, $row ) ); }
		}
		$reviewed = json_decode( file_get_contents( TAPIN_PLUGIN_DIR . 'assets/data/tapin-tehran-reviewed.json' ), true );
		foreach ( $reviewed['candidates'] ?? array() as $row ) { $rows[] = N::normalize_service_point( $row ); }
		$snapshot = get_option( 'tapin_directory_snapshot', array() );
		$sources = array_column( $snapshot, 'source' );
		$rows = array_values( array_filter( $rows, static fn( $row ) => ! in_array( $row['source'], $sources, true ) ) );
		foreach ( $snapshot as $row ) { $rows[] = N::normalize_service_point( $row ); }
		return $rows;
	}

	/** Match only against reviewed evidence. Preserve uploaded values, contacts and coordinates. */
	public static function reconcile( array $data, array $records ): array {
		if ( isset( $data['metadata'] ) && ! is_array( $data['metadata'] ) ) { return $data; } // Validator owns malformed metadata.
		$fields = array( 'name', 'province', 'city', 'address', 'postal_code', 'landline_phone' );
		$compatible = static function( $a, $b ) {
			foreach ( array( 'province', 'city' ) as $key ) {
				if ( ! empty( $a[$key] ) && ! empty( $b[$key] ) && self::key( $a[$key] ) !== self::key( $b[$key] ) ) { return false; }
			}
			return true;
		};
		$matches = array(); $signal = null;
		foreach ( array( 'postal_code', 'landline_phone', 'name' ) as $field ) {
			if ( empty( $data[$field] ) ) { continue; }
			if ( 'postal_code' === $field && ! preg_match( '/^\d{10}$/D', $data[$field] ) ) { continue; }
			if ( 'landline_phone' === $field && ! preg_match( '/^0[1-8]\d{9}$/D', $data[$field] ) ) { continue; }
			$matches = array_filter( $records, static function( $row ) use ( $data, $field, $compatible ) {
				if ( empty( $row['source'] ) || ! preg_match( '~^https://tapin\.ir/map/[a-z0-9-]+\.pdf$~D', $row['source'] ) ) { return false; }
				if ( self::key( $data[$field] ) !== self::key( $row[$field] ?? '' ) ) { return false; }
				return 'postal_code' === $field || ( ! empty( $data['province'] ) && $compatible( $data, $row ) );
			} );
			if ( $matches ) { $signal = $field; break; }
		}
		// Identical source entries do not make a deterministic match ambiguous.
		$unique = array();
		foreach ( $matches as $row ) { $unique[md5( wp_json_encode( array_intersect_key( $row, array_flip( $fields ) ) ) )] = $row; }
		$matches = array_values( $unique );
		$result = 'not_found'; $official = null;
		if ( count( $matches ) > 1 ) { $result = 'conflict'; }
		elseif ( count( $matches ) === 1 ) {
			$official = $matches[0];
			$result = ! $compatible( $data, $official ) ? 'conflict' : ( 'name' === $signal ? 'probable_match' : 'verified' );
		}
		$evidence = array( 'result' => $result, 'signal' => $signal, 'source_provider' => 'tapin', 'source_type' => 'official_postal_directory', 'reconciled_at' => gmdate( 'c' ), 'scope' => 'reviewed_snapshot', 'uploaded' => array_intersect_key( $data, array_flip( $fields ) ) );
		if ( $official ) {
			$evidence['source_url'] = $official['source'];
			$evidence['source_checked_at'] = $official['metadata']['source_checked_at'] ?? '2026-09-27';
			$evidence['official'] = array_intersect_key( $official, array_flip( $fields ) );
			if ( 'verified' === $result ) {
				foreach ( $fields as $field ) { if ( empty( $data[$field] ) && ! empty( $official[$field] ) ) { $data[$field] = $official[$field]; } }
			}
		}
		$data['metadata']['tapin_reconciliation'] = $evidence;
		return $data;
	}

	private static function key( string $value ): string {
		return preg_replace( '/[\s\x{200c}]+/u', '', N::to_latin_digits( N::normalize_persian_text( $value ) ?? '' ) );
	}
}
