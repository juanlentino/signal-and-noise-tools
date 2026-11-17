<?php
/**
 * Tests: agent pairing, observe only (inc/analytics-pairing.php). A human
 * (direct) session within 60 seconds of a bot- or suspect-class page view is
 * paired; joined by time only. Owner brief 2026-11-17, with its two real cases.
 *
 * Run: php tests/analytics-pairing.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function sn_analytics_class_where( $c ) { return "class = '{$c}'"; }
$GLOBALS['ae'] = array();
function sn_analytics_stitched_rows( callable $build, $from, $to ) { $GLOBALS['q'][] = $build( 'sn_pageviews', '' ); return null === $GLOBALS['ae'] ? null : array( $GLOBALS['ae'] ); }
require dirname( __DIR__ ) . '/inc/analytics-pairing.php';

// Session rows as the sessions read writes them: ISO 8601 in site time (New York, -05:00 in November).
$s    = static fn( $start, $end, $source = '(direct)' ) => array( 'start' => $start, 'end' => $end, 'source' => $source );
$utc  = static fn( $t ) => (int) strtotime( $t . ' UTC' );
$nii  = $s( '2026-11-16T22:45:43-05:00', '2026-11-16T22:50:30-05:00' );
$yer  = $s( '2026-11-17T09:06:52-05:00', '2026-11-17T09:17:02-05:00' );
$ash1 = $utc( '2026-11-17 03:50:44' ); // 22:50:44 ET, 14 seconds after Niigata ends.
$ash2 = $utc( '2026-11-17 14:09:20' ); // 09:09:20 ET, inside Yerevan's session.

echo "\nGroup: the two real cases\n";
$out = sn_analytics_pairing_flag( array( $nii, $yer ), array( $ash2, $ash1 ) );
ok( true === $out[0]['paired'], 'Niigata, ended 22:50:30, and an Ashburn read at 22:50:44: paired (within 60 seconds after)' );
ok( true === $out[1]['paired'], 'Yerevan, 09:06:52 to 09:17:02, and an Ashburn read at 09:09:20: paired (overlap)' );

echo "\nGroup: the 60-second edge, both sides\n";
$end = $utc( '2026-11-17 03:50:30' );
$beg = $utc( '2026-11-17 03:45:43' );
ok( true === sn_analytics_pairing_flag( array( $nii ), array( $end + 60 ) )[0]['paired'], 'a hit 60 seconds after the end pairs' );
ok( false === sn_analytics_pairing_flag( array( $nii ), array( $end + 61 ) )[0]['paired'], 'a hit 61 seconds after the end does not' );
ok( true === sn_analytics_pairing_flag( array( $nii ), array( $beg - 60 ) )[0]['paired'], 'a hit 60 seconds before the start pairs' );
ok( false === sn_analytics_pairing_flag( array( $nii ), array( $beg - 61 ) )[0]['paired'], 'a hit 61 seconds before the start does not' );
ok( false === sn_analytics_pairing_flag( array( $nii ), array( $beg - 3600, $end + 3600 ) )[0]['paired'], 'hits on both sides, far off, do not pair' );

echo "\nGroup: only a (direct) session, and not read is null\n";
ok( false === sn_analytics_pairing_flag( array( $s( $nii['start'], $nii['end'], 'LinkedIn' ) ), array( $ash1 ) )[0]['paired'], 'a referred session is never paired' );
ok( false === sn_analytics_pairing_flag( array( $s( $nii['start'], $nii['end'], '(internal)' ) ), array( $ash1 ) )[0]['paired'], '(internal) is not direct' );
ok( false === sn_analytics_pairing_flag( array( $nii ), array() )[0]['paired'], 'no automated hit: not paired' );
ok( null === sn_analytics_pairing_flag( array( $nii ), null )[0]['paired'], 'a failed hits read: null, never false' );
ok( array( 'start', 'end', 'source', 'paired' ) === array_keys( sn_analytics_pairing_flag( array( $nii ), array() )[0] ), 'the flag is added, nothing else changes' );

echo "\nGroup: per day\n";
$days = sn_analytics_pairing_by_day( sn_analytics_pairing_flag( array( $yer, $nii, $s( '2026-11-17T10:00:00-05:00', '2026-11-17T10:01:00-05:00', 'LinkedIn' ) ), array( $ash1, $ash2 ) ) );
ok( array( array( 'day' => '2026-11-16', 'sessions' => 1, 'direct' => 1, 'paired' => 1, 'contact_direct' => 0 ), array( 'day' => '2026-11-17', 'sessions' => 2, 'direct' => 1, 'paired' => 1, 'contact_direct' => 0 ) ) === $days, 'paired sessions per site day of their start, oldest first, beside sessions and direct ones' );

ok( null === sn_analytics_pairing_by_day( sn_analytics_pairing_flag( array( $nii ), null ) )[0]['paired'], 'a flag not read is null on every day, never 0' );

echo "\nGroup: the read\n";
$GLOBALS['ae'] = array( array( 'ts' => $ash1, 'n' => 3 ) );
$got = sn_analytics_pairing_on_sessions( array( $nii ), 'human' );
$sql = (string) end( $GLOBALS['q'] );
ok( true === $got[0]['paired'], 'human sessions are flagged from the read' );
ok( false !== strpos( $sql, "blob1 = 'pv' AND (class = 'bot' OR class = 'suspect')" ) && false !== strpos( $sql, 'GROUP BY ts' ), 'it reads one row per second of bot- and suspect-class page views' );
ok( false === strpos( $sql, 'index1' ) && false === strpos( $sql, 'blob4' ) && false === strpos( $sql, 'blob6' ), 'joined by time only: no visitor code, place or network in the read' );
ok( false !== strpos( $sql, "timestamp >= toDateTime('2026-11-17 03:44:43')" ) && false !== strpos( $sql, "timestamp < toDateTime('2026-11-17 03:51:31')" ), 'the read spans the sessions, 60 seconds either side' );
$n = count( $GLOBALS['q'] );
ok( null === sn_analytics_pairing_on_sessions( array( $nii ), 'bot' )[0]['paired'] && count( $GLOBALS['q'] ) === $n, 'other classes are not paired, and nothing is read for them' );
$GLOBALS['ae'] = null;
ok( null === sn_analytics_pairing_on_sessions( array( $nii ), 'human' )[0]['paired'], 'a failed read marks the sessions null' );
$GLOBALS['ae'] = array_fill( 0, SN_ANALYTICS_PAIRING_ROW_CAP, array( 'ts' => 1 ) );
ok( null === sn_analytics_pairing_on_sessions( array( $nii ), 'human' )[0]['paired'], 'a capped read is not read: null' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
