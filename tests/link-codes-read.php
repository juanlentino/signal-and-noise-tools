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
ok( false !== strpos( $sql, 'FROM wp_sn_analytics_daily' ) && false !== strpos( $sql, 'path LIKE %s' ) && false !== strpos( $sql, 'day BETWEEN %s AND %s' ) && false !== strpos( $sql, 'GROUP BY path, class' ), 'the durable read: coded paths in the range, by path and class' );

echo "\nGroup: per visitor-day, Analytics Engine\n";
$sql = sn_link_codes_visits_sql( 90, 'America/New_York' );
ok( false !== strpos( $sql, "blob1 = 'pv'" ) && false !== strpos( $sql, "blob2 LIKE '/r/%'" ) && false !== strpos( $sql, 'LOWER90' ), 'page views on coded paths, last 90 days' );
ok( false !== strpos( $sql, 'index1 AS vid' ) && false !== strpos( $sql, 'blob11 AS city' ) && false !== strpos( $sql, 'blob4 AS country' ) && false !== strpos( $sql, 'blob5 AS device' ) && false !== strpos( $sql, 'CLASSEXPR AS class' ), 'the visitor-day, city, country, device, and the read-time class' );
ok( false !== strpos( $sql, 'max(bitAnd(toUInt32(double8), 1)) AS webdriver' ) && false !== strpos( $sql, 'max(bitAnd(toUInt32(double8), 32)) AS present' ), 'the observe-only bot-signal bits and their presence bit' );
ok( false !== strpos( $sql, 'GROUP BY vid, day, path, city, country, device, class' ) && false !== strpos( $sql, 'LIMIT ' . SN_LINK_CODES_LIMIT ), 'grouped and capped' );
$psql = sn_link_codes_pages_sql( array( 'aaaaaaaaaaaaaaaa', "x' OR 1=1 --", 'bbbbbbbbbbbbbbbb' ), 90 );
ok( false !== strpos( $psql, "index1 IN ('aaaaaaaaaaaaaaaa','bbbbbbbbbbbbbbbb')" ) && false === strpos( $psql, 'OR 1=1' ), 'the rest of each visitor-day: a literal IN list of validated hashes only' );
ok( '' === sn_link_codes_pages_sql( array( 'nope' ), 90 ), 'no valid hash: no query' );

$rows = array(
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'day' => '2026-10-30', 'path' => '/r/hera', 'city' => 'New York', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'webdriver' => 0, 'no_input' => 0, 'present' => 32, 'views' => 1 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'day' => '2026-10-30', 'path' => '/r/hera/', 'city' => 'New York', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'webdriver' => 0, 'no_input' => 0, 'present' => 32, 'views' => 1 ),
	array( 'vid' => 'bbbbbbbbbbbbbbbb', 'day' => '2026-10-30', 'path' => '/r/hera', 'city' => 'Ashburn', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'webdriver' => 1, 'headless' => 8, 'present' => 32, 'views' => 1 ),
	array( 'vid' => 'cccccccccccccccc', 'day' => '2026-10-31', 'path' => '/r/zed', 'city' => '', 'country' => 'DE', 'device' => 'mobile', 'class' => 'suspect', 'present' => 0, 'views' => 1 ),
	array( 'vid' => 'dddddddddddddddd', 'day' => '2026-10-29', 'path' => '/r/Bad', 'city' => 'X', 'country' => 'US', 'device' => 'desktop', 'class' => 'human', 'present' => 32, 'views' => 1 ),
);
$pages = array(
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/r/hera', 'views' => 2 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/about/', 'views' => 1 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/notes/a-note', 'views' => 3 ),
	array( 'vid' => 'aaaaaaaaaaaaaaaa', 'path' => '/notes/a-note/', 'views' => 1 ),
);
$v = sn_link_codes_visits( $rows, $pages );
ok( array( 'zed', 'hera', 'hera' ) === array_column( $v, 'code' ), 'one row per visitor-day and code, newest day first, a malformed code dropped' );
$hera = $v[1]['class'] === 'human' ? $v[1] : $v[2];
$scan = $v[1]['class'] === 'human' ? $v[2] : $v[1];
ok( 'New York' === $hera['city'] && 'US' === $hera['country'] && 'desktop' === $hera['device'] && 2 === $hera['views'], 'place and device as stored; both spellings summed' );
ok( array( '/notes/a-note', '/about' ) === $hera['also'], 'the rest of that visitor-day: other pages, most viewed first, spellings folded, the code itself left out' );
ok( 'likely automated' === $scan['class'], 'a human-class visitor-day whose bot signals reach the threshold reads as likely automated, not a person (an email scanner)' );
ok( 'suspect' === $v[0]['class'] && array() === $v[0]['also'], 'a stored suspect stays suspect; no signals sent is not a verdict' );
ok( array() === sn_link_codes_visits( array(), array() ), 'nothing opened: an empty list' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
