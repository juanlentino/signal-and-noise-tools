<?php
/**
 * Standalone tests for the MCP endpoint module: the auth floor, the pure
 * dispatch (JSON parse → handle → status), and the sn_agents_surfaces manifest
 * advertisement. Sub-project B.
 *
 * @since plugin v9.22.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '9.22.0' ); }
if ( ! defined( 'SN_REST_NAMESPACE' ) ) { define( 'SN_REST_NAMESPACE', 'signal-noise/v1' ); }

if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function get_error_message() { return ''; } } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'name' === $k ? 'Signal & Noise' : ''; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
$GLOBALS['__cap'] = true; // toggle current_user_can result
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return $GLOBALS['__cap']; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://juanlentino.com/wp-json/' . ltrim( (string) $p, '/' ); } }
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }
$GLOBALS['__routes'] = array(); // recording register_rest_route() stub — proves route registration args.
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args ) {
		$GLOBALS['__routes'][] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
		return true;
	}
}

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-server.php';
require __DIR__ . '/../inc/mcp/mcp-endpoint.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "MCP endpoint — plugin v9.22.0\n\n";

// --- auth floor ---
$GLOBALS['__cap'] = true;
ok( sn_mcp_permission() === true, 'permission passes for an admin (manage_options)' );
$GLOBALS['__cap'] = false;
ok( sn_mcp_permission() === false, 'permission fails for a non-admin' );

// --- dispatch: valid request → 200 + response payload ---
$out = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}' );
ok( $out['status'] === 200 && isset( $out['payload']['result'] ), 'valid request dispatches to a 200 response' );

// --- dispatch: notification → 202 + null payload ---
$out = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
ok( $out['status'] === 202 && null === $out['payload'], 'a notification dispatches to 202 with no payload' );

// --- dispatch: malformed JSON → -32700 parse error ---
$out = sn_mcp_dispatch_body( 'not json{' );
ok( $out['status'] === 200 && ( $out['payload']['error']['code'] ?? null ) === -32700, 'malformed JSON → -32700 parse error' );

// --- manifest advertisement (wires sub-project A's filter) ---
$surfaces = sn_mcp_advertise_surface( array() );
ok( count( $surfaces ) === 1 && $surfaces[0]['type'] === 'mcp', 'advertise_surface appends an mcp entry' );
ok( $surfaces[0]['url'] === 'https://juanlentino.com/wp-json/signal-noise/v1/mcp', 'the advertised url is the endpoint' );
ok( ( $surfaces[0]['auth'] ?? '' ) === 'application-password', 'the entry carries the auth hint' );
// D5: the public manifest names ONLY the read door — an unattended-discovery
// surface should only advertise the unattended-safe door.
ok( false === strpos( $surfaces[0]['url'], 'mcp-rw' ), 'D5: the advertised url is never the rw door' );
ok( 1 === count( $surfaces ), 'D5: exactly one entry is advertised — the rw door gets no manifest entry of its own' );

// ============================================================
// v9.50.0 — the rw door route + door-aware dispatch
// ============================================================
echo "\nMCP endpoint — the rw door (v9.50.0)\n\n";

// --- D3: both routes register on rest_api_init, same namespace, same permission floor ---
$GLOBALS['__routes'] = array();
sn_mcp_register_route();
sn_mcp_register_rw_route();
ok( count( $GLOBALS['__routes'] ) === 2, 'two routes are registered (read + rw)' );

$read_route = null; $rw_route = null;
foreach ( $GLOBALS['__routes'] as $r ) {
	if ( '/mcp' === $r['route'] ) { $read_route = $r; }
	if ( '/mcp-rw' === $r['route'] ) { $rw_route = $r; }
}
ok( null !== $read_route, 'the read route (/mcp) is registered' );
ok( null !== $rw_route, 'the new rw route (/mcp-rw) is registered' );
ok( $rw_route['namespace'] === $read_route['namespace'], 'the rw route shares the same REST namespace as the read route' );
ok( 'POST' === ( $rw_route['args']['methods'] ?? '' ), 'the rw route is POST, same as the read route' );
ok( ( $rw_route['args']['permission_callback'] ?? '' ) === ( $read_route['args']['permission_callback'] ?? '' ),
	'the rw route shares the exact same permission_callback (same manage_options + application-password floor)' );
ok( ( $rw_route['args']['permission_callback'] ?? '' ) === 'sn_mcp_permission', 'the shared permission callback is sn_mcp_permission' );
ok( ( $rw_route['args']['callback'] ?? '' ) !== ( $read_route['args']['callback'] ?? '' ), 'the rw route uses its OWN REST callback (distinct door context)' );
ok( function_exists( 'sn_mcp_rw_rest_callback' ), 'sn_mcp_rw_rest_callback() is defined' );

// --- D3: door context resolved from the route, passed toward the handler —
//     dispatch_body accepts a $door and forwards it; defaulting preserves the
//     read door's existing (pre-v9.50.0) behavior exactly ---
$out = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}' );
ok( $out['status'] === 200 && isset( $out['payload']['result'] ), 'sanity: dispatch_body with NO door arg still behaves exactly as before (read default)' );
$out_rw = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}', SN_MCP_DOOR_RW );
ok( $out_rw['status'] === 200 && isset( $out_rw['payload']['result'] ), 'dispatch_body accepts an explicit rw door and still dispatches successfully' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
