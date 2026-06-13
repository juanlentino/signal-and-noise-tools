<?php
/**
 * Tests for inc/analytics-read.php — dashboard read accessors over the path table.
 * Run: php tests/analytics-read.php
 * @since plugin v5.0.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

class RD_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
		$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cm ) { return (string) ( $r['class'] ?? 'human' ) === $cm[1]; } ) );
		}
		// GROUP BY path → per-path views-weighted aggregate.
		if ( stripos( $sql, 'GROUP BY path' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$p = (string) $r['path'];
				if ( ! isset( $agg[ $p ] ) ) { $agg[ $p ] = array( 'path' => $p, 'views' => 0, 'visits' => 0, 'sw' => 0.0, 'tw' => 0.0 ); }
				$agg[ $p ]['views']  += (int) $r['views'];
				$agg[ $p ]['visits'] += (int) $r['visits'];
				$agg[ $p ]['sw']     += (float) $r['scroll_avg'] * (int) $r['views'];
				$agg[ $p ]['tw']     += (float) $r['time_avg'] * (int) $r['views'];
			}
			$out = array();
			foreach ( $agg as $a ) {
				$out[] = array( 'path' => $a['path'], 'views' => $a['views'], 'visits' => $a['visits'],
					'scroll_avg' => $a['views'] ? $a['sw'] / $a['views'] : 0, 'time_avg' => $a['views'] ? $a['tw'] / $a['views'] : 0 );
			}
			usort( $out, function ( $x, $y ) { return (int) $y['views'] - (int) $x['views']; } );
			return $out;
		}
		// GROUP BY day → per-day series.
		if ( stripos( $sql, 'GROUP BY day' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$d = (string) $r['day'];
				if ( ! isset( $agg[ $d ] ) ) { $agg[ $d ] = array( 'day' => $d, 'views' => 0, 'visits' => 0 ); }
				$agg[ $d ]['views']  += (int) $r['views'];
				$agg[ $d ]['visits'] += (int) $r['visits'];
			}
			ksort( $agg );
			return array_values( $agg );
		}
		// No GROUP BY → range totals (single aggregate row).
		$v = 0; $vi = 0; $sw = 0.0; $tw = 0.0;
		foreach ( $rows as $r ) { $v += (int) $r['views']; $vi += (int) $r['visits']; $sw += (float) $r['scroll_avg'] * (int) $r['views']; $tw += (float) $r['time_avg'] * (int) $r['views']; }
		return array( array( 'views' => $v, 'visits' => $vi, 'scroll_avg' => $v ? $sw / $v : 0, 'time_avg' => $v ? $tw / $v : 0 ) );
	}
}
$GLOBALS['wpdb'] = new RD_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-read.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$fixture = array(
	array( 'day' => '2026-06-10', 'path' => '/a', 'class' => 'human', 'views' => 100, 'visits' => 40, 'scroll_avg' => 60, 'time_avg' => 120 ),
	array( 'day' => '2026-06-11', 'path' => '/a', 'class' => 'human', 'views' => 300, 'visits' => 50, 'scroll_avg' => 80, 'time_avg' => 240 ),
	array( 'day' => '2026-06-11', 'path' => '/b', 'class' => 'human', 'views' => 300, 'visits' => 90, 'scroll_avg' => 50, 'time_avg' => 60 ),
	array( 'day' => '2026-06-11', 'path' => '/a', 'class' => 'bot',   'views' => 999, 'visits' => 1,  'scroll_avg' => 0,  'time_avg' => 0 ),
);

echo "Analytics read accessors\n\n";

echo "Group: top_paths\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$tp = sn_analytics_top_paths( '2026-06-01', '2026-06-12' );
ok( count( $tp ) === 2, 'top_paths: human paths only, grouped' );
ok( $tp[0]['path'] === '/a' && $tp[0]['views'] === 400, 'top_paths: ordered by views desc (/a=400 > /b=300)' );
$a = array_values( array_filter( $tp, function ( $r ) { return $r['path'] === '/a'; } ) )[0];
ok( $a['views'] === 400, 'top_paths: sums views across days' );
ok( abs( $a['scroll_avg'] - 75.0 ) < 0.01, 'top_paths: scroll_avg views-weighted ((60*100+80*300)/400=75, not plain-avg 70)' );
ok( abs( $a['time_avg'] - 210.0 ) < 0.01, 'top_paths: time_avg views-weighted ((120*100+240*300)/400=210, not plain-avg 180)' );
ok( is_float( $a['scroll_avg'] ) && is_int( $a['views'] ), 'top_paths: types normalized' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'GROUP BY path' ) !== false && strpos( $sql, 'ORDER BY views DESC' ) !== false, 'top_paths: SQL groups by path, orders by views' );
// SQL-shape pins: a plain AVG() regression must not slip through green.
ok(
	strpos( $sql, 'scroll_avg * views' ) !== false && strpos( $sql, 'NULLIF(SUM(views)' ) !== false,
	'top_paths: SQL uses views-weighted scroll expression (not AVG)'
);
ok(
	strpos( $sql, 'time_avg' ) !== false && strpos( $sql, '* views' ) !== false,
	'top_paths: SQL uses views-weighted time expression (not AVG)'
);
// SUM(col) AS alias mapping: a SUM(visits) AS views swap must fail.
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $sql ) === 1,
	'top_paths: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $sql ) === 1,
	'top_paths: SUM(visits) AS visits — alias mapping correct'
);

echo "\nGroup: range_totals\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$rt = sn_analytics_range_totals( '2026-06-01', '2026-06-12' );
ok( $rt['views'] === 700 && $rt['visits'] === 180, 'range_totals: sums human views/visits (excludes bot)' );
ok( abs( $rt['scroll_avg'] - 64.2857 ) < 0.01, 'range_totals: scroll_avg views-weighted ((60*100+80*300+50*300)/700≈64.29)' );
ok( is_int( $rt['views'] ) && is_float( $rt['scroll_avg'] ), 'range_totals: types normalized' );
$sql = end( $GLOBALS['wpdb']->queries );
// SQL-shape pins for range_totals (previously had NO SQL assertion).
ok(
	strpos( $sql, 'scroll_avg * views' ) !== false && strpos( $sql, 'NULLIF(SUM(views)' ) !== false,
	'range_totals: SQL uses views-weighted scroll expression (not AVG)'
);
ok(
	strpos( $sql, 'time_avg' ) !== false && strpos( $sql, '* views' ) !== false,
	'range_totals: SQL uses views-weighted time expression (not AVG)'
);
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $sql ) === 1,
	'range_totals: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $sql ) === 1,
	'range_totals: SUM(visits) AS visits — alias mapping correct'
);

echo "\nGroup: daily_series\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = $fixture;
$ds = sn_analytics_daily_series( '2026-06-01', '2026-06-12' );
ok( count( $ds ) === 2, 'daily_series: one row per day' );
ok( $ds[0]['day'] === '2026-06-10' && $ds[1]['day'] === '2026-06-11', 'daily_series: ascending by day' );
ok( $ds[1]['views'] === 600, 'daily_series: 2026-06-11 human views = 300(/a)+300(/b)' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'GROUP BY day' ) !== false && strpos( $sql, 'ORDER BY day ASC' ) !== false, 'daily_series: SQL groups by day ascending' );
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $sql ) === 1,
	'daily_series: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $sql ) === 1,
	'daily_series: SUM(visits) AS visits — alias mapping correct'
);

echo "\nGroup: daily_series weekly granularity\n";
// Stub returns no rows for this expression-based GROUP BY; these are SQL-shape assertions only.
$ws = sn_analytics_daily_series( '2026-03-01', '2026-06-12', 'human', 'week' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)' ) !== false, 'weekly: SQL floors day to ISO Monday' );
ok( strpos( $sql, 'GROUP BY DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)' ) !== false, 'weekly: groups by the week-floor expression' );
sn_analytics_daily_series( '2026-06-01', '2026-06-12' );
$sql2 = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql2, 'GROUP BY day' ) !== false && strpos( $sql2, 'DATE_SUB' ) === false, 'day granularity: unchanged GROUP BY day' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
