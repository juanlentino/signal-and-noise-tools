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
$GLOBALS['__abilities'] = array();
if ( ! function_exists( 'wp_get_ability' ) ) { function wp_get_ability( $name ) { return $GLOBALS['__abilities'][ $name ] ?? null; } }

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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
