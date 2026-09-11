<?php
/**
 * Tests: the rw door's four controls cover the abilities RUN ROUTE, not one
 * route on it (enforcement audit 2026-09-11, Phase 1).
 *
 * The audit found that POST /wp-abilities/v1/abilities/<slug>/run reached every
 * show_in_rest write ability with any manage_options application password,
 * and that the rw kill switch, the bound credential, the rate limit and the
 * audit row all lived on /signal-noise/v1/mcp-rw alone. This is the sibling of
 * tests/mcp-read-guard-run-route.php for the WRITE side.
 *
 * THE ASSERTIONS THAT MATTER MOST are the negative ones: a cookie+nonce
 * request from wp-admin's own buttons (which call this same route) must pass
 * untouched, and a readonly ability must pass untouched. A guard that keyed
 * on the route would break every admin button and every read.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok  - $m\n"; } else { $fail++; echo "  FAIL - $m\n"; } }

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return (string) $s; }
function apply_filters( $t, $v ) { return $v; }
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $t ][] = $c; return true; }
function sanitize_text_field( $s ) { return (string) $s; }
function wp_unslash( $s ) { return $s; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__options'][ $k ] = $v; return true; }
$GLOBALS['__transients'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }

// The credential: '' = cookie/nonce/CLI, a UUID = application password.
$GLOBALS['__app_pw'] = '';
function rest_get_authenticated_app_password() { return '' === $GLOBALS['__app_pw'] ? null : $GLOBALS['__app_pw']; }

// A registry stand-in: slug => readonly annotation. Absent slug = unknown ability.
$GLOBALS['__abilities'] = array();
class RW_Ability {
	private $ro;
	public function __construct( $ro ) { $this->ro = $ro; }
	public function get_meta() { return array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => $this->ro ) ); }
}
function wp_get_ability( $slug ) {
	return array_key_exists( $slug, $GLOBALS['__abilities'] ) ? new RW_Ability( $GLOBALS['__abilities'][ $slug ] ) : null;
}

// The audit sink: every row the guard writes lands here.
$GLOBALS['__audit'] = array();
function sn_mcp_rw_audit_record( $slug, $args, $outcome, $err = null ) { $GLOBALS['__audit'][] = array( $slug, $outcome ); return array(); }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-rw-guard.php';

class RG_Req {
	private $route; private $body;
	public function __construct( $r, $b = array() ) { $this->route = $r; $this->body = $b; }
	public function get_route() { return $this->route; }
	public function get_json_params() { return $this->body; }
}
function run_route( $slug ) { return '/wp-abilities/v1/abilities/' . $slug . '/run'; }

$BOUND   = '11111111-2222-4333-8444-555555555555';
$OTHER   = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$a_write = sn_mcp_rw_allowlist()[0];                 // a door slug, not readonly
$a_read  = sn_mcp_allowlist()[0];                    // a read slug, readonly
$legacy  = 'signal-noise/block-migrations-apply';    // a NON-door write, show_in_rest, destructive
$GLOBALS['__abilities'] = array( $a_write => false, $a_read => true, $legacy => false );

function reset_state( $bound, $app_pw ) {
	$GLOBALS['__options']    = array( 'sn_mcp_rw_app_password_uuid' => $bound );
	$GLOBALS['__transients'] = array();
	$GLOBALS['__app_pw']     = $app_pw;
	$GLOBALS['__audit']      = array();
}

echo "Group: the route's slug is extracted, or nothing is claimed\n";
ok( 'signal-noise/sn-apply' === sn_mcp_rw_guard_route_slug( run_route( 'signal-noise/sn-apply' ) ), 'a run route yields its ability slug' );
ok( '' === sn_mcp_rw_guard_route_slug( '/wp-abilities/v1/abilities' ), 'the catalogue route is not a run route' );
ok( '' === sn_mcp_rw_guard_route_slug( '/signal-noise/v1/mcp-rw' ), 'the door itself is not this guard\'s business' );
ok( '' === sn_mcp_rw_guard_route_slug( run_route( 'x' ) . '/extra' ), 'a route that merely CONTAINS /run is not a run route' );

echo "\nGroup: the pure verdict runs the door's controls in the door's order\n";
$allow_cred = array( 'allow' => true, 'code' => '' );
$allow_rate = array( 'allow' => true, 'retry_after' => 0 );
$v = sn_mcp_rw_guard_run_route_decision( true, $allow_cred, $allow_rate );
ok( ! $v['allow'] && 'rw_disabled' === $v['code'] && 403 === $v['status'], 'kill switch engaged refuses first, as rw_disabled' );
$v = sn_mcp_rw_guard_run_route_decision( false, array( 'allow' => false, 'code' => 'rw_credential_unbound' ), $allow_rate );
ok( ! $v['allow'] && 'rw_credential_unbound' === $v['code'], 'an unbound door refuses with the door\'s own code' );
$v = sn_mcp_rw_guard_run_route_decision( false, array( 'allow' => false, 'code' => 'credential_not_authorized' ), $allow_rate );
ok( ! $v['allow'] && 'credential_not_authorized' === $v['code'], 'a mismatched credential refuses with the door\'s own code' );
$v = sn_mcp_rw_guard_run_route_decision( false, $allow_cred, array( 'allow' => false, 'retry_after' => 60 ) );
ok( ! $v['allow'] && 'rate_limited' === $v['code'] && 429 === $v['status'] && 60 === $v['retry_after'], 'over the cap refuses 429 carrying retry_after' );
$v = sn_mcp_rw_guard_run_route_decision( false, $allow_cred, $allow_rate );
ok( $v['allow'] && '' === $v['code'], 'all four clear allows' );

echo "\nGroup: THE NEGATIVE ONES — who the guard must never touch\n";
reset_state( $BOUND, '' );
ok( null === sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) ), 'cookie+nonce (no app password) on a WRITE ability passes untouched — wp-admin\'s own buttons use this route' );
reset_state( '', '' );
ok( null === sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) ), 'cookie+nonce passes even with the door UNBOUND (the door\'s fail-closed default is about app passwords, not admins)' );
reset_state( $BOUND, $OTHER );
ok( null === sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_read ) ) ), 'a READONLY ability passes even with the wrong app password — reads have their own guard' );
ok( null === sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( 'signal-noise/does-not-exist' ) ) ), 'an unregistered ability is not claimed (core answers 404; no enumeration oracle here)' );
ok( null === sn_mcp_rw_guard_run_route( null, null, new RG_Req( '/wp/v2/posts' ) ), 'an unrelated REST route is untouched' );
$prior = new WP_Error( 'someone_elses_refusal', 'x', array( 'status' => 401 ) );
ok( $prior === sn_mcp_rw_guard_run_route( $prior, null, new RG_Req( run_route( $a_write ) ) ), 'a non-null prior result passes through untouched' );
ok( array() === $GLOBALS['__audit'], 'and none of those wrote an audit row' );

echo "\nGroup: an app-password WRITE on the run route meets the door\n";
reset_state( $BOUND, $OTHER );
$d = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ), array( 'input' => array( 'x' => 1 ) ) ) );
ok( is_wp_error( $d ) && 'sn_mcp_rw_credential_not_authorized' === $d->get_error_code() && 403 === $d->data['status'], "a door slug ($a_write) with the WRONG app password is refused exactly as /mcp-rw would refuse it" );
ok( array( array( $a_write, 'denied' ) ) === $GLOBALS['__audit'], 'and the refusal is a \'denied\' audit row naming the slug' );

reset_state( $BOUND, $OTHER );
$d = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $legacy ) ) );
ok( is_wp_error( $d ) && 'sn_mcp_rw_credential_not_authorized' === $d->get_error_code(), "a NON-door write ($legacy) is guarded too — the rule is the annotation, not the allowlist" );

reset_state( $BOUND, $OTHER );
$GLOBALS['__abilities']['signal-noise/describe-tags'] = true; // readonly => true, honestly — but on the rw door because it bills an AI call
$d = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( 'signal-noise/describe-tags' ) ) );
ok( is_wp_error( $d ) && 'sn_mcp_rw_credential_not_authorized' === $d->get_error_code(), 'a READONLY-annotated slug that sits on the rw door is guarded anyway — the allowlist outranks the annotation (describe-tags bills AI)' );

reset_state( '', $BOUND );
$d = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) );
ok( is_wp_error( $d ) && 'sn_mcp_rw_rw_credential_unbound' === $d->get_error_code(), 'an UNBOUND door refuses every app password, the bound-looking one included (fail-closed)' );

reset_state( $BOUND, $BOUND );
$GLOBALS['__options']['sn_mcp_rw_enabled'] = 0;
$d = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) );
ok( is_wp_error( $d ) && 'sn_mcp_rw_rw_disabled' === $d->get_error_code(), 'the rw KILL SWITCH now closes the run route, not just the door' );

reset_state( $BOUND, $BOUND );
ok( null === sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) ), 'the BOUND app password, switch on, is allowed through' );
ok( array() === $GLOBALS['__audit'], 'an allowed call writes nothing at pre_dispatch (the outcome is not known yet)' );

echo "\nGroup: the rate limit rides along\n";
reset_state( $BOUND, $BOUND );
$refused = null;
for ( $i = 0; $i < SN_MCP_RW_RATE_LIMIT_PER_MINUTE + 1; $i++ ) {
	$r = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) );
	if ( is_wp_error( $r ) ) { $refused = array( 'at' => $i, 'err' => $r ); break; }
}
ok( null !== $refused && SN_MCP_RW_RATE_LIMIT_PER_MINUTE === $refused['at'], 'call #' . ( SN_MCP_RW_RATE_LIMIT_PER_MINUTE + 1 ) . ' in a window is refused' );
ok( null !== $refused && 'sn_mcp_rw_rate_limited' === $refused['err']->get_error_code() && 429 === $refused['err']->data['status'], 'as a 429 carrying retry_after' );

echo "\nGroup: the outcome of an allowed call is recorded after the callbacks\n";
reset_state( $BOUND, $BOUND );
$out = sn_mcp_rw_guard_run_route_audit( array( 'ok' => true ), null, new RG_Req( run_route( $a_write ) ) );
ok( array( 'ok' => true ) === $out && array( array( $a_write, 'ok' ) ) === $GLOBALS['__audit'], 'a successful response is returned untouched and logged as ok' );
reset_state( $BOUND, $BOUND );
$e = new WP_Error( 'snt_sn_apply_fingerprint_stale', 'x', array( 'status' => 409 ) );
$out = sn_mcp_rw_guard_run_route_audit( $e, null, new RG_Req( run_route( $a_write ) ) );
ok( $e === $out && array( array( $a_write, 'error' ) ) === $GLOBALS['__audit'], 'an ability-level WP_Error is returned untouched and logged as error' );
reset_state( $BOUND, '' );
sn_mcp_rw_guard_run_route_audit( array( 'ok' => true ), null, new RG_Req( run_route( $a_write ) ) );
ok( array() === $GLOBALS['__audit'], 'a cookie-auth call is not logged here either (the door\'s log is for app-password traffic)' );

echo "\nGroup: every rw-door slug is covered, not a sample\n";
$missed = array();
foreach ( sn_mcp_rw_allowlist() as $slug ) {
	$GLOBALS['__abilities'][ $slug ] = false;
	reset_state( $BOUND, $OTHER );
	if ( ! is_wp_error( sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $slug ) ) ) ) ) { $missed[] = $slug; }
}
ok( array() === $missed, 'all ' . count( sn_mcp_rw_allowlist() ) . ' rw-door slugs are refused to a wrong app password' . ( $missed ? ' — MISSED: ' . implode( ',', $missed ) : '' ) );
$leaked = array();
foreach ( sn_mcp_allowlist() as $slug ) {
	$GLOBALS['__abilities'][ $slug ] = true;
	reset_state( $BOUND, $OTHER );
	if ( is_wp_error( sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $slug ) ) ) ) ) { $leaked[] = $slug; }
}
ok( array() === $leaked, 'and none of the ' . count( sn_mcp_allowlist() ) . ' read slugs is' . ( $leaked ? ' — LEAKED: ' . implode( ',', $leaked ) : '' ) );

echo "\nGroup: negative control — the instrument can fail\n";
// Prove the refusal above came from the credential check and not from some
// earlier accident: with the credential stubbed to allow, the same request passes.
reset_state( $BOUND, $OTHER );
$v = sn_mcp_rw_guard_run_route_decision( sn_mcp_rw_kill_switch_engaged(), array( 'allow' => true, 'code' => '' ), sn_mcp_rw_rate_limit_gate() );
ok( true === $v['allow'], 'the same live state with the credential check forced to allow passes — so the refusal was the credential, not an accident' );

echo "\nGroup: the constant still wins, and still cannot be flipped by a leaked password\n";
reset_state( $BOUND, $BOUND );
$GLOBALS['__options']['sn_mcp_rw_enabled'] = 1;
define( 'SN_MCP_RW_DISABLED', true );
$c = sn_mcp_rw_guard_run_route( null, null, new RG_Req( run_route( $a_write ) ) );
ok( is_wp_error( $c ) && 'sn_mcp_rw_rw_disabled' === $c->get_error_code(), 'the wp-config constant closes the run route even with the option enabled and the bound credential' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
