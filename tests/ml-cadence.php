<?php
/**
 * Tests for inc/ml-cadence.php — cadence flags (v10.22.0, ML pipeline #5) +
 * its health-check adapter. What this fixture pins:
 *   - publish cadence: post_date_gmt history → one flag when the current gap
 *     z-scores at/over the threshold; LATE only (a burst is not an ops flag);
 *   - cron cadence: every hook with enough recorded firings is watched; a
 *     hook with too little history is SKIPPED (unknown), never flagged;
 *   - a FAILED cron-history read skips the cron section but still returns
 *     the publish verdict (a partial answer is real — and the envelope says
 *     the cron section was skipped);
 *   - the health adapter maps flags → the standard envelope (one finding per
 *     flag, no fabrication on malformed rows);
 *   - the pipeline wrapper's 500/ok shapes.
 * Run: php tests/ml-cadence.php
 * @since plugin v10.22.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

function __( $s, $d = null ) { return (string) $s; }
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function apply_filters( $tag, $value ) { return $value; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint );
}
// Kernel: load the REAL math (pure file, zero WP calls) — never stub the
// formula under test.
require __DIR__ . '/../inc/ml-kernel.php';

// Publish history: get_posts returns post objects with post_date_gmt.
$GLOBALS['__pub_dates'] = array();
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['__pub_dates'] as $d ) {
		$p                = new stdClass();
		$p->post_date_gmt = $d;
		$out[]            = $p;
	}
	return $out;
}

// Cron history table via wpdb: hooks list + per-hook timestamps.
class MC_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $fail       = false;
	public $hooks      = array(); // hook => [fired_at_ts...]
	public $bound      = array();
	public function prepare( $sql, ...$args ) { $this->bound = $args; return $sql; }
	public function get_results( $sql, $output = OBJECT ) {
		if ( $this->fail ) { $this->last_error = 'gone'; return array(); }
		$this->last_error = '';
		if ( false !== strpos( $sql, 'GROUP BY hook' ) ) {
			$out = array();
			foreach ( $this->hooks as $hook => $ts ) {
				$out[] = array( 'hook' => $hook, 'c' => (string) count( $ts ) );
			}
			return $out;
		}
		// per-hook read: first bound arg is the hook (flattened by prepare stub).
		$flat = array();
		array_walk_recursive( $this->bound, function ( $v ) use ( &$flat ) { $flat[] = $v; } );
		$hook = (string) ( $flat[0] ?? '' );
		$out  = array();
		foreach ( $GLOBALS['wpdb']->hooks[ $hook ] ?? array() as $ts ) {
			// Models the REAL column: a UTC DATETIME string (gmdate at write).
			$out[] = array( 'fired_at' => gmdate( 'Y-m-d H:i:s', (int) $ts ) );
		}
		return $out;
	}
}
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
$GLOBALS['wpdb'] = new MC_Stub_wpdb();

// Registered cron schedules. Models the REAL snt_cron_interval_seconds()
// (inc/cron-dashboard.php): interval in seconds, and 0 — never null — when
// the hook has no recurring schedule (a single event, or wp_get_schedule
// returning false).
$GLOBALS['__cron_intervals'] = array();
function snt_cron_interval_seconds( $hook ) {
	return (int) ( $GLOBALS['__cron_intervals'][ (string) $hook ] ?? 0 );
}

require __DIR__ . '/../inc/ml-cadence.php';
require __DIR__ . '/../inc/health-check-ml-cadence.php';
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// A "now" the tests own — the impl takes it as an argument (no clock reads
// inside the logic path, which is what makes these pins possible).
$NOW = 100000;

echo "Group: publish cadence\n";
// Metronome-ish weekly-equivalent history with one wildly late current gap:
// events at 0,100,180,320,400 (the kernel fixture's series) → z 8.187 at now 700.
$GLOBALS['__pub_dates'] = array( gmdate( 'Y-m-d H:i:s', 400 ), gmdate( 'Y-m-d H:i:s', 320 ), gmdate( 'Y-m-d H:i:s', 180 ), gmdate( 'Y-m-d H:i:s', 100 ), gmdate( 'Y-m-d H:i:s', 0 ) );
$GLOBALS['wpdb']->hooks = array();
$env = snt_ml_cadence_flags( 700 );
ok( is_array( $env ) && true === $env['ok'], 'envelope ok' );
ok( 1 === count( $env['flags'] ) && 'publish' === $env['flags'][0]['kind'], 'the late publish gap flags' );
ok( 8.187 === round( $env['flags'][0]['z'], 4 ), 'the flag carries the kernel z verbatim' );
ok( false === $env['cron_skipped'], 'cron section ran (empty, not skipped)' );

// On time → no flag (z below threshold).
$env = snt_ml_cadence_flags( 500 );
ok( array() === $env['flags'], 'a normal gap never flags — quiet is an ANSWER' );

// EARLY/burst never flags: z is one-sided by design.
$GLOBALS['__pub_dates'] = array( gmdate( 'Y-m-d H:i:s', 401 ), gmdate( 'Y-m-d H:i:s', 400 ), gmdate( 'Y-m-d H:i:s', 320 ), gmdate( 'Y-m-d H:i:s', 180 ), gmdate( 'Y-m-d H:i:s', 100 ), gmdate( 'Y-m-d H:i:s', 0 ) );
$env = snt_ml_cadence_flags( 402 );
ok( array() === $env['flags'], 'a publish burst is not an ops deviation (one-sided z)' );

// Thin history → unknown, never flagged.
$GLOBALS['__pub_dates'] = array( gmdate( 'Y-m-d H:i:s', 100 ), gmdate( 'Y-m-d H:i:s', 0 ) );
$env = snt_ml_cadence_flags( 99999 );
ok( array() === $env['flags'], 'two publishes is not a cadence — unknown never flags' );

echo "\nGroup: cron cadence — the real poisoning shapes (v10.32.0)\n";
$GLOBALS['__pub_dates'] = array();

// Deterministic jitter: real cron gaps are never identical, and a zero-MAD
// window is the module's watched-never-flagged posture, not the case under
// test here.
$JIT = array( -120, 300, -60, 180, 0 );
/**
 * Build a firing series: $count timestamps, $base_gap apart, cycling $jitter.
 */
