<?php
/**
 * The native Quality view paints the classic panel's figures: the three
 * traffic classes, the top bot networks, and this site's own tools beside
 * the classes (in none of them), in the classic twin's words.
 *
 * Before 2026-11-11 it handed the breakdown's two top-level keys to the
 * table as rows, so the native Traffic quality table held two blank rows.
 *
 * Run: php tests/openstation-analytics-quality-painter.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

require_once __DIR__ . '/lib/os-leaf-harness.php';

$GLOBALS['__fp'] = array( 'views' => 12, 'proven' => array( 'views' => 9, 'visitor_days' => 1 ), 'claimed' => array( 'views' => 3, 'visitor_days' => 2 ), 'from' => '2026-11-01', 'through' => '2026-11-10', 'days' => 10, 'partial' => true );
$GLOBALS['__st'] = array( 'visitor_days' => 96, 'views' => 383, 'removed' => array( 'visitor_days' => 4, 'views' => 17 ), 'removed_no_engagement' => array( 'visitor_days' => 2, 'views' => 2 ), 'rule' => 'strict_v1', 'reason' => 'r', 'basis' => 'inferred', 'window' => array( 'from' => '2026-11-01', 'through' => '2026-11-10', 'partial' => false ) );
function sn_analytics_class_series( $from, $to, $g = 'day' ) { return array(); }
function sn_analytics_distribution( $m, $from, $to, $class = 'human' ) { return array(); }
function sn_analytics_bot_breakdown( $from, $to, $limit = 10 ) {
	return array(
		'totals'           => array( 'human' => 100, 'suspect' => 10, 'bot' => 30, 'total' => 140 ),
		'top_bot_networks' => array( array( 'value' => 'Amazon.com, Inc.', 'views' => 22, 'visits' => 3 ) ),
		'first_party'      => $GLOBALS['__fp'],
		'strict'           => $GLOBALS['__st'],
	);
}

require_once SNT_PATH . 'inc/analytics-lanes-first-party.php';
require_once SNT_PATH . 'inc/analytics-strict-human.php';
require_once SNT_PATH . 'apps/sn-analytics/parts/paint-kit.php';
require_once SNT_PATH . 'apps/sn-analytics/parts/painters/view-quality.php';

$pass = 0;
$fail = 0;
function q_ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "PASS: $m\n";
		return;
	}
	++$fail;
	echo "FAIL: $m\n";
}

$ctx    = array( 'from' => '2026-11-01', 'to' => '2026-11-11', 'class' => 'human', 'granularity' => 'day' );
$html   = \SignalNoise\OpenStationHost\Analytics\Painters\paint_view_quality( $ctx );
$tables = snt_leaf_tables( $html );
$text   = html_entity_decode( preg_replace( '/\s+/', ' ', strip_tags( str_replace( '><', '> <', $html ) ) ), ENT_QUOTES, 'UTF-8' );

$quality = array();
foreach ( $tables as $t ) {
	foreach ( (array) $t['data'] as $row ) {
		$quality[ (string) ( $row['value'] ?? '' ) ] = (string) ( $row['views'] ?? '' );
	}
}
q_ok( array( 'Presumed human' => '100', 'Suspect' => '10', 'Bot' => '30' ) === array_intersect_key( $quality, array( 'Presumed human' => 1, 'Suspect' => 1, 'Bot' => 1 ) ), 'the Traffic quality table holds the three classes with their views' );
q_ok( ! isset( $quality[''] ), 'no blank row: the breakdown\'s keys are not painted as rows' );
q_ok( '22' === ( $quality['Amazon.com, Inc.'] ?? null ), 'the top bot networks are painted, as the classic panel paints them' );
q_ok( ! isset( $quality["This site's own tools"] ), 'the own tools are not a row beside the classes, so no reader adds them in' );
q_ok( false !== strpos( $text, sn_lanes_first_party_line( $GLOBALS['__fp'] ) ), 'the own-tools line is painted, the words the classic twin paints' );
q_ok( false !== strpos( $text, 'Strictly human: 96 visitor-days (4 removed: automation signals; 2 removed: no engagement recorded).' ) && '100' === ( $quality['Presumed human'] ?? '' ), 'both numbers: presumed human 100 in the table and the strict line, the classic twin\'s words' );
$GLOBALS['__fp'] = null;
$GLOBALS['__st'] = null;
$t2 = html_entity_decode( strip_tags( \SignalNoise\OpenStationHost\Analytics\Painters\paint_view_quality( $ctx ) ), ENT_QUOTES, 'UTF-8' );
q_ok( false !== strpos( $t2, 'not measured in this range' ), 'a null figure says not measured' );
q_ok( false !== strpos( $t2, 'Strictly human: not measured' ), 'a null strict figure says not measured, never 0' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
