<?php
/**
 * Tests for the analytics ingest liveness check: a dead collector must not
 * read as a quiet day, and a failed query must never read as zero.
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

$GLOBALS['__q'] = array( 'cfg' => true, 'rows' => null, 'sql' => '' );
function sn_analytics_config() { return $GLOBALS['__q']['cfg']; }
function sn_analytics_query( $sql ) { $GLOBALS['__q']['sql'] = $sql; return $GLOBALS['__q']['rows']; }
function sn_health_pack_check( $label, $findings, $fix_hint = '', $skipped = null ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'skipped' => ( is_string( $skipped ) && '' !== $skipped ) ? $skipped : null );
}
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }

require_once __DIR__ . '/../inc/health-check-analytics-ingest.php';

$now = 1790000000;
$f   = sn_health_analytics_ingest_findings( array(
	array( 'cls' => 'human', 'recent' => '40', 'recent_pv' => '12', 'last_pv' => (string) ( $now - 600 ) ),
	array( 'cls' => 'bot', 'recent' => '300', 'recent_pv' => '100', 'last_pv' => (string) ( $now - 60 ) ),
), $now );
ok( array() === $f, 'healthy: recent human pageviews raise nothing' );

$f = sn_health_analytics_ingest_findings( array(
	array( 'cls' => 'human', 'recent' => '5', 'recent_pv' => '0', 'last_pv' => (string) ( $now - 30 * 3600 ) ),
	array( 'cls' => 'bot', 'recent' => '300', 'recent_pv' => '100', 'last_pv' => (string) ( $now - 60 ) ),
), $now );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], '30 hours old' ) && false !== strpos( $f[0]['note'], 'Other events kept arriving (305' ),
	'zero human pageviews while other rows arrive is flagged, naming the age and the other traffic' );

$f = sn_health_analytics_ingest_findings( array(), $now );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'no human pageview in the last 7 days' ) && false !== strpos( $f[0]['note'], 'stopped writing' ),
	'an empty dataset (collector dark) is flagged, never read as a quiet day' );

$f = sn_health_analytics_ingest_findings( array(
	array( 'cls' => 'human', 'recent' => '0', 'recent_pv' => '0', 'last_pv' => (string) ( $now - 26 * 3600 ) ),
), $now );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'Nothing else arrived' ), 'newest human row older than 24 h with nothing else is flagged' );

$GLOBALS['__q']['rows'] = null;
$r = sn_health_check_analytics_ingest();
ok( 0 === $r['count'] && is_string( $r['skipped'] ) && false !== strpos( $r['skipped'], 'could not be checked' ),
	'a failed query reports "could not check", never zero' );
ok( false !== strpos( $GLOBALS['__q']['sql'], "blob7 AS cls" ) && false !== strpos( $GLOBALS['__q']['sql'], "INTERVAL '7' DAY" ), 'the wrapper runs the grouped 7-day read' );

$GLOBALS['__q']['cfg'] = false;
$r = sn_health_check_analytics_ingest();
ok( 0 === $r['count'] && is_string( $r['skipped'] ), 'unconfigured analytics is skipped, not a finding' );

$GLOBALS['__q']['cfg']  = true;
$GLOBALS['__q']['rows'] = array();
$r = sn_health_check_analytics_ingest();
ok( 1 === $r['count'] && null === $r['skipped'], 'a successful empty read flags through the wrapper' );

// 2026-11-21: the canary tells a quiet day from a dead collector.
$quiet = array(
	array( 'cls' => 'human', 'recent' => '0', 'recent_pv' => '0', 'last_pv' => (string) ( $now - 38 * 3600 ) ),
	array( 'cls' => 'suspect', 'recent' => '9', 'recent_pv' => '9', 'last_pv' => (string) ( $now - 3600 ) ),
);
ok( array() === sn_health_analytics_ingest_findings( $quiet, $now, $now - 1800 ), 'canary seen 30 minutes ago and a human 38 h ago: a quiet day, no finding' );
$f = sn_health_analytics_ingest_findings( $quiet, $now, 0 );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'canary did not arrive' ), 'no canary in 24 h: flagged at 24 h, and the note says the canary is missing too' );
$f = sn_health_analytics_ingest_findings( $quiet, $now, null );
ok( 1 === count( $f ) && false === strpos( $f[0]['note'], 'canary' ), 'canary not read (null): the old 24 h rule, and no claim about the canary' );
$old = array( array( 'cls' => 'human', 'recent' => '0', 'recent_pv' => '0', 'last_pv' => (string) ( $now - 80 * 3600 ) ) );
$f   = sn_health_analytics_ingest_findings( $old, $now, $now - 600 );
ok( 1 === count( $f ) && false !== strpos( $f[0]['note'], 'the collector writes, but no person has been recorded for 3 days' ), 'canary fine but no human for over 72 h: flagged as the beacon or the classifier' );
ok( 1 === count( sn_health_analytics_ingest_findings( array(), $now, $now - 600 ) ), 'canary fine but no human row in 7 days: still flagged' );
ok( 1 === count( sn_health_analytics_ingest_findings( $quiet, $now, $now - 25 * 3600 ) ), 'a canary older than 24 h proves nothing' );
ok( false !== strpos( sn_health_analytics_canary_sql(), 'sn_owner_hits' ) && false !== strpos( sn_health_analytics_canary_sql(), 'double14) = 7' ), 'the canary read looks for identity 7 in the owner dataset' );
// 2026-11-22: the canary is its own path, so another SignalNoise- tool cannot stand in for it.
ok( false !== strpos( sn_health_analytics_canary_sql(), "blob2 = '/_sn/canary'" ), 'the canary read matches the canary path, not any identity 7 page view' );
ok( false !== strpos( sn_health_analytics_canary_sql( true ), "blob2 IN ('/_sn/canary', '/')" ), 'in the first day it also takes the old path /' );
$t0 = 1790000000;
ok( true === sn_health_analytics_canary_legacy( null, $t0 ) && true === sn_health_analytics_canary_legacy( array( 'at' => $t0, 'http' => 204, 'why' => '' ), $t0 ), 'no stored start (the new canary has not sent yet): the old path still counts' );
ok( true === sn_health_analytics_canary_legacy( array( 'since' => $t0 - 86399 ), $t0 ), 'under 24 hours since the first new send: an old row can still be in the window' );
ok( false === sn_health_analytics_canary_legacy( array( 'since' => $t0 - 86400 ), $t0 ), '24 hours on, every old row is outside the window: the canary path only' );
$GLOBALS['__opt']['sn_ingest_canary_last'] = array( 'at' => time(), 'http' => 204, 'why' => '', 'since' => time() - 90000 );
$GLOBALS['__q']['rows'] = array();
sn_health_check_analytics_ingest();
ok( false !== strpos( $GLOBALS['__q']['sql'], "blob2 = '/_sn/canary'" ), 'the check itself reads the canary path only once the first day is over' );
$GLOBALS['__opt']['sn_ingest_canary_last']['since'] = time() - 60;
sn_health_check_analytics_ingest();
ok( false !== strpos( $GLOBALS['__q']['sql'], "IN ('/_sn/canary', '/')" ), 'and both paths inside it' );
unset( $GLOBALS['__opt']['sn_ingest_canary_last'] );
$GLOBALS['__q']['rows'] = null;

// Issue 2484: a failed canary send is named in the note of a finding that is already raised, and nowhere else.
$errs = 0;
set_error_handler( function () use ( &$errs ) { $errs++; return true; } );
function nt( $rows, $canary, $ls = null ) { global $now; $f = sn_health_analytics_ingest_findings( $rows, $now, $canary, $ls ); return 1 === count( $f ) ? $f[0]['note'] : null; }
function has( $h, $n ) { return is_string( $h ) && false !== strpos( $h, $n ); }
$base = nt( $quiet, 0 );
$s403 = array( 'at' => $now - 42 * 60, 'http' => 403, 'why' => '' );
$n    = nt( $quiet, 0, $s403 );
ok( has( $n, 'The last canary send, 42 minutes ago, was answered HTTP 403.' ) && has( $n, 'canary did not arrive' ), 'T1: a 403 send adds the status and the age in minutes to the finding' );
$n = nt( $quiet, 0, array( 'at' => $now - 3 * 3600, 'http' => 0, 'why' => 'cURL error 28: Operation timed out' ) );
ok( has( $n, 'The last canary send, 3 hours ago, got no answer: cURL error 28: Operation timed out.' ) && ! has( $n, 'HTTP 0' ), 'T2: http 0 reads as no answer with the reason, in hours, never as HTTP 0' );
ok( has( nt( $quiet, 0, array( 'at' => $now - 60, 'http' => 0, 'why' => 'no beacon key configured' ) ), 'got no answer: no beacon key configured.' ), 'T3: a missing beacon key is named' );
ok( is_string( $base ) && $base === nt( $quiet, 0, array( 'at' => $now - 60, 'http' => 204, 'why' => '' ) ), 'T4: a clean 204 adds nothing' );
$same = is_string( $base );
foreach ( array( null, false, 'x', 403, array(), array( 'at' => $now - 60, 'why' => 'boom' ) ) as $bad ) {
	$same = $same && $base === nt( $quiet, 0, $bad );
}
ok( $same, 'T5: a missing or unreadable option adds nothing' );
ok( has( nt( $quiet, 0, array( 'at' => $now - 60, 'http' => 204, 'why' => 'odd' ) ), 'was answered HTTP 204: odd.' ), 'T6: a 204 with a reason still adds the sentence' );
$t7 = true;
foreach ( array( array( 'http' => 403 ), array( 'at' => 0, 'http' => 403 ), array( 'at' => $now + 600, 'http' => 403 ) ) as $ls ) {
	$n  = nt( $quiet, 0, $ls );
	$t7 = $t7 && has( $n, 'The last canary send was answered HTTP 403.' ) && ! has( $n, ' ago, was answered' );
}
ok( $t7, 'T7: a missing, zero or future send time omits the age' );
$n = nt( $quiet, 0, array( 'http' => 403, 'why' => str_repeat( 'a', 500 ) ) );
ok( has( $n, str_repeat( 'a', 200 ) . '.' ) && ! has( $n, str_repeat( 'a', 201 ) ), 'T8: a long reason is cut at 200 characters' );
$n = nt( $quiet, 0, array( 'http' => 403, 'why' => "line one\n  line two." ) );
ok( has( $n, ': line one line two.' ) && ! has( $n, 'two..' ), 'T8: whitespace runs collapse to one space and the period is not doubled' );
ok( has( nt( $quiet, 0, array( 'http' => 500, 'why' => array( 'x' ) ) ), 'was answered HTTP 500.' ) && 0 === $errs, 'T8: a reason that is not a string is dropped without a PHP notice' );
restore_error_handler();
$n = nt( $old, $now - 600, $s403 );
ok( has( $n, 'the collector writes, but no person has been recorded for 3 days' ) && has( $n, 'The last canary send' ), 'T9: the sentence is added on the 72 hour path' );
$n = nt( array(), null, $s403 );
ok( has( $n, 'stopped writing' ) && has( $n, 'The last canary send' ), 'T9: the sentence is added on the dark collector path' );
$healthy = array( array( 'cls' => 'human', 'recent' => '40', 'recent_pv' => '12', 'last_pv' => (string) ( $now - 600 ) ) );
ok( array() === sn_health_analytics_ingest_findings( $healthy, $now, null, $s403 ) && array() === sn_health_analytics_ingest_findings( $quiet, $now, $now - 1800, $s403 ),
	'T10: a failed send never raises a finding on a healthy read or a quiet day' );

$GLOBALS['__q']['rows']                  = array();
$GLOBALS['__opt']['sn_ingest_canary_last'] = array( 'at' => time() - 120, 'http' => 403, 'why' => '' );
$r                                       = sn_health_check_analytics_ingest();
ok( 1 === $r['count'] && has( $r['findings'][0]['note'], 'The last canary send' ) && has( $r['findings'][0]['note'], 'was answered HTTP 403.' ), 'T11: the wrapper reads the stored send and passes it on' );
$GLOBALS['__q']['rows'] = null;
$r                      = sn_health_check_analytics_ingest();
ok( 0 === $r['count'] && has( $r['skipped'], 'could not be checked' ) && ! has( $r['skipped'], 'canary send' ), 'T12: a failed query stays skipped and unchanged with a failed send stored' );
$GLOBALS['__q']['cfg'] = false;
$r                     = sn_health_check_analytics_ingest();
ok( 0 === $r['count'] && is_string( $r['skipped'] ) && ! has( $r['skipped'], 'canary send' ), 'T12: unconfigured analytics stays skipped and unchanged with a failed send stored' );
unset( $GLOBALS['__opt']['sn_ingest_canary_last'] );
$GLOBALS['__q'] = array( 'cfg' => true, 'rows' => array(), 'sql' => '' );
$r              = sn_health_check_analytics_ingest();
ok( 1 === $r['count'] && ! has( $r['findings'][0]['note'], 'The last canary send' ), 'T11: with no stored send the wrapper adds nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
