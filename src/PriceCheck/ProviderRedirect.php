<?php
namespace Tapin\ServicePointLocator\PriceCheck;
defined( 'ABSPATH' ) || exit;

/** urllib's redirect-header normalization and URL joining for HTTP(S) URL components. */
final class ProviderRedirect {
	private static function parts( string $url ): array {
		$url = ltrim( str_replace( array( "\r", "\n", "\t" ), '', $url ), "\x00..\x20" ); $parsed = parse_url( $url );
		if ( false === $parsed ) { throw new \DomainException( 'This redirect URL needs an explicit Python/PHP compatibility decision.' ); }
		preg_match( '~^(?:[a-zA-Z][a-zA-Z0-9+.-]*:)?//([^/?#]*)~', $url, $authority );
		return array( 'scheme' => isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : null, 'netloc' => $authority[1] ?? null, 'path' => $parsed['path'] ?? '', 'query' => $parsed['query'] ?? null, 'fragment' => $parsed['fragment'] ?? null );
	}
	private static function assemble( array $parts ): string {
		$path = $parts['path'];
		if ( null !== $parts['netloc'] ) { $path = '//' . $parts['netloc'] . ( '' !== $path && '/' !== $path[0] ? '/' : '' ) . $path; }
		if ( $parts['scheme'] ) { $path = $parts['scheme'] . ':' . $path; }
		if ( null !== $parts['query'] ) { $path .= '?' . $parts['query']; }
		if ( null !== $parts['fragment'] ) { $path .= '#' . $parts['fragment']; } return $path;
	}
	public static function resolve( string $location, string $base ): string {
		$header = self::parts( $location );
		if ( null !== $header['netloc'] && '' !== $header['netloc'] && '' === $header['path'] ) { $header['path'] = '/'; }
		// HTTPRedirectHandler round-trips urlparse/urlunparse before percent-encoding header bytes.
		$header['path'] = preg_replace( '~(^|/)([^/;]*);$~', '$1$2', $header['path'] );
		foreach ( array( 'query', 'fragment' ) as $key ) { if ( '' === $header[$key] ) { $header[$key] = null; } }
		$location = preg_replace_callback( '/[^\x21-\x7e]/', static fn( $match ) => sprintf( '%%%02X', ord( $match[0] ) ), self::assemble( $header ) );
		if ( '' === $location ) { return $base; }
		$original = self::parts( $base ); $relative = self::parts( $location );
		$relative['scheme'] = $relative['scheme'] ?? $original['scheme'];
		if ( $relative['scheme'] !== $original['scheme'] ) { return $location; }
		if ( null !== $relative['netloc'] && '' !== $relative['netloc'] ) { return self::assemble( $relative ); }
		$relative['netloc'] = $original['netloc'];
		if ( '' === $relative['path'] ) {
			$relative['path'] = $original['path'];
			if ( null === $relative['query'] ) { $relative['query'] = $original['query']; $relative['fragment'] = $relative['fragment'] ?? $original['fragment']; }
			return self::assemble( $relative );
		}
		$base_parts = explode( '/', $original['path'] ); if ( '' !== end( $base_parts ) ) { array_pop( $base_parts ); }
		if ( '/' === $relative['path'][0] ) { $segments = explode( '/', $relative['path'] ); }
		else {
			$segments = array_merge( $base_parts, explode( '/', $relative['path'] ) );
			$segments = array_merge( array_slice( $segments, 0, 1 ), array_values( array_filter( array_slice( $segments, 1, -1 ), static fn( $part ) => '' !== $part ) ), array_slice( $segments, -1 ) );
		}
		$resolved = array(); foreach ( $segments as $segment ) { if ( '..' === $segment ) { array_pop( $resolved ); } elseif ( '.' !== $segment ) { $resolved[] = $segment; } }
		if ( in_array( end( $segments ), array( '.', '..' ), true ) ) { $resolved[] = ''; }
		$relative['path'] = implode( '/', $resolved ); if ( '' === $relative['path'] ) { $relative['path'] = '/'; } return self::assemble( $relative );
	}
}
