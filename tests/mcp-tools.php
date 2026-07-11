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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
