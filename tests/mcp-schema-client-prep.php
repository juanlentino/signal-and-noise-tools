<?php
/**
 * Standalone tests for sn_mcp_normalize_schema()'s WP 7.1 delegation seam:
 * when core's wp_prepare_json_schema_for_client() exists it runs FIRST, and
 * our provider-specific fixes (scalar type 'object', top-level combinator
 * strip, empty-properties {}) still apply after it. Pre-7.1 (function absent)
 * behavior is asserted before the stub is defined, in the same process.
 *
 * The stub models the real core transform (per the 7.1 dev note): strips
 * sanitize_callback / validate_callback / arg_options recursively, hoists
 * property-level 'required' => true into a Draft-4 required array, and
 * leaves everything else alone.
 *
 * @since plugin v10.38.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "ok  - $label\n"; }
	else { $fail++; echo "FAIL - $label\n"; }
}

require dirname( __DIR__ ) . '/inc/mcp/mcp-tools.php';

// ---------------------------------------------------------------------------
// Pre-7.1: core function absent — current behavior byte-identical.
// ---------------------------------------------------------------------------
ok( ! function_exists( 'wp_prepare_json_schema_for_client' ), 'precondition: core prep function absent (pre-7.1 path)' );
$in  = array(
	'type'  => array( 'object', 'null' ),
	'anyOf' => array( array( 'required' => array( 'post_id' ) ), array( 'required' => array( 'slug' ) ) ),
	'properties' => array(),
);
$out = sn_mcp_normalize_schema( $in );
ok( 'object' === $out['type'], 'pre-7.1: union type collapsed to scalar object' );
ok( ! isset( $out['anyOf'] ), 'pre-7.1: top-level anyOf stripped' );
ok( $out['properties'] instanceof stdClass, 'pre-7.1: empty properties becomes {}' );

// A property carrying a server-only keyword survives untouched pre-7.1 (we
// never stripped these ourselves — that is exactly what core adds).
$in2  = array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ) ) );
$out2 = sn_mcp_normalize_schema( $in2 );
ok( isset( $out2['properties']['title']['sanitize_callback'] ), 'pre-7.1: server-only keywords pass through (no core prep to remove them)' );

// ---------------------------------------------------------------------------
// 7.1+: define a faithful model of the core function, then re-run.
// ---------------------------------------------------------------------------
$GLOBALS['__prep_calls'] = 0;
// Wrapped in a conditional block so PHP defines the stub at RUNTIME (an
// unconditional top-level declaration is hoisted at compile time, which would
// falsify the pre-7.1 assertions above).
if ( ! function_exists( 'wp_prepare_json_schema_for_client' ) ) {
function wp_prepare_json_schema_for_client( $schema, $schema_profile = 'draft-04' ) {
	$GLOBALS['__prep_calls']++;
	$strip = function ( $node ) use ( &$strip ) {
		if ( ! is_array( $node ) ) { return $node; }
		unset( $node['sanitize_callback'], $node['validate_callback'], $node['arg_options'] );
		if ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
			$required = isset( $node['required'] ) && is_array( $node['required'] ) ? $node['required'] : array();
			foreach ( $node['properties'] as $key => $prop ) {
				if ( is_array( $prop ) && isset( $prop['required'] ) && true === $prop['required'] ) {
					$required[] = $key;
					unset( $prop['required'] );
				}
				$node['properties'][ $key ] = $strip( $prop );
			}
			if ( array() !== $required ) { $node['required'] = array_values( array_unique( $required ) ); }
		}
		foreach ( array( 'items', 'not' ) as $k ) {
			if ( isset( $node[ $k ] ) ) { $node[ $k ] = $strip( $node[ $k ] ); }
		}
		foreach ( array( 'anyOf', 'allOf', 'oneOf' ) as $k ) {
			if ( isset( $node[ $k ] ) && is_array( $node[ $k ] ) ) { $node[ $k ] = array_map( $strip, $node[ $k ] ); }
		}
		return $node;
	};
	return $strip( $schema );
}
}

$out3 = sn_mcp_normalize_schema( $in2 );
ok( 1 === $GLOBALS['__prep_calls'], '7.1: core prep called exactly once per normalize' );
ok( ! isset( $out3['properties']['title']['sanitize_callback'] ), '7.1: server-only keywords stripped by delegated core prep' );

// Property-level required hoisted by core, and our fixes still apply on top.
$in4  = array(
	'type'  => array( 'object', 'null' ),
	'oneOf' => array( array( 'required' => array( 'a' ) ) ),
	'properties' => array(
		'a' => array( 'type' => 'string', 'required' => true, 'validate_callback' => '__return_true' ),
	),
);
$out4 = sn_mcp_normalize_schema( $in4 );
ok( array( 'a' ) === ( $out4['required'] ?? null ), '7.1: property-level required hoisted to Draft-4 array' );
ok( ! isset( $out4['properties']['a']['validate_callback'] ), '7.1: nested validate_callback stripped' );
ok( 'object' === $out4['type'], '7.1: our scalar-object fix still applied after core prep' );
ok( ! isset( $out4['oneOf'] ), '7.1: our top-level combinator strip still applied after core prep' );

// Empty/non-array degenerate input keeps its historical shape.
$out5 = sn_mcp_normalize_schema( array() );
ok( array( 'type' => 'object' ) === $out5, '7.1: empty schema still normalizes to {type:object} without calling core prep on junk' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