function mc_series( $first, $count, $base_gap, $jitter = array( 0 ) ) {
	$ts = array( (float) $first );
	for ( $i = 1; $i < $count; $i++ ) {
		$ts[] = $ts[ $i - 1 ] + $base_gap + $jitter[ ( $i - 1 ) % count( $jitter ) ];
	}
	return $ts;
}
$T0 = 1700000000;

// ── (A) THE LIVE BUG: a release-marathon burst poisons the window ─────
// wp_version_check fired ~hourly through the weekend (activity-coupled), so
// the trailing 50 firings span barely two days. The next ordinary quiet day
// z-scores off the chart against that burst — while the hook's REGISTERED
// schedule is twicedaily. The learner must not undercut ground truth.
$burst = mc_series( $T0, 50, HOUR_IN_SECONDS, $JIT );
$GLOBALS['wpdb']->hooks       = array( 'wp_version_check' => $burst );
$GLOBALS['__cron_intervals']  = array( 'wp_version_check' => 12 * HOUR_IN_SECONDS );
$now_a = end( $burst ) + DAY_IN_SECONDS;
// Non-vacuity: the raw statistic on this exact series DOES scream. What
// suppresses the flag is the new rule, not thin or absent data.
$raw_a = snt_ml_cadence_deviation_robust( $burst, $now_a );
ok( is_array( $raw_a ) && $raw_a['z'] >= 3.0, '(A) the raw robust z on the burst window is still >= 3 — the fixture really is a would-be flag' );
ok( $raw_a['span'] < 7 * DAY_IN_SECONDS, '(A) …because 50 firings spanned barely two days: a burst, not a baseline' );
$env = snt_ml_cadence_flags( $now_a );
ok( array() === $env['flags'], '(A) a burst-poisoned window never flags — the window must span real wall-clock time to be trusted' );
ok( 1 === $env['watched_hooks'], '(A) the hook is still WATCHED — untrusted window is an honest unknown, not an exclusion' );

