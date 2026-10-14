<?php

namespace Tapin\ServicePointLocator\PriceCheck;

use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;

defined( 'ABSPATH' ) || exit;

/** Merchant-origin configuration. Credential-bearing reads remain private server-side operations. */
final class ShopStorage {
	/** The source import preview uses this same pure cleaner before any storage writes. */
	public static function clean_row( $raw ): array { return self::clean( $raw ); }
	private static function text( $value ): string { return V::trim( V::string( V::fallback( $value, '' ) ) ); }
	private static function nullable( $value ): ?string { $text = self::text( $value ); return '' === $text ? null : $text; }

	/** storage.clean_shop_row; validate the complete batch before issuing any writes. */
	private static function clean( $raw ): array {
		if ( ! V::mapping( $raw ) ) { throw new \InvalidArgumentException( 'Shop must be a mapping.' ); }
		$id = V::get( $raw, 'id' );
		if ( is_bool( $id ) ) { throw new \InvalidArgumentException( 'شناسه شعبه الزامی است.' ); }
		if ( is_int( $id ) ) { $id = V::string( $id ); }
		elseif ( is_float( $id ) ) {
			if ( ! is_finite( $id ) || floor( $id ) !== $id ) { throw new \InvalidArgumentException( 'شناسه شعبه معتبر نیست.' ); }
			$id = V::string( V::integer( $id ) );
		} else { $id = self::text( $id ); }
		if ( '' === $id ) { throw new \InvalidArgumentException( 'شناسه شعبه الزامی است.' ); }
		$title = self::text( V::get( $raw, 'title' ) );
		if ( '' === $title ) { throw new \InvalidArgumentException( 'عنوان شعبه الزامی است.' ); }
		$private = V::get( $raw, 'token' );
		if ( is_bool( $private ) ) { throw new \InvalidArgumentException( 'توکن شعبه الزامی است.' ); }
		$private = self::text( $private );
		if ( '' === $private ) { throw new \InvalidArgumentException( 'توکن شعبه الزامی است.' ); }
		return array( 'id' => $id, 'title' => $title, 'province' => self::nullable( V::get( $raw, 'province' ) ), 'city' => self::nullable( V::get( $raw, 'city' ) ), 'token' => $private );
	}

	public static function list_rows( $province = null, $city = null ): array {
		$sql = 'SELECT id, title, province, city, (token IS NOT NULL AND token <> \'\') AS has_token FROM ' . ConfigurationDatabase::table( 'shops' );
		$clauses = $values = array();
		foreach ( array( 'province' => $province, 'city' => $city ) as $key => $value ) {
			if ( V::truth( $value ) ) { $clauses[] = "{$key} = %s"; $values[] = $value; }
		}
		if ( $clauses ) { $sql .= ' WHERE ' . implode( ' AND ', $clauses ); }
		return ConfigurationDatabase::shop_rows( $sql . ' ORDER BY id', $values );
	}

	/** No route exposes this method. Future admin reveals must use Access and WP nonce checks. */
	public static function get_private( $shop_id ): ?array {
		$rows = ConfigurationDatabase::shop_rows( 'SELECT * FROM ' . ConfigurationDatabase::table( 'shops' ) . ' WHERE id = %s', array( V::trim( V::string( $shop_id ) ) ) );
		return $rows[0] ?? null;
	}

	public static function save( array $rows ): int {
		$cleaned = array_map( array( self::class, 'clean' ), $rows );
		foreach ( $cleaned as $row ) { ConfigurationDatabase::upsert( 'shops', $row, array( 'title', 'province', 'city', 'token' ) ); }
		return count( $cleaned );
	}

	/** Provider shop_id references intentionally survive a shop deletion, as in the source. */
	public static function delete( $shop_id ): bool {
		return ConfigurationDatabase::delete( 'shops', array( 'id' => V::trim( V::string( $shop_id ) ) ) ) > 0;
	}
}
