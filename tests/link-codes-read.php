<?php
/**
 * Tests: the Links view's reads (inc/link-codes-read.php). Per code from the
 * durable daily rollup; per visitor-day from Analytics Engine's last 90 days,
 * joined on the visitor-day hash the analytics already keep. Owner request,
 * 2026-10-31: no new storage, scanners never read as people.
 *
 * Run: php tests/link-codes-read.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function add_filter() {}
function add_action() {}
function sn_analytics_source( $from, $kind = 'pageviews' ) { return 'sn_pageviews_v2'; }
function sn_analytics_trailing_from( $days ) { return '2026-08-01'; }
function sn_analytics_rollup_window_exprs( $days, $tz = '' ) { return array( "formatDateTime(timestamp, '%Y-%m-%d', '{$tz}')", "LOWER{$days}" ); }
function sn_analytics_rollup_class_parts( $fold = true ) { return array( 'CLASSEXPR', '' ); }
function sn_analytics_excluded_path_sql() { return ' AND EXCL'; }
function sn_analytics_canonical_path( $p ) { $p = (string) $p; if ( '' === $p ) { return ''; } $t = rtrim( $p, '/' ); return '' === $t ? '/' : $t; }
function sn_analytics_valid_vday_hashes( array $h ) { return array_values( array_filter( $h, static fn( $x ) => is_string( $x ) && preg_match( '/^[0-9a-f]{16}$/', $x ) ) ); }
const SNT_BOT_SIGNAL_WEIGHTS   = array( 'webdriver' => 3, 'no_input' => 2, 'ua_mismatch' => 2, 'headless' => 1, 'tz_mismatch' => 1 );
const SNT_BOT_SIGNAL_THRESHOLD = 3;
function sn_analytics_split_range_ok( $r ) { return 1 === preg_match( "/^ AND timestamp (<|>=) toDateTime\\('[0-9: -]+'\\)$/", (string) $r ); }
function sn_analytics_clock() { return 1793480000; }
function sn_analytics_site_tz_name() { return 'America/New_York'; }
$GLOBALS['t'] = array(); $GLOBALS['aesql'] = array(); $GLOBALS['answers'] = array(); $GLOBALS['split'] = false;
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t'][ $k ] = $v; return true; }
// The production stitch's contract: one build per half, null when any half fails.
function sn_analytics_stitched_rows( callable $build, $from, $to ) {
	$halves = $GLOBALS['split'] ? array( array( 'legacy_ds', " AND timestamp < toDateTime('2026-10-05 04:00:00')" ), array( 'v2_ds', " AND timestamp >= toDateTime('2026-10-05 04:00:00')" ) ) : array( array( 'legacy_ds', '' ) );
	$out = array();
	foreach ( $halves as $h ) {
		$sql = (string) $build( $h[0], $h[1] );
		$GLOBALS['aesql'][] = $sql;
		$a = array_shift( $GLOBALS['answers'] );
		if ( ! is_array( $a ) ) { return null; }
		$out[] = $a;
	}
	return $out;
}
function sn_bot_signals_score( array $row ) { $s = 0; foreach ( SNT_BOT_SIGNAL_WEIGHTS as $n => $w ) { $s += (int) ( (float) ( $row[ $n ] ?? 0 ) > 0 ) * $w; } return $s; }
$root = dirname( __DIR__ );
require $root . '/inc/link-codes.php';
require $root . '/inc/link-codes-read.php';

echo "\nGroup: per code, from the durable rollup\n";
$sum = sn_link_codes_summary( array(
	array( 'path' => '/r/hera', 'class' => 'human', 'first_day' => '2026-10-20', 'last_day' => '2026-10-29', 'views' => '3', 'visitor_days' => '2' ),
	array( 'path' => '/r/hera/', 'class' => 'human', 'first_day' => '2026-10-18', 'last_day' => '2026-10-21', 'views' => '1', 'visitor_days' => '1' ),
	array( 'path' => '/r/hera', 'class' => 'suspect', 'first_day' => '2026-10-17', 'last_day' => '2026-10-17', 'views' => '1', 'visitor_days' => '1' ),
	array( 'path' => '/r/zed', 'class' => 'bot', 'first_day' => '2026-10-30', 'last_day' => '2026-10-30', 'views' => '2', 'visitor_days' => null ),
	array( 'path' => '/r/Bad', 'class' => 'human', 'first_day' => '2026-10-30', 'last_day' => '2026-10-30', 'views' => '9', 'visitor_days' => '9' ),
) );
ok( array( 'zed', 'hera' ) === array_column( $sum, 'code' ), 'one row per code, both spellings folded, a malformed code dropped, last seen first' );
ok( array( 'code' => 'hera', 'first_seen' => '2026-10-17', 'last_seen' => '2026-10-29', 'views' => 5, 'human' => 3, 'suspect' => 1, 'bot' => 0 ) === $sum[1], 'first and last seen across classes, views summed, visitor-days by class' );
ok( 0 === $sum[0]['bot'] && 2 === $sum[0]['views'], 'a NULL visitor-day count is 0, not a fatal; its views still count' );
$sql = sn_link_codes_summary_sql( 'wp_sn_analytics_daily' );
ok( false !== strpos( $sql, 'SUM(pageview_visits) AS visitor_days' ) && false !== strpos( $sql, 'FROM wp_sn_analytics_daily' ) && false !== strpos( $sql, 'path LIKE %s' ) && false !== strpos( $sql, 'day BETWEEN %s AND %s' ) && false !== strpos( $sql, 'GROUP BY path, class' ), 'the durable read: coded paths in the range, by path and class' );

echo "\nGroup: per visitor-day, Analytics Engine\n";
$sql = sn_link_codes_visits_sql( 90, 'America/New_York' );
ok( false !== strpos( $sql, "blob1 = 'pv'" ) && false !== strpos( $sql, "blob2 LIKE '/r/%'" ) && false !== strpos( $sql, 'LOWER90' ), 'page views on coded paths, last 90 days' );
ok( false !== strpos( $sql, "'America/New_York') AS day" ), 'the day in site time' );
ok( false !== strpos( $sql, 'index1 AS vid' ) && false !== strpos( $sql, 'blob11 AS city' ) && false !== strpos( $sql, 'blob4 AS country' ) && false !== strpos( $sql, 'blob5 AS device' ) && false !== strpos( $sql, 'CLASSEXPR AS class' ), 'the visitor-day, city, country, device, and the read-time class' );
ok( false !== strpos( $sql, 'GROUP BY vid, day, path, city, country, device, class' ) && false !== strpos( $sql, 'LIMIT ' . SN_LINK_CODES_LIMIT ), 'grouped and capped' );
$half = sn_link_codes_visits_sql( 90, '', 'v2_ds', " AND timestamp >= toDateTime('2026-10-05 04:00:00')" );
ok( false !== strpos( $half, 'FROM v2_ds' ) && false !== strpos( $half, "LOWER90 AND timestamp >= toDateTime('2026-10-05 04:00:00')" ), 'one half of a stitched read: its dataset and its side of the split' );
ok( false === strpos( sn_link_codes_visits_sql( 90, '', 'x', ' OR 1=1' ), 'OR 1=1' ), 'a range that is not a split is dropped' );
$psql = sn_link_codes_pages_sql( array( 'aaaaaaaaaaaaaaaa', "x' OR 1=1 --", 'bbbbbbbbbbbbbbbb' ), 90, 'America/New_York' );
ok( false !== strpos( $psql, "index1 IN ('aaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbb')" ) && false === strpos( $psql, 'OR 1=1' ), 'the rest of each visitor-day: a literal IN list of validated hashes only' );
ok( false === strpos( $psql, "WHERE blob1" ) && false === strpos( $psql, "AND blob1 = 'pv'" ) && false !== strpos( $psql, "sum(if(blob1 = 'pv', _sample_interval, 0)) AS views" ), 'every event row (no_input rides on scroll and time rows), page views counted apart' );
ok( false !== strpos( $psql, 'max(bitAnd(toUInt32(double8), 2)) AS no_input' ) && false !== strpos( $psql, 'max(bitAnd(toUInt32(double8), 32)) AS present' ), 'the bot-signal bits and their presence bit, over all of it' );
ok( false !== strpos( $psql, ' AND EXCL' ), 'the excluded paths stay out' );
ok( strlen( sn_link_codes_pages_sql( array_map( static fn( $i ) => sprintf( '%016x', $i ), range( 1, SN_LINK_CODES_VID_CHUNK ) ), 90, 'America/New_York', 'sn_pageviews_v2', " AND timestamp >= toDateTime('2026-10-05 04:00:00')" ) ) < 10000, 'a full chunk stays under the 10,000-character SQL cap' );
ok( '' === sn_link_codes_pages_sql( array( 'nope' ), 90 ), 'no valid hash: no query' );

$rows = array(
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'day' => '2026-10-30', 'path' => '/r/hera', 'city' => 'New York', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'views' => 1 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'day' => '2026-10-30', 'path' => '/r/hera/', 'city' => 'New York', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'views' => 1 ),
	array( 'vid' => 'bbbbbbbbbbbbbbbb', 'day' => '2026-10-30', 'path' => '/r/hera', 'city' => 'Ashburn', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'views' => 1 ),
	array( 'vid' => 'cccccccccccccccc', 'day' => '2026-10-31', 'path' => '/r/zed', 'city' => '', 'country' => 'DE', 'device' => 'mobile', 'class' => 'suspect', 'views' => 1 ),
	array( 'vid' => 'dddddddddddddddd', 'day' => '2026-10-29', 'path' => '/r/Bad', 'city' => 'X', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'views' => 1 ),
);
$pages = array(
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/r/hera', 'views' => 2, 'present' => 32 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/about/', 'views' => 1, 'present' => 32 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/notes/a-note', 'views' => 3, 'present' => 32 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/notes/a-note/', 'views' => 1, 'present' => 32 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/feed/', 'views' => 0, 'present' => 32 ),
	// The scanner: headless on its page view, no_input on its later time row. Scored 3 together, exactly the threshold.
	array( 'vid' => 'bbbbbbbbbbbbbbbb', 'path' => '/r/hera', 'views' => 1, 'headless' => 8, 'present' => 32 ),
	array( 'vid' => 'bbbbbbbbbbbbbbbb', 'path' => '/r/hera', 'views' => 0, 'no_input' => 2, 'present' => 32 ),
	array( 'vid' => 'cccccccccccccccc', 'path' => '/r/zed', 'views' => 1, 'webdriver' => 1, 'present' => 32 ),
);
$v = sn_link_codes_visits( $rows, $pages );
ok( array( 'zed', 'hera', 'hera' ) === array_column( $v, 'code' ), 'one row per visitor-day and code, newest day first, a malformed code dropped' );
$hera = 'human' === $v[1]['class'] ? $v[1] : $v[2];
$scan = 'human' === $v[1]['class'] ? $v[2] : $v[1];
ok( 'New York' === $hera['city'] && 'US' === $hera['country'] && 'desktop' === $hera['device'] && 2 === $hera['views'], 'place and device as stored; both spellings summed' );
ok( array( '/notes/a-note', '/about' ) === $hera['also'], 'the rest of that visitor-day: pages viewed, most first, spellings folded, the code and view-less rows left out' );
ok( 'likely automated' === $scan['class'], 'signals from different rows of one visitor-day add up: score 3, at the threshold, reads likely automated (an email scanner)' );
ok( 'suspect' === $v[0]['class'], 'a stored suspect stays suspect, whatever its signals' );
ok( array() === array_filter( $v, static fn( $m ) => array_key_exists( 'vid', $m ) ) && false === strpos( (string) json_encode( $v ), 'aaaaaaaaaaaaaaaa' ), 'no row carries the visitor-day code' );
ok( array() === sn_link_codes_visits( array(), array() ), 'nothing opened: an empty list' );
$old = sn_link_codes_visits( array( array( 'vid' => 'eeeeeeeeeeeeeeee', 'day' => '2026-10-01', 'path' => '/r/old', 'class' => 'human', 'views' => 1 ) ), array( array( 'vid' => 'eeeeeeeeeeeeeeee', 'path' => '/r/old', 'webdriver' => 1, 'headless' => 8, 'present' => 0, 'views' => 1 ) ) );
ok( 'human' === $old[0]['class'], 'without the presence bit the signals were never sent: no verdict from them' );
$two = sn_link_codes_visits( array( array( 'vid' => 'ffffffffffffffff', 'day' => '2026-10-01', 'path' => '/r/x1', 'class' => 'human', 'views' => 1 ) ), array( array( 'vid' => 'ffffffffffffffff', 'path' => '/r/x1', 'no_input' => 2, 'present' => 32, 'views' => 1 ) ) );
ok( 'human' === $two[0]['class'], 'below the threshold (score 2) stays human' );

echo "\nGroup: the reads\n";
$GLOBALS['split'] = true;
$GLOBALS['answers'] = array(
	array( $rows[0] ), array( $rows[2] ),           // visits: legacy half, v2 half
	array( $pages[0], $pages[1] ), array( $pages[5], $pages[6] ), // pages: both halves
);
$r = sn_link_codes_visits_read();
ok( 2 === count( $r ) && 2 === count( array_filter( $GLOBALS['aesql'], static fn( $q ) => false !== strpos( $q, 'FROM v2_ds' ) ) ), 'both halves of the generation split are read and joined: opens keep showing after the legacy dataset stops' );
ok( in_array( 'likely automated', array_column( $r, 'class' ), true ), 'a visitor-day whose rows sit in different halves is still scored as one' );
ok( $r === get_transient( SN_LINK_CODES_CACHE ), 'cached' );
ok( false === strpos( (string) json_encode( get_transient( SN_LINK_CODES_CACHE ) ), 'aaaaaaaaaaaaaaaa' ), 'the cached answer carries no visitor-day code either' );
$GLOBALS['aesql'] = array();
ok( $r === sn_link_codes_visits_read() && array() === $GLOBALS['aesql'], 'a cached answer reads nothing' );
ok( false === sn_link_codes_visits_capped(), 'a read under the caps is not capped' );
$GLOBALS['t'] = array(); $GLOBALS['split'] = false;
$GLOBALS['answers'] = array( array_fill( 0, SN_LINK_CODES_LIMIT, $rows[0] ), array( $pages[0] ) );
ok( is_array( sn_link_codes_visits_read() ) && true === sn_link_codes_visits_capped(), 'a visits read that reaches its cap says so: older opens are not listed' );
$GLOBALS['t'] = array();
$GLOBALS['answers'] = array( array( $rows[0] ), array_fill( 0, SN_LINK_CODES_PAGES_LIMIT, $pages[0] ) );
ok( is_array( sn_link_codes_visits_read() ) && true === sn_link_codes_visits_capped(), 'a pages read that reaches its cap says so: a visitor-day can miss its signals' );
$GLOBALS['t'] = array(); $GLOBALS['split'] = false; $GLOBALS['answers'] = array( null );
ok( null === sn_link_codes_visits_read() && array() === $GLOBALS['t'], 'a failed read is null, and nothing is cached' );
$GLOBALS['answers'] = array( array( $rows[0] ), null );
ok( null === sn_link_codes_visits_read(), 'a failed pages read is null too, never a list without its signals' );

class FakeDb { public $prefix = 'wp_'; public $last_error = ''; public $q = ''; public $rows = array();
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, ...$args ) { $this->q = vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); return $this->q; }
	public function get_results( $q, $o ) { return $this->rows; } }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'SN_ANALYTICS_DAILY_TABLE' ) ) { define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' ); }
$GLOBALS['wpdb'] = new FakeDb();
$GLOBALS['wpdb']->rows = array( array( 'path' => '/r/hera', 'class' => 'human', 'first_day' => '2026-10-01', 'last_day' => '2026-10-02', 'views' => 2, 'visitor_days' => 1 ) );
$s = sn_link_codes_summary_read( '2026-10-01', '2026-10-31' );
ok( 'hera' === ( $s[0]['code'] ?? '' ) && false !== strpos( $GLOBALS['wpdb']->q, "LIKE '/r/%'" ) && false !== strpos( $GLOBALS['wpdb']->q, "BETWEEN '2026-10-01' AND '2026-10-31'" ), 'the durable read: the coded paths in the range' );
$GLOBALS['wpdb']->last_error = 'gone';
ok( null === sn_link_codes_summary_read( '2026-10-01', '2026-10-31' ), 'a failed durable read is null, not an empty table' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