// ── (B) SCHEDULE FLOOR: flagged late while not yet due ────────────────
// Same absurdity observed live: z 13.89 at a 6.4h gap on a twicedaily hook.
// Here the window IS trusted (it spans nine days), so only the floor can
// suppress it — and the identical series with NO registered schedule must
// still flag, which is what makes this test about the floor.
$sparse = mc_series( $T0, 5, 2 * DAY_IN_SECONDS );
$dense  = mc_series( end( $sparse ) + HOUR_IN_SECONDS, 45, HOUR_IN_SECONDS, $JIT );
$mixed  = array_merge( $sparse, $dense );
$now_b  = end( $mixed ) + (int) ( 6.4 * HOUR_IN_SECONDS );
$raw_b  = snt_ml_cadence_deviation_robust( $mixed, $now_b );
ok( is_array( $raw_b ) && $raw_b['span'] >= 7 * DAY_IN_SECONDS && $raw_b['z'] >= 3.0, '(B) the window spans over a week AND z-scores over three — trusted, and a would-be flag' );
$GLOBALS['wpdb']->hooks      = array( 'wp_version_check' => $mixed );
$GLOBALS['__cron_intervals'] = array( 'wp_version_check' => 12 * HOUR_IN_SECONDS );
$env = snt_ml_cadence_flags( $now_b );
ok( array() === $env['flags'], '(B) never flag below 1.5x the REGISTERED interval — 6.4h into a twicedaily schedule is not late' );
// Negative control: identical data and an identical 6.4h gap, but registered
// HOURLY — now the gap clears 1.5x and the flag lands. Only the floor's height
// changed, so the floor is demonstrably what suppressed the case above.
$GLOBALS['__cron_intervals'] = array( 'wp_version_check' => HOUR_IN_SECONDS );
$env = snt_ml_cadence_flags( $now_b );
ok( 1 === count( $env['flags'] ) && 'wp_version_check' === $env['flags'][0]['subject'], '(B) the identical series flags when the registered interval is hourly instead — the floor\'s height is what suppressed it' );

// ── (C) A GENUINE STALL MUST STILL FLAG ───────────────────────────────
$daily = mc_series( $T0, 50, DAY_IN_SECONDS, array( -600, 900, -300, 1200, 0 ) );
$now_c = end( $daily ) + (int) ( 3.5 * DAY_IN_SECONDS );
$GLOBALS['wpdb']->hooks      = array( 'sn_verify_auto_purge' => $daily );
$GLOBALS['__cron_intervals'] = array( 'sn_verify_auto_purge' => DAY_IN_SECONDS );
$env = snt_ml_cadence_flags( $now_c );
ok( 1 === count( $env['flags'] ) && 'sn_verify_auto_purge' === $env['flags'][0]['subject'], '(C) a daily hook silent for three and a half days STILL flags — the fix narrows false positives, not detection' );
ok( $env['flags'][0]['z'] >= 3.0 && $env['flags'][0]['ewma'] > 0.0, '(C) the flag carries the robust z and the median as the expected gap' );

// ── (D) UNSCHEDULED / ON-DEMAND: no ground truth, so span is all we have ─
// snt_ml_rebuild_async is a single event fired per publish — during the
// marathon it ran every half hour, which is not a cadence.
$ondemand = mc_series( $T0, 50, 30 * MINUTE_IN_SECONDS, $JIT );
$GLOBALS['wpdb']->hooks      = array( 'snt_ml_rebuild_async' => $ondemand );
$GLOBALS['__cron_intervals'] = array(); // Single event: no registered schedule.
$env = snt_ml_cadence_flags( end( $ondemand ) + 12 * HOUR_IN_SECONDS );
ok( array() === $env['flags'], '(D) an on-demand hook with no registered schedule and a sub-week window never flags' );

// (D2) …and neither does one whose window DOES span a week. Review caught
// this: sn_analytics_rollup and snt_ml_rebuild_async are wp_schedule_single_
// event hooks, so snt_cron_interval_seconds() returns 0 for both and neither
// the floor nor the agreement clause can engage. Their firings cluster around
// admin visits — five per weekday for a fortnight spans well past seven days
// while the median gap stays fifteen minutes, so span alone would re-admit the
// exact false positive being fixed. inc/cron-dashboard.php:491 already settled
// the doctrine: an on-demand hook's cadence tracks admin visits, not cron
// health. Without a registered schedule there is no expectation to violate.
$clustered = array();
for ( $day = 0; $day < 10; $day++ ) {
	$base = $T0 + $day * DAY_IN_SECONDS;
	foreach ( array( 0, 900, 1800, 2700, 3600 ) as $k => $off ) {
		$clustered[] = (float) ( $base + $off + $JIT[ $k ] );
	}
}
$raw_d = snt_ml_cadence_deviation_robust( $clustered, end( $clustered ) + DAY_IN_SECONDS );
ok( is_array( $raw_d ) && $raw_d['span'] >= 7 * DAY_IN_SECONDS && $raw_d['z'] >= 3.0, '(D2) the clustered window spans ten days AND z-scores over three — span alone would trust it' );
$GLOBALS['wpdb']->hooks      = array( 'sn_analytics_rollup' => $clustered );
$GLOBALS['__cron_intervals'] = array();
$env = snt_ml_cadence_flags( end( $clustered ) + DAY_IN_SECONDS );
ok( array() === $env['flags'], '(D2) a hook with NO registered schedule never flags, however long its window — no schedule, no expectation' );
ok( 1 === $env['watched_hooks'], '(D2) still watched: unquantifiable, not excluded' );

