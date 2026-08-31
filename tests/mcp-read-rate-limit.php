<?php
/**
 * Tests: the read door gets a ceiling (F1).
 *
 * §8 of the agent-surface threat model: the write door's stack ends in
 * sn_mcp_rw_rate_limit_gate(); the read door's was kill switch → manage_options
 * and nothing else. Harmless while the only caller is the owner's laptop;
 * exposed to a brokered caller (A5) it is an exfiltration channel with no
 * ceiling — the corpus drains at whatever rate the edge allows, and the audit
 * trail records reads it cannot slow.
 *
 * DELIBERATE DUPLICATION: these primitives mirror mcp-rw-guard.php's rather than
 * calling them. mcp-read-guard.php's header states the invariant — "this file
 * NEVER calls into mcp-rw-guard.php, and vice versa — the two doors' guards stay
 * isolated". Sharing the limiter would couple the doors at exactly the layer the
 * split exists to keep apart.
 *
 * HONEST SCOPE: this is fail-OPEN, like the write door's. When the backing store
 * is unavailable the call proceeds. That makes it a runaway-loop ceiling today,
 * not a security boundary — and §8 records that a broker would need it to fail
 * CLOSED before shipping.
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
function add_filter( $t, $c, $p = 10, $a = 1 ) { return true; }
$GLOBALS['__options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
function current_user_can( $c ) { return true; }
// A transient-backed store, so the counter is real rather than mocked away.
$GLOBALS['__t'] = array();
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__t'] ) ? $GLOBALS['__t'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__t'][ $k ] = $v; return true; }

// The endpoint file is not loaded here (it pulls WP registration deps); the
// guard resolves the namespace through this same seam.
function sn_mcp_namespace() { return defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1'; }
require __DIR__ . '/../inc/mcp/mcp-capabilities.php';
require __DIR__ . '/../inc/mcp/mcp-read-guard.php';

class RL_Req { private $r; public function __construct( $r ) { $this->r = $r; } public function get_route() { return $this->r; } }

$read_slug = sn_mcp_allowlist()[0];
$rw_slug   = sn_mcp_rw_allowlist()[0];
$run_route = '/wp-abilities/v1/abilities/' . $read_slug . '/run';
$mcp_route = '/' . sn_mcp_namespace() . '/mcp';

echo "Group: the read path is BOTH routes, not one of them\n";
ok( true === sn_mcp_read_guard_is_read_path( $run_route ), 'the abilities run route for a READ ability is on the read path' );
ok( true === sn_mcp_read_guard_is_read_path( $mcp_route ), 'the MCP read route is on the read path' );
ok( false === sn_mcp_read_guard_is_read_path( '/wp-abilities/v1/abilities/' . $rw_slug . '/run' ), 'a WRITE ability is NOT — it has its own door and its own limiter' );
ok( false === sn_mcp_read_guard_is_read_path( '/wp/v2/posts' ), 'an unrelated route is not' );

echo "\nGroup: the decision is a pure comparison, testable without a store\n";
ok( true === sn_mcp_read_rate_limit_decision( 0, 5 ), 'under the cap allows' );
ok( true === sn_mcp_read_rate_limit_decision( 4, 5 ), 'the last call under the cap allows' );
ok( false === sn_mcp_read_rate_limit_decision( 5, 5 ), 'AT the cap denies — the cap is a ceiling, not a target' );
ok( false === sn_mcp_read_rate_limit_decision( 99, 5 ), 'over the cap denies' );

echo "\nGroup: identity separates callers, and never collapses them to one bucket\n";
ok( 'uuid:abc' === sn_mcp_read_rate_limit_identity( 'abc', 'iphash' ), 'a bound credential identifies the caller' );
ok( 'ip:iphash' === sn_mcp_read_rate_limit_identity( '', 'iphash' ), 'without one, the IP hash does' );
ok( 'ip:unknown' === sn_mcp_read_rate_limit_identity( '', '' ), 'with neither, an explicit unknown bucket — never an empty key shared by everyone' );
ok( sn_mcp_read_rate_limit_key( 'uuid:a' ) !== sn_mcp_read_rate_limit_key( 'uuid:b' ), 'different identities get different keys' );

echo "\nGroup: the ceiling actually stops a caller\n";
$GLOBALS['__t'] = array();
$cap    = SN_MCP_READ_RATE_LIMIT_PER_MINUTE;
$allowed = 0;
for ( $i = 0; $i < $cap + 5; $i++ ) {
	$d = sn_mcp_read_rate_limit_check( 'uuid:burst' );
	if ( ! empty( $d['allow'] ) ) { $allowed++; }
}
ok( $allowed === $cap, "exactly $cap calls pass in one window (got $allowed)" );
$d = sn_mcp_read_rate_limit_check( 'uuid:burst' );
ok( empty( $d['allow'] ) && $d['retry_after'] > 0, 'the next call is refused and says when to retry' );
$other = sn_mcp_read_rate_limit_check( 'uuid:someone-else' );
ok( ! empty( $other['allow'] ), 'a DIFFERENT caller is unaffected — the limit is per identity, not global' );

echo "\nGroup: the gate refuses on the wire, on both routes\n";
$GLOBALS['__t'] = array();
for ( $i = 0; $i < $cap; $i++ ) { sn_mcp_read_rate_limit_check( sn_mcp_read_rate_limit_current_identity() ); }
$r = sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( $run_route ) );
ok( is_wp_error( $r ) && 'sn_mcp_read_rate_limited' === $r->get_error_code(), 'the run route refuses once the ceiling is reached' );
ok( 429 === ( $r->data['status'] ?? 0 ), 'as a 429, not a 403 — this is a ceiling, not a closed door' );
$GLOBALS['__t'] = array();
for ( $i = 0; $i < $cap; $i++ ) { sn_mcp_read_rate_limit_check( sn_mcp_read_rate_limit_current_identity() ); }
$m = sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( $mcp_route ) );
ok( is_wp_error( $m ) && 'sn_mcp_read_rate_limited' === $m->get_error_code(), 'and so does the MCP read route — one ceiling, both routes' );

echo "\nGroup: the WRITE door is untouched — it has its own limiter\n";
$GLOBALS['__t'] = array();
for ( $i = 0; $i < $cap + 10; $i++ ) { sn_mcp_read_rate_limit_check( sn_mcp_read_rate_limit_current_identity() ); }
$w = sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( '/wp-abilities/v1/abilities/' . $rw_slug . '/run' ) );
ok( null === $w, 'a write ability is never refused by the READ ceiling' );

echo "\nGroup: the kill switch outranks the ceiling\n";
// A disabled door is a 403 whatever the counter says: "closed" is a stronger
// statement than "slow down", and a caller told 429 would keep trying.
// EXHAUST the ceiling first. With an empty counter the limiter would allow and
// return $result regardless, so the pass-through assertion below could not fail
// — it has to run in the state where the mutant would answer 429.
$GLOBALS['__t'] = array();
for ( $i = 0; $i < $cap; $i++ ) { sn_mcp_read_rate_limit_check( sn_mcp_read_rate_limit_current_identity() ); }
$GLOBALS['__options']['sn_mcp_read_enabled'] = 0;
// Compose the two filters in their real priority order (10 then 11): the
// kill-switch guard answers, and the ceiling must pass that answer through
// rather than replacing a 403 with a 429.
$k = sn_mcp_read_guard_run_route( null, null, new RL_Req( $run_route ) );
ok( is_wp_error( $k ) && 'sn_mcp_read_disabled' === $k->get_error_code(), 'the switch answers first, with 403' );
$k2 = sn_mcp_read_guard_rate_limit_dispatch( $k, null, new RL_Req( $run_route ) );
ok( $k2 === $k, 'and the ceiling passes that 403 through untouched' );
$GLOBALS['__options'] = array();

echo "\nGroup: fail-OPEN without a store, and it is documented as such\n";
// Mirrors the write door: a throttle must not harden into an outage when its
// backing store is gone. This is why F1 is a ceiling, not a boundary — and why
// §8 requires it to fail CLOSED before any broker exists.
$GLOBALS['__t'] = array();
$d = sn_mcp_read_rate_limit_check( 'uuid:nostore' );
ok( ! empty( $d['allow'] ), 'with an empty store the call proceeds' );
$src = (string) file_get_contents( __DIR__ . '/../inc/mcp/mcp-read-guard.php' );
ok( false !== stripos( $src, 'fail-open' ), 'and the file says so in words, rather than leaving it to be discovered' );

echo "\nGroup: the read guard still never calls the write guard\n";
ok( false === strpos( $src, 'sn_mcp_rw_rate_limit' ), 'the isolation invariant in the read guard header still holds' );


echo "\nGroup: Task 4.A — the SAME ceiling, two failure directions (v13.50.0)\n";
//
// PRECONDITION A of the remote phase. The ceiling covers remote slugs on
// purpose ("load is load whoever is asking"), but a credentialed, phone-
// reachable caller is a different risk object from the owner's laptop. §8 of
// the threat model records F1 failing OPEN as the thing that must clear before
// that path widens.
//
// BOTH directions are pinned here, and both are negative-controlled in the
// release notes: a change that made the LOCAL path fail closed too would be a
// silent site-wide availability regression, which is exactly as bad as leaving
// the remote path open — just louder.

if ( ! function_exists( 'sn_mcp_remote_slugs' ) ) {
	require_once __DIR__ . '/../inc/mcp/mcp-remote-guard.php';
}
$rl_remote_slug  = 'signal-noise/remote-get-rss-stats';
$rl_remote_route = '/wp-abilities/v1/abilities/' . $rl_remote_slug . '/run';

// The route predicate itself, both ways.
ok( true === sn_mcp_read_guard_route_is_remote( $rl_remote_route ), 'the predicate identifies a REMOTE slug run route' );
ok( false === sn_mcp_read_guard_route_is_remote( $run_route ), 'and does NOT flag a local read slug as remote' );
ok( false === sn_mcp_read_guard_route_is_remote( '/signal-noise/v1/mcp' ), 'nor the read door itself' );
// The ceiling must still APPLY to remote slugs — failing closed is worthless
// if the route was never on the ceiling in the first place.
ok( true === sn_mcp_read_guard_is_read_path( $rl_remote_route ), 'a remote slug is still ON the ceiling (off the exposure list never meant off the ceiling)' );

// THE DISTINCTION THAT MAKES THIS SAFE, pinned first. A null count means
// EITHER "store gone" OR "no counter yet" — and the second is the normal first
// call of every window. The first cut of this change conflated them and would
// have refused the FIRST remote call in every window: an outage wearing a
// security costume. These four pin the pure decision in all four combinations.
ok( false === sn_mcp_read_rate_limit_miss_allows( true, false ), 'MISS: remote + unusable store => refuse (the only refusing combination)' );
ok( true  === sn_mcp_read_rate_limit_miss_allows( true, true ),  'MISS: remote + usable store => proceed, because this is just a cold key' );
ok( true  === sn_mcp_read_rate_limit_miss_allows( false, false ), 'MISS: local + unusable store => proceed (fail-open preserved)' );
ok( true  === sn_mcp_read_rate_limit_miss_allows( false, true ),  'MISS: local + usable store => proceed' );

// DIRECTION 1 — REMOTE with a usable store but a COLD key: must proceed.
$GLOBALS['__t'] = array();
$rl_cold = sn_mcp_read_rate_limit_check( 'uuid:remote-cold', true );
ok( ! empty( $rl_cold['allow'] ), 'remote + cold key => ALLOWED, and the counter is seeded rather than refused' );

// DIRECTION 2 — LOCAL fail-open is untouched.
$GLOBALS['__t'] = array();
$rl_d_local = sn_mcp_read_rate_limit_check( 'uuid:local-nostore' );
ok( ! empty( $rl_d_local['allow'] ), 'local path => still ALLOWED (fail-closed here would be an availability regression)' );
$GLOBALS['__t'] = array();
ok( ! empty( sn_mcp_read_rate_limit_check( 'uuid:local-explicit', false )['allow'] ), 'and an explicit false is the same as omitting it' );
ok( true === sn_mcp_read_rate_limit_store_available(), 'the harness DOES have a usable store, so the cold-key tests above are not passing by accident' );

// WITH a store, the remote path behaves normally — fail-closed must not mean
// "always closed", which would be an outage wearing a security costume.
$GLOBALS['__t'] = array();
sn_mcp_read_rate_limit_check( 'uuid:remote-warm', true ); // seeds the counter
$rl_warm = sn_mcp_read_rate_limit_check( 'uuid:remote-warm', true );
ok( ! empty( $rl_warm['allow'] ), 'remote + a live store => allowed normally (fail-closed applies to the MISS, not to every call)' );

// And the cap still bites on the remote path.
$GLOBALS['__t'] = array();
$rl_cap = SN_MCP_READ_RATE_LIMIT_PER_MINUTE;
for ( $i = 0; $i < $rl_cap; $i++ ) { sn_mcp_read_rate_limit_check( 'uuid:remote-burst', true ); }
ok( empty( sn_mcp_read_rate_limit_check( 'uuid:remote-burst', true )['allow'] ), 'and the cap still refuses the call after it on the remote path' );

// END TO END through the dispatch filter. This harness cannot simulate an
// UNUSABLE store (get_transient/set_transient are defined at file scope and
// cannot be undefined), so the refusing combination is pinned on the pure
// decision above, and what is checked here is that dispatch reaches the
// limiter at all for a remote route, plus that the flag is genuinely threaded.
$GLOBALS['__t'] = array();
$rl_disp_cold = sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( $rl_remote_route ) );
ok( null === $rl_disp_cold, 'DISPATCH: a remote request with a cold key passes (the door is not bricked)' );
$GLOBALS['__t'] = array();
for ( $i = 0; $i < $rl_cap + 1; $i++ ) { sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( $rl_remote_route ) ); }
$rl_disp_over = sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( $rl_remote_route ) );
ok( is_wp_error( $rl_disp_over ) && 'sn_mcp_read_rate_limited' === $rl_disp_over->get_error_code(), 'DISPATCH: dispatch really does reach the limiter for a REMOTE route (the cap refuses through it)' );
$GLOBALS['__t'] = array();
$rl_disp_local = sn_mcp_read_guard_rate_limit_dispatch( null, null, new RL_Req( $run_route ) );
ok( null === $rl_disp_local, 'DISPATCH: a LOCAL request still passes through untouched' );

// THE WIRING ITSELF. Everything above would pass if dispatch hardcoded `false`,
// so the argument is pinned against source — the one claim this harness cannot
// make behaviourally.
$rl_src = (string) file_get_contents( __DIR__ . '/../inc/mcp/mcp-read-guard.php' );
ok(
	1 === preg_match( '/sn_mcp_read_rate_limit_check\(\s*\n?\s*sn_mcp_read_rate_limit_current_identity\(\),\s*\n?\s*sn_mcp_read_guard_route_is_remote\(\s*\$route\s*\)/', $rl_src ),
	'WIRING: dispatch passes the route predicate as the fail-closed flag, not a hardcoded false'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
