<?php
namespace Tapin\ServicePointLocator\Geocoding;

use Tapin\ServicePointLocator\Normalization\DataNormalizer as N;

defined( 'ABSPATH' ) || exit;

/** Validate against the same real polygons shipped with the map, including holes. */
final class IranBoundary {
	private const NAMES = array(
		'Mazandaran' => 'مازندران', 'North Khorasan' => 'خراسان شمالی', 'Kerman' => 'کرمان', 'Ilam' => 'ایلام',
		'Lorestan' => 'لرستان', 'Markazi' => 'مرکزی', 'Chaharmahal and Bakhtiari' => 'چهارمحال و بختیاری',
		'Kermanshah' => 'کرمانشاه', 'Hamadan' => 'همدان', 'Qazvin' => 'قزوین', 'Gilan' => 'گیلان', 'Zanjan' => 'زنجان',
		'Semnan' => 'سمنان', 'Isfahan' => 'اصفهان', 'Kohgiluyeh and Boyer-Ahmad' => 'کهگیلویه و بویراحمد',
		'Kurdistan' => 'کردستان', 'West Azerbaijan' => 'آذربایجان غربی', 'Fars' => 'فارس', 'Bushehr' => 'بوشهر',
		'Ardabil' => 'اردبیل', 'Golestan' => 'گلستان', 'Razavi Khorasan' => 'خراسان رضوی', 'South Khorasan' => 'خراسان جنوبی',
		'Sistan and Baluchestan' => 'سیستان و بلوچستان', 'Qom' => 'قم', 'Alborz' => 'البرز', 'East Azerbaijan' => 'آذربایجان شرقی',
		'Yazd' => 'یزد', 'Hormozgan' => 'هرمزگان', 'Khuzestan' => 'خوزستان', 'Tehran' => 'تهران',
	);
	public static function key( string $label ): string {
		$label = preg_replace( '/^(استان|شهر)\s+/u', '', N::normalize_location( $label ) );
		return preg_replace( '/\s+/u', '', $label );
	}
	public static function province( float $latitude, float $longitude ): ?string {
		static $features;
		if ( null === $features ) {
			$path = TAPIN_PLUGIN_DIR . 'assets/iran-provinces.geojson';
			$data = is_readable( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
			$features = $data['features'] ?? array();
		}
		foreach ( $features as $feature ) {
			$geometry = $feature['geometry'];
			$polygons = 'MultiPolygon' === $geometry['type'] ? $geometry['coordinates'] : array( $geometry['coordinates'] );
			foreach ( $polygons as $rings ) {
				if ( ! self::inside( $longitude, $latitude, $rings[0] ) ) { continue; }
				foreach ( array_slice( $rings, 1 ) as $hole ) { if ( self::inside( $longitude, $latitude, $hole ) ) { continue 2; } }
				return self::NAMES[$feature['properties']['shapeName']] ?? null;
			}
		}
		return null;
	}
	private static function inside( float $x, float $y, array $ring ): bool {
		$inside = false;
		for ( $i = 0, $j = count( $ring ) - 1; $i < count( $ring ); $j = $i++ ) {
			list( $xi, $yi ) = $ring[$i]; list( $xj, $yj ) = $ring[$j];
			if ( ( ( $yi > $y ) !== ( $yj > $y ) ) && $x < ( $xj - $xi ) * ( $y - $yi ) / ( $yj - $yi ) + $xi ) { $inside = ! $inside; }
		}
		return $inside;
	}
}
