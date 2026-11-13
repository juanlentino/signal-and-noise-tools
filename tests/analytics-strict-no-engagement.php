<?php
/**
 * Strict rule v2's second removal, "no engagement recorded" (owner decision
 * 2026-11-13): one fixture per condition and per protection, the precedence
 * over the automation-signal removal, a visitor-day split across two UTC
 * days, the read's columns, and the two counts shown apart.
 * Run: php tests/analytics-strict-no-engagement.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
function add_action() {}
function __( $s ) { return $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function get_option( $k, $d = false ) { return $d; }

require __DIR__ . '/../inc/analytics-bot-signals.php';
require_once __DIR__ . '/../inc/analytics-referrer-kind.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

// The by-day read's row with the no-engagement columns: the scraper's shape
// unless a field says otherwise (one direct desktop page view, nothing after).
$row = static fn( $vid, array $over = array() ) => $over + array(
	'day' => '2026-11-09', 'vid' => $vid, 'views' => 1, 'human' => 1, 'present' => 1, 'pv_rows' => 1,
	'engaged' => 0, 'referred' => 0, 'not_desktop' => 0, 'guarded' => 0,
) + array_fill_keys( SNT_BOT_SIGNAL_BITS, 0 );
$removes = static fn( array $rows ) => ( sn_bot_signals_by_day( $rows )['2026-11-09']['no_engagement_removed'] ?? null );

echo "Group: each condition of the rule\n";
ok( 1 === $removes( array( $row( 'a' ) ) ), 'one direct desktop page view with nothing recorded after it: removed' );
ok( 0 === $removes( array( $row( 'b', array( 'pv_rows' => 2, 'views' => 2 ) ) ) ), 'a two-page visitor-day: kept' );
ok( 0 === $removes( array( $row( 'c', array( 'engaged' => 1 ) ) ) ), 'a recorded scroll, time or custom event: kept' );
ok( 0 === $removes( array( $row( 'd', array( 'referred' => 1 ) ) ) ), 'any referrer (an internal click, Quora, a LinkedIn tap): kept' );
ok( 0 === $removes( array( $row( 'e', array( 'not_desktop' => 1 ) ) ) ), 'mobile (the LinkedIn in-app tap, the relay no_input reader): kept' );
ok( 0 === $removes( array( $row( 'f', array( 'guarded' => 1 ) ) ) ), '/resume, /cv or an /r/ link code (Morton Grove): kept' );
ok( 0 === $removes( array( $row( 'g', array( 'human' => 0 ) ) ) ) || null === $removes( array( $row( 'g', array( 'human' => 0 ) ) ) ), 'not presumed human: never in human, so never removed from it' );

echo "\nGroup: the visitor-day is judged whole, not per UTC day\n";
$split = array(
	$row( 'h', array( 'day' => '2026-11-09' ) ),
	$row( 'h', array( 'day' => '2026-11-10', 'views' => 0, 'pv_rows' => 0, 'engaged' => 1 ) ), // its time event after UTC midnight
);
ok( 0 === ( sn_bot_signals_by_day( $split )['2026-11-09']['no_engagement_removed'] ?? -1 ), 'a page view at 19:55 ET with its time at 20:05 ET (two UTC rows): kept' );
$two = array( $row( 'i', array( 'day' => '2026-11-09' ) ), $row( 'i', array( 'day' => '2026-11-10' ) ) );
ok( 0 === array_sum( array_column( sn_bot_signals_by_day( $two ), 'no_engagement_removed' ) ), 'two page views of one visitor code across UTC days: kept (the narrowed "every session")' );

echo "\nGroup: each visitor-day is removed once, automation first\n";
$both = $row( 'j', array( 'webdriver' => 1 ) ); // webdriver alone scores the threshold and strict v1 removes it
$bd   = sn_bot_signals_by_day( array( $both, $row( 'k' ), $row( 'l', array( 'referred' => 1 ) ) ) )['2026-11-09'];
ok( 1 === $bd['strict_removed'] && 1 === $bd['no_engagement_removed'], 'one removed for automation signals, one for no engagement, never both for one visitor-day' );
ok( 1 === $bd['no_engagement_removed_views'] && 1 === $bd['strict_removed_views'], 'each count carries its own page views' );

echo "\nGroup: the signal counts stay over the visitor-days that carried signals\n";
$bd = sn_bot_signals_by_day( array( $row( 'm', array( 'present' => 0 ) ), $row( 'n' ) ) )['2026-11-09'];
ok( 1 === $bd['visitor_days'] && 2 === $bd['no_engagement_removed'], 'a visitor-day without signals is judged by this rule but not counted as a signal-carrier' );
$old = sn_bot_signals_by_day( array( array_diff_key( $row( 'o' ), array_flip( array( 'present', 'pv_rows', 'engaged', 'referred', 'not_desktop', 'guarded' ) ) ) ) )['2026-11-09'];
ok( ! array_key_exists( 'no_engagement_removed', $old ) && 1 === $old['visitor_days'], 'a read without the columns stores no such count: that day stays unmeasured for it' );

echo "\nGroup: the read\n";
$sql = sn_bot_signals_by_day_sql( 14, 'sn_v2', '', '', true );
foreach ( array( 'AS present', 'AS pv_rows', 'AS engaged', 'AS referred', 'AS not_desktop', 'AS guarded', "blob2 LIKE '/r/%'", "blob1 = 'tm' AND double2 > 0" ) as $needle ) {
	ok( false !== strpos( $sql, $needle ), 'the read carries ' . $needle );
}
ok( false !== strpos( $sql, "OR (blob7 != 'bot' AND (" ), 'it also reads presumed-human visitor-days that sent no signal' );
$plain = sn_bot_signals_by_day_sql( 14, 'sn_v2' );
ok( false === strpos( $plain, 'pv_rows' ) && false !== strpos( $plain, 'AND bitAnd(toUInt32(double8), 32) > 0' ), 'other callers (north star, the alert) get the read unchanged' );
$cols = implode( ' ', sn_strict_no_engagement_cols() );
ok( false === strpos( $cols, 'blob4' ) && false === strpos( $cols, 'blob10' ) && false === strpos( $cols, 'blob11' ) && false === strpos( $cols, 'blob12' ), 'no country, region, city or network in the rule\'s columns (the human class keeps its own network test)' );

echo "\nGroup: the two counts, shown apart\n";
$store = array(
	'2026-11-09' => array( 'visitor_days' => 5, 'likely_automated' => 1, 'strict_removed' => 1, 'strict_removed_views' => 1, 'no_engagement_removed' => 3, 'no_engagement_removed_views' => 3 ),
	'2026-11-10' => array( 'visitor_days' => 5, 'likely_automated' => 0, 'strict_removed' => 0, 'strict_removed_views' => 0 ), // before the second reason
);
$s = sn_bot_signals_strict( $store, '2026-11-09', '2026-11-10', array( 'unique_visitor_days' => 50, 'views' => 114 ) );
ok( 46 === $s['visitor_days'] && 110 === $s['views'], 'strict = human less both removals: 114 - 1 - 3 views' );
ok( array( 'visitor_days' => 1, 'views' => 1 ) === $s['removed'] && array( 'visitor_days' => 3, 'views' => 3 ) === $s['removed_no_engagement'], 'each reason keeps its own count' );
ok( true === $s['window_no_engagement']['partial'] && false === $s['window']['partial'], 'a day stored before the second reason makes only its window partial' );
$line = sn_analytics_strict_human_line( $s, 'views' );
ok( 0 === strpos( $line, 'Strictly human: 110 views (1 removed: automation signals; 3 removed: no engagement recorded)' ), 'the line names both reasons with their own counts: "' . $line . '"' );
ok( false !== strpos( $line, 'no-engagement removals over UTC days 2026-11-09 to 2026-11-09 only' ), 'and says which UTC days the second count covers' );
$old = sn_bot_signals_strict( array( '2026-11-10' => $store['2026-11-10'] ), '2026-11-10', '2026-11-10', array( 'views' => 9 ) );
ok( null === $old['removed_no_engagement'] && 9 === $old['views'] && false !== strpos( sn_analytics_strict_human_line( $old, 'views' ), 'no engagement recorded: not measured' ), 'no day with the second reading: not measured, never 0' );
ok( false === strpos( SNT_STRICT_NO_ENGAGEMENT_REASON, 'China' ) && false !== strpos( SNT_STRICT_NO_ENGAGEMENT_REASON, 'never by place or network' ), 'the reason says it is behavior only' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
