<?php
/**
 * Beacon bot signals (observe-only): the AE SQL, the readout over a captured
 * AE row shape (JOIN), the nightly store, the watch, the panel.
 * Run: php tests/analytics-bot-signals.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function add_action() {}
const SN_ANALYTICS_ROLLUP_DAILY_HOOK = 'sn_analytics_rollup_daily';
function __( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return esc_html( $s ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
$GLOBALS['__opt'] = array();
$GLOBALS['__ret'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
function sn_analytics_query( $sql ) { return array_shift( $GLOBALS['__ret'] ); }

require __DIR__ . '/../inc/analytics-bot-signals.php';
require __DIR__ . '/../inc/abilities-bot-signals.php';
require __DIR__ . '/../inc/analytics-render-signals.php';
require __DIR__ . '/../inc/watches.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// --- SQL: the shape that ran live against AE on 2026-09-29 -------------------
$sql = sn_bot_signals_sql();
ok( false !== strpos( $sql, 'bitAnd(toUInt32(double8), 32) > 0' ), 'only rows that carried signals are read (presence bit)' );
ok( false !== strpos( $sql, 'GROUP BY vid LIMIT 5000' ), 'grouped by visitor-day, explicit limit under the AE row cap' );
ok( false !== strpos( $sql, 'NOT (' . sn_analytics_network_human_sql() . ')' ), 'hosting routes through the one rule\'s network seam' );
ok( false !== strpos( $sql, "blob16 LIKE 'contact%'" ), 'intent includes the contact-* goals' );
foreach ( array( 1 => 'webdriver', 2 => 'no_input', 4 => 'ua_mismatch', 8 => 'headless', 16 => 'tz_mismatch' ) as $b => $n ) {
	ok( false !== strpos( $sql, "max(bitAnd(toUInt32(double8), {$b})) AS {$n}" ), "bit {$b} reads as {$n}" );
}
ok( ! sn_analytics_sql_too_long( $sql ), 'under the AE character cap (' . strlen( $sql ) . ')' );
ok( false !== strpos( sn_bot_signals_days_sql(), 'toStartOfDay(timestamp) AS day' ), 'the days read groups by alias' );

// --- JOIN: the captured AE row shape through the real readout ----------------
$fx = json_decode( file_get_contents( __DIR__ . '/fixtures/ae-bot-signals-rows.json' ), true );
$r  = sn_bot_signals_readout( $fx['rows'], $fx['days'] );
ok( 8 === $r['visitor_days'] && false === $r['truncated'], '8 visitor-days, not truncated' );
ok( 1 === $r['days_present'], 'a day with n "0" does not count as present' );
ok( 1 === $r['cohorts']['relay']['n'] && 0.0 === $r['cohorts']['relay']['rate']['no_input'], 'the relay reader fires nothing' );
ok( 1 === $r['cohorts']['intent']['n'], 'intent cohort counted' );
ok( 1 === $r['cohorts']['stored_bot']['n'], 'stored bot counted' );
ok( 1 === $r['cohorts']['over_cap']['n'] && 1 === $r['cohorts']['over_cap']['likely_automated'], 'views "120" is over the cap; no_input+ua_mismatch+tz scores 5' );
ok( 2 === $r['cohorts']['hosting']['n'] && 50.0 === $r['cohorts']['hosting']['rate']['webdriver'], 'hosting: 1 of 2 fired webdriver (bit value 1, not a boolean)' );
ok( 1 === $r['cohorts']['hosting']['likely_automated'], 'webdriver+no_input+headless = 6 crosses the threshold' );
ok( 4 === $r['human']['visitor_days'], 'human excludes over-cap and hosting' );
ok( 1 === $r['human']['likely_automated'], 'no_input + tz_mismatch scores 3, the threshold: likely automated, beside human' );
ok( false === $r['subtracted'], 'observe-only: says so' );
$r2 = sn_bot_signals_readout( array(), array() );
ok( null === $r2['cohorts']['relay']['rate']['webdriver'], 'an empty cohort rates null, never 0' );

// --- store: only a real read overwrites ---------------------------------------
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'] );
ok( true === sn_bot_signals_refresh() && 8 === sn_bot_signals_stored()['visitor_days'], 'a real read is stored' );
$GLOBALS['__ret'] = array( null );
ok( false === sn_bot_signals_refresh() && 8 === sn_bot_signals_stored()['visitor_days'], 'a failed read keeps the last good one' );

// --- watch: ripens on state ---------------------------------------------------
$w = snt_watch_ripe_bot_signals( array(), 0, null );
ok( false === $w['ripe'] && 'not measured yet' === $w['note'], 'nothing stored is not ripe' );
ok( false === snt_watch_ripe_bot_signals( array(), 0, $r )['ripe'], '1 day and thin cohorts are not ripe' );
$full = $r; $full['days_present'] = 14;
$full['cohorts']['relay']['n'] = 20; $full['cohorts']['intent']['n'] = 3; $full['cohorts']['over_cap']['n'] = 2;
$w = snt_watch_ripe_bot_signals( array(), 0, $full );
ok( true === $w['ripe'], '14 days with relay at 20 ripens; intent at 3 and over-cap at 2 do not hold it (owner, 2026-10-24)' );
ok( false !== strpos( $w['note'], 'over_cap 2' ) && false !== strpos( $w['note'], 'intent 3' ), 'the other cohorts, intent among them, are reported at their count' );
$st = sn_bot_signals_stored();
ok( 90 === SNT_BOT_SIGNAL_READOUT_DAYS && $st['days_present'] === $st['window_days'], 'the readout reads 90 days and labels only the days the signals cover' );
$d13 = $full; $d13['days_present'] = 13;
ok( false === snt_watch_ripe_bot_signals( array(), 0, $d13 )['ripe'], '13 days is not ripe' );
$full['cohorts']['relay']['n'] = 19;
$t = snt_watch_ripe_bot_signals( array(), 0, $full );
ok( false === $t['ripe'] && false !== strpos( $t['note'], 'relay 19' ), 'one thin cohort holds it and is named' );
$ids = array_column( snt_watches(), 'id' );
ok( in_array( 'bot_signals_validation', $ids, true ), 'the watch is registered' );

// --- panel --------------------------------------------------------------------
ob_start(); snt_analytics_render_bot_signals( null ); $o = ob_get_clean();
ok( '' === $o && 'Bot signals (observe-only)' === ( $GLOBALS['sn_an_empty_panels'][0]['title'] ?? '' ), 'nothing stored folds into the empty-panel collector' );
ob_start(); snt_analytics_render_bot_signals( sn_bot_signals_stored() ); $o = ob_get_clean();
ok( false !== strpos( $o, 'Not subtracted' ) && 5 === substr_count( $o, '<tr><td class="column-primary">' ), 'five cohort rows, the observe-only line' );
ok( false !== strpos( $o, '50.0%' ), 'a rate paints as a percent' );
ok( false !== strpos( $o, 'human baseline, not a bot rate' ), 'the caption names the short-page phone false positive' );

// --- Unreleased: likely automated per UTC day, for any analytics range -------
echo "\nGroup: likely automated, per day\n";
$bsql = sn_bot_signals_by_day_sql();
ok( false !== strpos( $bsql, "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day" ) && false !== strpos( $bsql, 'GROUP BY day, vid LIMIT 5000' ), 'the per-day read groups by the day alias and the visitor-day, never a function in GROUP BY' );
ok( false !== strpos( $bsql, 'bitAnd(toUInt32(double8), 32) > 0' ) && false !== strpos( $bsql, 'AS human' ) && false === strpos( $bsql, 'SELECT (' ), 'only signal-bearing rows, the human flag, no subquery' );
ok( ! sn_analytics_sql_too_long( $bsql ), 'under the AE character cap (' . strlen( $bsql ) . ')' );
$dated = array();
foreach ( $fx['rows'] as $i => $row ) {
	$dated[] = $row + array( 'day' => $i < 4 ? '2026-09-28' : '2026-09-29' );
}
$bd = sn_bot_signals_by_day( $dated );
// 2026-11-11: each day also carries strict rule v1's removals; no_input + tz_mismatch (the likely one) removes nothing.
$z = array( 'strict_removed' => 0, 'strict_removed_views' => 0 );
ok( array( '2026-09-28' => array( 'visitor_days' => 2, 'likely_automated' => 0 ) + $z, '2026-09-29' => array( 'visitor_days' => 2, 'likely_automated' => 1 ) + $z ) === $bd, 'per day: human visitor-days under the cap that carried signals, and how many scored at the threshold (the readout\'s human rule)' );
$merged = sn_bot_signals_by_day_merge( array( '2026-09-01' => array( 'visitor_days' => 9, 'likely_automated' => 1 ), '2026-09-28' => array( 'visitor_days' => 99, 'likely_automated' => 9 ), '2026-09-29' => array( 'visitor_days' => 5, 'likely_automated' => 5 ) ), array( '2026-09-28' => array( 'visitor_days' => 2, 'likely_automated' => 0 ) ), '2026-09-15' );
ok( array( '2026-09-01', '2026-09-28' ) === array_keys( $merged ) && 2 === $merged['2026-09-28']['visitor_days'], 'a nightly read REPLACES the days it covered (a day it no longer returns is dropped) and keeps the older ones' );
$la = sn_bot_signals_likely_automated( $bd, '2026-09-24', '2026-09-30' );
ok( array( 'visitor_days' => 1, 'scored_visitor_days' => 4, 'measured_days' => 2, 'unmeasured_days' => 5, 'basis' => 'inferred', 'subtracted' => false ) === $la, 'over a 7-day range: 1 of 4 scored visitor-days, on the 2 days the beacon carried signals; 5 days unmeasured' );
$none = sn_bot_signals_likely_automated( array(), '2026-09-24', '2026-09-30' );
ok( null === $none['visitor_days'] && null === $none['scored_visitor_days'] && 0 === $none['measured_days'] && 7 === $none['unmeasured_days'], 'no day measured: null, never 0, and all 7 days unmeasured' );
$GLOBALS['__opt'] = array();
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $dated );
ok( true === sn_bot_signals_refresh() && 2 === count( (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION ) ), 'the nightly refresh stores the per-day reading beside the readout' );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], null );
ok( true === sn_bot_signals_refresh() && 2 === count( (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION ) ), 'a failed per-day read keeps the stored days and still stores the readout' );

// The store must outgrow the nightly read: 22 nights of the REAL refresh, each
// read answering what the SQL asks for (UTC days N-14..N), must keep every day.
echo "\nGroup: the per-day store grows night over night\n";
$GLOBALS['__opt'] = array();
$night0 = strtotime( '2026-09-01 03:00:00 UTC' );
for ( $n = 0; $n < 22; $n++ ) {
	$clock = $night0 + $n * 86400;
	sn_analytics_clock( $clock );
	$read = array();
	for ( $k = 14; $k >= 0; $k-- ) {
		$read[] = array( 'day' => gmdate( 'Y-m-d', $clock - $k * 86400 ), 'vid' => "v{$n}-{$k}", 'views' => 1, 'human' => 1 );
	}
	$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $read );
	sn_bot_signals_refresh();
}
$store = (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION );
ok( 36 === count( $store ) && '2026-08-18' === array_key_first( $store ) && '2026-09-22' === array_key_last( $store ), 'after 22 nights the store holds 36 days, 2026-08-18 to 2026-09-22 (' . count( $store ) . ' held), so a 30-day range is measured' );
$la30 = sn_bot_signals_likely_automated( $store, '2026-08-24', '2026-09-22' );
ok( 30 === $la30['measured_days'] && 0 === $la30['unmeasured_days'], 'a 30-day range reads 30 measured days, not 15' );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], array() );
sn_bot_signals_refresh();
ok( $store === (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION ), 'an empty but successful read keeps the stored days (it is logged, never a wipe)' );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], array_fill( 0, SNT_BOT_SIGNAL_LIMIT, $read[0] ) );
sn_bot_signals_refresh();
ok( $store === (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION ), 'a capped read is skipped and the stored days are kept' );

// 2026-11-11: the first night after install writes the strict reading for every
// day the 90-day AE window holds, in chunks behind a cursor, so no range reads
// partial for two weeks. A day no read returns keeps its old shape (unmeasured).
echo "\nGroup: the first night backfills the strict reading over the AE window\n";
$csql = sn_bot_signals_by_day_sql( 90, 'sn_pageviews', '', '2026-10-14|2026-10-28' );
ok( false !== strpos( $csql, "WHERE timestamp >= toDateTime('2026-10-14 00:00:00') AND timestamp < toDateTime('2026-10-28 00:00:00') AND bitAnd" ) && ! sn_analytics_sql_too_long( $csql ), 'a chunk reads its own days, first day to exclusive end, under the AE character cap' );
ok( false !== strpos( sn_bot_signals_by_day_sql( 14, 'sn_pageviews', '', '2026-10-14; DROP' ), "INTERVAL '14' DAY" ), 'a malformed span falls back to the trailing window, never interpolated' );
sn_analytics_clock( strtotime( '2026-11-11 03:00:00 UTC' ) );
$old = array( 'visitor_days' => 5, 'likely_automated' => 1 ); // stored before strict rule v1
$GLOBALS['__opt'] = array( SNT_BOT_SIGNAL_BY_DAY_OPTION => array( '2026-08-01' => $old, '2026-10-01' => $old, '2026-10-20' => $old ) );
$hit = static fn( $day ) => array( array( 'day' => $day, 'vid' => 'x', 'views' => 2, 'human' => 1, 'webdriver' => 1, 'no_input' => 0, 'ua_mismatch' => 0, 'headless' => 0, 'tz_mismatch' => 0 ) );
// The readout, its days, the nightly 14-day read, then the backfill chunks, newest first.
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-20' ), $hit( '2026-10-01' ), array(), array(), array(), array() );
sn_bot_signals_refresh();
$store = (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION );
ok( 1 === ( $store['2026-10-20']['strict_removed'] ?? null ) && 1 === ( $store['2026-10-01']['strict_removed'] ?? null ), 'days 3 and 6 weeks back carry the strict reading after one night' );
ok( $old === ( $store['2026-08-01'] ?? null ), 'a day before the AE window, which no read covers, keeps its old shape: unmeasured, not 0 removed' );
ok( array() === $GLOBALS['__ret'], 'the whole 90-day window was read in one night (every chunk asked)' );
ok( '2026-08-13' === get_option( SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION ), 'the cursor reaches the window\'s first day' );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-02' ) );
sn_bot_signals_refresh();
ok( array( $hit( '2026-10-02' ) ) === $GLOBALS['__ret'], 'once done, a night asks for no backfill' );

// A chunk that reaches the row cap, or fails, stops the walk where it is: the
// next night resumes from the cursor, and nothing is replaced from a short read.
$GLOBALS['__opt'] = array( SNT_BOT_SIGNAL_BY_DAY_OPTION => array( '2026-10-01' => $old ) );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-20' ), array_fill( 0, SNT_BOT_SIGNAL_LIMIT, $hit( '2026-10-01' )[0] ) );
sn_bot_signals_refresh();
$store = (array) get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION );
ok( $old === $store['2026-10-01'] && isset( $store['2026-10-20']['strict_removed'] ), 'a capped chunk is skipped (its days keep their shape); the chunk before it is kept' );
ok( '2026-10-14' === get_option( SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION ), 'the cursor stops before the capped chunk' );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-01' ), array(), array(), array(), array() );
sn_bot_signals_refresh();
ok( 1 === ( get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION )['2026-10-01']['strict_removed'] ?? null ) && '2026-08-13' === get_option( SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION ), 'the next night resumes at the cursor and finishes' );
$GLOBALS['__opt'] = array();
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-20' ) );
function_exists( 'sn_bot_signals_by_day_backfill' ) && sn_bot_signals_by_day_backfill( 0.0 );
ok( 4 === count( $GLOBALS['__ret'] ) && false === get_option( SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION ), 'no budget: no read, no cursor moved' );
// Review on #2419: the budget is what is left of the nightly tick, measured from
// the job's start, and a chunk starts only with SNT_BOT_SIGNAL_BY_DAY_MIN_LEFT to spare.
sn_bot_signals_by_day_backfill( 7.9 );
ok( 4 === count( $GLOBALS['__ret'] ), 'under 8 seconds left: no chunk starts (two 6-second halves would pass the cron limit)' );
$GLOBALS['__ret'] = array( array() ); // one chunk answers; the next read fails and stops the walk
sn_bot_signals_by_day_backfill( 9.0 );
ok( '2026-10-14' === get_option( SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION ), 'with 9 seconds left a chunk starts' );
$GLOBALS['__opt'] = array();
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-20' ) );
sn_bot_signals_refresh( microtime( true ) - 30 );
ok( array( $hit( '2026-10-20' ) ) === $GLOBALS['__ret'] && false === get_option( SNT_BOT_SIGNAL_BY_DAY_BACKFILL_OPTION ), 'a nightly job 30 seconds in skips the backfill: what is left of the 35-second tick is under 8' );
$GLOBALS['__ret'] = array( $fx['rows'], $fx['days'], $hit( '2026-11-10' ), $hit( '2026-10-20' ) );
sn_bot_signals_refresh( '' ); // the hook's own empty argument reads as the start of the job
ok( array() === $GLOBALS['__ret'], 'run from the hook, the job measures from its own start and backfills' );
sn_analytics_clock( 0 );

echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );
