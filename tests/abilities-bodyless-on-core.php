<?php
/**
 * A bodyless ability call, on core's own input path.
 *
 * Abilities that take no required input declare core's idiom: 'type' =>
 * 'object' with a top-level 'default' => array() (2026-11-10, the core-first
 * audit's finding 10), in place of the array( 'object', 'null' ) type union
 * they carried before. WP_Ability::normalize_input() swaps a null input for
 * that default before validation, in the REST run controller and in
 * execute() alike, so the callback receives an array.
 *
 * This suite proves it on core's REAL code, never a stub: rest-api.php's
 * rest_validate_value_from_schema() behind WP_Ability::normalize_input() and
 * validate_input(), plus prepare_input() (which also sanitizes) on 7.2 and
 * later, all loaded from the core dir SNT_WP_HTML_API points into. The
 * schemas are the plugin's real registrations, captured from the inc/ files.
 *
 * Five inputs that mean "nothing, or almost nothing", each must validate:
 * no input at all; an explicit null (a GET or DELETE with no ?input=, a POST
 * with no body); {} (an MCP client's empty arguments decoded as an object);
 * [] (the same decoded as an array); and a valid object built from the
 * schema's own property defaults.
 *
 * The control: the same schema without its default must reject null. Were it
 * to accept it, this suite would prove nothing about the default.
 *
 * Run: SNT_WP_HTML_API=<wp>/wp-includes/html-api php tests/abilities-bodyless-on-core.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }

require __DIR__ . '/lib/wp-html-api.php';
snt_test_require_core( dirname( snt_test_html_api_dir() ), array( 'class-wp-error.php', 'plugin.php', 'rest-api.php', 'json-schema.php', 'abilities-api/class-wp-ability.php' ) );

// The only stand-ins: translation, the error test, and the one functions.php
// helper core's client prep reads. Validation itself is core's.
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'wp_is_numeric_array' ) ) {
	function wp_is_numeric_array( $data ) {
		return is_array( $data ) && 0 === count( array_filter( array_keys( $data ), 'is_string' ) );
	}
}
$GLOBALS['__bc_wrong'] = array();
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $fn, $msg, $ver ) { $GLOBALS['__bc_wrong'][] = "$fn: $msg"; }
}

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

// Capture the real registrations: core's own hook API fires the inc/ files'
// wp_abilities_api_init closures, and this records what each one registers.
$GLOBALS['__bc_abilities'] = array();
function wp_register_ability( $slug, $args ) { $GLOBALS['__bc_abilities'][ $slug ] = $args; }

// A spread of the shapes the change touched: empty properties, rich and nested
// properties (sn-posts), a string|integer property union (the analytics
// sections, registered in a loop), write abilities (purge-all-caches,
// corpus-integrity-scan, anchor-sweep, the jev passes), and admin/remote twin
// pairs (analytics-fields, the remote set).
$bc_files = array(
	'abilities-watches.php',
	'abilities-sn-posts.php',
	'abilities-jev.php',
	'analytics-fields.php',
	'abilities-provenance.php',
	'abilities-corpus-integrity.php',
	'abilities-system-register-parts.php',
	'uptime-status.php',
	'abilities-analytics-sections.php',
	'abilities-remote-set-parts.php',
	'mcp/mcp-tools-schema-parts.php',
);
foreach ( $bc_files as $bc_f ) {
	require_once __DIR__ . '/../inc/' . $bc_f;
}
do_action( 'wp_abilities_api_init' );

/** A real WP_Ability carrying a name and an input schema, nothing else. */
function bc_ability( $slug, array $schema ) {
	$r = new ReflectionClass( 'WP_Ability' );
	$a = $r->newInstanceWithoutConstructor();
	$r->getProperty( 'name' )->setValue( $a, $slug );
	$r->getProperty( 'input_schema' )->setValue( $a, $schema );
	return $a;
}

