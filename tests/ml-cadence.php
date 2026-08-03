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
	public $bound      = array(); // declared: PHP 8.2+ deprecates dynamic properties
	public $hooks      = array(); // hook => [fired_at_ts...]
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

// v10.32.0: the schedule-floor stub. Models snt_cron_interval_seconds()
// (inc/cron-dashboard.php) without pulling in the real WP schedule
// registry — a hook absent from the map (or explicitly 0) means "no
// registered recurring schedule", the on-demand branch.
$GLOBALS['__cron_intervals'] = array();
function snt_cron_interval_seconds( $hook ) {
	return (int) ( $GLOBALS['__cron_intervals'][ $hook ] ?? 0 );
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

echo "\nGroup: cron cadence\n";
$GLOBALS['__pub_dates'] = array();
$GLOBALS['wpdb']->hooks = array(
	'snt_steady_hourly' => array( 0, 3600, 7200, 10800, 14400, 18000 ),      // metronome, current gap huge but std 0 → z null → SKIP (unknown)
	'snt_drifty_daily'  => array( 0, 100, 180, 320, 400 ),                   // the pinned series → z 8.187 at now 700
	'snt_thin_hook'     => array( 0, 100 ),                                  // too little history → skipped
);
// These two are registered recurring hooks in this fixture (their gap at
// now=700 comfortably clears 1.5x their own interval, so the schedule
// floor added in v10.32.0 must not touch this pre-existing pin).
$GLOBALS['__cron_intervals'] = array( 'snt_drifty_daily' => 100, 'snt_steady_hourly' => 3600 );
$env = snt_ml_cadence_flags( 700 );
ok( 1 === count( $env['flags'] ) && 'cron' === $env['flags'][0]['kind'] && 'snt_drifty_daily' === $env['flags'][0]['subject'], 'exactly the drifty hook flags' );
ok( 2 === $env['watched_hooks'], 'watched counts hooks with enough history (thin one excluded); the metronome is watched but unquantifiable' );

echo "\nGroup: schedule floor + burst resistance (v10.32.0)\n";

// (a) THE POISONING SHAPE, live-diagnosed 2026-08-02/03: 50 firings at
// 9-60min gaps spanning ~26h (a release-marathon weekend), then a 6.4h
// quiet gap. Registered interval 43200s (twicedaily) → the floor
// (1.5x43200=18h) comfortably clears the 6.4h gap → MUST NOT flag, no
// matter how poisoned the learned EWMA is. Pre-fix (no floor threaded
// through) this fixture flags at z~19 — that is the RED.
$pattern      = array( 540, 900, 1200, 1800, 2400, 3000, 3600 ); // 9,15,20,30,40,50,60 min
$poison_events = array( 0 );
$t = 0;
for ( $i = 0; $i < 49; $i++ ) {
	$t              += $pattern[ $i % count( $pattern ) ];
	$poison_events[] = $t;
}
$poison_last = end( $poison_events );
$GLOBALS['wpdb']->hooks      = array( 'wp_version_check' => $poison_events );
$GLOBALS['__cron_intervals'] = array( 'wp_version_check' => 43200 );
$env = snt_ml_cadence_flags( $poison_last + 6.4 * 3600 );
ok( array() === $env['flags'], '(a) a burst-poisoned learner never contradicts its OWN registered schedule — not yet due at 6.4h against a 12h cadence' );
ok( 1 === $env['watched_hooks'], '(a) still watched — the floor gates the flag, not the visibility' );

// (b) REGRESSION GUARD: a genuinely stalled DAILY hook (interval 86400)
// silent 3+ days must still flag — the floor must not become a blanket
// suppressor of the check's actual purpose.
$stall_intervals = array( 86000, 86700, 86200, 86500, 85900, 86300, 86600 );
$stall_events    = array( 0 );
$t = 0;
foreach ( $stall_intervals as $iv ) {
	$t              += $iv;
	$stall_events[] = $t;
}
$stall_last = end( $stall_events );
$GLOBALS['wpdb']->hooks      = array( 'snt_daily_stall' => $stall_events );
$GLOBALS['__cron_intervals'] = array( 'snt_daily_stall' => 86400 );
$env = snt_ml_cadence_flags( $stall_last + 3 * 86400 );
ok( 1 === count( $env['flags'] ) && 'snt_daily_stall' === $env['flags'][0]['subject'], '(b) a real 3-day stall on a daily hook still flags — the floor does not blanket-suppress' );

// (c) on-demand hook (no registered interval), 36h-span burst window: a
// handful of tightly-packed firings is not a rhythm. Pre-fix (no
// span-gate) this flags at z~20 against the same 6.4h gap shape — that
// is the RED. Post-fix: thin-history watched, never flagged.
$burst_events = array( 0 );
$t = 0; $i = 0;
while ( $t < 129600 ) { // fill to a ~36h span
	$t += $pattern[ $i % count( $pattern ) ];
	$burst_events[] = $t;
	$i++;
}
$burst_last = end( $burst_events );
$GLOBALS['wpdb']->hooks      = array( 'snt_ml_rebuild_async' => $burst_events );
$GLOBALS['__cron_intervals'] = array(); // on-demand: no registered schedule at all
$env = snt_ml_cadence_flags( $burst_last + 6.4 * 3600 );
ok( array() === $env['flags'], '(c) an on-demand hook with only a 36h-span burst window is thin history — watched, never flagged' );
ok( 1 === $env['watched_hooks'], '(c) still watched — thin-span gates the flag, not the visibility' );

// (d) REGRESSION GUARD: an on-demand hook with a genuinely healthy
// ~3-week history (span well over the 7-day min-span floor) that then
// truly stalls must still flag — the span-gate must not become a
// blanket suppressor of on-demand hooks either.
$healthy_pattern = array( 3 * 3600, 5 * 3600, 4 * 3600, 6 * 3600, 3.5 * 3600, 4.5 * 3600 );
$healthy_events  = array( 0 );
$t = 0; $i = 0;
while ( $t < 21 * 86400 ) { // ~3 weeks of history
	$t += $healthy_pattern[ $i % count( $healthy_pattern ) ];
	$healthy_events[] = $t;
	$i++;
}
$healthy_last = end( $healthy_events );
$GLOBALS['wpdb']->hooks      = array( 'snt_ml_rebuild_async' => $healthy_events );
$GLOBALS['__cron_intervals'] = array();
$env = snt_ml_cadence_flags( $healthy_last + 10 * 86400 );
ok( 1 === count( $env['flags'] ) && 'snt_ml_rebuild_async' === $env['flags'][0]['subject'], '(d) an on-demand hook with a healthy multi-week history that truly stalls still flags' );

// Reset shared fixture state for the groups that follow.
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

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
