<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Explicit proxy trust only. Default ignores all forwarding headers. */
final class SecurityIpResolver {
	private array $networks = array();
	private bool $trust_all;
	public function __construct( array $trusted = array(), bool $trust_all = false ) {
		$this->trust_all = $trust_all;
		foreach ( $trusted as $item ) {
			$text = V::text( V::fallback( $item, '' ) ); $parts = explode( '/', $text ); $ip = self::normalize( $parts[0] );
			if ( '' === $ip || count( $parts ) > 2 ) { continue; } $binary = self::packed( $ip ); $bits = strlen( $binary ) * 8;
			$mask = $parts[1] ?? ( false !== strpos( $text, '.' ) ? '32' : '128' );
			if ( false !== strpos( $mask, '.' ) && 32 === $bits ) {
				$raw = @inet_pton( $mask ); if ( false === $raw ) { continue; } $pattern = ''; foreach ( unpack( 'C*', $raw ) as $byte ) { $pattern .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT ); }
				if ( preg_match( '/^1*0*$/D', $pattern ) ) { $mask = (string) substr_count( $pattern, '1' ); }
				elseif ( preg_match( '/^0*1*$/D', $pattern ) ) { $mask = (string) substr_count( $pattern, '0' ); } else { continue; }
			}
			if ( ! preg_match( '/^[0-9]+$/D', $mask ) || (int) $mask > $bits ) { continue; }
			// ip_network(host) is strict; CIDR entries explicitly use strict=False.
			if ( 1 === count( $parts ) ) { $bit_text = ''; foreach ( unpack( 'C*', $binary ) as $byte ) { $bit_text .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT ); } if ( false !== strpos( substr( $bit_text, (int) $mask ), '1' ) ) { continue; } }
			$this->networks[] = array( $binary, (int) $mask );
		}
	}
	private static function packed( string $ip ): string { return inet_pton( explode( '%', $ip, 2 )[0] ); }
	/** Literal IP normalization (allowlist); ports/brackets are not literal IPs. */
	public static function normalize( $value ): string {
		if ( null === $value || is_bool( $value ) ) { return ''; } $text = V::trim( V::string( $value ) );
		$scope = ''; if ( false !== strpos( $text, '%' ) ) { $parts = explode( '%', $text ); if ( 2 !== count( $parts ) || '' === $parts[1] || false !== strpos( $parts[1], '/' ) || false === strpos( $parts[0], ':' ) ) { return ''; } $text = $parts[0]; $scope = '%' . strtolower( $parts[1] ); }
		$packed = @inet_pton( $text ); if ( false === $packed ) { return ''; }
		if ( 4 === strlen( $packed ) ) { return inet_ntop( $packed ); }
		// Python 3.14 ipaddress renders IPv4-mapped IPv6 with a dotted suffix.
		if ( substr( $packed, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) { return '::ffff:' . inet_ntop( substr( $packed, 12 ) ) . $scope; }
		$words = array_values( unpack( 'n*', $packed ) ); $best = -1; $length = 1;
		for ( $i = 0; $i < 8; $i++ ) { if ( 0 !== $words[$i] ) { continue; } $start = $i; while ( $i < 8 && 0 === $words[$i] ) { $i++; } if ( $i - $start > $length ) { $best = $start; $length = $i - $start; } }
		$hex = array_map( 'dechex', $words );
		return ( $best < 0 ? implode( ':', $hex ) : implode( ':', array_slice( $hex, 0, $best ) ) . '::' . implode( ':', array_slice( $hex, $best + $length ) ) ) . $scope;
	}
	public static function address( string $text ): string {
		$text = V::trim( $text );
		if ( '[' === substr( $text, 0, 1 ) && false !== strpos( $text, ']' ) ) { $text = substr( $text, 1, strpos( $text, ']' ) - 1 ); }
		elseif ( 1 === substr_count( $text, ':' ) && false !== strpos( $text, '.' ) ) { $text = substr( $text, 0, strrpos( $text, ':' ) ); }
		return self::normalize( $text );
	}
	private function trusted( string $peer ): bool {
		if ( $this->trust_all ) { return true; } if ( '' === $peer ) { return false; } $binary = self::packed( $peer );
		foreach ( $this->networks as [$network, $mask] ) {
			if ( strlen( $network ) !== strlen( $binary ) ) { continue; } $bytes = intdiv( $mask, 8 ); $bits = $mask % 8;
			if ( substr( $network, 0, $bytes ) === substr( $binary, 0, $bytes ) && ( ! $bits || ( ord( $network[$bytes] ) & ( 255 << ( 8 - $bits ) ) ) === ( ord( $binary[$bytes] ) & ( 255 << ( 8 - $bits ) ) ) ) ) { return true; }
		} return false;
	}
	public function resolve( string $direct, array $headers ): string {
		$peer = self::address( $direct ); if ( ! $this->trusted( $peer ) ) { return $peer; }
		$lowered = array(); foreach ( $headers as $key => $value ) { $lowered[strtolower( $key )] = $value; }
		foreach ( explode( ',', V::string( V::fallback( $lowered['x-forwarded-for'] ?? '', '' ) ) ) as $part ) { $ip = self::address( $part ); if ( '' !== $ip ) { return $ip; } }
		$ip = self::address( $lowered['x-real-ip'] ?? '' ); if ( '' !== $ip ) { return $ip; }
		foreach ( explode( ',', $lowered['forwarded'] ?? '' ) as $segment ) { foreach ( explode( ';', $segment ) as $pair ) {
			$parts = explode( '=', $pair, 2 ); if ( 2 !== count( $parts ) || 'for' !== strtolower( V::trim( $parts[0] ) ) ) { continue; }
			$text = trim( trim( V::trim( $parts[1] ), '"' ), "'" ); if ( 0 === strpos( strtolower( $text ), 'unknown' ) ) { continue; }
			$ip = self::address( $text ); if ( '' !== $ip ) { return $ip; }
		} } return $peer;
	}
}
