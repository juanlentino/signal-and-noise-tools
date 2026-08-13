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

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code()    { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data()    { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return $v instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
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
// v9.51.0 (lane SEC-A): the rw guard's live-state seams.
$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; } }
$GLOBALS['__app_pw_uuid'] = null; // what rest_get_authenticated_app_password() returns for the current request.
if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) { function rest_get_authenticated_app_password() { return $GLOBALS['__app_pw_uuid']; } }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-tools.php';
require __DIR__ . '/../inc/mcp/mcp-server.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';
require __DIR__ . '/../inc/mcp/mcp-read-guard.php'; // v10.9.0: read kill switch
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
// v9.51.0 froze the read route on the literal 'sn_mcp_permission'; v10.9.0
// AMENDS that pin deliberately (owner-requested read kill switch): the route
// now uses the layered sn_mcp_read_permission(), whose floor is still the
// byte-identical sn_mcp_permission() and which never calls mcp-rw-guard.php.
ok( ( $read_route['args']['permission_callback'] ?? '' ) === 'sn_mcp_read_permission', 'v10.9.0: the read route uses the layered read guard (kill switch → the unchanged sn_mcp_permission floor)' );
ok( ( $rw_route['args']['permission_callback'] ?? '' ) === 'sn_mcp_rw_permission', 'v9.51.0: the rw route now uses its OWN hardened permission_callback, sn_mcp_rw_permission' );
ok( ( $rw_route['args']['permission_callback'] ?? '' ) !== ( $read_route['args']['permission_callback'] ?? '' ),
	'the two doors no longer share a permission_callback (the credential-split finding: a leaked read credential used to be exactly as dangerous as a write one)' );
ok( ( $rw_route['args']['callback'] ?? '' ) !== ( $read_route['args']['callback'] ?? '' ), 'the rw route uses its OWN REST callback (distinct door context)' );
ok( function_exists( 'sn_mcp_rw_rest_callback' ), 'sn_mcp_rw_rest_callback() is defined' );
ok( function_exists( 'sn_mcp_rw_permission' ), 'sn_mcp_rw_permission() is defined' );

// --- D3: door context resolved from the route, passed toward the handler —
//     dispatch_body accepts a $door and forwards it; defaulting preserves the
//     read door's existing (pre-v9.50.0) behavior exactly ---
$out = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}' );
ok( $out['status'] === 200 && isset( $out['payload']['result'] ), 'sanity: dispatch_body with NO door arg still behaves exactly as before (read default)' );
$out_rw = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}', SN_MCP_DOOR_RW );
ok( $out_rw['status'] === 200 && isset( $out_rw['payload']['result'] ), 'dispatch_body accepts an explicit rw door and still dispatches successfully' );

// ============================================================
// v9.51.0 (lane SEC-A) — sn_mcp_rw_permission(): kill switch + credential split
// ============================================================
echo "\nMCP rw-door permission floor (v9.51.0, lane SEC-A)\n\n";

function sn_test_reset_rw_guard_state() {
	$GLOBALS['__opts']        = array();
	$GLOBALS['__app_pw_uuid'] = null;
	$GLOBALS['__cap']         = true;
}

// --- R1 DECISION pinned: fresh install (no bound credential yet) = deny-closed ---
sn_test_reset_rw_guard_state();
$result = sn_mcp_rw_permission();
ok( is_wp_error( $result ), 'R1 DECISION: with no bound rw credential, sn_mcp_rw_permission() denies (deny-closed on unbound state)' );
ok( is_wp_error( $result ) && ( $result->get_error_data()['status'] ?? null ) === 403, 'the unbound-state denial is a 403' );
ok( is_wp_error( $result ) && 'sn_mcp_rw_rw_credential_unbound' === $result->get_error_code(), 'the unbound-state denial carries the rw_credential_unbound code' );

// --- R2: kill switch wins even with a valid bound+matching credential ---
sn_test_reset_rw_guard_state();
$uuid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
update_option( 'sn_mcp_rw_app_password_uuid', $uuid );
$GLOBALS['__app_pw_uuid'] = $uuid;
ok( sn_mcp_rw_permission() === true, 'sanity: bound + matching credential + no kill switch -> allow' );