/** core's path for one input: normalize then validate (7.1), or prepare (7.2+). */
function bc_run( WP_Ability $a, $input, $no_input = false ) {
	if ( method_exists( $a, 'prepare_input' ) ) {
		return $no_input ? $a->prepare_input() : $a->prepare_input( $input );
	}
	$n = $no_input ? $a->normalize_input() : $a->normalize_input( $input );
	if ( is_wp_error( $n ) ) {
		return $n;
	}
	$v = $a->validate_input( $n );
	return is_wp_error( $v ) ? $v : $n;
}

/** An object of the schema's own property defaults: valid by the author's word. */
function bc_valid_object( array $schema ) {
	$out = array();
	foreach ( (array) ( $schema['properties'] ?? array() ) as $k => $p ) {
		if ( is_array( $p ) && array_key_exists( 'default', $p ) ) {
			$out[ $k ] = $p['default'];
		}
	}
	return $out;
}

echo 'abilities-bodyless-on-core suite (core ' . ( method_exists( 'WP_Ability', 'prepare_input' ) ? 'with prepare_input, 7.2+' : 'normalize + validate, 7.1' ) . ")\n";

$bc_tested = array();
foreach ( $GLOBALS['__bc_abilities'] as $slug => $args ) {
	$schema = $args['input_schema'] ?? null;
	if ( ! is_array( $schema ) || ! array_key_exists( 'default', $schema ) ) {
		continue;
	}
	$bc_tested[] = $slug;
	ok( 'object' === ( $schema['type'] ?? null ) && array() === $schema['default'], "$slug: input is 'object' with 'default' => array()" );
	$a     = bc_ability( $slug, $schema );
	$valid = bc_valid_object( $schema );
	$cases = array(
		'no input'           => array( null, true ),
		'null'               => array( null, false ),
		'{}'                 => array( new stdClass(), false ),
		'[]'                 => array( array(), false ),
		'a valid object ' . json_encode( (object) $valid ) => array( $valid, false ),
	);
	foreach ( $cases as $label => $case ) {
		$got = bc_run( $a, $case[0], $case[1] );
		ok( ! is_wp_error( $got ), "$slug: $label validates" . ( is_wp_error( $got ) ? ' [' . $got->get_error_message() . ']' : '' ) );
	}
	$none = bc_run( $a, null );
	ok( array() === $none, "$slug: a null input reaches the callbacks as array(), the default" );
	// Not loosened: additionalProperties false still refuses an unknown key.
	if ( false === ( $schema['additionalProperties'] ?? null ) ) {
		ok( is_wp_error( bc_run( $a, array( 'bc_not_a_key' => 1 ) ) ), "$slug: an unknown key is still refused" );
	}
	// The control: without the default, core rejects the very null it accepted.
	$bare = $schema;
	unset( $bare['default'] );
	ok( is_wp_error( bc_run( bc_ability( $slug, $bare ), null ) ), "$slug: control, the same schema without its default rejects null" );
}

echo "\nCoverage\n";
ok( count( $bc_tested ) >= 35, 'the capture exercised the representative set (got ' . count( $bc_tested ) . ' abilities)' );
foreach ( array( 'signal-noise/watches', 'signal-noise/sn-posts', 'signal-noise/purge-all-caches', 'signal-noise/get-deploy-status', 'signal-noise/analytics-query', 'signal-noise/remote-analytics-fields', 'signal-noise/remote-uptime-status', 'signal-noise/anchor-sweep' ) as $must ) {
	ok( in_array( $must, $bc_tested, true ), "$must is among them" );
}
ok( array() === $GLOBALS['__bc_wrong'], 'core raised no _doing_it_wrong while validating' . ( $GLOBALS['__bc_wrong'] ? ' [' . implode( '; ', array_unique( $GLOBALS['__bc_wrong'] ) ) . ']' : '' ) );

// What both MCP doors are handed: our door projects through core's client
// prep and sn_mcp_normalize_schema(), and the default must reach the wire as
// {} (an object, matching type "object"), never as [].
echo "\nThe advertised inputSchema\n";
$bc_wire = json_encode( sn_mcp_normalize_schema( $GLOBALS['__bc_abilities']['signal-noise/watches']['input_schema'] ) );
ok( '{"type":"object","default":{},"properties":{},"additionalProperties":false}' === $bc_wire, "our door advertises watches as $bc_wire" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