// (D3) The same series WITH a registered recurrence still flags — proving
// (D2) turns on the missing schedule and not on the fixture's shape.
$GLOBALS['__cron_intervals'] = array( 'sn_analytics_rollup' => HOUR_IN_SECONDS );
$env = snt_ml_cadence_flags( end( $clustered ) + DAY_IN_SECONDS );
ok( 1 === count( $env['flags'] ) && 'sn_analytics_rollup' === $env['flags'][0]['subject'], '(D3) give that identical series an hourly registration and it flags — the missing schedule is what suppressed it' );

// ── (E) FREQUENT SCHEDULED HOOKS KEEP THEIR COVERAGE ──────────────────
// An hourly hook can never span a week in 50 firings. Refusing to flag it
// forever would be a real detection loss — so a window whose learned rhythm
// AGREES with the registered interval is trusted regardless of span.
$hourly = mc_series( $T0, 50, HOUR_IN_SECONDS, $JIT );
$GLOBALS['wpdb']->hooks      = array( 'snt_hourly_worker' => $hourly );
$GLOBALS['__cron_intervals'] = array( 'snt_hourly_worker' => HOUR_IN_SECONDS );
$env = snt_ml_cadence_flags( end( $hourly ) + 6 * HOUR_IN_SECONDS );
ok( 1 === count( $env['flags'] ) && 'snt_hourly_worker' === $env['flags'][0]['subject'], '(E) an hourly hook six hours silent flags: the learned median agrees with the registered interval, so the short span is trusted' );

// ── (G) exact boundaries — pinned so a future refactor cannot flip them ─
// The floor is `gap < interval * 1.5 → suppress`, so landing EXACTLY on 1.5x
// is due, not early. Build a trusted daily window and step the gap across it.
$GLOBALS['wpdb']->hooks      = array( 'snt_boundary' => $daily );
$GLOBALS['__cron_intervals'] = array( 'snt_boundary' => DAY_IN_SECONDS );
$floor_at = end( $daily ) + (int) ( 1.5 * DAY_IN_SECONDS );
ok( array() === snt_ml_cadence_flags( $floor_at - 1 )['flags'], '(G) one second under 1.5x the registered interval: suppressed' );
ok( 1 === count( snt_ml_cadence_flags( $floor_at )['flags'] ), '(G) exactly 1.5x the registered interval: due, so a three-sigma gap flags' );

// The agreement clause is `median >= interval * 0.5 → trusted`; the burst
// window's median is exactly one hour, so a two-hour registration sits
// precisely on the boundary and must be TRUSTED (inclusive).
$GLOBALS['wpdb']->hooks      = array( 'snt_agree_edge' => $burst );
$GLOBALS['__cron_intervals'] = array( 'snt_agree_edge' => 2 * HOUR_IN_SECONDS );
ok( 1 === count( snt_ml_cadence_flags( $now_a )['flags'] ), '(G) learned median exactly 0.5x the registered interval: trusted (inclusive), so it flags' );
$GLOBALS['__cron_intervals'] = array( 'snt_agree_edge' => 2 * HOUR_IN_SECONDS + 1 );
ok( array() === snt_ml_cadence_flags( $now_a )['flags'], '(G) one second past that boundary: the learned rhythm no longer agrees, window untrusted' );

// ── (F) the preserved honest-unknown postures, together ───────────────
$GLOBALS['wpdb']->hooks = array(
	'snt_metronome'  => mc_series( $T0, 50, DAY_IN_SECONDS ),           // zero MAD → z null
	'snt_thin_hook'  => array( $T0, $T0 + 100 ),                         // < 5 firings → not watched
	'sn_stalled'     => $daily,                                          // the (C) stall
);
$GLOBALS['__cron_intervals'] = array( 'sn_stalled' => DAY_IN_SECONDS, 'snt_metronome' => DAY_IN_SECONDS );
$env = snt_ml_cadence_flags( $now_c );
ok( 1 === count( $env['flags'] ) && 'sn_stalled' === $env['flags'][0]['subject'], '(F) exactly the stalled hook flags among a metronome and a thin history' );
ok( 2 === $env['watched_hooks'], '(F) watched counts hooks with enough history (thin one excluded); the metronome is watched but unquantifiable' );
$GLOBALS['__cron_intervals'] = array();

