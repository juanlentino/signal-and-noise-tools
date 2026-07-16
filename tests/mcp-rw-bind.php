<?php
/**
 * Standalone tests for R9 (v9.51.0, lane SEC-C): the MCP write-door
 * credential-binding handler — sn_handle_bind_mcp_rw_credential()
 * (inc/admin-post-actions.php), registered as 'bind_mcp_rw_credential' in
 * sn_admin_post_handlers() (inc/admin-post-handler.php). The render side
 * (inc/admin-forms/mcp-connect.php's sn_admin_render_mcp_rw_binding()) is
 * covered in tests/mcp-connect-render.php; this suite drives the POST
 * handler directly, the same "standalone lane suite" idiom as
 * tests/mcp-rw-guard.php / tests/mcp-rw-audit.php.
 *
 * Load-bearing case: the OWNERSHIP check. The dispatcher
 * (sn_handle_admin_post(), inc/admin-post-handler.php) already runs
 * check_admin_referer() + current_user_can('manage_options') before any
 * handler is reached, but capability alone says nothing about which
 * Application Password a POSTed UUID names — that value is fully
 * attacker-controlled. Binding it without verifying it belongs to the
 * CURRENT user's own Application Passwords would let the write door's R1
 * credential check (inc/mcp/mcp-rw-guard.php) trust an unrelated credential.
 *
 * Run: php tests/mcp-rw-bind.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
define( 'SN_MCP_TEST', true );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

// ---- In-memory options store — backs the REAL sn_mcp_rw_bound_uuid()/
// sn_mcp_set_rw_bound_uuid() (inc/mcp/mcp-rw-guard.php, required below and
// never stubbed). ----
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }

// ---- Toggleable current_user_can() — capability-gate test flips this. ----
$GLOBALS['__can_manage_options'] = true;
function current_user_can( $cap, $id = 0 ) {
	if ( 'manage_options' === $cap ) { return $GLOBALS['__can_manage_options']; }
	return true;
}

function get_current_user_id() { return 7; }

// ---- Configurable fixture standing in for the CURRENT user's own
// Application Passwords (real WP_Application_Passwords::get_user_application_
// passwords() is itself scoped to one user id, so a flat fixture is enough — see
// tests/mcp-connect-render.php's identical stub for the render-side tests). ----
$GLOBALS['__app_passwords'] = array();
class WP_Application_Passwords {
	public static function get_user_application_passwords( $user_id ) {
		return $GLOBALS['__app_passwords'];
	}
}

// ---- Real-behavior sanitize_text_field()/wp_unslash() stand-ins, NOT
// pass-throughs — wp_unslash() is a RECORDING stub so "the handler actually
// calls wp_unslash() on the posted field" is provable (project convention:
// update_option() does not unslash, so every $_POST string field must be
// unslashed at the handler — see the update-option-slash-asymmetry memory). ----
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
$GLOBALS['__unslash_calls'] = array();
function wp_unslash( $v ) {
	$GLOBALS['__unslash_calls'][] = $v;
	return is_string( $v ) ? stripslashes( $v ) : $v;
}

require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';   // the REAL sn_mcp_set_rw_bound_uuid()/sn_mcp_rw_bound_uuid() — never stub either.
require __DIR__ . '/../inc/admin-post-actions.php';  // the REAL sn_handle_bind_mcp_rw_credential() under test.

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function reset_state() {
	$GLOBALS['__opts']            = array();
	$GLOBALS['__app_passwords']   = array();
	$GLOBALS['__can_manage_options'] = true;
	$GLOBALS['__unslash_calls']   = array();
}

echo "MCP rw-door credential-bind handler — plugin v9.51.0 (lane SEC-C, R9)\n\n";

$uuid_owned    = '11111111-1111-1111-1111-111111111111';
$uuid_other    = '22222222-2222-2222-2222-222222222222'; // well-formed, but NOT in this user's fixture.

// ============================================================
// Capability gate — the handler's own defensive re-check
// ============================================================
echo "-- capability gate --\n";
reset_state();
$GLOBALS['__can_manage_options'] = false;
$GLOBALS['__app_passwords']      = array( array( 'uuid' => $uuid_owned, 'name' => 'CI', 'created' => 1700000000, 'last_used' => null ) );
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $uuid_owned ) );
ok( 'mcp_rw_bind_invalid' === $flash, 'current_user_can(manage_options) false -> refused, even with an otherwise-owned UUID' );
ok( '' === sn_mcp_rw_bound_uuid(), 'capability-denied call never writes the option' );

// ============================================================
// OWNERSHIP CHECK — the load-bearing case
// ============================================================
echo "\n-- ownership check: a well-formed UUID this user does NOT hold is refused --\n";
reset_state();
$GLOBALS['__app_passwords'] = array( array( 'uuid' => $uuid_owned, 'name' => 'CI', 'created' => 1700000000, 'last_used' => null ) );
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $uuid_other ) );
ok( 'mcp_rw_bind_invalid' === $flash, 'a well-formed UUID absent from the current user\'s own Application Passwords is refused' );
ok( '' === sn_mcp_rw_bound_uuid(), 'REGRESSION GUARD: the option is NEVER written for an unowned UUID (this is the vulnerability this handler exists to prevent)' );

echo "\n-- ownership check: no Application Passwords at all -> any UUID is refused --\n";
reset_state();
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $uuid_owned ) );
ok( 'mcp_rw_bind_invalid' === $flash, 'with zero Application Passwords on record, even a plausible UUID is refused' );
ok( '' === sn_mcp_rw_bound_uuid(), 'option untouched' );

echo "\n-- ownership check: WP_Application_Passwords absent (older WP) -> refused, not fatal --\n";
// Can't un-define the class once loaded in this process; this simulates the
// "class not found" branch of sn_handle_bind_mcp_rw_credential() by directly
// exercising the same class_exists()-false path is not reachable in-process,
// so this case is covered by code inspection (function_exists()-style guard,
// same idiom the rest of this file's dependencies already use) rather than
// a live assertion here.

// ============================================================
// Bind — a UUID the user DOES own succeeds
// ============================================================
echo "\n-- bind: an owned UUID succeeds --\n";
reset_state();
$GLOBALS['__app_passwords'] = array(
	array( 'uuid' => $uuid_owned, 'name' => 'Claude Code', 'created' => 1700000000, 'last_used' => null ),
);
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $uuid_owned ) );
ok( 'mcp_rw_bound' === $flash, 'binding an owned UUID returns mcp_rw_bound' );
ok( $uuid_owned === sn_mcp_rw_bound_uuid(), 'the option now reads back the bound UUID' );

// Re-bind to a second owned password — proves the handler does not just
// "stick" on the first bind; it re-authorizes on every submission.
echo "\n-- bind: re-binding to a DIFFERENT owned UUID succeeds and overwrites the prior one --\n";
$GLOBALS['__app_passwords'][] = array( 'uuid' => $uuid_other, 'name' => 'Backup key', 'created' => 1700000000, 'last_used' => null );
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $uuid_other ) );
ok( 'mcp_rw_bound' === $flash, 're-binding to a second owned UUID also returns mcp_rw_bound' );
ok( $uuid_other === sn_mcp_rw_bound_uuid(), 'the option now reflects the NEW bound UUID, not the old one' );

// ============================================================
// Unbind — the explicit empty-string clear
// ============================================================
echo "\n-- unbind: an explicit empty string clears the bound credential --\n";
reset_state();
sn_mcp_set_rw_bound_uuid( $uuid_owned );
ok( $uuid_owned === sn_mcp_rw_bound_uuid(), 'sanity: something is bound before the unbind call' );
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => '' ) );
ok( 'mcp_rw_unbound' === $flash, 'posting an empty string returns mcp_rw_unbound' );
ok( '' === sn_mcp_rw_bound_uuid(), 'the option reads back empty after unbind' );

echo "\n-- unbind: a MISSING field (no sn_mcp_rw_uuid key at all) also unbinds ('' default) --\n";
reset_state();
sn_mcp_set_rw_bound_uuid( $uuid_owned );
$flash = sn_handle_bind_mcp_rw_credential( array() );
ok( 'mcp_rw_unbound' === $flash, 'an absent field defaults to the empty-string unbind path' );
ok( '' === sn_mcp_rw_bound_uuid(), 'the option reads back empty' );

// ============================================================
// wp_unslash() is actually applied to the posted field
// ============================================================
echo "\n-- wp_unslash() applied --\n";
reset_state();
$GLOBALS['__app_passwords'] = array( array( 'uuid' => $uuid_owned, 'name' => 'CI', 'created' => 1700000000, 'last_used' => null ) );
sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => $uuid_owned ) );
ok( in_array( $uuid_owned, $GLOBALS['__unslash_calls'], true ), 'the posted sn_mcp_rw_uuid value was routed through wp_unslash() before use' );

// ============================================================
// is_string guard (project convention): a crafted array-shaped POST field
// must never reach a (string) cast warning.
// ============================================================
echo "\n-- is_string guard: an array-shaped sn_mcp_rw_uuid never warns, never binds --\n";
reset_state();
$GLOBALS['__app_passwords'] = array( array( 'uuid' => $uuid_owned, 'name' => 'CI', 'created' => 1700000000, 'last_used' => null ) );
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => array( 'evil' => true ) ) );
ok( 'mcp_rw_unbound' === $flash, 'a crafted array payload is treated as absent (empty string) -> the safe unbind path, never a fatal/warning' );
ok( '' === sn_mcp_rw_bound_uuid(), 'option stays empty' );
$array_reached_unslash = false;
foreach ( $GLOBALS['__unslash_calls'] as $call ) {
	if ( is_array( $call ) ) { $array_reached_unslash = true; }
}
ok( false === $array_reached_unslash, 'the raw array payload itself was NEVER passed to wp_unslash() (guarded by is_string() before that call — only the empty-string fallback reaches it)' );

// ============================================================
// A malformed (non-UUID-shaped) string is refused at the guard layer
// ============================================================
echo "\n-- a garbage string (not UUID-shaped) is refused --\n";
reset_state();
$GLOBALS['__app_passwords'] = array( array( 'uuid' => $uuid_owned, 'name' => 'CI', 'created' => 1700000000, 'last_used' => null ) );
$flash = sn_handle_bind_mcp_rw_credential( array( 'sn_mcp_rw_uuid' => 'not-a-real-uuid' ) );
ok( 'mcp_rw_bind_invalid' === $flash, 'a non-UUID-shaped string is refused (fails the ownership match, since it can never equal a real UUID)' );
ok( '' === sn_mcp_rw_bound_uuid(), 'option untouched' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
