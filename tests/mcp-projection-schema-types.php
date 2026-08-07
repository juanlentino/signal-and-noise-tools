<?php
/**
 * Signal & Noise Tools — projection-round-trip coverage for the CONSOLIDATED
 * MCP tools' input schemas. Standing regression guard for the class of bug
 * this file's fix session found (v10.41.1): `signal-noise/sn-apply`'s
 * `target` property declared its object/array union as a nested `oneOf`
 * with NO top-level `type` key at all — `oneOf` was its only key. That
 * shape survives this codebase's OWN sn_mcp_normalize_schema() untouched
 * (it strips oneOf/allOf/anyOf at the schema ROOT only, by design — see
 * that function's own comment), but at least one real MCP client facing an
 * untyped parameter serializes whatever it's given as a JSON STRING rather
 * than structured JSON — confirmed live, first real call: every sn_apply
 * call failed input validation with "input[target][0] is not of type
 * object" regardless of what the caller sent.
 *
 * This test does what no test before it did: run the REAL, registered
 * ability's REAL input_schema through the REAL sn_mcp_project_tool() and
 * assert every property the projection actually ships carries a usable
 * `type` — not a hand-written fixture schema that only ever modeled the
 * happy path. It would have caught `target` before ship. Run over all five
 * CONSOLIDATED tools (sn_posts, sn_site_facts, sn_scan, sn_validate,
 * sn_apply — sn_mcp_capabilities.php's own "the fifth CONSOLIDATED tool"
 * count, not the six a stale/rounded-up brief may have named) in one loop,
 * so the next union-typed property fails closed instead of shipping quiet.
 *
 * Sweep result (this session): `signal-noise/sn-validate`'s `checks`
 * property had the IDENTICAL shape (nested oneOf, no fallback type) — found
 * by this sweep, not independently reported live, and fixed alongside
 * target in the same session (inc/abilities-sn-validate.php).
 *
 * @since plugin v10.41.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $msg\n"; }
	else { $fail++; echo "FAIL: $msg\n"; }
}

/* ════════════════════════════════════════════════════════════════════════
 * Minimal WP + abilities-registry stubs — real registration, not fixtures.
 * ════════════════════════════════════════════════════════════════════════ */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $x ) { return $x instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); } }

$GLOBALS['__acts'] = array();
if ( ! function_exists( 'add_action' ) ) { function add_action( $tag, $cb, $p = 10, $a = 1 ) { $GLOBALS['__acts'][ $tag ][] = $cb; } }

$GLOBALS['__ab'] = array();
if ( ! function_exists( 'wp_register_ability' ) ) { function wp_register_ability( $slug, $args ) { $GLOBALS['__ab'][ $slug ] = $args; return true; } }

// A thin wrapper exposing exactly what sn_mcp_project_tool() reads —
// mirrors tests/mcp-tools.php's SN_Test_Ability, but built directly from
// whatever wp_register_ability() actually received (no hand-authored
// fixture schema to drift from the real one).
class SN_Test_Projected_Ability {
	private $args;
	public function __construct( array $args ) { $this->args = $args; }
	public function get_name() { return (string) ( $this->args['label'] ?? '' ); }
	public function get_label() { return (string) ( $this->args['label'] ?? '' ); }
	public function get_description() { return (string) ( $this->args['description'] ?? '' ); }
	public function get_input_schema() { return $this->args['input_schema'] ?? array(); }
	public function get_output_schema() { return $this->args['output_schema'] ?? array(); }
	public function get_meta() { return $this->args['meta'] ?? array(); }
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $slug ) {
		return isset( $GLOBALS['__ab'][ $slug ] ) ? new SN_Test_Projected_Ability( $GLOBALS['__ab'][ $slug ] ) : null;
	}
}

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';

// The five CONSOLIDATED tools (inc/mcp/mcp-capabilities.php's own comment:
// sn-apply is "the fifth CONSOLIDATED tool" — there is no sixth registered
// anywhere in inc/; sn_dismiss is still unbuilt per docs/mcp-consolidation/
// FINDINGS.md). Each file is self-contained at registration time (own
// top-of-file constants only) — verified by reading every one before this
// test was written, not assumed.
require __DIR__ . '/../inc/abilities-sn-posts.php';
require __DIR__ . '/../inc/abilities-sn-site-facts.php';
require __DIR__ . '/../inc/abilities-sn-scan.php';
require __DIR__ . '/../inc/abilities-sn-validate.php';
require __DIR__ . '/../inc/sn-apply-executors.php'; // SNT_SN_APPLY_CHANGE_TYPES, referenced eagerly by the registration array below.
require __DIR__ . '/../inc/abilities-sn-apply.php';

foreach ( $GLOBALS['__acts']['wp_abilities_api_init'] ?? array() as $cb ) {
	$cb();
}

$consolidated_slugs = array(
	'signal-noise/sn-posts',
	'signal-noise/sn-site-facts',
	'signal-noise/sn-scan',
	'signal-noise/sn-validate',
	'signal-noise/sn-apply',
);

echo "MCP projection schema-type sweep — the five consolidated tools\n\n";

foreach ( $consolidated_slugs as $slug ) {
	ok( isset( $GLOBALS['__ab'][ $slug ] ), "$slug: registered" );
}

