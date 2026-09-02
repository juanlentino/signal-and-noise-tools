<?php
/**
 * Standalone fixture tests for the reader-anomalies pipeline.
 *
 * The property that matters most is FAIL-CLOSED: a sensor that did not answer
 * must not report calm. An empty findings list and an unreadable instrument are
 * different states, and only one of them means "nothing is happening".
 *
 * @since 13.76.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__fetch'] = array( 'ok' => true, 'rows' => array(), 'error' => null );
function snt_mr_fetch( $days = 30, $view = 'aggregate' ) {
	$GLOBALS['__fetch_args'] = array( $days, $view );
	return $GLOBALS['__fetch'];
}

// The REAL analytics stat functions, not stubs: the baseline assertions below
// pin median/MAD values, and a stub would only prove the stub. Loaded in
// production order (derived before signals), as tests/analytics-signals.php does.
if ( ! defined( 'SN_ANALYTICS_CLASSES' ) ) { define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) ); }
require __DIR__ . '/../inc/analytics-derived.php';
require __DIR__ . '/../inc/analytics-signals.php';
require __DIR__ . '/../inc/mr-series.php';
require __DIR__ . '/../inc/ml-reader-anomalies.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $m\n"; } else { $fail++; echo "FAIL  $m\n"; } }

$NOW = strtotime( '2026-09-02 12:00:00 UTC' );

/** $days consecutive days of $hits ending yesterday. */
function rows( $family, $days, $hits, $now ) {
	$out = array();
	$end = $now - DAY_IN_SECONDS;
	for ( $i = 0; $i < $days; $i++ ) {
		$out[] = array(
			'family'  => $family,
			'surface' => 'html',
			'day'     => gmdate( 'Y-m-d', $end - $i * DAY_IN_SECONDS ),
			'hits'    => is_array( $hits ) ? ( $hits[ $i ] ?? 1 ) : $hits,
		);
	}
	return $out;
}

// ── FAIL-CLOSED ──────────────────────────────────────────────────────────────
$GLOBALS['__fetch'] = array( 'ok' => false, 'rows' => array(), 'error' => 'http_503' );
$r = snt_ml_reader_anomalies( $NOW );
ok( false === $r['ok'] && 'unavailable' === $r['state'], 'a failed sensor fetch is UNAVAILABLE, never an empty findings list' );
ok( 'http_503' === $r['reason'], 'and it names the reason' );
ok( ! isset( $r['families'] ), 'an unavailable record carries no findings rows at all' );
ok( isset( $r['window']['from'], $r['window']['to'] ), 'but still states the window it could not read' );

// ── the window ───────────────────────────────────────────────────────────────
$GLOBALS['__fetch'] = array( 'ok' => true, 'rows' => rows( 'search', 30, 100, $NOW ), 'error' => null );
$r = snt_ml_reader_anomalies( $NOW );
ok( '2026-09-01' === $r['window']['to'], 'the window ends YESTERDAY — today is a partial day and would read as a crash' );
ok( 30 === $r['window']['days'], 'thirty days' );
ok( array( 30, 'aggregate' ) === $GLOBALS['__fetch_args'], 'the sensor is asked for exactly the window it reports' );

// ── eligibility ──────────────────────────────────────────────────────────────
$GLOBALS['__fetch'] = array(
	'ok'    => true,
	'error' => null,
	'rows'  => array_merge(
		rows( 'search', 30, 100, $NOW ),   // present throughout  -> eligible
		rows( 'openai', 24, 8, $NOW ),     // present most days   -> eligible
		rows( 'amazon-ai', 9, 160, $NOW )  // bursty, high volume -> EXCLUDED
	),
);
$r = snt_ml_reader_anomalies( $NOW );
sort( $r['eligible'] );
ok( array( 'openai', 'search' ) === $r['eligible'], 'presence decides eligibility' );
ok( isset( $r['excluded']['amazon-ai'] ) && 9 === $r['excluded']['amazon-ai'], 'a high-VOLUME bursty family is excluded, and its day count is reported' );
ok( 3 === $r['counts']['families_seen'] && 2 === $r['counts']['families_eligible'], 'both counts are stated, so the floor is visible' );
ok( 20 === $r['floor']['min_days'] && 30 === $r['floor']['of'], 'the floor itself is in the payload — a threshold nobody can read is a magic number' );