update_option( 'sn_mcp_rw_enabled', false );
$result = sn_mcp_rw_permission();
ok( is_wp_error( $result ) && 'sn_mcp_rw_rw_disabled' === $result->get_error_code(), 'R2: the option kill switch denies rw even with a perfectly valid bound credential' );
update_option( 'sn_mcp_rw_enabled', true );
ok( sn_mcp_rw_permission() === true, 'resetting the kill switch option restores the allow' );

// --- R2: kill switch is checked BEFORE manage_options (non-admin still gets the 403, not the plain-false denial) ---
sn_test_reset_rw_guard_state();
update_option( 'sn_mcp_rw_enabled', false );
$GLOBALS['__cap'] = false; // not an admin either
$result           = sn_mcp_rw_permission();
ok( is_wp_error( $result ) && 'sn_mcp_rw_rw_disabled' === $result->get_error_code(),
	'R2: the kill switch fires first — a non-admin request during a kill-switch outage still gets rw_disabled, not the plain admin-floor denial' );

// --- credential mismatch / no app-password auth, once the admin floor + kill switch are clear ---
sn_test_reset_rw_guard_state();
update_option( 'sn_mcp_rw_app_password_uuid', $uuid );
$GLOBALS['__app_pw_uuid'] = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'; // a DIFFERENT app password, still an admin
$result                   = sn_mcp_rw_permission();
ok( is_wp_error( $result ) && 'sn_mcp_rw_credential_not_authorized' === $result->get_error_code(),
	'R1: an admin authenticated with the WRONG application password is denied credential_not_authorized' );

sn_test_reset_rw_guard_state();
update_option( 'sn_mcp_rw_app_password_uuid', $uuid );
$GLOBALS['__app_pw_uuid'] = null; // cookie+nonce admin session, no app-password auth at all
$result                   = sn_mcp_rw_permission();
ok( is_wp_error( $result ) && 'sn_mcp_rw_credential_not_authorized' === $result->get_error_code(),
	'R1: a cookie-authenticated admin (no application password on this request) is denied credential_not_authorized even though manage_options passes' );

// --- the existing non-admin denial shape is preserved when nothing else is wrong ---
sn_test_reset_rw_guard_state();
update_option( 'sn_mcp_rw_app_password_uuid', $uuid );
$GLOBALS['__app_pw_uuid'] = $uuid;
$GLOBALS['__cap']         = false;
ok( sn_mcp_rw_permission() === false, 'a non-admin gets the plain `false` denial (same failure shape as the pre-v9.51.0 floor), not a WP_Error' );

// ============================================================
// READ-DOOR-FROZEN: sn_mcp_permission() and the /mcp path are provably
// unaffected by every rw-guard state permutation above.
// ============================================================
echo "\nRead-door frozen proof (v9.51.0)\n\n";

sn_test_reset_rw_guard_state();
$GLOBALS['__cap'] = true;
ok( sn_mcp_permission() === true, 'read-door frozen: admin still passes with a clean rw-guard state' );

update_option( 'sn_mcp_rw_enabled', false ); // rw kill switch ON
update_option( 'sn_mcp_rw_app_password_uuid', $uuid ); // rw credential bound
$GLOBALS['__app_pw_uuid'] = 'ffffffff-ffff-ffff-ffff-ffffffffffff'; // mismatched vs the rw-bound uuid
ok( sn_mcp_permission() === true, 'read-door frozen: admin still passes even while the rw kill switch is engaged and the rw credential would deny' );
$out = sn_mcp_dispatch_body( '{"jsonrpc":"2.0","id":1,"method":"ping"}' ); // default door = read
ok( $out['status'] === 200 && isset( $out['payload']['result'] ), 'read-door frozen: the read-door dispatch path is unaffected by any rw-guard option state' );

$GLOBALS['__cap'] = false;
ok( sn_mcp_permission() === false, 'read-door frozen: a non-admin still fails read-door permission regardless of rw-guard state' );
sn_test_reset_rw_guard_state();

