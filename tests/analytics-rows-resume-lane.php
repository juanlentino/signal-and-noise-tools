<?php
/**
 * Tests: the résumé lane in the analytics rows read (owner-only measurement,
 * 2026-11-02). The theme beacon sends the /resume lane a view showed on every
 * beacon of it; the analytics worker (1.28.0) stores it as double17 of the
 * second-generation rows, 1 music, 2 operations, 0 anything else. The rows
 * read groups by it as resume_lane, from Analytics Engine only, and names
 * only the two lanes; no public surface reads it.
 *
 * Run: php tests/analytics-rows-resume-lane.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_RANGES', array( 7, 14, 30, 90, 365 ) );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_message() { return $this->message; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function add_filter() {}
function add_action() {}
function wp_timezone() { return new DateTimeZone( 'America/New_York' ); }
function snt_analytics_range_is_valid( $r ) { return in_array( $r, array( 7, 14, 30, 90, 365, 'all' ), true ); }
function snt_analytics_is_ymd( $s ) { return is_string( $s ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ); }
function sn_analytics_canonical_path( $p ) { $t = rtrim( (string) $p, '/' ); return '' === $t ? '/' : $t; }
function sn_analytics_source( $day, $kind = 'pageviews' ) { return 'sn_pageviews_v2'; }
function sn_analytics_class_where( $c ) { return "CLASS({$c})"; }
function sn_analytics_excluded_path_sql() { return ' AND EXCL'; }
function sn_analytics_split_range_ok( $r ) { return false; }
function sn_analytics_site_tz_name() { return 'America/New_York'; }
$GLOBALS['clean_from'] = '2026-10-17';
function sn_analytics_v2_clean_from() { return $GLOBALS['clean_from']; }
$GLOBALS['answers'] = array();
function sn_analytics_stitched_rows( callable $build, $from, $to ) {
	$GLOBALS['built'][] = $build( 'sn_pageviews_v2', '' );
	return array( array_shift( $GLOBALS['answers'] ) );
}
$root = dirname( __DIR__ );
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-referrer-kind.php';
require $root . '/inc/analytics-rows.php';
require $root . '/inc/analytics-rows-events.php';
require $root . '/inc/analytics-fields.php';
$v    = static fn( $in ) => snt_arows_validate( $in );
$plan = static fn( $in ) => snt_arows_plan( $v( $in ), '2026-10-20', '2026-11-02' );

echo "\nGroup: what a query may ask\n";
ok( in_array( 'resume_lane', SNT_AROWS_DIMENSIONS, true ), 'resume_lane is a dimension' );
foreach ( array( array( 'resume_lane' ), array( 'path', 'resume_lane' ), array( 'resume_lane', 'day' ), array( 'resume_lane', 'country', 'device', 'hour' ) ) as $d ) {
	ok( 'events' === $plan( array( 'dimensions' => $d ) ), implode( '+', $d ) . ': Analytics Engine, never the stored rollups' );
}
$st = $plan( array( 'dimensions' => array( 'resume_lane' ), 'source' => 'stored' ) );
ok( is_wp_error( $st ) && false !== strpos( $st->message, 'source "events"' ), 'source stored: refused, the rollups do not keep it' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'resume_lane', 'entry' ) ) ) ) && is_wp_error( $v( array( 'dimensions' => array( 'resume_lane' ), 'source' => 'sessions' ) ) ), 'not a session dimension: refused with entry and from source sessions' );
foreach ( array( array( 'resume_lane' ), array( 'path', 'resume_lane' ), array( 'resume_lane', 'day' ) ) as $d ) {
	ok( ! is_wp_error( $v( array( 'dimensions' => $d, 'sort' => 'time' ) ) ), 'time sort accepted: ' . implode( '+', $d ) );
}
ok( is_wp_error( $v( array( 'dimensions' => array( 'resume_lane', 'country' ), 'sort' => 'time' ) ) ) && is_wp_error( $v( array( 'dimensions' => array( 'resume_lane' ), 'referrer' => 'google.com', 'sort' => 'time' ) ) ), 'time sort refused with country, and under a referrer filter' );
$GLOBALS['clean_from'] = '';
$un = $plan( array( 'dimensions' => array( 'resume_lane' ) ) );
ok( is_wp_error( $un ) && false !== strpos( $un->message, 'second-generation' ), 'while the second generation is not verified: refused, never every view read as (unknown)' );
ok( 'events' === $plan( array( 'dimensions' => array( 'country', 'device' ) ) ), 'the refusal is the lane\'s alone' );
$GLOBALS['clean_from'] = '2026-10-17';

echo "\nGroup: the Analytics Engine query\n";
$sql = snt_arows_events_sql( $v( array( 'dimensions' => array( 'path', 'resume_lane' ), 'path' => '/resume/' ) ), 'a', 'b', 'UTC', false );
ok( false !== strpos( $sql, 'toUInt32(double17) AS resume_lane' ) && false !== strpos( $sql, 'GROUP BY path, resume_lane' ), 'the lane code, grouped by its alias' );
ok( false !== strpos( $sql, "(blob2 = '/resume' OR blob2 = '/resume/')" ), 'a path filter narrows it to the résumé' );

echo "\nGroup: only the two lanes are named\n";
foreach ( array( array( '1', 'music' ), array( 1, 'music' ), array( '2', 'operations' ), array( '0', '(unknown)' ), array( '', '(invalid)' ), array( '3', '(invalid)' ), array( '1.5', '(invalid)' ), array( 'music', '(invalid)' ), array( '<script>', '(invalid)' ) ) as $c ) {
	ok( $c[1] === snt_arows_clean( 'resume_lane', $c[0], array() ), 'stored ' . var_export( $c[0], true ) . ' reads ' . $c[1] );
}

echo "\nGroup: the read, end to end\n";
$GLOBALS['answers'] = array(
	array(
		array( 'resume_lane' => '1', 'views' => 3, 'visits' => 2, 'time_sum' => 9000, 'time_events' => 3, 'scroll_events' => 6 ),
		array( 'resume_lane' => '2', 'views' => 2, 'visits' => 2, 'time_sum' => 4000, 'time_events' => 2, 'scroll_events' => 8 ),
		array( 'resume_lane' => '0', 'views' => 40, 'visits' => 20, 'time_sum' => 1000, 'time_events' => 10, 'scroll_events' => 40 ),
		array( 'resume_lane' => '7', 'views' => 1, 'visits' => 1, 'time_sum' => 0, 'time_events' => 0, 'scroll_events' => 0 ),
	),
	array( array( 'resume_lane' => '1', 'pageview_visits' => 2 ), array( 'resume_lane' => '2', 'pageview_visits' => 1 ) ),
);
$q    = $v( array( 'dimensions' => array( 'resume_lane' ) ) );
$read = snt_arows_events_fetch( $q, '2026-10-20', '2026-11-02' );
ok( 9000.0 === $read['rows'][0]['time_sum'] && 6.0 === $read['rows'][0]['scroll_events'], 'time and scroll kept per lane: every beacon of a view carries its lane' );
$out  = snt_arows_shape( $read['rows'], $q, array() );
$by   = array_column( $out['rows'], null, 'resume_lane' );
$keys = array_keys( $by );
sort( $keys );
ok( array( '(invalid)', '(unknown)', 'music', 'operations' ) === $keys, 'music, operations, (unknown) and (invalid): no stored value passes through' );
ok( 3 === $by['music']['views'] && 2 === $by['music']['pageview_visits'] && 3000.0 === $by['music']['time_avg_per_view'] && 50.0 === $by['music']['scroll_avg_per_view'], 'music: views, visitor-days, time and scroll per view' );
ok( 2 === $by['operations']['views'] && 1 === $by['operations']['pageview_visits'] && 100.0 === $by['operations']['scroll_avg_per_view'], 'operations: its own row' );

echo "\nGroup: the registry and the owner's doors only\n";
$f = array_column( snt_analytics_fields()['dimensions'], null, 'name' );
ok( isset( $f['resume_lane'] ) && array( 'events' ) === $f['resume_lane']['sources'] && 'music | operations | (unknown)' === $f['resume_lane']['unit'], 'the registry names it, from Analytics Engine only' );
// Owner-only: only the rows read, its registry and its doors may name the lane. A public surface (/stats) reading it adds a file here.
$named = array();
foreach ( glob( $root . '/inc/{,*/}*.php', GLOB_BRACE ) as $file ) {
	$src = (string) file_get_contents( $file );
	if ( false !== strpos( $src, "'resume_lane'" ) || false !== strpos( $src, 'double17' ) ) {
		$named[] = substr( $file, strlen( $root ) + 1 );
	}
}
sort( $named );
ok( array( 'inc/abilities-analytics-rows.php', 'inc/analytics-fields.php', 'inc/analytics-resume-lanes.php', 'inc/analytics-rows-events.php', 'inc/analytics-rows.php', 'inc/mcp/mcp-remote-contract.php' ) === $named, 'only the owner\'s analytics reads name the lane: ' . implode( ', ', $named ) );
ok( false === strpos( (string) file_get_contents( $root . '/inc/public-stats.php' ), 'lane' ), 'the public /stats page reads no lane' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
