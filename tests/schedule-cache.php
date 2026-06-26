<?php
/**
 * Standalone fixture tests for inc/schedule-cache.php (the purge seam).
 *
 * Task 6 of the scheduled-content subsystem: sn_schedule_purge_urls is a thin
 * wrapper over the plugin's existing Cloudflare purge-by-URL function. It is the
 * ONLY purge call the fire handler makes, so this test pins its contract:
 *   - configured + non-empty  -> passes the EXACT array straight through to
 *                                sn_cf_purge_urls and returns its (bool) result;
 *   - unconfigured            -> returns false, sn_cf_purge_urls NOT called;
 *   - empty input             -> returns false (no-op).
 *
 * The sn_cf_purge_urls stub is INPUT-AWARE: it records the exact $urls it
 * received so a wrong array passed through makes a test FAIL (the
 * stub-at-the-boundary lesson: a record-only stub would let a marshalling bug
 * slip). The de-dupe/chunk behaviour of the real sn_cf_purge_urls is NOT
 * re-tested here; the wrapper's contract is "pass through untouched", so the
 * stub asserts the array crosses the seam verbatim.
 *
 * Run: php tests/schedule-cache.php
 *
 * @since plugin v6.40.0
 */

// SECURITY: Prevent web access. Test fixture, not a runtime module. CLI / WP-CLI
// only, mirroring tests/schedule-engine.php.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );

// ─── Stubs for the Cloudflare purge seam ──────────────────────────────
// Both are input-aware / toggleable via globals so the same wrapper is exercised
// across configured / unconfigured / empty without re-requiring the module.
$GLOBALS['__cf_configured']    = true;   // sn_cf_is_configured() return.
$GLOBALS['__cf_purge_return']  = true;   // sn_cf_purge_urls() return.
$GLOBALS['__cf_purge_calls']   = array(); // records each $urls argument.

if ( ! function_exists( 'sn_cf_is_configured' ) ) {
	function sn_cf_is_configured() {
		return ! empty( $GLOBALS['__cf_configured'] );
	}
}
if ( ! function_exists( 'sn_cf_purge_urls' ) ) {
	function sn_cf_purge_urls( $urls ) {
		$GLOBALS['__cf_purge_calls'][] = $urls;
		return $GLOBALS['__cf_purge_return'];
	}
}

require_once __DIR__ . '/../inc/schedule-cache.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}

echo "schedule-cache: sn_schedule_purge_urls purge seam\n\n";

// ─── Group: configured + non-empty -> passes through, returns true ────
echo "Group: configured pass-through\n";
$GLOBALS['__cf_configured']   = true;
$GLOBALS['__cf_purge_return'] = true;
$GLOBALS['__cf_purge_calls']  = array();

$urls = array( 'https://example.com/notes/', 'https://example.com/' );
$ret  = sn_schedule_purge_urls( $urls );
ok( $ret === true, 'configured + non-empty: returns true (dispatched)' );
ok( count( $GLOBALS['__cf_purge_calls'] ) === 1, 'configured + non-empty: sn_cf_purge_urls called exactly once' );
// EXACT pass-through: the array crosses the seam verbatim. Falsification: a
// wrong URL in the expected array would make this assert FAIL.
ok(
	$GLOBALS['__cf_purge_calls'][0] === array( 'https://example.com/notes/', 'https://example.com/' ),
	'configured + non-empty: the EXACT $urls array is passed through untouched'
);

// ─── Group: wrapper returns whatever sn_cf_purge_urls returns ─────────
echo "\nGroup: return value forwarded\n";
$GLOBALS['__cf_configured']   = true;
$GLOBALS['__cf_purge_return'] = false; // configured but CF returns false (e.g. all-URLs-filtered inside CF).
$GLOBALS['__cf_purge_calls']  = array();
$ret = sn_schedule_purge_urls( array( 'https://example.com/x' ) );
ok( $ret === false, 'configured: forwards a false return from sn_cf_purge_urls' );
ok( count( $GLOBALS['__cf_purge_calls'] ) === 1, 'configured: sn_cf_purge_urls still called when it returns false' );

// ─── Group: unconfigured -> false, NOT called ─────────────────────────
echo "\nGroup: unconfigured\n";
$GLOBALS['__cf_configured']   = false;
$GLOBALS['__cf_purge_return'] = true;
$GLOBALS['__cf_purge_calls']  = array();
$ret = sn_schedule_purge_urls( array( 'https://example.com/x' ) );
ok( $ret === false, 'unconfigured: returns false (no creds)' );
ok( count( $GLOBALS['__cf_purge_calls'] ) === 0, 'unconfigured: sn_cf_purge_urls is NOT called' );

// ─── Group: empty input -> false, NOT called ──────────────────────────
echo "\nGroup: empty input\n";
$GLOBALS['__cf_configured']   = true;
$GLOBALS['__cf_purge_return'] = true;
$GLOBALS['__cf_purge_calls']  = array();
$ret = sn_schedule_purge_urls( array() );
ok( $ret === false, 'empty array: returns false (nothing to purge)' );
ok( count( $GLOBALS['__cf_purge_calls'] ) === 0, 'empty array: sn_cf_purge_urls is NOT called' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
