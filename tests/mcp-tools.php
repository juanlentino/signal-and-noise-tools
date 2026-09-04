<?php
/**
 * Standalone tests for the MCP tools module: slug↔tool-name mapping, the
 * WP_Ability → MCP Tool projection, tools/list, and tools/call (allowlist gate,
 * permission check, WP_Error handling). Sub-project B.
 *
 * @since plugin v9.22.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message;
		public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

// v9.51.0 (lane SEC-B): stubs required by inc/mcp/mcp-rw-audit.php, loaded
// below alongside mcp-tools.php so sn_mcp_call_tool()'s rw-gated audit call
// site (its tail — see that file) is exercised end-to-end, not just unit-
// tested in isolation (tests/mcp-rw-audit.php covers the isolated unit).
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) { function rest_get_authenticated_app_password() { return 'test-uuid'; } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return false !== strpos( (string) $e, '@' ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k ) { return 'Test Site'; } }
if ( ! function_exists( 'wp_mail' ) ) { function wp_mail( $to, $subject, $body ) { return true; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $v ) ); } }
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

// v9.51.0 (lane SEC-C, R7): stubs required by inc/mcp/mcp-rw-guard.php's rate
// limiter (object-cache-or-transient storage abstraction), loaded below
// alongside the rest of the rw plumbing so sn_mcp_call_tool()'s rate-limit
// call site is exercised end-to-end (the isolated unit lives in
// tests/mcp-rw-guard.php).
$GLOBALS['__wp_cache']              = array();
$GLOBALS['__transients']            = array();
$GLOBALS['__using_ext_object_cache'] = false;
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache() { return $GLOBALS['__using_ext_object_cache']; } }
if ( ! function_exists( 'wp_cache_get' ) ) { function wp_cache_get( $k, $g = '' ) { return array_key_exists( "$g:$k", $GLOBALS['__wp_cache'] ) ? $GLOBALS['__wp_cache']["$g:$k"] : false; } }
if ( ! function_exists( 'wp_cache_set' ) ) { function wp_cache_set( $k, $v, $g = '', $ttl = 0 ) { $GLOBALS['__wp_cache']["$g:$k"] = $v; return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = 'auth' ) { return 'test-salt'; } }

// A lightweight WP_Ability stand-in + a registry wp_get_ability() reads.
class SN_Test_Ability {
	private $n, $label, $desc, $in, $out, $perm, $result, $meta;
	public function __construct( $n, $args ) {
		$this->n = $n; $this->label = $args['label'] ?? ''; $this->desc = $args['description'] ?? '';
		$this->in = $args['input_schema'] ?? array(); $this->out = $args['output_schema'] ?? array();
		$this->perm = $args['perm'] ?? true; $this->result = $args['result'] ?? null;
		// v9.51.0 (lane SEC-C, R6): optional injected meta (annotations…), mirroring
		// what a real WP_Ability's get_meta() returns. Absent by default — most
		// fixtures don't need it, and the "no declared meta at all" default path
		// (get-health-scan above) is itself a pinned case.
		$this->meta = $args['meta'] ?? array();
	}
	public function get_name() { return $this->n; }
	public function get_label() { return $this->label; }
	public function get_description() { return $this->desc; }
	public function get_input_schema() { return $this->in; }
	public function get_output_schema() { return $this->out; }
	public function get_meta() { return $this->meta; }
	public function check_permissions( $i = null ) { return $this->perm; }
	public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php'; // v9.51.0 (lane SEC-C, R7): sn_mcp_call_tool()'s top calls sn_mcp_rw_rate_limit_gate() on the rw door.
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-rw-audit.php'; // v9.51.0 (lane SEC-B): sn_mcp_call_tool()'s tail calls into this on the rw door.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP tools — plugin v9.22.0\n\n";

// --- name mapping round-trip ---
ok( sn_mcp_tool_name_from_slug( 'signal-noise/get-health-scan' ) === 'signal-noise__get-health-scan', 'slug → tool name maps / to __' );
ok( sn_mcp_slug_from_tool_name( 'signal-noise__get-health-scan' ) === 'signal-noise/get-health-scan', 'tool name → slug reverses __ to /' );
ok( sn_mcp_slug_from_tool_name( sn_mcp_tool_name_from_slug( 'signal-and-noise/get-design-tokens' ) ) === 'signal-and-noise/get-design-tokens', 'round-trip is lossless' );

// --- projection ---
$GLOBALS['__abilities']['signal-noise/get-health-scan'] = new SN_Test_Ability( 'signal-noise/get-health-scan', array(
	'label' => 'Get health scan', 'description' => 'Latest Site Health results.',
	'input_schema' => array(), // no inputs
	'output_schema' => array( 'type' => 'object' ),
	'result' => array( 'status' => 'green' ),
) );
$tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-health-scan'] );
ok( $tool['name'] === 'signal-noise__get-health-scan', 'projected tool name is mapped' );
ok( strpos( $tool['description'], 'Get health scan' ) !== false, 'projected description includes the label' );
ok( ( $tool['inputSchema']['type'] ?? '' ) === 'object', 'empty input schema normalizes to {type:object}' );
ok( isset( $tool['outputSchema'] ), 'outputSchema included when the ability declares one' );

// --- tools/list only projects registered allowlisted abilities ---
$list = sn_mcp_list_tools();
ok( isset( $list['tools'] ) && is_array( $list['tools'] ), 'list_tools returns a tools array' );
ok( count( $list['tools'] ) === 1, 'list_tools projects only the registered (1) allowlisted ability' );

// --- tools/call: success ---
$call = sn_mcp_call_tool( 'signal-noise__get-health-scan', array() );
ok( isset( $call['result'] ) && false === $call['result']['isError'], 'call success returns a non-error result' );
ok( ( $call['result']['structuredContent']['status'] ?? '' ) === 'green', 'call returns structuredContent from execute()' );
ok( strpos( $call['result']['content'][0]['text'], 'green' ) !== false, 'call returns a text content block' );

// --- tools/call: un-allowlisted slug is rejected even if named directly ---
$GLOBALS['__abilities']['signal-noise/purge-all-caches'] = new SN_Test_Ability( 'signal-noise/purge-all-caches', array( 'result' => 'purged' ) );
$bad = sn_mcp_call_tool( 'signal-noise__purge-all-caches', array() );
ok( isset( $bad['error'] ) && -32602 === $bad['error']['code'], 'un-allowlisted slug is rejected with -32602 (never executes)' );

// --- tools/call: permission denied → isError result ---
// v13.0.0: vehicle moved get-insights → get-analytics-events (wave 2 retired
// the former from the door; the property is slug-independent).
$GLOBALS['__abilities']['signal-noise/get-analytics-events'] = new SN_Test_Ability( 'signal-noise/get-analytics-events', array( 'perm' => false, 'result' => array( 'x' => 1 ) ) );
$denied = sn_mcp_call_tool( 'signal-noise__get-analytics-events', array() );
ok( isset( $denied['result'] ) && true === $denied['result']['isError'], 'permission denial returns isError:true' );

// --- tools/call: execute() WP_Error → isError result (not a crash) ---
$GLOBALS['__abilities']['signal-noise/get-rss-stats'] = new SN_Test_Ability( 'signal-noise/get-rss-stats', array( 'result' => new WP_Error( 'boom', 'feed unavailable' ) ) );
$err = sn_mcp_call_tool( 'signal-noise__get-rss-stats', array() );
ok( isset( $err['result'] ) && true === $err['result']['isError'] && strpos( $err['result']['content'][0]['text'], 'feed unavailable' ) !== false, 'execute() WP_Error becomes an isError result with the message' );

// --- schema conformance: the abilities' ['object','null'] union + empty properties
//     must normalize to a strict-MCP-conformant object schema (Anthropic/OpenAI reject otherwise) ---
$GLOBALS['__abilities']['signal-noise/get-cron-history'] = new SN_Test_Ability( 'signal-noise/get-cron-history', array(
	'label' => 'Get cron history', 'description' => 'Past runs.',
	'input_schema' => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
) );
$ct = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-cron-history'] );
ok( $ct['inputSchema']['type'] === 'object', 'union [object,null] type normalizes to the literal "object"' );
$enc = wp_json_encode( $ct['inputSchema'] );
ok( strpos( $enc, '"properties":{}' ) !== false && strpos( $enc, '"properties":[]' ) === false, 'empty properties encodes as {} not [] (MCP-conformant)' );

// --- P1: sn_mcp_schema_needs_wrap — false ONLY for an exact "object" root
//     ('object' string or a single-element ['object'] union). Everything else
//     (other unions, array roots, scalars, missing) needs wrapping: MCP requires
//     structuredContent to be a JSON object, and only an exact-object root
//     guarantees the ability's raw output always is one. ---
ok( false === sn_mcp_schema_needs_wrap( array( 'type' => 'object' ) ), 'wrap rule: "object" string root does not need wrapping' );
ok( false === sn_mcp_schema_needs_wrap( array( 'type' => array( 'object' ) ) ), 'wrap rule: single-element ["object"] union does not need wrapping' );
ok( true === sn_mcp_schema_needs_wrap( array( 'type' => array( 'object', 'null' ) ) ), 'wrap rule: ["object","null"] union needs wrapping (the get-narration/get-insights class)' );
ok( true === sn_mcp_schema_needs_wrap( array( 'type' => 'array' ) ), 'wrap rule: array root needs wrapping (the list-cron-events/get-cron-history class)' );
ok( true === sn_mcp_schema_needs_wrap( array( 'type' => 'string' ) ), 'wrap rule: scalar root needs wrapping' );
ok( true === sn_mcp_schema_needs_wrap( array() ), 'wrap rule: missing/empty schema needs wrapping (safe default)' );
// Flagged in lane notes: signal-noise/get-health-scan's REAL registered output_schema
// (inc/abilities-health.php) is ['object','null'] — it returns null pre-scan. It is NOT
// one of the 4 confirmed-live failures (a scan had already run in the live repro), but it
// is the SAME class of bug; the deterministic rule closes it too and its envelope will
// change shape. Pinned here directly against the real schema shape.
ok( true === sn_mcp_schema_needs_wrap( array( 'type' => array( 'object', 'null' ), 'properties' => array( 'finding_total' => array( 'type' => 'integer' ) ) ) ), 'get-health-scan\'s real ["object","null"] root also needs wrapping (latent bug the rule catches)' );

// --- P5 pin: the pure-object fixture above (signal-noise/get-health-scan, declared
//     type:object) must stay a byte-identical passthrough — protects the 9 working tools. ---
ok( ! isset( $tool['outputSchema']['properties']['result'] ), 'pure-object ability: advertised outputSchema is not wrapped' );
ok( ! array_key_exists( 'result', $call['result']['structuredContent'] ), 'pure-object ability: call structuredContent is not wrapped' );

// --- P2/P3: array-rooted ability (list-cron-events) wraps both the advertised schema
//     and the call result; the original array schema rides untouched inside properties.result ---
$GLOBALS['__abilities']['signal-noise/list-cron-events'] = new SN_Test_Ability( 'signal-noise/list-cron-events', array(
	'label' => 'List cron events', 'description' => 'Scheduled cron events.',
	'output_schema' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
	'result' => array( array( 'hook' => 'sn_daily' ) ),
) );
$lt = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/list-cron-events'] );
ok( ( $lt['outputSchema']['type'] ?? '' ) === 'object', 'array-rooted ability: advertised outputSchema wraps to type object' );
ok( ( $lt['outputSchema']['properties']['result']['type'] ?? '' ) === 'array', 'array-rooted ability: wrapped schema keeps the original array type untouched inside properties.result' );
ok( in_array( 'result', $lt['outputSchema']['required'] ?? array(), true ), 'array-rooted ability: wrapped schema requires "result"' );
$lt_enc = wp_json_encode( $lt['outputSchema'] );
ok( strpos( $lt_enc, '"properties":{"result":' ) !== false, 'wrapped outputSchema properties encodes as an object keyed by "result" (not a list)' );

$lc = sn_mcp_call_tool( 'signal-noise__list-cron-events', array() );
ok( ( $lc['result']['structuredContent']['result'][0]['hook'] ?? '' ) === 'sn_daily', 'array-rooted ability: call wraps structuredContent as {result:[...]}' );
ok( strpos( $lc['result']['content'][0]['text'], '"result"' ) !== false, 'array-rooted ability: text content block reflects the wrapped value too' );

// --- Probe fold: a wrapped ability whose result is an EMPTY PHP array must
//     encode the INNER value as {} (object), not [] — {"result":[]} would
//     violate the advertised properties.result ["object","null"] union the
//     same way the top-level belt already prevents for passthrough tools. ---
// v13.0.0: vehicle moved get-insights → ai-cache-probe-status (doored; same
// object|null envelope class).
$GLOBALS['__abilities']['signal-noise/ai-cache-probe-status'] = new SN_Test_Ability( 'signal-noise/ai-cache-probe-status', array(
	'label' => 'AI cache probe', 'description' => 'Probe verdict, or null pre-data.',
	'output_schema' => array( 'type' => array( 'object', 'null' ) ),
	'result' => array(),
) );
$ic = sn_mcp_call_tool( 'signal-noise__ai-cache-probe-status', array() );
ok( is_object( $ic['result']['structuredContent']['result'] ?? null ), 'wrapped empty-array result: inner value casts to an object so it encodes {} not []' );

// --- P2/P3: object|null-rooted ability returning null wraps too — null stays legal
//     INSIDE properties.result (the get-narration/get-insights no-scan-yet class) ---
// v13.0.0: vehicle moved get-narration → anchor-status (doored; the
// object|null no-data-yet class is what's under test, not the slug).
$GLOBALS['__abilities']['signal-noise/anchor-status'] = new SN_Test_Ability( 'signal-noise/anchor-status', array(
	'label' => 'Anchor status', 'description' => 'Anchor state, or null pre-scan.',
	'output_schema' => array(
		'type'       => array( 'object', 'null' ),
		'properties' => array( 'headline' => array( 'type' => array( 'string', 'null' ) ) ),
	),
	'result' => null,
) );
$nt = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/anchor-status'] );
ok( ( $nt['outputSchema']['type'] ?? '' ) === 'object', 'object|null-rooted ability: advertised outputSchema wraps to type object' );
$result_schema_type = $nt['outputSchema']['properties']['result']['type'] ?? null;
ok( is_array( $result_schema_type ) && in_array( 'null', $result_schema_type, true ) && in_array( 'object', $result_schema_type, true ), 'wrapped schema properties.result still allows the original ["object","null"] union untouched' );

$nc = sn_mcp_call_tool( 'signal-noise__anchor-status', array() );
ok( array_key_exists( 'result', $nc['result']['structuredContent'] ?? array() ) && null === $nc['result']['structuredContent']['result'], 'object|null-rooted ability returning null: call wraps to {result:null}, never bare null' );

// --- P4: empty-array structuredContent must encode {} not [] ---
$empty_result = sn_mcp_success_result( array() );
ok( is_object( $empty_result['structuredContent'] ), 'sn_mcp_success_result: empty-array structuredContent is cast to an object' );
ok( '{}' === wp_json_encode( $empty_result['structuredContent'] ), 'empty structuredContent encodes as {} not []' );
ok( '[]' === $empty_result['content'][0]['text'], 'the text content block still shows [] (only structuredContent gets the object-cast belt)' );

// ============================================================
// v9.50.0 — two doors: readOnlyHint projection + per-door call gating
// ============================================================
echo "\nMCP tools — two doors (v9.50.0)\n\n";

// --- D4: read-door tools advertise annotations.readOnlyHint:true ---
$read_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-health-scan'] );
ok( ( $read_tool['annotations']['readOnlyHint'] ?? null ) === true, 'read-door projection (default door): annotations.readOnlyHint is true' );
$read_tool_explicit = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-health-scan'], SN_MCP_DOOR_READ );
ok( ( $read_tool_explicit['annotations']['readOnlyHint'] ?? null ) === true, 'read-door projection (explicit door): annotations.readOnlyHint is true' );

// --- v9.51.0 (lane SEC-C, R6): rw-door tools now DO carry a fully-populated,
//     curated annotations object (readOnlyHint/destructiveHint/idempotentHint)
//     — see the R6 section below. get-health-scan has no 'annotations' meta at
//     all in this fixture (SN_Test_Ability's get_meta() isn't even defined),
//     so it exercises the fully-absent-meta default path. ---
$rw_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-health-scan'], SN_MCP_DOOR_RW );
ok( isset( $rw_tool['annotations'] ), 'rw-door projection (R6): DOES carry an annotations key now (v2 — no longer omitted)' );
ok( ( $rw_tool['annotations']['readOnlyHint'] ?? null ) === false, 'no declared meta: readOnlyHint defaults false (MCP\'s own default)' );
ok( ( $rw_tool['annotations']['destructiveHint'] ?? null ) === true, 'no declared meta + no per-slug override: destructiveHint defaults true (MCP\'s own maximally-cautious default — Finding B, unchanged for a slug nobody has reviewed)' );
ok( ( $rw_tool['annotations']['idempotentHint'] ?? null ) === false, 'no declared meta: idempotentHint defaults false (MCP\'s own default)' );

// --- tools/list is door-aware: only projects abilities on the resolved door's
//     allowlist, and only those that resolve via wp_get_ability ---
$GLOBALS['__abilities']['signal-noise/ai-pair-suggest'] = new SN_Test_Ability( 'signal-noise/ai-pair-suggest', array(
	// v11.34.0: ai-alt-suggest was RETIRED from the rw door, so it is no longer
	// projected and every assertion keyed to it failed. Swapped for another
	// rw-only, AI-billed suggestion ability; the properties under test (rw-only
	// projection, and the R7 per-window call cap) are about the DOOR, not the slug.
	'label' => 'Suggest a pair', 'result' => array( 'suggestion' => 'a cat' ),
) );
$read_list = sn_mcp_list_tools( SN_MCP_DOOR_READ );
$read_names = array_column( $read_list['tools'], 'name' );
ok( ! in_array( 'signal-noise__ai-pair-suggest', $read_names, true ), 'tools/list(read): an rw-only ability is not projected' );

$rw_list = sn_mcp_list_tools( SN_MCP_DOOR_RW );
$rw_names = array_column( $rw_list['tools'], 'name' );
ok( in_array( 'signal-noise__ai-pair-suggest', $rw_names, true ), 'tools/list(rw): the rw-only ability IS projected' );
ok( ! in_array( 'signal-noise__get-health-scan', $rw_names, true ), 'tools/list(rw): a read-only ability is not projected (no duplication across doors)' );
foreach ( $rw_list['tools'] as $t ) {
	ok( isset( $t['annotations']['readOnlyHint'], $t['annotations']['destructiveHint'], $t['annotations']['idempotentHint'] ),
		"tools/list(rw): projected tool '{$t['name']}' carries a fully-populated annotations object (R6, v2)" );
}

// --- D6: per-door CALL gating — the security property holds at the call gate,
//     not just the advertised list ---
$rw_only_on_read = sn_mcp_call_tool( 'signal-noise__ai-pair-suggest', array(), SN_MCP_DOOR_READ );
ok( isset( $rw_only_on_read['error'] ) && -32602 === $rw_only_on_read['error']['code'], 'an rw-only slug called on the read door -> unknown tool (-32602), never executes' );

$rw_only_on_rw = sn_mcp_call_tool( 'signal-noise__ai-pair-suggest', array(), SN_MCP_DOOR_RW );
ok( isset( $rw_only_on_rw['result'] ) && false === $rw_only_on_rw['result']['isError'], 'the same rw-only slug called on the rw door succeeds' );

$read_only_on_rw = sn_mcp_call_tool( 'signal-noise__get-health-scan', array(), SN_MCP_DOOR_RW );
ok( isset( $read_only_on_rw['error'] ) && -32602 === $read_only_on_rw['error']['code'], 'a read-only slug called on the rw door -> unknown tool (no cross-door leakage)' );

// --- D6: the excluded slugs are unknown on BOTH doors, even named directly ---
$GLOBALS['__abilities']['signal-noise/run-cron-event'] = new SN_Test_Ability( 'signal-noise/run-cron-event', array( 'result' => 'ran' ) );
$excluded_on_read = sn_mcp_call_tool( 'signal-noise__run-cron-event', array(), SN_MCP_DOOR_READ );
ok( isset( $excluded_on_read['error'] ) && -32602 === $excluded_on_read['error']['code'], 'run-cron-event is unknown on the read door' );
$excluded_on_rw = sn_mcp_call_tool( 'signal-noise__run-cron-event', array(), SN_MCP_DOOR_RW );
ok( isset( $excluded_on_rw['error'] ) && -32602 === $excluded_on_rw['error']['code'], 'run-cron-event is unknown on the rw door too (never on any door)' );

$GLOBALS['__abilities']['signal-noise/ai-orphan-apply'] = new SN_Test_Ability( 'signal-noise/ai-orphan-apply', array( 'result' => 'deleted' ) );
$held_on_rw = sn_mcp_call_tool( 'signal-noise__ai-orphan-apply', array(), SN_MCP_DOOR_RW );
ok( isset( $held_on_rw['error'] ) && -32602 === $held_on_rw['error']['code'], 'the held ai-orphan-apply is unknown on the rw door (owner-held, not yet opted in)' );

// --- D1: get-analytics-events (array-rooted) is now read-door-allowlisted;
//     pin the wrap rule end-to-end through the new addition ---
$GLOBALS['__abilities']['signal-noise/get-analytics-events'] = new SN_Test_Ability( 'signal-noise/get-analytics-events', array(
	'label' => 'Get analytics events', 'description' => 'Top custom events for a window.',
	'output_schema' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
	'result' => array( array( 'event' => 'talk_qr_scan', 'count' => 12 ) ),
) );
$events_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-analytics-events'], SN_MCP_DOOR_READ );
ok( ( $events_tool['outputSchema']['type'] ?? '' ) === 'object', 'D1: get-analytics-events advertised outputSchema wraps array root to object' );
ok( ( $events_tool['outputSchema']['properties']['result']['type'] ?? '' ) === 'array', 'D1: get-analytics-events wrapped schema keeps the original array type inside properties.result' );

$events_list = sn_mcp_list_tools( SN_MCP_DOOR_READ );
$events_names = array_column( $events_list['tools'], 'name' );
ok( in_array( 'signal-noise__get-analytics-events', $events_names, true ), 'D1: get-analytics-events is projected on tools/list(read) now that it is allowlisted' );

$events_call = sn_mcp_call_tool( 'signal-noise__get-analytics-events', array(), SN_MCP_DOOR_READ );
ok( ( $events_call['result']['structuredContent']['result'][0]['event'] ?? '' ) === 'talk_qr_scan', 'D1: get-analytics-events call end-to-end wraps structuredContent as {result:[...]}' );
ok( ( $events_call['result']['annotations']['readOnlyHint'] ?? null ) === null, 'sanity: annotations live on the projected TOOL, not on the call RESULT' );

// ============================================================
// v9.51.0 — lane SEC-B: rw-gated audit log at sn_mcp_call_tool()'s tail
// ============================================================
echo "\nMCP tools — rw audit log integration (v9.51.0, lane SEC-B)\n\n";

$GLOBALS['__opts'] = array(); // Fresh audit-log option state for this section.

// --- READ DOOR IS BYTE-FROZEN: success, permission-denied, execute-error,
//     AND unknown-tool calls on the read door must never create the rw audit
//     option AT ALL (not "empty rows" — the option itself stays untouched). ---
sn_mcp_call_tool( 'signal-noise__get-health-scan', array(), SN_MCP_DOOR_READ );          // success
sn_mcp_call_tool( 'signal-noise__get-analytics-events', array(), SN_MCP_DOOR_READ );     // permission denied (perm=false fixture above)
sn_mcp_call_tool( 'signal-noise__get-rss-stats', array(), SN_MCP_DOOR_READ );            // execute() WP_Error
sn_mcp_call_tool( 'signal-noise__run-cron-event', array(), SN_MCP_DOOR_READ );           // unknown tool (protocol error)
ok( false === get_option( SN_MCP_RW_AUDIT_OPTION, false ), 'READ-DOOR-FROZEN: success/denied/error/unknown calls on the read door never create the rw audit-log option' );

// --- RW DOOR: success outcome + redaction integration (a real content-
//     bearing arg, alt_text, must never reach the stored row) ---
// v11.34.0 moved this vehicle ai-alt-apply → update-post-surfaces when the
// former retired (this block CRASHES on a null blob rather than failing an
// assertion when its slug leaves the door). v13.0.0 wave 2 retired
// update-post-surfaces too, so the vehicle moves again → ai-pair-suggest.
// The PROPERTY under test is unchanged and is not about any slug:
// sn_mcp_rw_audit_safe_args() is deliberately slug-independent, so any doored
// rw slug carrying one safe scalar key and one content-bearing key works.
$GLOBALS['__abilities']['signal-noise/ai-pair-suggest'] = new SN_Test_Ability( 'signal-noise/ai-pair-suggest', array(
	'perm' => true, 'result' => array( 'ok' => true, 'post_id' => 9 ),
) );
sn_mcp_call_tool( 'signal-noise__ai-pair-suggest', array( 'post_id' => 9, 'alt_text' => 'a cat sitting on a fence' ), SN_MCP_DOOR_RW );
$blob = get_option( SN_MCP_RW_AUDIT_OPTION );
ok( is_array( $blob ) && 1 === count( $blob['rows'] ), 'RW DOOR: a successful rw call appends exactly one audit row' );
$row0 = $blob['rows'][0];
ok( 'signal-noise/ai-pair-suggest' === $row0['slug'] && 'ok' === $row0['outcome'], 'the row records the slug + ok outcome' );
ok( ( $row0['args_redacted']['post_id'] ?? null ) === 9, 'the row keeps the safe scalar key (post_id)' );
ok( ! array_key_exists( 'alt_text', $row0['args_redacted'] ), 'PROBE PIN: the row NEVER contains the real content-bearing arg (alt_text), end-to-end from the call site' );

// --- RW DOOR: permission-denied outcome ---
$GLOBALS['__abilities']['signal-noise/prune-unused-tags'] = new SN_Test_Ability( 'signal-noise/prune-unused-tags', array( 'perm' => false, 'result' => array( 'ok' => true ) ) );
sn_mcp_call_tool( 'signal-noise__prune-unused-tags', array(), SN_MCP_DOOR_RW );
$blob = get_option( SN_MCP_RW_AUDIT_OPTION );
$row1 = $blob['rows'][1];
ok( 'denied' === $row1['outcome'] && 'permission_denied' === ( $row1['error_code'] ?? null ), 'RW DOOR: a permission-denied call is audited with outcome=denied + error_code=permission_denied' );

// --- RW DOOR: execute() WP_Error outcome ---
// v13.0.0: vehicle moved run-narration → ai-link-apply (wave 2 retired the former).
$GLOBALS['__abilities']['signal-noise/ai-link-apply'] = new SN_Test_Ability( 'signal-noise/ai-link-apply', array( 'perm' => true, 'result' => new WP_Error( 'ai_unavailable', 'AI client unreachable' ) ) );
sn_mcp_call_tool( 'signal-noise__ai-link-apply', array(), SN_MCP_DOOR_RW );
$blob = get_option( SN_MCP_RW_AUDIT_OPTION );
$row2 = $blob['rows'][2];
ok( 'error' === $row2['outcome'] && 'ai_unavailable' === ( $row2['error_code'] ?? null ), 'RW DOOR: an execute() WP_Error is audited with outcome=error + the WP_Error\'s own code' );

ok( 3 === count( $blob['rows'] ), 'exactly 3 rw-door rows total after 3 rw calls — the earlier read-door calls contributed none' );

// --- Judgment call, pinned explicitly: a protocol-level rejection on the rw
//     door (unknown/un-allowlisted tool name — before the ability even
//     resolves) is NOT audited. sn_mcp_call_tool()'s tail (where SEC-B's
//     audit call lives) is never reached for this branch; see mcp-tools.php's
//     top-of-function early returns, untouched by lane SEC-B. ---
sn_mcp_call_tool( 'signal-noise__run-cron-event', array(), SN_MCP_DOOR_RW );
$blob = get_option( SN_MCP_RW_AUDIT_OPTION );
ok( 3 === count( $blob['rows'] ), 'JUDGMENT CALL PIN: an unknown-tool call on the rw door does NOT add an audit row (protocol rejection happens before the tail)' );

// ============================================================
// v9.51.0 — lane SEC-C, R6: rw-door tool annotations (WP -> MCP translation
// + the known-wrong per-slug override map)
// ============================================================
echo "\nMCP tools — R6 rw-door annotations (v9.51.0, lane SEC-C)\n\n";

// --- A slug whose own meta.annotations is trustworthy AS-IS (declares all
//     three keys) passes through untranslated-in-substance, just relabeled
//     to the Hint vocabulary. R6 pin: "a destructive tool (e.g.
//     prune-unused-tags) advertises destructiveHint:true". ---
$GLOBALS['__abilities']['signal-noise/prune-unused-tags'] = new SN_Test_Ability( 'signal-noise/prune-unused-tags', array(
	'result' => array( 'ok' => true ),
	'meta'   => array( 'annotations' => array( 'destructive' => true, 'idempotent' => false ) ),
) );
$prune_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/prune-unused-tags'], SN_MCP_DOOR_RW );
ok( true === ( $prune_tool['annotations']['destructiveHint'] ?? null ), 'R6 PIN: prune-unused-tags (declares destructive:true) advertises destructiveHint:true' );
ok( false === ( $prune_tool['annotations']['readOnlyHint'] ?? null ), 'prune-unused-tags: no readonly declared -> readOnlyHint false' );
ok( false === ( $prune_tool['annotations']['idempotentHint'] ?? null ), 'prune-unused-tags: declares idempotent:false -> idempotentHint false' );

// --- R6 pin: "an AI generator advertises non-readonly + non-destructive +
//     idempotent-false" — the theme's 5 return-only AI-generation abilities.
//     Their OWN meta declares readonly:false + idempotent:false but NO
//     destructive key at all; without the override map they would inherit
//     MCP's maximally-cautious absent-key default (destructiveHint:true). ---
$GLOBALS['__abilities']['signal-and-noise/ai-generate-page-note-summary'] = new SN_Test_Ability( 'signal-and-noise/ai-generate-page-note-summary', array(
	'result' => array( 'summary' => 'text' ),
	'meta'   => array( 'annotations' => array( 'readonly' => false, 'idempotent' => false ) ),
) );
$ai_gen_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-and-noise/ai-generate-page-note-summary'], SN_MCP_DOOR_RW );
ok( false === ( $ai_gen_tool['annotations']['readOnlyHint'] ?? null ), 'R6 PIN: AI generator (theme) advertises readOnlyHint:false (trusted from its own declaration)' );
ok( false === ( $ai_gen_tool['annotations']['destructiveHint'] ?? null ), 'R6 PIN: AI generator (theme) advertises destructiveHint:false (KNOWN-WRONG override — no destructive key declared, would otherwise default true)' );
ok( false === ( $ai_gen_tool['annotations']['idempotentHint'] ?? null ), 'R6 PIN: AI generator (theme) advertises idempotentHint:false (trusted from its own declaration)' );

// --- The plugin's AI-BILLED "*-suggest" verdict family: NO annotations key
//     declares 'destructive' OR 'readonly' at all (only 'idempotent'). Without
//     the override, destructiveHint would wrongly default true for a call that
//     only returns a suggestion and never touches WP state. ---
$GLOBALS['__abilities']['signal-noise/ai-pair-suggest'] = new SN_Test_Ability( 'signal-noise/ai-pair-suggest', array(
	'result' => array( 'suggestion' => 'a cat' ),
	'meta'   => array( 'annotations' => array( 'idempotent' => true ) ),
) );
$suggest_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/ai-pair-suggest'], SN_MCP_DOOR_RW );
ok( false === ( $suggest_tool['annotations']['destructiveHint'] ?? null ), 'KNOWN-WRONG override: ai-pair-suggest (AI-BILLED verdict, no destructive declared) advertises destructiveHint:false, not the dangerous absent-key default' );
ok( true === ( $suggest_tool['annotations']['idempotentHint'] ?? null ), 'ai-pair-suggest: declares idempotent:true -> idempotentHint true (trusted)' );

// --- A read-only ability (readonly:true declared) cannot be destructive by
//     definition — destructiveHint resolves to false even with no explicit
//     'destructive' key and no per-slug override entry needed. ---
$GLOBALS['__abilities']['signal-noise/get-audit-log'] = new SN_Test_Ability( 'signal-noise/get-audit-log', array(
	'result' => array( 'view' => 'summary' ),
	'meta'   => array( 'annotations' => array( 'readonly' => true, 'idempotent' => true ) ),
) );
$audit_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-audit-log'], SN_MCP_DOOR_RW );
ok( true === ( $audit_tool['annotations']['readOnlyHint'] ?? null ), 'get-audit-log (PII-gated onto the rw door, but genuinely PURE-READ): readOnlyHint true' );
ok( false === ( $audit_tool['annotations']['destructiveHint'] ?? null ), 'a declared-readonly ability is never destructive, even absent an explicit destructive:false' );

// --- The read door's annotations are UNCHANGED by any of this (byte-frozen) ---
$read_tool_after_r6 = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-health-scan'], SN_MCP_DOOR_READ );
ok( array( 'readOnlyHint' => true ) === $read_tool_after_r6['annotations'], 'READ-DOOR-FROZEN: read-door annotations are still the single readOnlyHint:true shape, untouched by R6' );

// ============================================================
// v9.51.0 — lane SEC-C, R7: rate limit gate at sn_mcp_call_tool()'s top,
// rw-door only. The predicate/identity/storage layer lives in
// inc/mcp/mcp-rw-guard.php (see tests/mcp-rw-guard.php); this suite proves
// the CALL-SITE wiring — gated on $door, never touching the read door.
// ============================================================
echo "\nMCP tools — R7 rate limit call-site gate (v9.51.0, lane SEC-C)\n\n";

// Fresh rate-limit bucket: every rw call earlier in this suite (SEC-B's
// section, the D6 cross-door pins) already consumed some of the shared
// 'uuid:test-uuid' identity's budget in this same 60s window — clear the
// backing store so this section starts from an empty bucket.
$GLOBALS['__transients'] = array();
$GLOBALS['__wp_cache']   = array();

// v11.34.0: dismiss-candidate was RETIRED from the rw door, so every call
// below was refused before reaching the rate-limit gate and the cap never
// engaged. prune-unused-tags is still doored and equally side-effect-light
// for a drain loop; R7 is a property of the DOOR's per-identity window, not of
// any particular tool.
$GLOBALS['__abilities']['signal-noise/prune-unused-tags'] = new SN_Test_Ability( 'signal-noise/prune-unused-tags', array( 'result' => array( 'ok' => true ) ) );

// Drain the per-minute cap for a fresh identity by calling the rw door
// SN_MCP_RW_RATE_LIMIT_PER_MINUTE times — every one of those must still
// succeed (each is a legitimate, allowed call).
for ( $i = 0; $i < SN_MCP_RW_RATE_LIMIT_PER_MINUTE; $i++ ) {
	$r = sn_mcp_call_tool( 'signal-noise__prune-unused-tags', array(), SN_MCP_DOOR_RW );
	ok( isset( $r['result'] ) && false === $r['result']['isError'], "R7: rw call " . ( $i + 1 ) . " within the cap succeeds" );
}
// The next one, same identity (same test-uuid from the stub), must be a
// JSON-RPC error carrying a retry hint — never a silent execute().
$over = sn_mcp_call_tool( 'signal-noise__prune-unused-tags', array(), SN_MCP_DOOR_RW );
ok( isset( $over['error'] ), 'R7: the call past the per-minute cap on the rw door returns a JSON-RPC error, not a result' );
ok( false !== stripos( $over['error']['message'] ?? '', 'rate limit' ) || false !== stripos( $over['error']['message'] ?? '', 'Retry' ), 'R7: the rate-limit error message is identifiable as a rate-limit denial' );
ok( isset( $over['error']['data']['retry_after'] ) && $over['error']['data']['retry_after'] > 0, 'R7: the error carries a retry_after hint in its data (JSON-RPC has no HTTP header seam mid-batch)' );

// --- READ-DOOR-FROZEN: hammering the read door the same number of times
//     (well past the rw cap) must NEVER trip a rate limit — the read door is
//     explicitly exempt from this measure. ---
$GLOBALS['__abilities']['signal-noise/get-deploy-status'] = new SN_Test_Ability( 'signal-noise/get-deploy-status', array( 'result' => array( 'ok' => true ) ) );
for ( $i = 0; $i < SN_MCP_RW_RATE_LIMIT_PER_MINUTE + 10; $i++ ) {
	$r = sn_mcp_call_tool( 'signal-noise__get-deploy-status', array(), SN_MCP_DOOR_READ );
	if ( isset( $r['error'] ) ) {
		ok( false, "READ-DOOR-FROZEN: read-door call " . ( $i + 1 ) . " unexpectedly rate-limited" );
		break;
	}
}
ok( true, 'READ-DOOR-FROZEN: ' . ( SN_MCP_RW_RATE_LIMIT_PER_MINUTE + 10 ) . ' read-door calls (well past the rw cap) all succeeded — the read door is never rate-limited' );


echo "\nGroup: output-schema re-validation — drift stops shipping as silent success (v13.51.0)\n";
//
// WHY. tools/list advertises sn_mcp_project_output_schema(...), and the MCP
// client SDK validates every structuredContent against that advertisement ON
// THE CLIENT — after our server returned success and our telemetry said ok.
// The wrapper now validates the same value against the same projection first.
//
// The engine is core's rest_validate_value_from_schema, absent from this
// harness — so a MINI validator stands in: root type, required keys, declared
// property scalar types, one level deep plus the {result:...} wrap. Enough to
// fail in BOTH directions; the real engine is core's problem, the WIRING is ours.

function rest_validate_value_from_schema( $value, $schema, $param = '' ) {
	$type = $schema['type'] ?? null;
	if ( 'object' === $type && ! is_array( $value ) ) {
		return new WP_Error( 'rest_invalid_type', "$param is not of type object." );
	}
	// LIST discipline: an advertised array root must actually be a list — this
	// is what catches a mutation that validates the RAW schema against the
	// WRAPPED value ({result:[…]} is not a list).
	if ( 'array' === $type && ( ! is_array( $value ) || array_keys( $value ) !== range( 0, max( 0, count( $value ) - 1 ) ) && array() !== $value ) ) {
		return new WP_Error( 'rest_invalid_type', "$param is not of type array." );
	}
	foreach ( (array) ( $schema['required'] ?? array() ) as $req ) {
		if ( ! is_array( $value ) || ! array_key_exists( $req, $value ) ) {
			return new WP_Error( 'rest_property_required', "$req is a required property of $param." );
		}
	}
	// additionalProperties:false — mirrors core's rest_validate_object_value_from_schema,
	// which returns rest_additional_properties_forbidden ("%s is not a valid property of
	// Object.") for any key not in `properties`. Added when the rw door started
	// validating INPUT: the schemas have always declared this and nothing enforced it,
	// so without it here the new assertions would pass vacuously against a stub that
	// simply ignores undeclared keys — which is exactly how they were silently dropped
	// in production.
	if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] && is_array( $value ) ) {
		foreach ( array_keys( $value ) as $k ) {
			if ( ! array_key_exists( $k, (array) ( $schema['properties'] ?? array() ) ) ) {
				return new WP_Error( 'rest_additional_properties_forbidden', "$k is not a valid property of Object." );
			}
		}
	}
	foreach ( (array) ( $schema['properties'] ?? array() ) as $k => $sub ) {
		if ( ! is_array( $value ) || ! array_key_exists( $k, $value ) ) { continue; }
		$st = is_array( $sub ) ? ( $sub['type'] ?? null ) : null;
		$v  = $value[ $k ];
		if ( 'string' === $st && ! is_string( $v ) ) { return new WP_Error( 'rest_invalid_type', "$param\\[$k] is not of type string." ); }
		if ( 'integer' === $st && ! is_int( $v ) ) { return new WP_Error( 'rest_invalid_type', "$param\\[$k] is not of type integer." ); }
		if ( is_array( $sub ) && isset( $sub['properties'] ) && is_array( $v ) ) {
			$inner = rest_validate_value_from_schema( $v, $sub, "$param\\[$k]" );
			if ( is_wp_error( $inner ) ) { return $inner; }
		}
	}
	return true;
}

// Telemetry capture — the wrapper's calls are function_exists-guarded, so
// defining these NOW arms recording for the calls below only.
$GLOBALS['__tel'] = array();
function sn_mcp_telemetry_record( $tool, $args, $door, $outcome, $gate = null, $ms = 0, $count = null, $code = null, $detail = null, $status = null ) {
	$GLOBALS['__tel'][] = compact( 'tool', 'outcome', 'code', 'detail', 'status' );
}
function sn_mcp_telemetry_elapsed_ms( $t0 ) { return 1; }

// A fixture whose output MATCHES its advertised schema…
$GLOBALS['__abilities']['signal-noise/uptime-status'] = new SN_Test_Ability( 'signal-noise/uptime-status', array(
	'output_schema' => array( 'type' => 'object', 'required' => array( 'configured' ), 'properties' => array( 'configured' => array( 'type' => 'string' ) ) ),
	'result'        => array( 'configured' => 'yes' ),
) );
$GLOBALS['__tel'] = array();
$ok_call = sn_mcp_call_tool( 'signal-noise__uptime-status', array() );
ok( isset( $ok_call['result'] ) && false === $ok_call['result']['isError'], 'a conforming payload still returns success' );
ok( 'ok' === ( $GLOBALS['__tel'][0]['outcome'] ?? '' ), 'and telemetry records ok' );

// …and the SAME fixture with a DRIFTED payload (declared string, returns int).
$GLOBALS['__abilities']['signal-noise/uptime-status'] = new SN_Test_Ability( 'signal-noise/uptime-status', array(
	'output_schema' => array( 'type' => 'object', 'required' => array( 'configured' ), 'properties' => array( 'configured' => array( 'type' => 'string' ) ) ),
	'result'        => array( 'configured' => 7 ),
) );
$GLOBALS['__tel'] = array();
$bad_call = sn_mcp_call_tool( 'signal-noise__uptime-status', array() );
ok( isset( $bad_call['result'] ) && true === $bad_call['result']['isError'], 'a drifted payload is REFUSED server-side, not left for the client SDK to discover' );
ok( false !== strpos( (string) $bad_call['result']['content'][0]['text'], 'Output schema mismatch' ), 'the refusal names itself' );
ok( 'server_error' === ( $GLOBALS['__tel'][0]['outcome'] ?? '' ), 'telemetry records server_error — the drift is VISIBLE, the whole point' );
ok( 'sn_mcp_output_schema_mismatch' === ( $GLOBALS['__tel'][0]['code'] ?? '' ), 'with its own error_code' );
ok( '' !== (string) ( $GLOBALS['__tel'][0]['detail'] ?? '' ), 'and a detail naming the violation' );
ok( 500 === ( $GLOBALS['__tel'][0]['status'] ?? 0 ), 'status 500: drift is the SERVER breaking its advertisement, never the caller\'s fault' );

// A missing REQUIRED key refuses too (the second failure family).
$GLOBALS['__abilities']['signal-noise/uptime-status'] = new SN_Test_Ability( 'signal-noise/uptime-status', array(
	'output_schema' => array( 'type' => 'object', 'required' => array( 'configured' ), 'properties' => array( 'configured' => array( 'type' => 'string' ) ) ),
	'result'        => array( 'other' => 'x' ),
) );
ok( true === ( sn_mcp_call_tool( 'signal-noise__uptime-status', array() )['result']['isError'] ?? false ), 'a payload missing a required property refuses' );

// The WRAP case: a list-shaped root is validated AS ADVERTISED — wrapped.
$GLOBALS['__abilities']['signal-noise/list-cron-events'] = new SN_Test_Ability( 'signal-noise/list-cron-events', array(
	'output_schema' => array( 'type' => 'array' ),
	'result'        => array( array( 'hook' => 'sn_daily' ) ),
) );
ok( false === ( sn_mcp_call_tool( 'signal-noise__list-cron-events', array() )['result']['isError'] ?? true ), 'a wrapped list validates against the wrapped projection, not the raw schema' );

// The kill switch, and the pure checker\'s fail-open contract.
$GLOBALS['__abilities']['signal-noise/uptime-status'] = new SN_Test_Ability( 'signal-noise/uptime-status', array(
	'output_schema' => array( 'type' => 'object', 'required' => array( 'configured' ), 'properties' => array( 'configured' => array( 'type' => 'string' ) ) ),
	'result'        => array( 'configured' => 7 ),
) );
ok( null === sn_mcp_output_schema_violation( array( 'configured' => 7 ), array() ), 'pure checker: an EMPTY schema yields null (nothing was advertised, nothing can drift)' );
ok( is_string( sn_mcp_output_schema_violation( array( 'configured' => 7 ), array( 'type' => 'object', 'properties' => array( 'configured' => array( 'type' => 'string' ) ) ) ) ), 'pure checker: a violation yields the message' );
ok( null === sn_mcp_output_schema_violation( array( 'configured' => 'y' ), array( 'type' => 'object', 'properties' => array( 'configured' => array( 'type' => 'string' ) ) ) ), 'pure checker: a conforming value yields null' );

// ── v13.73.0: the WRITE door validates its INPUT ───────────────────────────
// The schemas always declared additionalProperties:false; nothing enforced it.
// Measured 2026-09-02: an invented `dry_run` key passed to
// signal-noise/apply-tag-description was accepted, ignored, and the write
// happened anyway — the caller believed the call was constrained.
$sn_in_schema = array(
	'type'                 => 'object',
	'required'             => array( 'name', 'description' ),
	'properties'           => array(
		'name'        => array( 'type' => 'string' ),
		'description' => array( 'type' => 'string' ),
	),
	'additionalProperties' => false,
);

ok( null === sn_mcp_input_schema_violation( array( 'name' => 'A', 'description' => 'B' ), $sn_in_schema ), 'input checker: a conforming call yields null' );

$sn_v = sn_mcp_input_schema_violation( array( 'name' => 'A', 'description' => 'B', 'dry_run' => true ), $sn_in_schema );
ok( is_string( $sn_v ) && '' !== $sn_v, 'input checker: THE dry_run CASE — an undeclared key is a violation, not a silent drop' );
ok( is_string( $sn_v ) && false !== strpos( $sn_v, 'dry_run' ), 'the violation NAMES the offending key, so the caller can fix it' );

ok( is_string( sn_mcp_input_schema_violation( array( 'name' => 'A' ), $sn_in_schema ) ), 'a missing required property is a violation' );
ok( is_string( sn_mcp_input_schema_violation( array( 'name' => 1, 'description' => 'B' ), $sn_in_schema ) ), 'a wrong scalar type is a violation' );

// It adds NO rules: a schema that does not declare the constraint stays permissive.
$sn_open = array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string' ) ) );
ok( null === sn_mcp_input_schema_violation( array( 'name' => 'A', 'extra' => 1 ), $sn_open ), 'a schema without additionalProperties:false still accepts extra keys' );

// FAIL-OPEN, exactly like the output side: an empty/absent schema yields no verdict.
ok( null === sn_mcp_input_schema_violation( array( 'anything' => 1 ), array() ), 'an empty schema yields no verdict, never a rejection' );

// VACUITY GUARD: if the harness stub ignored additionalProperties, every
// assertion above would pass while proving nothing. Prove the stub bites.
$sn_probe = rest_validate_value_from_schema( array( 'name' => 'A', 'description' => 'B', 'nope' => 1 ), $sn_in_schema, 'arguments' );
ok( is_wp_error( $sn_probe ), 'the harness validator itself rejects undeclared keys (assertions above are not vacuous)' );

// WIRING. The ordering pin stays a source check - "before execute()" is a
// property of the CODE's shape and there is no other way to see it. The DOOR
// scoping does not: it used to be pinned by searching for the literal string
// `SN_MCP_DOOR_RW === $door && function_exists( ... )`, which names a FILE for
// what is a property of BEHAVIOUR, and would have gone red on a pure rename.
// It is now driven through the dispatcher on both doors instead (#986).
$sn_src = file_get_contents( __DIR__ . '/../inc/mcp/mcp-tools.php' );
$sn_gate = strpos( $sn_src, 'sn_mcp_input_schema_violation(' . "\n" );
$sn_exec = strpos( $sn_src, '$ability->execute( $args )' );
ok( false !== $sn_gate && false !== $sn_exec && $sn_gate < $sn_exec, 'the gate runs BEFORE execute(), so a rejected call mutates nothing' );

// BEHAVIOUR: an undeclared argument is refused on BOTH doors.
//
// Until v13.96.1 the gate ran on the write door only. The read door had no
// input validation at ANY layer - upstream's execute() does not validate
// (class-wp-ability.php: "input is not automatically validated against the
// input schema"), and only the REST run-route calls validate_input(). So one
// declaration was enforced three different ways depending on which door the
// caller used, while every read ability's docblock said "Validated against
// input_schema above".
//
// The stock fixtures declare `input_schema => array()`, which makes the gate
// fail OPEN by design - an absent schema yields no verdict. Asserting against
// them would have "passed" for the wrong reason on the read door and proved
// nothing, so both doors are re-fixtured here with a schema that actually
// constrains. The slugs are kept because each is already allowlisted for its
// door and an un-allowlisted slug is refused before the gate is reached.
$sn_strict = array(
	'type'                 => array( 'object', 'null' ),
	'properties'           => array( 'declared' => array( 'type' => 'string' ) ),
	'additionalProperties' => false,
);
$GLOBALS['__abilities']['signal-noise/get-health-scan'] = new SN_Test_Ability( 'signal-noise/get-health-scan', array(
	'input_schema' => $sn_strict, 'result' => array( 'status' => 'green' ),
) );
$GLOBALS['__abilities']['signal-noise/ai-pair-suggest'] = new SN_Test_Ability( 'signal-noise/ai-pair-suggest', array(
	'input_schema' => $sn_strict, 'result' => array( 'ok' => true ),
) );

foreach ( array( 'READ' => SN_MCP_DOOR_READ, 'WRITE' => SN_MCP_DOOR_RW ) as $sn_dn => $sn_door ) {
	// The write door is rate limited, and the rw calls earlier in this file have
	// already spent the budget - without this reset the probe returns -32000
	// ("Rate limit exceeded") and never reaches the gate, which would read as
	// "the gate does not fire" when the call simply never got there. The
	// limiter's state is these two harness globals.
	$GLOBALS['__transients'] = array();
	$GLOBALS['__wp_cache']   = array();

	$sn_tool = 'READ' === $sn_dn ? 'signal-noise__get-health-scan' : 'signal-noise__ai-pair-suggest';
	$sn_bad  = sn_mcp_call_tool( $sn_tool, array( 'totally_undeclared_knob' => 1 ), $sn_door );
	$sn_code = $sn_bad['error']['code'] ?? null;
	$sn_msg  = (string) ( $sn_bad['error']['message'] ?? '' );
	ok( -32602 === $sn_code,
		"$sn_dn door: an undeclared argument is refused (-32602), not silently dropped - got " . var_export( $sn_code, true ) );
	ok( false !== strpos( $sn_msg, 'totally_undeclared_knob' ),
		"$sn_dn door: the refusal NAMES the offending key" );
}

// ...and the common path is untouched: a no-argument read call still succeeds.
// Read schemas declare type [object,null] precisely because a bodyless call
// delivers null, so the gate must have no opinion about it. Without this line,
// "both doors reject" could be satisfied by a gate that rejects everything.
$sn_noarg = sn_mcp_call_tool( 'signal-noise__get-health-scan', array(), SN_MCP_DOOR_READ );
ok( ! isset( $sn_noarg['error'] ), 'READ door: a no-argument call is NOT collateral damage of the gate' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
