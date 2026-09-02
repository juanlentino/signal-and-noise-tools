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

// ── NEGATIVE CONTROL: the detector must be able to FIRE ─────────────────────
// The first live run reported anomalies: 0 across all seven families. A zero
// from a detector that has never fired is indistinguishable from a detector
// that CANNOT fire, so both sides are exercised here against live-shaped data.

// UP side, openai-shaped: median 13, MAD 11 -> fires above ~62 (measured).
$oa = array(); for ( $i = 0; $i < 30; $i++ ) { $oa[] = 8 + ( $i % 5 ) * 2; }
$oa[1] = 900; // index 0 is YESTERDAY: rows() counts backward, so recent = low index
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'openai', 30, $oa, $NOW ) );
$r = snt_ml_reader_anomalies( $NOW );
ok( $r['counts']['anomalies'] > 0, 'NEGATIVE CONTROL: a spike on a live-shaped series DOES fire' );

// MAD-0 family: the live `uptime` shape. Before the fallback this could never
// fire at all — the most rigid reader was the one structurally excluded.
$up = array_fill( 0, 30, 480 );
$up[1] = 40; // recent day (index 0 = yesterday)
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'uptime', 30, $up, $NOW ) );
$r = snt_ml_reader_anomalies( $NOW );
ok( 0.0 === (float) $r['families'][0]['baseline']['mad'], 'fixture reproduces the live MAD-0 shape' );
ok( $r['counts']['anomalies'] > 0, 'a MAD-0 family CAN now fire — the sqrt(pi/2) fallback' );

// A PERFECTLY rigid series stays an honest unknown: no fallback rescues it.
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'uptime', 30, array_fill( 0, 30, 480 ), $NOW ) );
$r = snt_ml_reader_anomalies( $NOW );
ok( 0 === $r['counts']['anomalies'], 'a perfectly rigid series reports nothing — unquantifiable, not calm' );

// DOWN side: silence. Robust z cannot reach it on bounded-below counts, so the
// binary rule must. Measured live: zero hits scores |z| 0.74-2.30 vs 3.5.
// Reproduces the LIVE other-bot dispersion: median 376, MAD ~110 (ratio 0.29).
// A tighter fixture would let robust z reach zero and prove nothing — measured:
// at MAD/median 0.09 the z path fires on silence, at 0.29 it cannot.
$si = array(); for ( $i = 0; $i < 30; $i++ ) { $si[] = 376 + ( ( $i % 5 ) - 2 ) * 110; }
$si[0] = 0; // yesterday
$GLOBALS['__fetch'] = array( 'ok' => true, 'error' => null, 'rows' => rows( 'other-bot', 30, $si, $NOW ) );
$r  = snt_ml_reader_anomalies( $NOW );
$sg = array_filter( $r['families'][0]['signals'], static function ( $s ) { return 'reader_silent' === $s['kind']; } );
ok( 1 === count( $sg ), 'a family present on most days going to ZERO fires the silence rule' );
ok( 1 === $r['counts']['silences'], 'and is counted separately from anomalies' );
$one = array_values( $sg )[0];
ok( 'down' === $one['direction'] && 3 === $one['severity'], 'silence is a down finding at severity 3' );
ok( false !== strpos( $one['plain_label'], 'went silent' ), 'and says so plainly' );

// The z path must NOT have produced it — that is the whole point.
ok( 0 === $r['counts']['anomalies'], 'robust z produced NOTHING for that same zero day — the rule is why it is reported' );

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
ok( false !== strpos( $h['summary'], 'deviation' ), 'the sentence names the deviations' );

// v13.79.0: silence is reported SEPARATELY, because it is a binary presence
// rule and not a z-score. The earlier assertion here pinned the word "BELOW",
// asserting a two-sided reading that live data showed could never fire.
$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 0, 'silences' => 2, 'families_eligible' => 7, 'families_seen' => 12 ) ) );
ok( 'recommended' === $h['status'], 'silence alone is enough to lower the verdict' );
ok( false !== strpos( $h['summary'], 'silence' ), 'and the sentence names it' );
ok( false === strpos( $h['summary'], 'deviation' ), 'without claiming a deviation that did not happen' );

$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 3, 'silences' => 2, 'families_eligible' => 7, 'families_seen' => 12 ) ) );
ok( false !== strpos( $h['summary'], 'deviation' ) && false !== strpos( $h['summary'], 'silence' ), 'both kinds are reported, and kept distinct' );

$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 0, 'silences' => 0, 'families_eligible' => 7, 'families_seen' => 12 ) ) );
ok( 'good' === $h['status'] && false !== strpos( $h['summary'], 'none went silent' ), 'a clean window says BOTH things explicitly' );

$h = snt_ml_reader_anomalies_health( $base + array( 'counts' => array( 'anomalies' => 0, 'families_eligible' => 0, 'families_seen' => 4 ) ) );
ok( 'recommended' === $h['status'], 'nothing eligible is NOT good — zero measured families cannot vouch for calm' );
ok( false !== strpos( $h['summary'], 'nothing carries a statistic yet' ), 'and says why' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
