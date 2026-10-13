<?php
namespace Tapin\ServicePointLocator\PriceCheck;
use Tapin\ServicePointLocator\PriceCheck\PythonValue as V;
defined( 'ABSPATH' ) || exit;

/** Presentation-only options/input metadata from api.py::_bulk_fields_with_kinds. */
final class BulkSchemaFields {
	private static function param( $ref, array $providers ) {
		$id = BulkConfig::field_provider_id( $ref, $providers ); foreach ( $providers as $provider ) { if ( $provider->id === $id ) { $target = ProviderDefinition::target( $provider, V::get( $ref, 'param' ) ); foreach ( ProviderDefinition::params( $provider ) as $param ) { if ( V::get( $param, 'name' ) === $target ) { return $param; } } } } return null;
	}
	public static function fields( $config, array $providers, array $active ): array {
		$fields = BulkMapping::fields_with_kinds( $config, $providers, $active );
		foreach ( $fields as $field ) {
			$options = $seen = $params = array(); $mirror = self::param( V::get( $field, 'mirror' ), $providers ); $source = self::param( V::get( $field, 'source' ), $providers );
			if ( null !== $mirror ) { $params[] = $mirror; $field->input = V::fallback( V::get( $field, 'input' ), 'text' ); if ( in_array( V::get( $mirror, 'input' ), array( 'text', 'number', 'select' ), true ) ) { $field->input = $mirror->input; } }
			elseif ( null !== $source ) { $params[] = $source; }
			else { foreach ( $active as $provider ) { $target = V::get( BulkConfig::mappings_for( $config, $provider->id ), $field->name ); $resolved = V::truth( $target ) ? ProviderDefinition::target( $provider, $target ) : null; if ( null !== $resolved && false === strpos( $resolved, '.' ) ) { $param = ProviderDefinition::find_param( $provider, $resolved ); if ( null !== $param ) { $params[] = $param; } } } }
			foreach ( $params as $param ) { if ( 'select' === V::get( $param, 'input' ) ) { foreach ( ProviderSchema::options( $param ) as $option ) { if ( ! in_array( $option->value, $seen, true ) ) { $seen[] = $option->value; $options[] = $option; } } } }
			$field->options = $options; if ( null !== $mirror ) { $field->mirror_param = (object) array( 'input' => V::get( $mirror, 'input' ), 'kind' => V::get( $mirror, 'kind' ), 'options' => $options ); }
		} return $fields;
	}
}
