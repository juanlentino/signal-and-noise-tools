<?php
/**
 * Standalone test: sn_analytics_path_window() — views and visits for ONE path
 * over a window from the durable daily table, both spellings of the path,
 * and the site-wide row count that separates "no analytics in this window"
 * from "this note had no views".
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }   // inc/analytics-rollup.php:111 evaluates it at load
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
function add_action() { return true; }
function add_filter() { return true; }
function apply_filters( $h, $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_permalink( $p ) { return 'https://example.test/notes/foo/'; }

class PW_WPDB {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();   // per-path result
	public $site = 0;         // site-wide count
	public function prepare( $sql, ...$args ) { $this->queries[] = array( $sql, $args ); return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function get_row( $sql, $out = OBJECT ) { return $this->rows; }
	public function get_var( $sql ) { return $this->site; }
}
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
$wpdb = new PW_WPDB();
$GLOBALS['wpdb'] = $wpdb;

require __DIR__ . '/../inc/analytics-rollup.php';   // SN_ANALYTICS_DAILY_TABLE + canonical path helpers' file
require __DIR__ . '/../inc/analytics-derive.php';
require __DIR__ . '/../inc/analytics-posts.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "analytics path window\n\n";

$wpdb->rows = array( 'views' => '312', 'visits' => '187', 'days' => '9' );
$wpdb->site = 40;
$r = sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' );
ok( is_array( $r ) && 312 === $r['views'] && 187 === $r['visits'] && 9 === $r['days'] && 40 === $r['site_rows'], 'views, visits, days and the site-wide row count come back as ints' );
$q = $wpdb->queries[0][0];
ok( false !== strpos( $q, "class = 'human'" ) && false !== strpos( $q, 'path IN ( %s, %s )' ), 'the per-path query is human-class and asks for BOTH spellings of the path' );
ok( array( '/notes/foo', '/notes/foo/', '2026-08-07', '2026-09-05' ) === $wpdb->queries[0][1], 'the canonical spelling and the trailing-slash spelling are both bound, then the window' );

$wpdb->queries = array();
$r = sn_analytics_path_window( '/', '2026-08-07', '2026-09-05' );
ok( array( '/', '/', '2026-08-07', '2026-09-05' ) === $wpdb->queries[0][1], 'the root path binds itself twice rather than an empty spelling' );

$wpdb->rows = array( 'views' => null, 'visits' => null, 'days' => '0' );
$wpdb->site = 0;
$r = sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' );
ok( is_array( $r ) && 0 === $r['views'] && 0 === $r['site_rows'], 'no rows anywhere reads as views 0 with site_rows 0 -- the caller can say "no analytics in this window"' );

$wpdb->rows = null;
ok( null === sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' ), 'a failed read is null, never a zero' );
// The stub answers rows again from here, so a null below can only come from the guard.
$wpdb->rows = array( 'views' => '5', 'visits' => '5', 'days' => '1' );
$wpdb->site = 3;
ok( is_array( sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' ) ), 'control: with rows back, a good input reads' );
ok( null === sn_analytics_path_window( '', '2026-08-07', '2026-09-05' ), 'an empty path is refused' );
ok( null === sn_analytics_path_window( '/notes/foo/', 'yesterday', '2026-09-05' ), 'a malformed day is refused' );
$wpdb->queries = array();
$r = sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' );
ok( false !== strpos( $wpdb->queries[1][0], "class = 'human'" ) && array( '2026-08-07', '2026-09-05' ) === $wpdb->queries[1][1], 'the site-wide count is human-class only and bound to the same window' );
$wpdb->site = null;
$r = sn_analytics_path_window( '/notes/foo/', '2026-08-07', '2026-09-05' );
ok( is_array( $r ) && null === $r['site_rows'] && 5 === $r['views'], 'a site-wide count that could not be read is null, never a 0 that reads as "no analytics"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
