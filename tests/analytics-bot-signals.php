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
$full['cohorts']['relay']['n'] = 20; $full['cohorts']['intent']['n'] = 20; $full['cohorts']['over_cap']['n'] = 2;
$w = snt_watch_ripe_bot_signals( array(), 0, $full );
ok( true === $w['ripe'], '14 days with relay and intent at 20 ripens, over-cap at 2 does not hold it' );
ok( false !== strpos( $w['note'], 'over_cap 2' ), 'the other cohorts are reported at their count' );
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
ok( array( '2026-09-28' => array( 'visitor_days' => 2, 'likely_automated' => 0 ), '2026-09-29' => array( 'visitor_days' => 2, 'likely_automated' => 1 ) ) === $bd, 'per day: human visitor-days under the cap that carried signals, and how many scored at the threshold (the readout\'s human rule)' );
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

echo "\nResult: {$pass} passed, {$fail} failed.\n";
exit( $fail > 0 ? 1 : 0 );
