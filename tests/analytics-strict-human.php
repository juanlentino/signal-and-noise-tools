<?php
/**
 * Strictly human (strict rule v1, owner decision 2026-11-11): which presumed-
 * human visitor-days the rule removes, the per-day store it rides, the range
 * figure (human less removed, null when unmeasured) and the one line every
 * surface paints.
 * Run: php tests/analytics-strict-human.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function add_action() {}
function __( $s ) { return $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
$GLOBALS['__opt'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; }

require __DIR__ . '/../inc/analytics-bot-signals.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// One row per visitor-day, the by-day read's shape. Bits are their bit values
// (AE returns bitAnd's result, not a boolean).
$row = static fn( $id, array $bits, $views = 2, $human = 1 ) => array( 'day' => '2026-11-10', 'vid' => $id, 'views' => $views, 'human' => $human )
	+ array_combine( $bits, array_map( static fn( $b ) => array_search( $b, SNT_BOT_SIGNAL_BITS, true ), $bits ) )
	+ array_fill_keys( SNT_BOT_SIGNAL_BITS, 0 );
$rows = array(
	'no_input'         => $row( 'a', array( 'no_input' ) ),
	'no_input_tz'      => $row( 'b', array( 'no_input', 'tz_mismatch' ) ),
	'intent'           => $row( 'c', array( 'no_input', 'headless' ) ),
	'webdriver'        => $row( 'd', array( 'webdriver' ), 3 ),
	'ua_tz'            => $row( 'e', array( 'ua_mismatch', 'tz_mismatch' ), 5 ),
	'headless_tz_in'   => $row( 'f', array( 'headless', 'tz_mismatch', 'no_input' ), 7 ),
	'ua_only'          => $row( 'g', array( 'ua_mismatch' ) ),
	'over_cap'         => $row( 'h', array( 'webdriver' ), SNT_ANALYTICS_VDAY_PV_CAP + 1 ),
	'non_human'        => $row( 'i', array( 'webdriver', 'ua_mismatch' ), 4, 0 ),
);

echo "Group: which visitor-days strict rule v1 removes\n";
ok( 3 === sn_bot_signals_score( $rows['no_input_tz'] ) && 3 === sn_bot_signals_score( $rows['intent'] ), 'the fixtures reach the threshold where the score alone would flag them' );
$removed = array_keys( array_filter( $rows, 'sn_bot_signals_strict_removes' ) );
ok( array( 'webdriver', 'ua_tz', 'headless_tz_in' ) === $removed, 'exactly webdriver, ua_mismatch+tz and headless+tz+no_input are removed (got ' . implode( ',', $removed ) . ')' );
ok( ! sn_bot_signals_strict_removes( $rows['no_input_tz'] ), 'no_input + tz_mismatch scores 3 and is kept: a VPN or traveling phone reader' );
ok( ! sn_bot_signals_strict_removes( $rows['intent'] ), 'no_input + headless scores 3 and is kept: an intent reader fired it' );
ok( ! sn_bot_signals_strict_removes( $rows['ua_only'] ), 'ua_mismatch alone scores 2: under the threshold, kept' );
ok( ! sn_bot_signals_strict_removes( $rows['over_cap'] ) && ! sn_bot_signals_strict_removes( $rows['non_human'] ), 'over the cap or not presumed human: never in human, so never removed from it' );
ok( 'strict_v2' === SNT_STRICT_HUMAN_RULE && false !== strpos( SNT_STRICT_HUMAN_REASON, 'tz_mismatch' ), 'the rule is named once, with its reason' );

echo "\nGroup: the per-day store carries the removals\n";
$bd = sn_bot_signals_by_day( array_values( $rows ) );
ok( array( 'visitor_days' => 7, 'likely_automated' => 5, 'strict_removed' => 3, 'strict_removed_views' => 15 ) === ( $bd['2026-11-10'] ?? null ), 'one day: 7 presumed human, 5 flagged by score, 3 removed with their 3+5+7 views' );

echo "\nGroup: the range figure is human less removed\n";
$store = array(
	'2026-11-01' => array( 'visitor_days' => 9, 'likely_automated' => 4 ), // stored before the rule: unmeasured
	'2026-11-10' => $bd['2026-11-10'],
	'2026-11-11' => array( 'visitor_days' => 3, 'likely_automated' => 1, 'strict_removed' => 1, 'strict_removed_views' => 2 ),
	'2026-11-20' => array( 'visitor_days' => 3, 'likely_automated' => 3, 'strict_removed' => 3, 'strict_removed_views' => 3 ), // outside the range
);
$human = array( 'unique_visitor_days' => 100, 'views' => 400 );
$s     = sn_bot_signals_strict( $store, '2026-11-01', '2026-11-11', $human );
ok( is_array( $s ) && 96 === $s['visitor_days'] && 383 === $s['views'], 'strict = 100 - 4 visitor-days and 400 - 17 views' );
ok( is_array( $s ) && array( 'visitor_days' => 4, 'views' => 17 ) === $s['removed'], 'removed is the sum of the removed rows over the range\'s stored days' );
ok( is_array( $s ) && array( 'from' => '2026-11-10', 'through' => '2026-11-11', 'partial' => true ) === $s['window'], 'the window covers only the days with the strict reading, and says partial' );
ok( 100 === $human['unique_visitor_days'] && 400 === $human['views'], 'the presumed-human totals are not touched' );
ok( false === sn_bot_signals_strict( $store, '2026-11-10', '2026-11-11', $human )['window']['partial'], 'a range the stored days cover end to end is not partial' );
ok( null === sn_bot_signals_strict( $store, '2026-11-01', '2026-11-05', $human ), 'only an old-shape day in the range: null, never 0 removed' );
ok( null === sn_bot_signals_strict( array(), '2026-11-01', '2026-11-11', $human ), 'nothing stored: null' );
$pv = sn_bot_signals_strict( $store, '2026-11-11', '2026-11-11', array( 'visits' => 3, 'pageview_visits' => 3, 'views' => 1 ) );
ok( null === $pv['visitor_days'] && 0 === $pv['views'], 'visitor-days come from unique_visitor_days only (null without it, never visits); a figure never goes below 0' );
$GLOBALS['__opt'][ SNT_BOT_SIGNAL_BY_DAY_OPTION ] = $store;
ok( $s === sn_analytics_strict_human( '2026-11-01', '2026-11-11', $human ), 'the reader reads the nightly store (an option, no Analytics Engine query)' );

echo "\nGroup: the one line\n";
$line = sn_analytics_strict_human_line( $s );
ok( 0 === strpos( $line, 'Strictly human: 96 visitor-days (4 removed: automation signals; no engagement recorded: not measured)' ), 'visitor-days: "' . $line . '"' );
ok( false !== strpos( $line, 'UTC days 2026-11-10 to 2026-11-11 only' ), 'a partial window says which UTC days the removals cover' );
ok( 0 === strpos( sn_analytics_strict_human_line( $s, 'views' ), 'Strictly human: 383 views (17 removed: automation signals; no engagement recorded: not measured)' ), 'views: the same figure in views' );
ok( false !== strpos( sn_analytics_strict_human_line( null ), 'not measured' ), 'null says not measured, never 0' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