echo "\nGroup: failed cron read = partial answer, spoken\n";
$GLOBALS['__pub_dates'] = array( gmdate( 'Y-m-d H:i:s', 400 ), gmdate( 'Y-m-d H:i:s', 320 ), gmdate( 'Y-m-d H:i:s', 180 ), gmdate( 'Y-m-d H:i:s', 100 ), gmdate( 'Y-m-d H:i:s', 0 ) );
$GLOBALS['wpdb']->fail  = true;
$env = snt_ml_cadence_flags( 700 );
ok( is_array( $env ) && 1 === count( $env['flags'] ) && 'publish' === $env['flags'][0]['kind'], 'publish verdict still returns when the cron read fails' );
ok( true === $env['cron_skipped'], 'the envelope SAYS the cron section was skipped — a partial answer never poses as a full one' );
$GLOBALS['wpdb']->fail = false;

echo "\nGroup: the health adapter (reads the REAL clock — pins structure, not the z)\n";
$GLOBALS['wpdb']->hooks = array(); // Publish flag only: the epoch-era fixture dates are ancient vs time(), so the publish gap flags.
$check = sn_health_check_ml_cadence();
ok( 'Cadence deviations' === $check['label'] && 1 === $check['count'], 'adapter packs one finding per flag' );
ok( false !== strpos( (string) $check['findings'][0]['subject_label'], 'Publishing' ), 'the publish flag names its subject' );
ok( false !== strpos( (string) $check['findings'][0]['note'], 'z ' ) && false !== strpos( (string) $check['findings'][0]['note'], 'expected gap' ), 'the note carries the z and the humanized gaps' );

echo "\nGroup: the pipeline wrapper\n";
require __DIR__ . '/../inc/ml-pipelines.php';
$via = snt_ml_run( 'cadence-flags', array() );
ok( is_array( $via ) && true === $via['ok'] && 1 === count( $via['flags'] ), 'registry route returns the live envelope' );

echo "\nGroup: views rhythm — the pure statistic (traffic rhythm flags)\n";
// R4 row: "the deterministic cadence watch extended from cron to views" —
// the same robust median/MAD posture as the cron path, one-sided QUIET only.
ok( null === snt_ml_views_rhythm( array( 100, 100, 100 ), 40 ), 'fewer than four complete weeks is thin history — unknown, never flagged' );
$metro = snt_ml_views_rhythm( array( 100, 100, 100, 100, 100 ), 40 );
ok( is_array( $metro ) && null === $metro['z'], 'a zero-spread metronome is watched, never flagged — spread of zero makes deviation unquantifiable' );
$quiet = snt_ml_views_rhythm( array( 100, 110, 90, 105, 95, 100, 108, 92 ), 40 );
ok( is_array( $quiet ) && null !== $quiet['z'] && $quiet['z'] >= 3.0, 'a genuinely quiet week z-scores over the flag threshold (got z ' . ( $quiet['z'] ?? 'null' ) . ')' );
ok( 100 === $quiet['median'] && 40 === $quiet['current'], 'the verdict carries the typical week and the current one, as counts' );
$busy = snt_ml_views_rhythm( array( 100, 110, 90, 105, 95, 100, 108, 92 ), 400 );
ok( is_array( $busy ) && 0.0 === $busy['z'], 'one-sided by design: a BUSY week is not a deviation — z clamps to 0' );

