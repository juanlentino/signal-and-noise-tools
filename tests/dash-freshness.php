<?php
/**
 * Tests: how old is the screen?
 *
 * THE DEFECT THIS EXISTS FOR. Every figure on the dashboard was rendered in
 * the same type, under one present-tense headline — "Everything is holding" —
 * while the readings behind it were taken across half a day. Measured live
 * 2026-08-19: sn_analytics_rollup_daily had last fired ~13 hours earlier, so
 * the views figure and the whole 30-day trend were most of a day old, sitting
 * beside a worker version ten minutes old and a purge stamp sixteen seconds
 * old. The headline is a claim about NOW, assembled from readings that are not.
 *
 * This plugin already refuses to fabricate a VALUE it does not have — "not
 * seen yet" instead of a zero, an em dash for an absent repo, null rather than
 * a measured zero. It was applying none of that discipline to TIME.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( '__' ) ) { function __( $t, $d = '' ) { return $t; } }
if ( ! function_exists( '_n' ) ) { function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = '' ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'human_time_diff' ) ) {
	// Enough fidelity to assert the UNIT, which is what the subline shows.
	function human_time_diff( $from, $to = 0 ) {
		$d = abs( (int) $to - (int) $from );
		if ( $d < HOUR_IN_SECONDS ) { return max( 1, (int) round( $d / 60 ) ) . ' mins'; }
		if ( $d < DAY_IN_SECONDS )  { return max( 1, (int) round( $d / 3600 ) ) . ' hours'; }
		return max( 1, (int) round( $d / 86400 ) ) . ' days';
	}
}
require __DIR__ . '/../inc/dash-freshness.php';
require __DIR__ . '/../inc/admin-glance.php';  // the REAL attention predicate dash-verdict depends on
require __DIR__ . '/../inc/dash-verdict.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
echo "dashboard freshness\n\n";

$now = 1787187000;
function reading( $label, $ago, $stale_after, $now = 1787187000 ) {
	return array(
		'label'       => $label,
		'measured_at' => null === $ago ? null : $now - $ago,
		'stale_after' => $stale_after,
	);
}

// ── THE OLDEST READING IS WHAT THE HEADLINE IS ACTUALLY CLAIMING ───────────
$f = sn_dash_freshness( array(
	reading( 'Analytics', 13 * HOUR_IN_SECONDS, 2 * DAY_IN_SECONDS ),
	reading( 'Fleet', 4 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS ),
), $now );
ok( 'Analytics' === ( $f['oldest']['label'] ?? '' ), 'the oldest reading is the one the verdict is really as-of' );
ok( 13 * HOUR_IN_SECONDS === ( $f['oldest']['age'] ?? 0 ), 'and its age is reported in seconds, not rounded away' );

// ── NEVER-MEASURED IS NOT OLD, IT IS A DIFFERENT ANSWER ────────────────────
// The whole point of realtime-zero-vs-null: a source that has never reported
// is not "infinitely stale", it is unknown. Folding it into `oldest` would
// let one untracked hook permanently pin the subline to a fake maximum.
$f2 = sn_dash_freshness( array(
	reading( 'Analytics', 3 * HOUR_IN_SECONDS, 2 * DAY_IN_SECONDS ),
	reading( 'Citations', null, HOUR_IN_SECONDS ),
), $now );
ok( 'Analytics' === ( $f2['oldest']['label'] ?? '' ), 'A NEVER-MEASURED SOURCE IS NOT THE OLDEST — unknown is not old' );
ok( in_array( 'Citations', $f2['unmeasured'], true ), 'it is reported separately, as unmeasured' );
ok( empty( $f2['stale'] ), 'and it is NOT stale either — you cannot be late for a train you never boarded' );

// ── STALENESS IS PER-SOURCE, AND A GLOBAL THRESHOLD INVERTS IT ─────────────
// This fixture is built so that ANY single global threshold gets one of the
// two wrong: at 13h the daily rollup is perfectly normal and the five-minute
// probe is badly overdue. A screen that alarmed on the rollup would cry wolf
// every single day; one that stayed quiet on the probe would miss a dead one.
$f3 = sn_dash_freshness( array(
	reading( 'Analytics', 13 * HOUR_IN_SECONDS, 2 * DAY_IN_SECONDS ),
	reading( 'Fleet', 13 * HOUR_IN_SECONDS, HOUR_IN_SECONDS ),
), $now );
$stale_labels = array_column( $f3['stale'], 'label' );
ok( in_array( 'Fleet', $stale_labels, true ), 'A 13-HOUR-OLD 5-MINUTE PROBE IS STALE — it is late against its OWN cadence' );
ok( ! in_array( 'Analytics', $stale_labels, true ), 'A 13-HOUR-OLD DAILY ROLLUP IS NOT — same age, and the right answer is the opposite' );

// Clock skew must not produce a negative age or a fake "measured in the future".
$skew = sn_dash_freshness( array( array( 'label' => 'Fleet', 'measured_at' => $now + 500, 'stale_after' => HOUR_IN_SECONDS ) ), $now );
ok( 0 === ( $skew['oldest']['age'] ?? -1 ), 'a future timestamp clamps to zero rather than reporting a negative age' );

ok( null === sn_dash_freshness( array(), $now )['oldest'], 'no readings at all yields NULL, never a zero age' );

// ── A STALE READING REACHES THE VERDICT, ON BOTH SURFACES ─────────────────
// Not a new alarm mechanism: it becomes a CARD, so the one shared
// sn_dash_verdict() raises it and the widget cannot disagree with the screen.
$cards = sn_dash_freshness_cards( $f3 );
ok( 1 === count( $cards ), 'one card per stale reading, and none for the healthy one' );
ok( 'warn' === ( $cards[0]['pill']['kind'] ?? '' ), 'a stale reading warns — it is unmeasured, not proven bad' );
$verdict = sn_dash_verdict( $cards );
ok( 'ok' !== $verdict['state'], 'THE VERDICT STOPS BEING GREEN — a stale input is not a healthy one' );
ok( 1 === count( $verdict['exceptions'] ), 'and it is named in the exceptions, where you would look' );

$clean = sn_dash_freshness_cards( sn_dash_freshness( array( reading( 'Fleet', 60, HOUR_IN_SECONDS ) ), $now ) );
ok( array() === $clean, 'nothing stale produces NO cards — a healthy screen gains nothing to look at' );

// ── THE SUBLINE FRAGMENT ──────────────────────────────────────────────────
ok( false !== strpos( sn_dash_freshness_label( $f ), 'oldest reading' ), 'the subline says what the age IS, not just a bare duration' );
ok( false !== strpos( sn_dash_freshness_label( $f ), '13 hours' ), 'and states it in human units' );
ok( '' === sn_dash_freshness_label( sn_dash_freshness( array(), $now ) ), 'with nothing measured the fragment is EMPTY — never "oldest reading unknown ago"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
