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
	private $n, $result; public function __construct( $n, $a ) { $this->n = $n; $this->result = $a['result'] ?? null; }
	public function get_name() { return $this->n; } public function get_label() { return 'L'; }
	public function get_description() { return 'D'; } public function get_input_schema() { return array(); }
	public function get_output_schema() { return array(); }
	public function check_permissions( $i = null ) { return true; } public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array( 'signal-noise/get-health-scan' => new SN_Test_Ability( 'signal-noise/get-health-scan', array( 'result' => array( 'status' => 'green' ) ) ) );
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