echo "\nGroup: views rhythm — the assembler (rollups already kept, honesty rules)\n";
// The daily-range stub: the assembler reads the SAME accessor the public
// stats page reads, class human, and sums per day itself.
$GLOBALS['__vr_rows'] = false; // non-array = FAILED read
function sn_analytics_daily_range( $from, $to, $class = 'human' ) {
	$GLOBALS['__vr_window'] = array( $from, $to, $class );
	return $GLOBALS['__vr_rows'];
}
$now = strtotime( '2026-08-12 15:00:00 UTC' );
$GLOBALS['__pub_dates'] = array(); // no publish flag noise in this group
$GLOBALS['wpdb']->hooks = array();
$env = snt_ml_cadence_flags( $now );
ok( true === $env['views_skipped'], 'a FAILED rollup read skips the views section and SAYS SO in the envelope' );
ok( array() === $env['flags'], 'and fabricates no views flag' );
// 13 weeks of steady reading, then a silent current week. Rows only every
// other day — absent days inside measured history are real zeros.
$rows = array();
$day0 = strtotime( '2026-08-12 00:00:00 UTC' );
for ( $d = 8; $d <= 7 * 13; $d += 2 ) {
	$rows[] = array( 'day' => gmdate( 'Y-m-d', $day0 - $d * DAY_IN_SECONDS ), 'path' => '/notes/x/', 'views' => 20 + ( $d % 5 ) * 7 );
}
$GLOBALS['__vr_rows'] = $rows;
$env = snt_ml_cadence_flags( $now );
ok( false === $env['views_skipped'], 'a successful read is not skipped' );
ok( 1 === count( $env['flags'] ) && 'views' === $env['flags'][0]['kind'], 'the silent current week raises exactly one views flag' );
ok( 'human' === ( $GLOBALS['__vr_window'][2] ?? '' ), 'the read is the HUMAN class — a bot wave can never enter the rhythm' );
$vf = $env['flags'][0];
ok( isset( $vf['expected_views'], $vf['current_views'] ) && $vf['current_views'] < $vf['expected_views'], 'the flag speaks in COUNTS (expected_views/current_views), never in gap seconds' );
ok( $vf['z'] >= 3.0, 'and the z clears the shared threshold (got ' . $vf['z'] . ')' );
// Sensor birth: only three weeks of measured history → thin, never flagged.
// Without the clamp the five pre-birth weeks would read as zeros, the median
// would collapse, and the quiet current week could never flag again — or
// worse, a NORMAL week would flag against a zero baseline.
$rows = array();
for ( $d = 8; $d <= 7 * 3; $d += 2 ) {
	$rows[] = array( 'day' => gmdate( 'Y-m-d', $day0 - $d * DAY_IN_SECONDS ), 'path' => '/notes/x/', 'views' => 20 + ( $d % 5 ) * 7 );
}
$GLOBALS['__vr_rows'] = $rows;
$env = snt_ml_cadence_flags( $now );
ok( array() === $env['flags'] && false === $env['views_skipped'], 'weeks before the sensor existed are EXCLUDED, so a young sensor is thin history (watched), not a wall of fake zeros' );
// The clamp's load-bearing direction: a sensor SIX weeks old with a quiet
// current week MUST flag. Without the clamp, six pre-birth zero-weeks join
// the history, the median collapses toward zero and the MAD balloons — and
// the real quiet week is silently missed. The clamp is what keeps a young
// sensor's real deviations visible, not just its fake ones suppressed.
$rows = array();
for ( $d = 8; $d <= 7 * 7; $d += 2 ) {
	$rows[] = array( 'day' => gmdate( 'Y-m-d', $day0 - $d * DAY_IN_SECONDS ), 'path' => '/notes/x/', 'views' => 20 + ( $d % 5 ) * 7 );
}
$GLOBALS['__vr_rows'] = $rows;
$env = snt_ml_cadence_flags( $now );
ok( 1 === count( $env['flags'] ) && 'views' === $env['flags'][0]['kind'], 'a six-week-old sensor with a silent current week still flags — the birth clamp protects real deviations, not only against fake ones' );

echo "\nGroup: the health adapter speaks views in counts\n";
// The adapter reads the REAL clock, so this fixture anchors to it — a
// hardcoded date here would rot into a different verdict next month.
$day0r = strtotime( gmdate( 'Y-m-d 00:00:00', time() ) . ' UTC' );
$rows  = array();
for ( $d = 8; $d <= 7 * 13; $d += 2 ) {
	$rows[] = array( 'day' => gmdate( 'Y-m-d', $day0r - $d * DAY_IN_SECONDS ), 'path' => '/notes/x/', 'views' => 20 + ( $d % 5 ) * 7 );
}
$GLOBALS['__vr_rows'] = $rows;
$check = sn_health_check_ml_cadence();
ok( 1 === $check['count'] && false !== strpos( (string) $check['findings'][0]['note'], 'views' ), 'the views finding renders its note in VIEWS' );
ok( false === strpos( (string) $check['findings'][0]['note'], 'expected gap' ), 'and never as a humanized duration — 105 views is not 105 seconds' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
