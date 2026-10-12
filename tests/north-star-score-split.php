<?php
/**
 * The north star's calibration split by the beacon bot-signal score (theme
 * audit idea 5, 2026-10-12): three cohorts side by side (presumed human,
 * likely automated, not scored), each the same sweep, never subtracted. A
 * visitor-day with no signal rows is NOT presumed human: it was not scored.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
function __( $s ) { return $s; }
function sn_setting( $k, $d = null ) { return $d; }
function add_action() {}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
require __DIR__ . '/../inc/north-star.php';
// The score and its constants, without the reader's SQL dependencies.
const SNT_BOT_SIGNAL_BITS      = array( 1 => 'webdriver', 2 => 'no_input', 4 => 'ua_mismatch', 8 => 'headless', 16 => 'tz_mismatch' );
const SNT_BOT_SIGNAL_WEIGHTS   = array( 'webdriver' => 3, 'no_input' => 2, 'ua_mismatch' => 2, 'headless' => 1, 'tz_mismatch' => 1 );
const SNT_BOT_SIGNAL_THRESHOLD = 3;
const SNT_BOT_SIGNAL_LIMIT     = 5000;
function sn_bot_signals_score( array $row ) {
	$s = 0;
	foreach ( SNT_BOT_SIGNAL_WEIGHTS as $name => $w ) {
		$s += (int) ( (float) ( $row[ $name ] ?? 0 ) > 0 ) * $w;
	}
	return $s;
}

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "North star: the calibration split by the bot-signal score\n\n";

$cfg = snt_nsm_config();
$t   = time() - 3600;
$v   = static fn( $vid, $dev, $scroll ) => array(
	array( 'vid' => $vid, 'ev' => 'pv', 'path' => '/notes/x/', 'ts' => $t, 'device' => $dev ),
	array( 'vid' => $vid, 'ev' => 'sc', 'path' => '/notes/x/', 'ts' => $t, 'device' => $dev, 'scroll' => $scroll ),
);
$visits = array(
	$v( 'h1', 'desktop', 80 ), // scored, under threshold: presumed human
	$v( 'h2', 'desktop', 10 ),
	$v( 'b1', 'desktop', 10 ), // webdriver alone scores 3: likely automated
	$v( 'b2', 'mobile', 90 ),  // no_input on one row, ua_mismatch on another: 2 + 2 merged
	$v( 'n1', 'desktop', 60 ), // no signal rows: not scored
);
$rows = array(
	array( 'day' => '2026-10-01', 'vid' => 'h1', 'webdriver' => 0, 'no_input' => 2, 'ua_mismatch' => 0, 'headless' => 0, 'tz_mismatch' => 0 ),
	array( 'day' => '2026-10-01', 'vid' => 'h2', 'webdriver' => 0, 'no_input' => 0, 'ua_mismatch' => 0, 'headless' => 0, 'tz_mismatch' => 0 ),
	array( 'day' => '2026-10-01', 'vid' => 'b1', 'webdriver' => '1', 'no_input' => 0, 'ua_mismatch' => 0, 'headless' => 0, 'tz_mismatch' => 0 ),
	array( 'day' => '2026-10-01', 'vid' => 'b2', 'webdriver' => 0, 'no_input' => 2, 'ua_mismatch' => 0, 'headless' => 0, 'tz_mismatch' => 0 ),
	array( 'day' => '2026-10-02', 'vid' => 'b2', 'webdriver' => 0, 'no_input' => 0, 'ua_mismatch' => 4, 'headless' => 0, 'tz_mismatch' => 0 ),
);

$verdicts = snt_nsm_vid_verdicts( $rows );
ok( false === $verdicts['h1'] && false === $verdicts['h2'], 'scored under the threshold: presumed human' );
ok( true === $verdicts['b1'], 'webdriver alone reaches the threshold (string numerics from AE included)' );
ok( true === $verdicts['b2'], 'bits merge across the rows a visitor-day appears in: 2 + 2 is likely automated' );
ok( ! isset( $verdicts['n1'] ), 'a visitor-day with no signal rows has no verdict' );

$split = snt_nsm_sweep_by_score( $visits, $cfg, $verdicts );
ok( array( 'presumed_human', 'likely_automated', 'not_scored' ) === array_keys( $split ), 'three cohorts, never two' );
ok( 1 === $split['not_scored']['desktop']['viewed'] && 2 === $split['presumed_human']['desktop']['viewed'], 'NOT SCORED IS NOT HUMAN: n1 lands in not_scored, never in presumed_human' );
ok( 1 === $split['presumed_human']['desktop']['scroll'][50] && 1 === $split['likely_automated']['mobile']['scroll'][50], 'each cohort keeps its own scroll counts per device' );

$whole = snt_nsm_sweep( $visits, $cfg );
$sum   = true;
foreach ( array( 'mobile', 'desktop', 'unknown' ) as $d ) {
	foreach ( array( 'viewed', 'live' ) as $k ) {
		$sum = $sum && $whole[ $d ][ $k ] === array_sum( array_map( static fn( $c ) => $c[ $d ][ $k ], $split ) );
	}
	foreach ( SNT_NSM_SWEEP_SCROLL as $s ) {
		$sum = $sum && $whole[ $d ]['scroll'][ $s ] === array_sum( array_map( static fn( $c ) => $c[ $d ]['scroll'][ $s ], $split ) );
	}
}
ok( $sum, 'THE SUM PIN: the three cohorts add up to the whole calibration on every device and floor (nothing dropped, nothing subtracted)' );

$no_reader = snt_nsm_calibration_by_score( $visits, $cfg );
ok( null === $no_reader['cohorts'] && 'The bot-signal reader is not loaded.' === $no_reader['reason'] && false === $no_reader['subtracted'], 'no reader loaded: cohorts null with a reason, never zeros' );

// Declared inside a block so PHP does not hoist them above the case before.
// The real stitcher runs; the Analytics Engine answer is the fixture.
if ( true ) {
	function sn_bot_signals_by_day_sql( $days, $source = '', $range = '' ) { return 'SQL'; }
	function sn_analytics_query( $sql ) { return 'SQL' === $sql ? $GLOBALS['__sets'] : null; }
}
$GLOBALS['__sets'] = $rows;
$read = snt_nsm_calibration_by_score( $visits, $cfg );
ok( 2 === $read['signal_days'] && '2026-10-01' === $read['first_day'] && 'inferred' === $read['basis'], 'a live read names the signal days it covers and its basis' );
ok( $read['cohorts'] === $split, 'the live read is the pure split over the fetched rows' );

$GLOBALS['__sets'] = null;
ok( null === snt_nsm_calibration_by_score( $visits, $cfg )['cohorts'], 'a failed signal read: cohorts null, never everyone not scored' );
$GLOBALS['__sets'] = array_fill( 0, SNT_BOT_SIGNAL_LIMIT, $rows[0] );
ok( null === snt_nsm_calibration_by_score( $visits, $cfg )['cohorts'], 'a read at the row cap: cohorts null, since a partial map would misfile scored visitor-days' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
