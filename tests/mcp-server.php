<?php
/**
 * Standalone tests for the MCP server module: JSON-RPC 2.0 envelope, the method
 * router (initialize, tools/list, tools/call, ping), error codes, and
 * notification handling (no id → no response). Sub-project B.
 *
 * @since plugin v9.22.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '9.22.0' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $message; public function __construct( $c = '', $m = '' ) { $this->message = $m; }
		public function get_error_message() { return $this->message; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'name' === $k ? 'Signal & Noise' : ''; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

class SN_Test_Ability {
	private $n, $result, $out;
	// output_schema defaults to array() (unchanged prior behavior) unless a test
	// supplies one — needed to exercise the P1-P3 wrap rule end-to-end.
	public function __construct( $n, $a ) { $this->n = $n; $this->result = $a['result'] ?? null; $this->out = $a['output_schema'] ?? array(); }
	public function get_name() { return $this->n; } public function get_label() { return 'L'; }
	public function get_description() { return 'D'; } public function get_input_schema() { return array(); }
	public function get_output_schema() { return $this->out; }
	public function check_permissions( $i = null ) { return true; } public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array( 'signal-noise/get-health-scan' => new SN_Test_Ability( 'signal-noise/get-health-scan', array( 'result' => array( 'status' => 'green' ) ) ) );
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-resources.php';
require __DIR__ . '/../inc/mcp/mcp-prompts.php';
require __DIR__ . '/../inc/mcp/mcp-server.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP server — plugin v9.22.0\n\n";

// initialize
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18' ) ) );
ok( ( $r['result']['protocolVersion'] ?? '' ) === '2025-06-18', 'initialize negotiates the protocol version' );
ok( ( $r['result']['capabilities']['tools']['listChanged'] ?? null ) === false, 'initialize declares listChanged:false' );
ok( ( $r['result']['serverInfo']['name'] ?? '' ) === 'Signal & Noise', 'initialize returns serverInfo' );
ok( ( $r['id'] ?? null ) === 1 && ( $r['jsonrpc'] ?? '' ) === '2.0', 'response echoes id + jsonrpc 2.0' );

// tools/list
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) );
ok( isset( $r['result']['tools'] ) && count( $r['result']['tools'] ) === 1, 'tools/list returns the projected tool' );

// tools/call success
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'signal-noise__get-health-scan', 'arguments' => array() ) ) );
ok( ( $r['result']['isError'] ?? null ) === false, 'tools/call returns a tool result' );

// tools/call unknown tool → JSON-RPC error
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => array( 'name' => 'signal-noise__nope' ) ) );
ok( ( $r['error']['code'] ?? null ) === -32602, 'tools/call with an unknown tool → -32602 error' );

// ping
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping' ) );
ok( isset( $r['result'] ) && ! isset( $r['error'] ), 'ping returns an (empty) result' );

// unknown method (a request with id) → -32601
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'does/not-exist' ) );
ok( ( $r['error']['code'] ?? null ) === -32601, 'unknown method → -32601 method not found' );

// notification (no id) → no response
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
ok( null === $r, 'a notification (no id) yields no response' );

// bad envelope (missing jsonrpc) → -32600
$r = sn_mcp_handle_request( array( 'id' => 7, 'method' => 'ping' ) );
ok( ( $r['error']['code'] ?? null ) === -32600, 'missing jsonrpc version → -32600 invalid request' );

// --- end-to-end wrap belt: an array-rooted tool called through the full JSON-RPC
//     path wraps structuredContent (the class of bug that broke list-cron-events /
//     get-cron-history live — see inc/mcp/mcp-tools.php sn_mcp_schema_needs_wrap) ---
$GLOBALS['__abilities']['signal-noise/list-cron-events'] = new SN_Test_Ability( 'signal-noise/list-cron-events', array(
	'output_schema' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
	'result'        => array( array( 'hook' => 'sn_daily' ) ),
) );
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => array( 'name' => 'signal-noise__list-cron-events', 'arguments' => array() ) ) );
ok( ( $r['result']['structuredContent']['result'][0]['hook'] ?? '' ) === 'sn_daily', 'array-rooted tool called end-to-end wraps structuredContent as {result:[...]}' );

// --- end-to-end passthrough pin: an object-rooted tool stays unwrapped ---
$GLOBALS['__abilities']['signal-noise/get-rss-stats'] = new SN_Test_Ability( 'signal-noise/get-rss-stats', array(
	'output_schema' => array( 'type' => 'object' ),
	'result'        => array( 'ok' => true ),
) );
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => array( 'name' => 'signal-noise__get-rss-stats', 'arguments' => array() ) ) );
ok( ( $r['result']['structuredContent']['ok'] ?? null ) === true && ! array_key_exists( 'result', $r['result']['structuredContent'] ), 'object-rooted tool called end-to-end stays a byte-identical passthrough' );

// ============================================================
// v9.50.0 — door-aware dispatch + resources/prompts (lane PROTO)
// ============================================================
echo "\nMCP server — door-aware dispatch + resources/prompts (v9.50.0)\n\n";

// --- initialize: capabilities now also advertise resources + prompts ---
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 10, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18' ) ) );
ok( ( $r['result']['capabilities']['tools']['listChanged'] ?? null ) === false, 'sanity: tools capability unchanged' );
ok( ( $r['result']['capabilities']['resources']['listChanged'] ?? null ) === false, 'initialize declares a resources capability (listChanged:false)' );
ok( ( $r['result']['capabilities']['prompts']['listChanged'] ?? null ) === false, 'initialize declares a prompts capability (listChanged:false)' );

// --- initialize: $door threads into serverInfo (a no-op arg pre-v9.50.0, per
//     lane DOORS' report — this closes it) ---
$r_read = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 11, 'method' => 'initialize' ), SN_MCP_DOOR_READ );
ok( ( $r_read['result']['serverInfo']['name'] ?? '' ) === 'Signal & Noise', 'initialize on the read door (explicit) keeps the unsuffixed name' );
$r_rw = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 12, 'method' => 'initialize' ), SN_MCP_DOOR_RW );
ok( stripos( $r_rw['result']['serverInfo']['name'] ?? '', 'read-write' ) !== false, 'initialize on the rw door names serverInfo "(read-write)"' );

// --- tools/list threads $door through the full router (not just sn_mcp_list_tools directly) ---
$GLOBALS['__abilities']['signal-noise/ai-alt-suggest'] = new SN_Test_Ability( 'signal-noise/ai-alt-suggest', array( 'result' => array( 'suggestion' => 'a cat' ) ) );
$r_read_list = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/list' ), SN_MCP_DOOR_READ );
$read_names  = array_column( $r_read_list['result']['tools'], 'name' );
ok( ! in_array( 'signal-noise__ai-alt-suggest', $read_names, true ), 'router: tools/list(read) omits an rw-only ability' );
$r_rw_list = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/list' ), SN_MCP_DOOR_RW );
$rw_names  = array_column( $r_rw_list['result']['tools'], 'name' );
ok( in_array( 'signal-noise__ai-alt-suggest', $rw_names, true ), 'router: tools/list(rw) includes the rw-only ability' );

// --- tools/call threads $door through the full router (the call-gate security
//     property proven end-to-end, not just at sn_mcp_call_tool directly) ---
$r_denied = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 15, 'method' => 'tools/call', 'params' => array( 'name' => 'signal-noise__ai-alt-suggest', 'arguments' => array() ) ), SN_MCP_DOOR_READ );
ok( ( $r_denied['error']['code'] ?? null ) === -32602, 'router: an rw-only tool called on the read door -> unknown tool' );
$r_allowed = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 16, 'method' => 'tools/call', 'params' => array( 'name' => 'signal-noise__ai-alt-suggest', 'arguments' => array() ) ), SN_MCP_DOOR_RW );
ok( ( $r_allowed['result']['isError'] ?? null ) === false, 'router: the same tool called on the rw door succeeds' );

// --- resources/list + resources/read (R1/R2) ---
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 17, 'method' => 'resources/list' ) );
ok( isset( $r['result']['resources'] ) && count( $r['result']['resources'] ) === 4, 'resources/list returns the 4 R2 resources' );

$GLOBALS['__abilities']['signal-and-noise/get-design-tokens'] = new SN_Test_Ability( 'signal-and-noise/get-design-tokens', array( 'result' => array( 'colors' => array( 'accent' => '#123456' ) ) ) );
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 18, 'method' => 'resources/read', 'params' => array( 'uri' => 'sn://design-tokens' ) ) );
ok( ( $r['result']['contents'][0]['uri'] ?? '' ) === 'sn://design-tokens', 'resources/read happy path returns the requested uri' );
$decoded_tokens = json_decode( $r['result']['contents'][0]['text'] ?? '', true );
ok( ( $decoded_tokens['colors']['accent'] ?? '' ) === '#123456', 'resources/read(sn://design-tokens) end-to-end passes through the ability result' );

// --- R4: unknown resource uri -> -32602 JSON-RPC error ---
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 19, 'method' => 'resources/read', 'params' => array( 'uri' => 'sn://nope' ) ) );
ok( ( $r['error']['code'] ?? null ) === -32602, 'resources/read with an unknown uri -> -32602' );

// --- prompts/list + prompts/get (R1/R3) ---
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 20, 'method' => 'prompts/list' ) );
ok( isset( $r['result']['prompts'] ) && count( $r['result']['prompts'] ) === 2, 'prompts/list returns the 2 R3 prompts' );

$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 21, 'method' => 'prompts/get', 'params' => array( 'name' => 'weekly-report' ) ) );
ok( ( $r['result']['messages'][0]['role'] ?? '' ) === 'user', 'prompts/get(weekly-report) end-to-end returns a user message' );
ok( false !== strpos( $r['result']['messages'][0]['content']['text'] ?? '', 'get-analytics-summary' ), 'prompts/get(weekly-report) end-to-end names get-analytics-summary' );

// --- R4: unknown prompt name -> -32602 JSON-RPC error ---
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 22, 'method' => 'prompts/get', 'params' => array( 'name' => 'does-not-exist' ) ) );
ok( ( $r['error']['code'] ?? null ) === -32602, 'prompts/get with an unknown name -> -32602' );

// --- notifications/ping behavior is unchanged by the door param (sanity) ---
$r = sn_mcp_handle_request( array( 'jsonrpc' => '2.0', 'id' => 23, 'method' => 'ping' ), SN_MCP_DOOR_RW );
ok( isset( $r['result'] ) && ! isset( $r['error'] ), 'ping is unaffected by an explicit rw door' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