// ============================================================
// v10.9.0: read-door kill switch (mcp-read-guard.php)
// ============================================================
echo "\nRead-door kill switch (v10.9.0)\n\n";

// Pure predicate truth table (mirror of the rw switch's semantics).
ok( true === sn_mcp_read_kill_switch_decision( true, true ), 'constant disabled wins even when the option says enabled' );
ok( true === sn_mcp_read_kill_switch_decision( false, false ), 'option off → disabled' );
ok( false === sn_mcp_read_kill_switch_decision( false, true ), 'constant unset + option on → enabled' );
ok( true === sn_mcp_read_kill_switch_decision( true, false ), 'constant disabled + option off → disabled (the fourth combination; the table is now exhaustive)' );

// THE LIVE WRAPPER'S DEFAULT, pinned directly.
//
// The predicate above is pure — it RECEIVES $option_enabled and can say nothing
// about what the live caller passes. The read door's FAIL-OPEN-ON-ABSENCE
// property lives entirely in the `true` default of the get_option() call inside
// sn_mcp_read_kill_switch_engaged(): an untouched option means "the owner never
// turned it off", so the door stays open.
//
// That default sits one file away from the remote door's, which deliberately
// fails CLOSED. Adjacent switches pointing opposite directions is exactly the
// shape that invites someone to "fix the inconsistency" — flipping this `true`
// to `false` would darken the read door for every caller who never touched the
// option. These two assertions are what stops that from being a silent change.
// They exercise the wrapper directly rather than through
// sn_mcp_read_permission(), so they survive any refactor of the permission
// callback's shape or its capability coupling.
unset( $GLOBALS['__opts'][ SN_MCP_READ_ENABLED_OPTION ] );
ok( false === sn_mcp_read_kill_switch_engaged(), 'live wrapper: option ABSENT from the store → NOT engaged (the get_option() default is `true`; the read door fails OPEN on absence)' );
update_option( SN_MCP_READ_ENABLED_OPTION, false );
ok( true === sn_mcp_read_kill_switch_engaged(), 'live wrapper: option present and false → engaged (the owner\'s explicit off is honoured)' );

$GLOBALS['__cap'] = true;
unset( $GLOBALS['__opts'][ SN_MCP_READ_ENABLED_OPTION ] );
ok( sn_mcp_read_permission() === true, 'absent option (owner never touched it) → read door open for an admin (fail-open-on-absence, same as rw)' );

update_option( SN_MCP_READ_ENABLED_OPTION, false ); // read kill switch ON
$deny = sn_mcp_read_permission();
ok( $deny instanceof WP_Error && 'sn_mcp_read_disabled' === $deny->get_error_code(), 'engaged switch → WP_Error sn_mcp_read_disabled BEFORE the manage_options floor (tools/list can never leak while dark)' );

// Door isolation, both directions: the read switch never touches the rw door…
sn_test_reset_rw_guard_state();
update_option( 'sn_mcp_rw_enabled', true );
update_option( 'sn_mcp_rw_app_password_uuid', $uuid );
$GLOBALS['__app_pw_uuid'] = $uuid;
ok( sn_mcp_rw_permission() === true, 'read kill switch engaged does NOT close the rw door (guards are isolated by design)' );
// …and the rw switch never touches the read door (re-proving the v9.51.0
// frozen-proof under the NEW layered callback).
update_option( SN_MCP_READ_ENABLED_OPTION, true );
update_option( 'sn_mcp_rw_enabled', false ); // rw kill switch ON
ok( sn_mcp_read_permission() === true, 'rw kill switch engaged does NOT close the read door (the layered callback preserves the frozen-proof)' );

$GLOBALS['__cap'] = false;
ok( sn_mcp_read_permission() === false, 'switch off + non-admin → the same plain false shape as the pre-v10.9.0 floor' );
unset( $GLOBALS['__opts'][ SN_MCP_READ_ENABLED_OPTION ] );
sn_test_reset_rw_guard_state();

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
