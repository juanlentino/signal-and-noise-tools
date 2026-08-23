<?php
/**
 * Drift verdicts survive a plugin update.
 *
 * WHY THIS EXISTS, measured 2026-08-23. The drift check is the only
 * Content-Health check that spends money, and it cached its verdicts in a
 * TRANSIENT. Two scans back to back showed the cache working perfectly — 48.0s
 * then 5.4s, an 8.8x speedup with no model calls on the second. But the scan
 * immediately after a plugin update paid for the whole corpus again: on a
 * persistent object cache an update flushes transients, and this repo ships
 * several releases a day. Cost was driven by RELEASES, not by edits or cadence,
 * re-computing verdicts identical to the ones just discarded.
 *
 * The reasoning already existed one file over, above sn_health_store_scan():
 * the scan result is an autoload=no option "so the scan survives the
 * object-cache flush a caching plugin fires on a plugin update". It had never
 * been applied to the thing that costs money.
 *
 * Run: php tests/health-drift-verdict-cache.php
 *
 * @since 12.23.1
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label\n"; }
}

// Two separate stores, so the test can tell an option from a transient — which
// is the entire point of the change.
$GLOBALS['__opt']       = array();
$GLOBALS['__transient'] = array();
$GLOBALS['__autoload']  = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__opt'][ $k ]      = $v;
	$GLOBALS['__autoload'][ $k ] = $autoload;
	return true;
}
function get_transient( $k ) { return $GLOBALS['__transient'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transient'][ $k ] = $v; return true; }

require __DIR__ . '/../inc/health-drift-verdict-cache.php';

$V = array( array( 'phrase' => 'this year', 'verdict' => 'stale', 'reason' => 'r' ) );

echo "Group: an absent verdict set is null, never an empty array\n";
// The load-bearing distinction. A caller that reads array() as "checked, nothing
// stale" would skip the model call and record a clean verdict for a post nobody
// has ever looked at.
ok( null === sn_drift_verdict_get( 1, 'm', 'p' ), 'a miss returns null' );
sn_drift_verdict_put( 1, 'm', 'p', array() );
ok( array() === sn_drift_verdict_get( 1, 'm', 'p' ), 'a stored EMPTY verdict set returns array(), not null — "checked, nothing stale" is a real answer' );

echo "\nGroup: the key is (post, post_modified, prompt_version)\n";
sn_drift_verdict_put( 2, 'MOD-A', 'PV-1', $V );
ok( $V === sn_drift_verdict_get( 2, 'MOD-A', 'PV-1' ), 'an exact key match returns the verdicts' );
ok( null === sn_drift_verdict_get( 2, 'MOD-B', 'PV-1' ), 'an edited post (post_modified changed) misses, so it re-pays' );
ok( null === sn_drift_verdict_get( 2, 'MOD-A', 'PV-2' ), 'a changed system prompt (prompt_version) misses, so the corpus re-pays' );
ok( null === sn_drift_verdict_get( 99, 'MOD-A', 'PV-1' ), 'another post misses' );

echo "\nGroup: the TTL the transient used to enforce is enforced here\n";
$GLOBALS['__opt'] = array();
sn_drift_verdict_put( 3, 'm', 'p', $V );
$GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ][3]['ts'] = time() - SN_DRIFT_VERDICT_TTL - 1;
ok( null === sn_drift_verdict_get( 3, 'm', 'p' ), 'an entry past SN_DRIFT_VERDICT_TTL misses' );
$GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ][3]['ts'] = time() - SN_DRIFT_VERDICT_TTL + 60;
ok( $V === sn_drift_verdict_get( 3, 'm', 'p' ), 'an entry just inside it still hits' );

echo "\nGroup: an option does not expire on its own, so writes prune and cap\n";
$GLOBALS['__opt'] = array();
sn_drift_verdict_put( 10, 'm', 'p', $V );
$GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ][10]['ts'] = time() - SN_DRIFT_VERDICT_TTL - 1;
sn_drift_verdict_put( 11, 'm', 'p', $V );
ok( ! isset( $GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ][10] ), 'a write prunes expired entries — the job the transient did for free' );
ok( isset( $GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ][11] ), 'and keeps the live one' );

$GLOBALS['__opt'] = array();
for ( $i = 1; $i <= SN_DRIFT_VERDICT_CAP + 10; $i++ ) {
	sn_drift_verdict_put( $i, 'm', 'p', $V );
	$GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ][ $i ]['ts'] = time() - ( SN_DRIFT_VERDICT_CAP + 10 - $i );
}
sn_drift_verdict_put( 99999, 'm', 'p', $V );
$store = $GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ];
ok( count( $store ) <= SN_DRIFT_VERDICT_CAP, 'the store is capped (' . count( $store ) . ' <= ' . SN_DRIFT_VERDICT_CAP . ')' );
ok( isset( $store[99999] ), 'the newest entry survives the cap' );
ok( ! isset( $store[1] ), 'and the oldest is evicted' );

echo "\nGroup: it is an OPTION, and that is the whole point\n";
$GLOBALS['__opt'] = array(); $GLOBALS['__transient'] = array();
sn_drift_verdict_put( 5, 'm', 'p', $V );
ok( isset( $GLOBALS['__opt'][ SN_DRIFT_VERDICT_OPT ] ), 'verdicts are stored in an option' );
ok( array() === $GLOBALS['__transient'], 'and NOT in a transient — a plugin update flushes those, which is what was re-paying the corpus every release' );
ok( false === $GLOBALS['__autoload'][ SN_DRIFT_VERDICT_OPT ], 'written autoload=no, so it never rides a front-end request' );

// The check itself must not reintroduce one. A source assertion, deliberately:
// the regression would be someone reaching for set_transient again out of habit.
$src = (string) file_get_contents( __DIR__ . '/../inc/health-check-drift-time-phrases.php' );
ok( false === strpos( $src, 'set_transient(' ), 'the drift check no longer writes a transient for verdicts' );
ok( false === strpos( $src, 'get_transient(' ), 'nor reads one' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
