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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
