<?php
/**
 * Breached-credential rejection, Phase 3: the surface — v13.60.0.
 *
 * Properties: (1) the flagged count is DERIVED by walking users and reading
 * the memo (a user with no memo is unknown, not clean); (2) the verdict's
 * order of concern — a mode OFF > accounts flagged > recent fail-closed
 * rejections > good — with the boundary of "recent" pinned; (3) the summary
 * is derived from the counts it reports; (4) never `critical`; (5) the Site
 * Health test registers as a DIRECT test and reads the same verdict.
 */

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
$GLOBALS['__filters'] = array();
function add_filter( $t, $c, $p = 10, $a = 1 ) { $GLOBALS['__filters'][] = array( $t, $c ); return true; }
function add_action( $t, $c, $p = 10, $a = 1 ) { return true; }
$GLOBALS['__users'] = array();
function get_users( $args ) { $GLOBALS['__get_users_args'] = $args; return array_keys( $GLOBALS['__users'] ); }
function sn_hibp_login_memo( $id ) { return $GLOBALS['__users'][ $id ] ?? null; }
$GLOBALS['__set_stats'] = array( 'breached_count' => 0, 'unavailable_count' => 0, 'last_breached_at' => 0, 'last_unavailable_at' => 0 );
function sn_hibp_set_stats() { return $GLOBALS['__set_stats']; }
$GLOBALS['__set_off'] = false; $GLOBALS['__login_off'] = false;
function sn_hibp_set_disabled() { return $GLOBALS['__set_off']; }
function sn_hibp_login_disabled() { return $GLOBALS['__login_off']; }
const SN_HIBP_BREACHED = 'breached';

require_once __DIR__ . '/../inc/breached-credentials-surface.php';

echo "breached credentials — Phase 3 surface — v13.60.0\n\n";
$NOW = 1_800_000_000;

// ─── (1) the flagged count is derived from memos ───
$GLOBALS['__users'] = array(
	1 => array( 'verdict' => 'breached', 'count' => 9 ),
	2 => array( 'verdict' => 'not_breached', 'count' => 0 ),
	3 => null, // never logged in since Mode B: unknown, not clean
	4 => array( 'verdict' => 'breached', 'count' => 2 ),
);
$f = sn_hibp_flagged_users();
ok( 4 === $f['users'] && 3 === $f['checked'] && 2 === $f['breached'] && array( 1, 4 ) === $f['breached_ids'], 'flagged: 4 users, 3 checked, 2 breached — the memo-less user is neither' );
ok( -1 === ( $GLOBALS['__get_users_args']['number'] ?? 0 ) && 'ID' === ( $GLOBALS['__get_users_args']['fields'] ?? '' ), 'walks EVERY user by ID, no page cap' );

// ─── (2) order of concern ───
$d = sn_hibp_surface_data();
$h = sn_hibp_health( $d, $NOW );
ok( 'recommended' === $h['status'] && 2 === $h['flagged'] && false !== strpos( $h['summary'], '2 of 3' ), 'flagged accounts → recommended, summary derived from the counts (2 of 3 checked)' );

$GLOBALS['__users'] = array( 1 => array( 'verdict' => 'not_breached' ), 2 => array( 'verdict' => 'not_breached' ), 3 => null );
$GLOBALS['__set_stats'] = array( 'breached_count' => 4, 'unavailable_count' => 1, 'last_breached_at' => 1, 'last_unavailable_at' => $NOW - SN_HIBP_SURFACE_RECENT_SECS );
$h = sn_hibp_health( sn_hibp_surface_data(), $NOW );
ok( 'recommended' === $h['status'] && true === $h['unavailable_recent'] && false !== strpos( $h['summary'], 'fail-closed' ), 'a fail-closed rejection exactly 7 days old is RECENT → recommended, and the summary names fail-closed' );
$GLOBALS['__set_stats']['last_unavailable_at'] = $NOW - SN_HIBP_SURFACE_RECENT_SECS - 1;
$h = sn_hibp_health( sn_hibp_surface_data(), $NOW );
ok( 'good' === $h['status'] && false === $h['unavailable_recent'] && false !== strpos( $h['summary'], '2 of 3 account(s) checked' ) && false !== strpos( $h['summary'], '4 breached password(s) refused' ), 'one second older is NOT recent → good, and the good summary still reports the set-time counts' );
$GLOBALS['__set_stats'] = array( 'breached_count' => 0, 'unavailable_count' => 0, 'last_breached_at' => 0, 'last_unavailable_at' => 0 );
$h = sn_hibp_health( sn_hibp_surface_data(), $NOW );
ok( 'good' === $h['status'], 'clean everything → good' );
$h = sn_hibp_health( array( 'set' => $GLOBALS['__set_stats'], 'login' => array( 'checked' => 0, 'breached' => 0, 'users' => 3 ), 'set_enabled' => true, 'login_enabled' => true ), $NOW );
ok( 'good' === $h['status'] && false !== strpos( $h['summary'], '0 of 3 account(s) checked' ), 'nobody checked yet is still good — but the summary says 0 of 3, not "all clean"' );

// a mode OFF outranks everything
$GLOBALS['__users'] = array( 1 => array( 'verdict' => 'breached' ) );
$GLOBALS['__login_off'] = true;
$h = sn_hibp_health( sn_hibp_surface_data(), $NOW );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], 'SN_HIBP_LOGIN_DISABLED' ) && false === strpos( $h['summary'], 'SN_HIBP_SET_DISABLED' ), 'login mode OFF → recommended naming the constant, even with a flagged account (nothing is being measured)' );
$GLOBALS['__set_off'] = true;
$h = sn_hibp_health( sn_hibp_surface_data(), $NOW );
ok( false !== strpos( $h['summary'], 'SN_HIBP_SET_DISABLED' ) && false !== strpos( $h['summary'], ' and ' ), 'both OFF → both constants named' );
$GLOBALS['__set_off'] = false; $GLOBALS['__login_off'] = false;

// ─── (4) never critical ───
$src = (string) file_get_contents( __DIR__ . '/../inc/breached-credentials-surface.php' );
ok( false === strpos( str_replace( "never `critical`", '', $src ), "'critical'" ), 'REGRESSION: no verdict is critical — Mode B is advisory and the row would shout the same fact twice' );

// ─── (5) Site Health wiring ───
$reg = array_values( array_filter( $GLOBALS['__filters'], static fn( $f ) => 'site_status_tests' === $f[0] ) );
ok( 1 === count( $reg ) && 'sn_hibp_register_site_health_test' === $reg[0][1], 'registers on site_status_tests' );
$tests = sn_hibp_register_site_health_test( array() );
ok( 'sn_hibp_site_health_result' === ( $tests['direct']['sn_hibp_breach_check']['test'] ?? '' ), 'a DIRECT test named sn_hibp_breach_check' );
$row = sn_hibp_site_health_result();
ok( 'recommended' === $row['status'] && 'sn_hibp_breach_check' === $row['test'] && false !== strpos( $row['description'], '1 of 1' ), 'the row reads the same verdict (one flagged of one checked) and is HTML-escaped' );
ok( 'Security' === ( $row['badge']['label'] ?? '' ), 'Security badge, beside the SSRF pinning row' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
