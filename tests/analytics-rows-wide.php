<?php
/**
 * Tests: the analytics rows read, widened (owner brief "MCP full analytics
 * access", 2026-11-01): up to four dimensions, city, region and hour, custom
 * from and to, and the planner that answers from the stored rollups when they
 * hold the combination and from Analytics Engine (about 90 days) otherwise.
 *
 * Run: php tests/analytics-rows-wide.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_message() { return $this->message; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function add_filter() {}
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function snt_analytics_range_is_valid( $r ) { return in_array( $r, array( 7, 14, 30, 90, 365, 'all' ), true ); }
function snt_analytics_is_ymd( $s ) { return is_string( $s ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ); }
function sn_analytics_canonical_path( $p ) { $p = (string) $p; if ( '' === $p ) { return ''; } $t = rtrim( $p, '/' ); return '' === $t ? '/' : $t; }
function sn_analytics_source( $day, $kind = 'pageviews' ) { return 'sn_pageviews_v2'; }
function sn_analytics_class_where( $c ) { return "CLASS({$c})"; }
function sn_analytics_excluded_path_sql() { return ' AND EXCL'; }
function sn_analytics_split_range_ok( $r ) { return 1 === preg_match( "/^ AND timestamp (<|>=) toDateTime\\('[0-9: -]+'\\)$/", (string) $r ); }
function sn_analytics_site_tz_name() { return 'America/New_York'; }
$GLOBALS['answers'] = array(); $GLOBALS['built'] = array();
function sn_analytics_stitched_rows( callable $build, $from, $to ) {
	$sql = $build( 'v2', '' );
	$GLOBALS['built'][] = $sql;
	$a = array_shift( $GLOBALS['answers'] );
	return is_array( $a ) ? ( isset( $a['sets'] ) ? $a['sets'] : array( $a ) ) : null; // 'sets': both halves of a stitched read.
}
$root = dirname( __DIR__ );
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-referrer-kind.php';
require $root . '/inc/analytics-rows.php';
require $root . '/inc/analytics-rows-events.php';
$v = static fn( $in ) => snt_arows_validate( $in );

echo "\nGroup: what a query may ask\n";
ok( ! is_wp_error( $v( array( 'dimensions' => array( 'country', 'device', 'city', 'hour' ) ) ) ), 'four dimensions' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'country', 'device', 'city', 'hour', 'day' ) ) ) ), 'five are refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'city', 'city' ) ) ) ), 'the same dimension twice is refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'ip' ) ) ) ), 'an unknown dimension is refused' );
$c = $v( array( 'dimensions' => array( 'city' ), 'from' => '2026-10-01', 'to' => '2026-10-31' ) );
ok( 'custom' === $c['range'] && '2026-10-01' === $c['from'] && '2026-10-31' === $c['to'], 'a custom window, from and to' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'city' ), 'from' => '2026-10-31', 'to' => '2026-10-01' ) ) ), 'from after to is refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'city' ), 'from' => '2026-10-01' ) ) ), 'from without to is refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'city' ), 'range' => 30, 'from' => '2026-10-01', 'to' => '2026-10-02' ) ) ), 'a range and from-to together are refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'city' ), 'from' => '2026-02-30', 'to' => '2026-03-01' ) ) ), 'a day that does not exist is refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'city' ), 'source' => 'cache' ) ) ), 'an unknown source is refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'country', 'city' ), 'sort' => 'time' ) ) ) && ! is_wp_error( $v( array( 'dimensions' => array( 'path', 'city' ), 'sort' => 'time' ) ) ), 'time sorts only rows that are pages' );
ok( is_wp_error( snt_arows_validate( array( 'dimensions' => array( 'path' ), 'path' => '(unmatched)', 'source' => 'events' ), true ) ), 'the (unmatched) breakdown reads the stored rows only' );

echo "\nGroup: which source answers\n";
$plan = static fn( $in, $from = '2026-10-15' ) => snt_arows_plan( $v( $in ), $from, '2026-10-31' );
foreach ( array( array( 'path' ), array( 'path', 'day' ), array( 'link_code' ), array( 'day' ), array( 'city' ), array( 'region', 'day' ), array( 'referrer' ) ) as $d ) {
	ok( 'stored' === $plan( array( 'dimensions' => $d ) ), implode( '+', $d ) . ': the stored rollups (forever)' );
}
foreach ( array( array( 'country', 'device' ), array( 'hour' ), array( 'path', 'country' ), array( 'path', 'link_code' ), array( 'city', 'device', 'day', 'hour' ) ) as $d ) {
	ok( 'events' === $plan( array( 'dimensions' => $d ) ), implode( '+', $d ) . ': Analytics Engine' );
}
ok( 'events' === $plan( array( 'dimensions' => array( 'country' ), 'path' => '/about' ) ), 'a path filter on a non-page dimension: Analytics Engine' );
ok( 'events' === $plan( array( 'dimensions' => array( 'path' ), 'source' => 'events' ) ), 'source events forces it' );
$e = $plan( array( 'dimensions' => array( 'hour' ), 'source' => 'stored' ) );
ok( is_wp_error( $e ) && false !== strpos( $e->message, 'source "events"' ), 'source stored on what is not stored: refused, naming the way' );
$e = $plan( array( 'dimensions' => array( 'hour' ) ), '2026-07-01' );
ok( is_wp_error( $e ) && false !== strpos( $e->message, '2026-08-03' ), 'older than Analytics Engine keeps: refused, naming the first day it holds' );
ok( 'stored' === $plan( array( 'dimensions' => array( 'city' ) ), '2025-01-01' ), 'the stored rollups go back to the first stored day' );
ok( is_wp_error( $plan( array( 'dimensions' => array( 'country' ), 'path' => "/a'b" ) ) ), 'a path with a quote is never sent to Analytics Engine' );

echo "\nGroup: the Analytics Engine query\n";
$q   = $v( array( 'dimensions' => array( 'city', 'region', 'day', 'hour' ), 'class' => 'suspect' ) );
$sql = snt_arows_events_sql( $q, '2026-10-01 04:00:00', '2026-11-01 04:00:00', 'America/New_York', false );
ok( false !== strpos( $sql, 'blob11 AS city' ) && false !== strpos( $sql, 'blob10 AS region' ), 'city and region' );
ok( false !== strpos( $sql, "formatDateTime(timestamp, '%Y-%m-%d', 'America/New_York') AS day" ) && false !== strpos( $sql, "formatDateTime(timestamp, '%H', 'America/New_York') AS hour" ), 'day and hour in the site\'s time zone' );
ok( false !== strpos( $sql, "timestamp >= toDateTime('2026-10-01 04:00:00') AND timestamp < toDateTime('2026-11-01 04:00:00')" ) && false !== strpos( $sql, 'CLASS(suspect)' ) && false !== strpos( $sql, ' AND EXCL' ), 'the window, the read-time class, the excluded paths' );
ok( false !== strpos( $sql, "sumIf(_sample_interval, blob1 = 'pv') AS views" ) && false !== strpos( $sql, "sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum" ) && false !== strpos( $sql, 'GROUP BY city, region, day, hour' ), 'weighted counts, grouped by the dimensions' );
$pv = snt_arows_events_sql( $q, '2026-10-01 04:00:00', '2026-11-01 04:00:00', 'America/New_York', true );
ok( false !== strpos( $pv, "AND blob1 = 'pv'" ) && false !== strpos( $pv, 'count(DISTINCT index1) AS pageview_visits' ), 'visitor-days with a page view: their own query, as the rollup counts them' );
$lc = snt_arows_events_sql( $v( array( 'dimensions' => array( 'link_code', 'country' ), 'path' => '/about/', 'referrer' => 'news.ycombinator.com' ) ), 'a', 'b', '', false );
ok( false !== strpos( $lc, "blob2 LIKE '/r/%'" ) && false !== strpos( $lc, "(blob2 = '/about' OR blob2 = '/about/')" ) && false !== strpos( $lc, "(blob3 = 'news.ycombinator.com' OR blob3 = 'www.news.ycombinator.com')" ), 'link codes, the path and referrer filters in both stored spellings' );
ok( '' === snt_arows_events_sql( array( 'path' => "/x' OR 1=1 --" ) + $q, 'a', 'b', '', false ), 'the builder never interpolates an unsafe filter' );
ok( false !== strpos( snt_arows_events_sql( $q, 'a', 'b', '', false, 'legacy', " AND timestamp < toDateTime('2026-10-05 04:00:00')" ), "FROM legacy WHERE timestamp >= toDateTime('a') AND timestamp < toDateTime('b') AND timestamp < toDateTime('2026-10-05 04:00:00')" ), 'one half of a stitched read' );

echo "\nGroup: the referrer as the dims rollup reads it\n";
$rq = snt_arows_events_sql( $v( array( 'dimensions' => array( 'referrer', 'country' ) ) ), 'a', 'b', '', false );
ok( false !== strpos( $rq, "if((double10 = 1 AND timestamp >= toDateTime('2026-10-03 15:09:00')), '(internal)', blob3) AS referrer" ) && false !== strpos( $rq, 'GROUP BY referrer, country' ), 'an internal click is (internal), grouped by the alias' );
$dq = snt_arows_events_sql( $v( array( 'dimensions' => array( 'country' ), 'referrer' => '(direct)' ) ), 'a', 'b', '', false );
$iq = snt_arows_events_sql( $v( array( 'dimensions' => array( 'country' ), 'referrer' => '(internal)' ) ), 'a', 'b', '', false );
ok( false !== strpos( $dq, " AND blob3 = '' AND NOT (double10 = 1" ) && false === strpos( $dq, "'(direct)'" ), '(direct): no referrer and not an internal click, never the literal' );
ok( false !== strpos( $iq, " AND (double10 = 1 AND timestamp" ) && false === strpos( $iq, "blob3 = '(internal)'" ), '(internal): the internal-click predicate' );
ok( false !== strpos( $rq, 'ORDER BY views DESC LIMIT 5000' ) && false !== strpos( snt_arows_events_sql( $v( array( 'dimensions' => array( 'country' ) ) ), 'a', 'b', '', true ), 'ORDER BY pageview_visits DESC LIMIT 5000' ), 'each half keeps its top rows when capped' );
$bad = array( 'dims' => array( 'country' ), 'class' => 'human', 'path' => null, 'referrer' => "x' OR 1=1 --" );
ok( '' === snt_arows_events_sql( $bad, 'a', 'b', '', false ), 'the builder refuses a referrer that is not a hostname or sentinel' );
ok( false === strpos( snt_arows_events_sql( $v( array( 'dimensions' => array( 'hour' ) ) ), 'a', 'b', "UTC'); DROP", false ), 'DROP' ), 'the builder refuses a time zone that is not a zone name' );

echo "\nGroup: four dimensions sum to the single-dimension total (test 7b)\n";
$one  = snt_arows_events_sql( $v( array( 'dimensions' => array( 'country' ) ) ), 'a', 'b', 'UTC', false );
$four = snt_arows_events_sql( $v( array( 'dimensions' => array( 'country', 'device', 'city', 'hour' ) ) ), 'a', 'b', 'UTC', false );
$where = static fn( $s ) => substr( $s, strpos( $s, ' FROM ' ), strpos( $s, ' GROUP BY ' ) - strpos( $s, ' FROM ' ) );
$mets  = static fn( $s ) => substr( $s, strpos( $s, "sumIf(_sample_interval, blob1 = 'pv')" ), strpos( $s, ' FROM ' ) - strpos( $s, "sumIf(_sample_interval, blob1 = 'pv')" ) );
ok( $where( $one ) === $where( $four ) && $mets( $one ) === $mets( $four ), 'the same rows and the same weighted sums, grouped finer: the views add up to the same total' );
$GLOBALS['answers'] = array(
	array( array( 'country' => 'US', 'device' => 'desktop', 'city' => 'A', 'hour' => '09', 'views' => 3, 'visits' => 2 ), array( 'country' => 'US', 'device' => 'mobile', 'city' => 'B', 'hour' => '10', 'views' => 2, 'visits' => 1 ) ),
	array( array( 'country' => 'US', 'device' => 'desktop', 'city' => 'A', 'hour' => '09', 'pageview_visits' => 2 ), array( 'country' => 'US', 'device' => 'mobile', 'city' => 'B', 'hour' => '10', 'pageview_visits' => 1 ) ),
);
$r4 = snt_arows_events_fetch( $v( array( 'dimensions' => array( 'country', 'device', 'city', 'hour' ) ) ), '2026-10-01', '2026-10-31' );
ok( 5.0 === array_sum( array_column( $r4['rows'], 'views' ) ) && 3 === array_sum( array_column( $r4['rows'], 'pageview_visits' ) ), 'the fetch keeps every row\'s views and joins its visitor-days by key' );
$GLOBALS['answers'] = array(
	array( 'sets' => array( array( array( 'country' => 'US', 'views' => 3, 'visits' => 2 ) ), array( array( 'country' => 'US', 'views' => 4, 'visits' => 1 ), array( 'country' => 'CA', 'views' => 1, 'visits' => 1 ) ) ) ),
	array( 'sets' => array( array( array( 'country' => 'US', 'pageview_visits' => 2 ) ), array( array( 'country' => 'US', 'pageview_visits' => 1 ) ) ) ),
);
$st2 = snt_arows_events_fetch( $v( array( 'dimensions' => array( 'country' ) ) ), '2026-10-01', '2026-10-31' );
$us  = array_values( array_filter( $st2['rows'], static fn( $r ) => 'US' === $r['country'] ) )[0];
ok( 7.0 === $us['views'] && 3.0 === $us['visits'] && 3 === $us['pageview_visits'] && 2 === count( $st2['rows'] ) && false === $st2['capped'], 'the two halves of a stitched read add up, views and visitor-days alike' );
$full = array_map( static fn( $i ) => array( 'country' => 'C' . $i, 'views' => 1, 'visits' => 1 ), range( 1, SNT_AROWS_EVENTS_CAP ) );
$GLOBALS['answers'] = array( $full, array() );
ok( true === snt_arows_events_fetch( $v( array( 'dimensions' => array( 'country' ) ) ), '2026-10-01', '2026-10-31' )['capped'], 'a half that reaches the cap says so' );
$GLOBALS['answers'] = array( null );
ok( is_wp_error( snt_arows_events_fetch( $v( array( 'dimensions' => array( 'hour' ) ) ), '2026-10-01', '2026-10-31' ) ), 'a failed read is an error, never an empty answer' );

echo "\nGroup: time and scroll per view only where a view's beacons share its row\n";
// Live 2026-11-01: one Los Angeles view read 475% scroll (its reader's beacons geolocated elsewhere than its page view), and hours read 125%.
$GLOBALS['answers'] = array(
	array( array( 'city' => 'Los Angeles', 'views' => 1, 'visits' => 1, 'time_sum' => 21573, 'time_events' => 3, 'scroll_events' => 19 ), array( 'city' => 'Juneau', 'views' => 0, 'visits' => 1, 'time_sum' => 900, 'time_events' => 1, 'scroll_events' => 4 ) ),
	array( array( 'city' => 'Los Angeles', 'pageview_visits' => 1 ) ),
);
$geo = snt_arows_events_fetch( $v( array( 'dimensions' => array( 'city' ) ) ), '2026-10-01', '2026-10-31' );
ok( 1 === count( $geo['rows'] ) && null === $geo['rows'][0]['scroll_events'] && null === $geo['rows'][0]['time_sum'] && null === $geo['rows'][0]['time_events'] && 1.0 === $geo['rows'][0]['views'], 'by city: views kept, time and scroll null, and a row of beacons only (no view) is dropped' );
$GLOBALS['answers'] = array(
	array( array( 'path' => '/about', 'hour' => '18', 'views' => 1, 'visits' => 1, 'time_sum' => 5570, 'time_events' => 2, 'scroll_events' => 5 ) ),
	array( array( 'path' => '/about', 'hour' => '18', 'pageview_visits' => 1 ) ),
);
ok( null === snt_arows_events_fetch( $v( array( 'dimensions' => array( 'path', 'hour' ) ) ), '2026-10-01', '2026-10-31' )['rows'][0]['scroll_events'], 'by hour, even with path: null (a view\'s beacons can land in the next hour)' );
$GLOBALS['answers'] = array(
	array( array( 'path' => '/about', 'day' => '2026-10-31', 'views' => 2, 'visits' => 1, 'time_sum' => 5570, 'time_events' => 2, 'scroll_events' => 6 ) ),
	array( array( 'path' => '/about', 'day' => '2026-10-31', 'pageview_visits' => 1 ) ),
);
$pd = snt_arows_events_fetch( $v( array( 'dimensions' => array( 'path', 'day' ) ) ), '2026-10-01', '2026-10-31' )['rows'][0];
ok( 6.0 === $pd['scroll_events'] && 5570.0 === $pd['time_sum'] && 2.0 === $pd['time_events'], 'by path and day: time and scroll kept, as the stored rollup keeps them' );

echo "\nGroup: the window in site time, the values as stored\n";
ok( array( '2026-10-01 04:00:00', '2026-11-01 04:00:00' ) === snt_arows_utc_bounds( '2026-10-01', '2026-10-31' ), 'October in New York: EDT both ends' );
ok( array( '2026-11-01 04:00:00', '2026-11-03 05:00:00' ) === snt_arows_utc_bounds( '2026-11-01', '2026-11-02' ), 'across the November clock change' );
ok( 'Winter Park' === snt_arows_clean( 'city', ' Winter Park ', array() ) && '(unknown)' === snt_arows_clean( 'city', '', array() ) && '(invalid)' === snt_arows_clean( 'region', str_repeat( 'x', 129 ), array() ), 'city and region as named, (unknown) when blank' );
ok( '09' === snt_arows_clean( 'hour', '09', array() ) && '(invalid)' === snt_arows_clean( 'hour', '24', array() ), 'hour 00 to 23' );

echo "\nGroup: the read end to end: routed, and the answer names its source and window\n";
function add_action() {}
function snt_analytics_range_dates( $r ) { return 'all' === $r ? array( '2025-06-01', '2026-10-31' ) : array( '2026-10-' . sprintf( '%02d', 32 - (int) $r ), '2026-10-31' ); }
function sn_analytics_exact_metrics_since() { return '2026-07-11'; }
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' ); define( 'SN_ANALYTICS_DIMS_TABLE', 'sn_analytics_dims' ); define( 'ARRAY_A', 'ARRAY_A' );
class FakeDb { public $prefix = 'wp_'; public $last_error = ''; public $q = ''; public function esc_like( $s ) { return $s; } public function prepare( $sql, $args ) { $this->q = $sql; return $sql; } public function get_results( $q, $o ) { return array( array( 'city' => 'Boston', 'views' => 4, 'visits' => 2 ) ); } }
$GLOBALS['wpdb'] = new FakeDb();
require $root . '/inc/analytics-rows-fetch.php';
require $root . '/inc/abilities-analytics-rows.php';
$st = snt_ability_analytics_rows( array( 'dimensions' => array( 'city' ), 'range' => 7 ) );
ok( 'stored' === $st['source'] && '2026-10-25' === $st['from'] && '2026-10-31' === $st['to'] && 'Boston' === $st['rows'][0]['city'] && false !== strpos( $GLOBALS['wpdb']->q, 'FROM wp_sn_analytics_dims' ), 'city alone: the stored dims table, and the answer says so with its window' );
$GLOBALS['answers'] = array( array( array( 'city' => 'Boston', 'hour' => '09', 'views' => 2, 'visits' => 1 ) ), array( array( 'city' => 'Boston', 'hour' => '09', 'pageview_visits' => 1 ) ) );
$ev = snt_ability_analytics_rows( array( 'dimensions' => array( 'city', 'hour' ), 'from' => '2026-10-20', 'to' => '2026-12-31' ) );
ok( 'events' === $ev['source'] && 'custom' === $ev['range'] && '2026-10-20' === $ev['from'] && '2026-10-31' === $ev['to'] && '09' === $ev['rows'][0]['hour'], 'city by hour: Analytics Engine; a window past today ends today' );
$old = snt_ability_analytics_rows( array( 'dimensions' => array( 'hour' ), 'range' => 'all' ) );
ok( is_wp_error( $old ) && false !== strpos( $old->message, 'must start on 2026-08-03' ), 'all of history by hour: refused with the first day Analytics Engine holds' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