// Every eligible family produces a row, even a quiet one.
$fams = array_column( $r['families'], 'family' );
sort( $fams );
ok( array( 'openai', 'search' ) === $fams, 'one row per eligible family' );

// ── ZERO-FILL reaches the composers ──────────────────────────────────────────
// A family present for exactly 20 days then silent for 10 is eligible, and the
// silence must be visible as zeros rather than as a shorter series.
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'seo', 20, 50, $NOW - 10 * DAY_IN_SECONDS ) );
$r = snt_ml_reader_anomalies( $NOW );
ok( in_array( 'seo', $r['eligible'], true ), 'a family that went quiet ten days ago is still eligible on presence' );
$row = $r['families'][0];
ok( 20 * 50 === $row['total'], 'its total counts only real hits — the fill adds nothing' );

// ── the BASELINE is on every row, including quiet ones ───────────────────────
// The case that matters: a family with NO anomalies. Before this the median
// lived only inside an anomaly signal's interval, so a family that deviated
// from nothing reported no norm at all.
$flat = array();
for ( $i = 0; $i < 30; $i++ ) { $flat[] = 100; }   // rigid: MAD is a real 0.
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'uptime', 30, $flat, $NOW ) );
$r   = snt_ml_reader_anomalies( $NOW );
$row = $r['families'][0];
ok( isset( $row['baseline'] ), 'every family row carries a baseline' );
ok( 100.0 === (float) $row['baseline']['median'], 'the median is the series median (100)' );
$anoms = array_filter( $row['signals'], static function ( $s ) { return 'anomaly' === ( $s['kind'] ?? '' ); } );
ok( array() === $anoms, 'and this family produced NO anomalies — the case the baseline exists for' );
// NOT (float)$mad === 0.0 — (float) null is also 0.0, so the cast would make the
// two states identical, which is the exact distinction this field exists for.
ok( null !== $row['baseline']['mad'] && 0.0 === (float) $row['baseline']['mad'], 'a rigid series reports MAD 0 (a real measurement) and NOT null (an absence)' );

// A varying series reports a real spread.
$vary = array();
for ( $i = 0; $i < 30; $i++ ) { $vary[] = 100 + ( $i % 2 ? 20 : -20 ); }
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'search', 30, $vary, $NOW ) );
$row = snt_ml_reader_anomalies( $NOW )['families'][0];
ok( $row['baseline']['mad'] > 0.0, 'a varying series reports a non-zero MAD (' . $row['baseline']['mad'] . ')' );
ok( null !== $row['baseline']['median'], 'and a median' );

// ── the health verdict ───────────────────────────────────────────────────────
require __DIR__ . '/../inc/ml-reader-anomalies-health.php';

$h = snt_ml_reader_anomalies_health( array( 'state' => 'unavailable', 'reason' => 'http_503' ) );
ok( 'recommended' === $h['status'], 'an UNREADABLE sensor is not a good verdict' );
ok( false !== strpos( $h['summary'], 'unknown, not quiet' ), 'and the sentence says so in words' );
ok( false !== strpos( $h['summary'], 'http_503' ), 'naming the reason' );

$h = snt_ml_reader_anomalies_health( null );
ok( 'recommended' === $h['status'], 'a missing report is also not good' );

$base = array( 'state' => 'ok', 'floor' => array( 'min_days' => 20, 'of' => 30 ) );
$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 0, 'families_eligible' => 7, 'families_seen' => 12 ) ) );
ok( 'good' === $h['status'], 'measured families with no deviations is good' );
ok( false !== strpos( $h['summary'], '7 of 12' ), 'and states how much of the population it actually measured' );

$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 3, 'families_eligible' => 7, 'families_seen' => 12 ) ) );
ok( 'recommended' === $h['status'], 'deviations are recommended, never CRITICAL — an instrument, not a gate' );
ok( false !== strpos( $h['summary'], 'BELOW' ), 'the sentence keeps the two-sided reading visible' );

$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 0, 'families_eligible' => 0, 'families_seen' => 4 ) ) );
ok( 'recommended' === $h['status'], 'nothing eligible is NOT good — zero measured families cannot vouch for calm' );
ok( false !== strpos( $h['summary'], 'nothing carries a statistic yet' ), 'and says why' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