/**
 * Does $node carry a "usable type" for MCP projection purposes: a concrete
 * `type` (scalar string, or a non-empty array union — both survive
 * sn_mcp_normalize_schema() untouched at any depth below the root) OR an
 * `enum` (a fixed value list is self-describing even without `type`). A
 * node whose ONLY type-bearing keys are oneOf/anyOf/allOf fails this check —
 * that is exactly the shape that shipped `target` as `{}`.
 *
 * @param array $node
 * @return bool
 */
function snt_test_schema_node_has_usable_type( array $node ) {
	if ( isset( $node['type'] ) ) {
		if ( is_string( $node['type'] ) && '' !== $node['type'] ) { return true; }
		if ( is_array( $node['type'] ) && ! empty( $node['type'] ) ) { return true; }
	}
	if ( isset( $node['enum'] ) && is_array( $node['enum'] ) && ! empty( $node['enum'] ) ) { return true; }
	return false;
}

/**
 * Recursively walk a projected schema node, collecting a 'path => reason'
 * violation for every node that is combinator-only (oneOf/anyOf/allOf with
 * no usable type of its own) — then recurse into properties/items/each
 * combinator branch so a violation buried deeper than one level is still
 * caught.
 *
 * @param mixed  $node
 * @param string $path
 * @param array  $violations
 * @return void
 */
function snt_test_walk_schema_for_untyped_combinators( $node, $path, array &$violations ) {
	if ( ! is_array( $node ) ) {
		return;
	}

	$has_combinator = isset( $node['oneOf'] ) || isset( $node['anyOf'] ) || isset( $node['allOf'] );
	if ( $has_combinator && ! snt_test_schema_node_has_usable_type( $node ) ) {
		$violations[ $path ] = 'combinator-only, no usable type (oneOf/anyOf/allOf with no fallback type or enum)';
	}

	if ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
		foreach ( $node['properties'] as $key => $prop ) {
			if ( is_array( $prop ) ) {
				snt_test_walk_schema_for_untyped_combinators( $prop, $path . '.' . $key, $violations );
			}
		}
	}
	if ( isset( $node['items'] ) && is_array( $node['items'] ) ) {
		snt_test_walk_schema_for_untyped_combinators( $node['items'], $path . '[]', $violations );
	}
	foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $combinator_key ) {
		if ( isset( $node[ $combinator_key ] ) && is_array( $node[ $combinator_key ] ) ) {
			foreach ( $node[ $combinator_key ] as $i => $branch ) {
				if ( is_array( $branch ) ) {
					snt_test_walk_schema_for_untyped_combinators( $branch, $path . ".$combinator_key[$i]", $violations );
				}
			}
		}
	}
}

foreach ( $consolidated_slugs as $slug ) {
	$ability = wp_get_ability( $slug );
	if ( ! $ability ) {
		continue; // already failed the registration assert above.
	}
	$tool   = sn_mcp_project_tool( $ability, SN_MCP_DOOR_RW );
	$schema = $tool['inputSchema'];

	$violations = array();
	snt_test_walk_schema_for_untyped_combinators( $schema, 'inputSchema', $violations );

	ok( array() === $violations, "$slug: PROJECTED inputSchema has no combinator-only (untyped) property" . ( empty( $violations ) ? '' : ' — ' . wp_json_encode( $violations ) ) );

	// Every direct top-level property (the exact position `target` occupied)
	// carries either a usable type or is itself a plain nested object/array
	// with its OWN properties/items (which the walk above already covers) —
	// belt-and-braces top-level-only assertion, cheap and specific to where
	// the real bug shipped.
	foreach ( ( $schema['properties'] ?? array() ) as $prop_key => $prop_schema ) {
		if ( ! is_array( $prop_schema ) ) {
			continue;
		}
		$top_level_ok = snt_test_schema_node_has_usable_type( $prop_schema )
			|| isset( $prop_schema['properties'] )
			|| isset( $prop_schema['items'] );
		ok( $top_level_ok, "$slug: top-level property \"$prop_key\" is not bare-combinator/untyped in the projected schema" );
	}
}

/* ── sn-apply schema/runtime parity: the DECLARED target.scope enum must
 * cover every scope the runtime resolver accepts. v10.57.0 shipped
 * roadmap_board with resolve_target accepting scope "maturity_roadmap"
 * while the input_schema still pinned the enum to provenance_anchors —
 * a client that validates against the schema (the MCP proxy does,
 * strictly) refused the call before the server ever saw it. Caught LIVE
 * on the first dry run, 2026-08-07; this pin makes the next scoped type
 * fail HERE instead. ── */
$apply_schema = $GLOBALS['__ab']['signal-noise/sn-apply']['input_schema'] ?? array();
$scope_enum   = $apply_schema['properties']['target']['properties']['scope']['enum'] ?? array();
foreach ( array( 'provenance_anchors', 'maturity_roadmap' ) as $runtime_scope ) {
	ok( in_array( $runtime_scope, $scope_enum, true ), "sn-apply: declared target.scope enum covers runtime scope \"$runtime_scope\" (schema/resolver parity — a schema-validating client refuses what the enum omits)" );
}

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
