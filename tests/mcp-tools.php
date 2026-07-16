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
		public function get_error_message() { return $this->message; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

// A lightweight WP_Ability stand-in + a registry wp_get_ability() reads.
class SN_Test_Ability {
	private $n, $label, $desc, $in, $out, $perm, $result;
	public function __construct( $n, $args ) {
		$this->n = $n; $this->label = $args['label'] ?? ''; $this->desc = $args['description'] ?? '';
		$this->in = $args['input_schema'] ?? array(); $this->out = $args['output_schema'] ?? array();
		$this->perm = $args['perm'] ?? true; $this->result = $args['result'] ?? null;
	}
	public function get_name() { return $this->n; }
	public function get_label() { return $this->label; }
	public function get_description() { return $this->desc; }
	public function get_input_schema() { return $this->in; }
	public function get_output_schema() { return $this->out; }
	public function check_permissions( $i = null ) { return $this->perm; }
	public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';

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
$GLOBALS['__abilities']['signal-noise/get-insights'] = new SN_Test_Ability( 'signal-noise/get-insights', array( 'perm' => false, 'result' => array( 'x' => 1 ) ) );
$denied = sn_mcp_call_tool( 'signal-noise__get-insights', array() );
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
$GLOBALS['__abilities']['signal-noise/get-insights'] = new SN_Test_Ability( 'signal-noise/get-insights', array(
	'label' => 'Get insights', 'description' => 'Cached insights, or null pre-scan.',
	'output_schema' => array( 'type' => array( 'object', 'null' ) ),
	'result' => array(),
) );
$ic = sn_mcp_call_tool( 'signal-noise__get-insights', array() );
ok( is_object( $ic['result']['structuredContent']['result'] ?? null ), 'wrapped empty-array result: inner value casts to an object so it encodes {} not []' );

// --- P2/P3: object|null-rooted ability returning null wraps too — null stays legal
//     INSIDE properties.result (the get-narration/get-insights no-scan-yet class) ---
$GLOBALS['__abilities']['signal-noise/get-narration'] = new SN_Test_Ability( 'signal-noise/get-narration', array(
	'label' => 'Get narration', 'description' => 'Cached narration, or null pre-generation.',
	'output_schema' => array(
		'type'       => array( 'object', 'null' ),
		'properties' => array( 'headline' => array( 'type' => array( 'string', 'null' ) ) ),
	),
	'result' => null,
) );
$nt = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-narration'] );
ok( ( $nt['outputSchema']['type'] ?? '' ) === 'object', 'object|null-rooted ability: advertised outputSchema wraps to type object' );
$result_schema_type = $nt['outputSchema']['properties']['result']['type'] ?? null;
ok( is_array( $result_schema_type ) && in_array( 'null', $result_schema_type, true ) && in_array( 'object', $result_schema_type, true ), 'wrapped schema properties.result still allows the original ["object","null"] union untouched' );

$nc = sn_mcp_call_tool( 'signal-noise__get-narration', array() );
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

// --- D4: rw-door tools carry NO annotations in v1 (don't launder known-wrong ones) ---
$rw_tool = sn_mcp_project_tool( $GLOBALS['__abilities']['signal-noise/get-health-scan'], SN_MCP_DOOR_RW );
ok( ! isset( $rw_tool['annotations'] ), 'rw-door projection: no annotations key at all (v1)' );

// --- tools/list is door-aware: only projects abilities on the resolved door's
//     allowlist, and only those that resolve via wp_get_ability ---
$GLOBALS['__abilities']['signal-noise/ai-alt-suggest'] = new SN_Test_Ability( 'signal-noise/ai-alt-suggest', array(
	'label' => 'Suggest alt text', 'result' => array( 'suggestion' => 'a cat' ),
) );
$read_list = sn_mcp_list_tools( SN_MCP_DOOR_READ );
$read_names = array_column( $read_list['tools'], 'name' );
ok( ! in_array( 'signal-noise__ai-alt-suggest', $read_names, true ), 'tools/list(read): an rw-only ability is not projected' );

$rw_list = sn_mcp_list_tools( SN_MCP_DOOR_RW );
$rw_names = array_column( $rw_list['tools'], 'name' );
ok( in_array( 'signal-noise__ai-alt-suggest', $rw_names, true ), 'tools/list(rw): the rw-only ability IS projected' );
ok( ! in_array( 'signal-noise__get-health-scan', $rw_names, true ), 'tools/list(rw): a read-only ability is not projected (no duplication across doors)' );
foreach ( $rw_list['tools'] as $t ) {
	ok( ! isset( $t['annotations'] ), "tools/list(rw): projected tool '{$t['name']}' carries no annotations" );
}

// --- D6: per-door CALL gating — the security property holds at the call gate,
//     not just the advertised list ---
$rw_only_on_read = sn_mcp_call_tool( 'signal-noise__ai-alt-suggest', array(), SN_MCP_DOOR_READ );
ok( isset( $rw_only_on_read['error'] ) && -32602 === $rw_only_on_read['error']['code'], 'an rw-only slug called on the read door -> unknown tool (-32602), never executes' );

$rw_only_on_rw = sn_mcp_call_tool( 'signal-noise__ai-alt-suggest', array(), SN_MCP_DOOR_RW );
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
