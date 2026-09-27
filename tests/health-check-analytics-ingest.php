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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
