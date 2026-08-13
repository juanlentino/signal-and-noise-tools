<?php
/**
 * Tests: the read kill switch covers the READ PATH, not one route on it (F2).
 *
 * The threat model's §8 found this while scoping roadmap 3D:
 * `sn_mcp_read_permission()` was referenced in exactly one place — the MCP
 * endpoint's read route — so the native Abilities run-route
 * (/wp-abilities/v1/abilities/<slug>/run) never consulted the switch. An
 * owner-identity caller reached every read ability with `sn_mcp_read_enabled`
 * set to OFF, while the switch read as though it had closed the door.
 *
 * Harmless while the only caller is the owner's laptop. Load-bearing the moment
 * it is not — which is exactly what 3D would change, and why this is fixed
 * BEFORE any broker exists rather than bundled with one.
 *
 * THE ASSERTION THAT MATTERS MOST is the negative one: the READ switch must not
 * darken the WRITE door. The two doors' guards are deliberately isolated, and a
 * fix that closed both would be a worse bug than the one it replaced.
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
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function current_user_can( $c ) { return true; }

require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-read-guard.php';

/** A minimal REST request stand-in: the guard only ever asks for the route. */
class RG_Req {
	private $route;
	public function __construct( $r ) { $this->route = $r; }
	public function get_route() { return $this->route; }
}

$read_slugs = sn_mcp_allowlist();
$rw_slugs   = sn_mcp_rw_allowlist();
$a_read     = $read_slugs[0];
$a_write    = $rw_slugs[0];

echo "Group: the route's slug is extracted, or nothing is claimed\n";
ok( 'signal-noise/get-analytics-events' === sn_mcp_read_guard_route_slug( '/wp-abilities/v1/abilities/signal-noise/get-analytics-events/run' ), 'a run route yields its ability slug' );
ok( '' === sn_mcp_read_guard_route_slug( '/wp-abilities/v1/abilities' ), 'the catalogue route is not a run route' );
ok( '' === sn_mcp_read_guard_route_slug( '/wp/v2/posts' ), 'an unrelated namespace yields nothing' );
ok( '' === sn_mcp_read_guard_route_slug( '/wp-abilities/v1/abilities/signal-noise/x/run/extra' ), 'a route that merely CONTAINS /run is not a run route' );
ok( '' === sn_mcp_read_guard_route_slug( '' ), 'an empty route yields nothing' );

echo "\nGroup: with the switch OFF, nothing changes\n";
$GLOBALS['__options'] = array();
ok( null === sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) ), 'an untouched switch lets a read ability through (fail-open-on-absence preserved)' );
$GLOBALS['__options']['sn_mcp_read_enabled'] = 1;
ok( null === sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) ), 'an explicitly enabled switch lets it through' );

echo "\nGroup: with the switch ON, the read path is closed — not just the MCP route\n";
$GLOBALS['__options']['sn_mcp_read_enabled'] = 0;
$denied = sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) );
ok( is_wp_error( $denied ), 'a READ ability on the native run route is refused' );
ok( 'sn_mcp_read_disabled' === $denied->get_error_code(), 'and refused with the SAME code the MCP door uses — one switch, one verdict' );
ok( 403 === ( $denied->data['status'] ?? 0 ), 'as a 403' );

echo "\nGroup: THE NEGATIVE ONE — the read switch must not darken the WRITE door\n";
// The two doors' guards are deliberately isolated (mcp-read-guard.php's header
// says so). A read kill that also killed writes would be a worse bug than F2.
$w = sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_write . '/run' ) );
ok( null === $w, "a WRITE ability ($a_write) is untouched by the READ switch" );
$unknown = sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/some/unregistered-thing/run' ) );
ok( null === $unknown, 'an ability on neither allowlist is not claimed by this guard' );
ok( null === sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp/v2/posts' ) ), 'an unrelated REST route is untouched even with the switch on' );

echo "\nGroup: the guard never overrides an answer someone else already gave\n";
$prior = new WP_Error( 'someone_elses_refusal', 'x', array( 'status' => 401 ) );
$out   = sn_mcp_read_guard_run_route( $prior, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) );
ok( $out === $prior, 'a non-null prior result passes through untouched' );

echo "\nGroup: every read ability is covered, not a sample\n";
$missed = array();
foreach ( $read_slugs as $slug ) {
	if ( ! is_wp_error( sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $slug . '/run' ) ) ) ) {
		$missed[] = $slug;
	}
}
ok( array() === $missed, 'all ' . count( $read_slugs ) . ' read abilities are refused' . ( $missed ? ' — MISSED: ' . implode( ',', $missed ) : '' ) );
$leaked = array();
foreach ( $rw_slugs as $slug ) {
	if ( is_wp_error( sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $slug . '/run' ) ) ) ) {
		$leaked[] = $slug;
	}
}
ok( array() === $leaked, 'and none of the ' . count( $rw_slugs ) . ' write abilities is' . ( $leaked ? ' — LEAKED: ' . implode( ',', $leaked ) : '' ) );

echo "\nGroup: the constant still wins, and still cannot be flipped by a leaked password\n";
$GLOBALS['__options']['sn_mcp_read_enabled'] = 1;
define( 'SN_MCP_READ_DISABLED', true );
$c = sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) );
ok( is_wp_error( $c ), 'the wp-config constant closes the run route even with the option enabled' );

echo "\nGroup: the ceiling covers remote slugs, but the READ switch does not darken them\n";
// The remote slug is deliberately off sn_mcp_allowlist(). Left alone, that means
// its run route gets no ceiling at all. The ceiling is about LOAD, so extending
// it is right; the kill switch is about AUTHORIZATION, and the remote slug has
// its own — one that fails CLOSED, unlike this one.
require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
$remote_slug  = sn_mcp_remote_slugs()[0];
$remote_route = '/wp-abilities/v1/abilities/' . $remote_slug . '/run';

ok( true === sn_mcp_read_guard_is_read_path( $remote_route ), 'the remote run route IS on the read path, so the ceiling reaches it' );

// Engage the READ kill switch and confirm it does not answer for the remote slug.
$GLOBALS['__options']['sn_mcp_read_enabled'] = false;
ok( true === sn_mcp_read_kill_switch_engaged(), 'the read switch is engaged for this check' );
ok( null === sn_mcp_read_guard_run_route( null, null, new RG_Req( $remote_route ) ), 'THE NEGATIVE ONE: the READ kill switch does not darken a remote slug' );
ok( is_wp_error( sn_mcp_read_guard_run_route( null, null, new RG_Req( '/wp-abilities/v1/abilities/' . $a_read . '/run' ) ) ), 'while it still darkens a genuine read-allowlist slug' );
$GLOBALS['__options']['sn_mcp_read_enabled'] = true;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
