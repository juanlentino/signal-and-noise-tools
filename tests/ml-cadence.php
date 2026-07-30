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
$env = snt_ml_cadence_flags( 700 );
ok( 1 === count( $env['flags'] ) && 'cron' === $env['flags'][0]['kind'] && 'snt_drifty_daily' === $env['flags'][0]['subject'], 'exactly the drifty hook flags' );
ok( 2 === $env['watched_hooks'], 'watched counts hooks with enough history (thin one excluded); the metronome is watched but unquantifiable' );

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
