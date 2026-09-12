<?php
/**
 * Standalone tests: the 2026-07-28 revision on the MCP doors, dual-era.
 *
 * Mirrors sn-remote-mcp-worker/test/modern.test.mjs case for case: era
 * selection, server/discover, the seven validation checks in spec order and
 * their HTTP statuses, the base64 Mcp-Name sentinel, legacy-only methods at
 * 404, result decoration, and that the legacy handshake is byte-identical.
 *
 * @since plugin v14.2.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '14.2.0' ); }
if ( ! defined( 'SN_REST_NAMESPACE' ) ) { define( 'SN_REST_NAMESPACE', 'signal-noise/v1' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'name' === $k ? 'Signal & Noise' : ''; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) { function rest_get_authenticated_app_password() { return null; } }

class SN_Test_Ability {
	private $n, $result;
	public function __construct( $n, $r ) { $this->n = $n; $this->result = $r; }
	public function get_name() { return $this->n; } public function get_label() { return 'L'; } public function get_description() { return 'D'; }
	public function get_input_schema() { return array(); } public function get_output_schema() { return array(); }
	public function check_permissions( $i = null ) { return true; } public function execute( $i = null ) { return $this->result; }
}
$GLOBALS['__abilities'] = array( 'signal-noise/get-health-scan' => new SN_Test_Ability( 'signal-noise/get-health-scan', array( 'status' => 'green' ) ) );
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-resources.php';
require __DIR__ . '/../inc/mcp/mcp-prompts.php';
require __DIR__ . '/../inc/mcp/mcp-server.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';
require __DIR__ . '/../inc/mcp/mcp-read-guard.php';
require __DIR__ . '/../inc/mcp/mcp-endpoint.php';
require __DIR__ . '/../inc/mcp/mcp-modern.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

const V = '2026-07-28';
const META = 'io.modelcontextprotocol/';
function meta( $over = array() ) { return array_merge( array( META . 'protocolVersion' => V, META . 'clientCapabilities' => (object) array(), META . 'clientInfo' => array( 'name' => 't', 'version' => '0' ) ), $over ); }
function modern( $method, $params = array(), $headers = array(), $id = 7 ) {
	$body = json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => array_merge( $params, array( '_meta' => meta() ) ) ) );
	return sn_mcp_dispatch_body( $body, SN_MCP_DOOR_READ, array_merge( array( 'mcp-protocol-version' => V, 'mcp-method' => $method ), $headers ) );
}
function legacy( $msg ) { return sn_mcp_dispatch_body( json_encode( $msg ), SN_MCP_DOOR_READ ); }

echo "MCP doors — the 2026-07-28 revision, dual-era (v14.2.0)\n\n";

// --- era selection ---
ok( sn_mcp_is_modern_request( array( 'mcp-protocol-version' => V ), array() ), 'the header alone selects the modern era' );
ok( sn_mcp_is_modern_request( array(), array( 'params' => array( '_meta' => meta() ) ) ), 'modern _meta alone selects the modern era' );
ok( ! sn_mcp_is_modern_request( array( 'mcp-protocol-version' => '2025-11-25' ), array( 'method' => 'initialize', 'params' => array() ) ), 'a legacy header + initialize is legacy' );

$r = legacy( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25' ) ) );
ok( 200 === $r['status'] && '2025-11-25' === ( $r['payload']['result']['protocolVersion'] ?? '' ), 'legacy initialize now echoes 2025-11-25 (the connector client\'s revision)' );
ok( ! isset( $r['payload']['result']['resultType'] ), 'the legacy result is undecorated — byte-identical to before' );
$r = legacy( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2026-07-28' ) ) );
ok( '2025-06-18' === ( $r['payload']['result']['protocolVersion'] ?? '' ), 'a legacy initialize asking for a modern version is answered with the legacy default, as before' );

// --- server/discover ---
$r = modern( 'server/discover' );
$res = $r['payload']['result'] ?? array();
ok( 200 === $r['status'] && 'complete' === ( $res['resultType'] ?? '' ), 'discover answers 200 / resultType complete' );
ok( ( $res['supportedVersions'] ?? array() ) === sn_mcp_all_protocol_versions() && V === $res['supportedVersions'][0], 'discover lists every version, modern first' );
ok( isset( $res['capabilities']['tools'], $res['capabilities']['resources'], $res['capabilities']['prompts'] ), 'discover carries the three capabilities' );
ok( 'Signal & Noise' === ( $res['_meta'][ META . 'serverInfo' ]['name'] ?? '' ) || '' !== ( $res['_meta'][ META . 'serverInfo' ]['name'] ?? '' ), 'discover names the server in _meta' );
ok( isset( $res['ttlMs'] ) && $res['ttlMs'] >= 0 && 'private' === ( $res['cacheScope'] ?? '' ), 'discover carries ttlMs >= 0 and cacheScope private' );

// --- validation, in the spec's order ---
$r = sn_mcp_dispatch_body( json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => array( META . 'clientCapabilities' => (object) array() ) ) ) ), SN_MCP_DOOR_READ, array( 'mcp-protocol-version' => V, 'mcp-method' => 'tools/list' ) );
ok( 400 === $r['status'] && -32602 === $r['payload']['error']['code'], '1. missing _meta.protocolVersion → -32602 / 400' );

$r = modern( 'tools/list', array(), array( 'mcp-protocol-version' => '2025-11-25' ) );
ok( 400 === $r['status'] && -32020 === $r['payload']['error']['code'], '2. header not equal to body version → HeaderMismatch -32020 / 400' );
$r = sn_mcp_dispatch_body( json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => meta() ) ) ), SN_MCP_DOOR_READ, array( 'mcp-method' => 'tools/list' ) );
ok( 400 === $r['status'] && -32020 === $r['payload']['error']['code'], '2. header absent → HeaderMismatch -32020 / 400' );

$r = sn_mcp_dispatch_body( json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => meta( array( META . 'protocolVersion' => '2027-01-01' ) ) ) ) ), SN_MCP_DOOR_READ, array( 'mcp-protocol-version' => '2027-01-01', 'mcp-method' => 'tools/list' ) );
ok( 400 === $r['status'] && -32022 === $r['payload']['error']['code'], '3. unknown modern version → UnsupportedProtocolVersion -32022 / 400' );
ok( ( $r['payload']['error']['data'] ?? array() ) === array( 'supported' => sn_mcp_all_protocol_versions(), 'requested' => '2027-01-01' ), '3. ...naming every supported version and the one requested' );

$r = modern( 'tools/list', array(), array( 'mcp-method' => 'tools/call' ) );
ok( 400 === $r['status'] && -32020 === $r['payload']['error']['code'], '4. Mcp-Method different from the body → -32020' );
$r = sn_mcp_dispatch_body( json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => meta() ) ) ), SN_MCP_DOOR_READ, array( 'mcp-protocol-version' => V ) );
ok( 400 === $r['status'] && -32020 === $r['payload']['error']['code'], '4. Mcp-Method missing → -32020' );

$tool = 'signal-noise__get-health-scan';
$r = modern( 'tools/call', array( 'name' => $tool, 'arguments' => array() ) );
ok( 400 === $r['status'] && -32020 === $r['payload']['error']['code'], '5. tools/call without Mcp-Name → -32020' );
$r = modern( 'tools/call', array( 'name' => $tool, 'arguments' => array() ), array( 'mcp-name' => 'other' ) );
ok( -32020 === $r['payload']['error']['code'], '5. tools/call with a different Mcp-Name → -32020' );
$r = modern( 'tools/call', array( 'name' => $tool, 'arguments' => array() ), array( 'mcp-name' => '=?base64?' . base64_encode( $tool ) . '?=' ) );
ok( 200 === $r['status'] && 'complete' === ( $r['payload']['result']['resultType'] ?? '' ), '5. the base64 sentinel on Mcp-Name is decoded before comparing' );
ok( false === sn_mcp_decode_header_value( '=?base64?!!!?=' ), '5. a malformed sentinel decodes to false (a mismatch), never to a string' );
$r = modern( 'resources/read', array( 'uri' => 'x://nope' ), array( 'mcp-name' => 'x://other' ) );
ok( -32020 === $r['payload']['error']['code'], '5. resources/read mirrors params.uri in Mcp-Name' );

$r = sn_mcp_dispatch_body( json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array( '_meta' => array( META . 'protocolVersion' => V ) ) ) ), SN_MCP_DOOR_READ, array( 'mcp-protocol-version' => V, 'mcp-method' => 'tools/list' ) );
ok( 400 === $r['status'] && -32602 === $r['payload']['error']['code'], '6. missing _meta.clientCapabilities → -32602 / 400' );

foreach ( array( 'initialize', 'ping', 'notifications/initialized' ) as $m ) {
	$r = modern( $m );
	ok( 404 === $r['status'] && -32601 === $r['payload']['error']['code'], "7. legacy-only method '$m' is not of this era: -32601 / 404" );
}

// --- results ---
$r = modern( 'tools/list' );
$res = $r['payload']['result'];
$leg = legacy( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) )['payload']['result'];
ok( 'complete' === $res['resultType'] && 'private' === $res['cacheScope'] && $res['ttlMs'] >= 0, 'tools/list carries resultType, ttlMs and cacheScope' );
ok( isset( $res['_meta'][ META . 'serverInfo' ] ), 'tools/list names the server in _meta' );
ok( $res['tools'] === $leg['tools'], 'tools/list advertises exactly the legacy tool set' );

$r = modern( 'tools/call', array( 'name' => $tool, 'arguments' => array() ), array( 'mcp-name' => $tool ) );
$res = $r['payload']['result'];
ok( 200 === $r['status'] && 'complete' === $res['resultType'] && ! isset( $res['ttlMs'] ), 'tools/call is decorated with resultType and carries no cache hints' );
$legc = legacy( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => array() ) ) )['payload']['result'];
ok( $res['content'] === $legc['content'], 'tools/call returns the same content as the legacy call' );

$r = modern( 'tools/call', array( 'name' => 'nope', 'arguments' => array() ), array( 'mcp-name' => 'nope' ) );
ok( 200 === $r['status'] && -32602 === $r['payload']['error']['code'], 'an unknown tool is a -32602 RPC error at HTTP 200 (the method exists)' );

$r = sn_mcp_dispatch_body( json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => array( '_meta' => meta() ) ) ), SN_MCP_DOOR_READ, array( 'mcp-protocol-version' => V, 'mcp-method' => 'notifications/cancelled' ) );
ok( 202 === $r['status'] && null === $r['payload'], 'a modern notification is accepted with 202 and no body' );

$r = modern( 'prompts/list' );
ok( 'private' === ( $r['payload']['result']['cacheScope'] ?? '' ), 'prompts/list carries cache hints' );
$r = modern( 'resources/list' );
ok( 'private' === ( $r['payload']['result']['cacheScope'] ?? '' ), 'resources/list carries cache hints' );

// --- the rw door threads through unchanged ---
$body = json_encode( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'server/discover', 'params' => array( '_meta' => meta() ) ) );
$r = sn_mcp_dispatch_body( $body, SN_MCP_DOOR_RW, array( 'mcp-protocol-version' => V, 'mcp-method' => 'server/discover' ) );
ok( 200 === $r['status'] && isset( $r['payload']['result']['_meta'][ META . 'serverInfo' ] ), 'the rw door serves the modern era with its own serverInfo' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
