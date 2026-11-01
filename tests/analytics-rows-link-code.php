<?php
/**
 * Tests: link_code, a dimension of the stored analytics rows (local and the
 * remote twin, contract 25). The code of a coded résumé link (/r/CODE, see
 * inc/link-codes.php), read out of the stored path: nothing new is stored.
 * Owner request, 2026-10-31.
 *
 * Run: php tests/analytics-rows-link-code.php
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
define( 'SN_ANALYTICS_DIMS_TABLE', 'sn_analytics_dims' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } public function get_error_code() { return $this->code; } }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function add_filter() {}
function add_action() {}
function snt_analytics_range_is_valid( $r ) { return in_array( $r, array( 7, 14, 30, 90, 365, 'all' ), true ); }
function sn_analytics_canonical_path( $p ) { $p = (string) $p; if ( '' === $p ) { return ''; } $t = rtrim( $p, '/' ); return '' === $t ? '/' : $t; }
function sn_analytics_canonical_path_sql( $col = 'path' ) { return "CANON({$col})"; }
class FakeDb {
	public $prefix = 'wp_'; public $last_error = ''; public $sql = ''; public $args = array();
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $sql, $args ) { $this->sql = $sql; $this->args = $args; return $sql; }
	public function get_results( $sql, $o ) { return array(); }
}
$GLOBALS['wpdb'] = new FakeDb();
$root = dirname( __DIR__ );
require $root . '/inc/link-codes.php';
require $root . '/inc/analytics-rows.php';
require $root . '/inc/analytics-rows-fetch.php';
require $root . '/inc/abilities-analytics-rows.php';

echo "\nGroup: validation\n";
$v = static fn( $in ) => snt_arows_validate( $in );
ok( ! is_wp_error( $v( array( 'dimensions' => array( 'link_code' ) ) ) ), 'link_code alone' );
ok( ! is_wp_error( $v( array( 'dimensions' => array( 'link_code', 'day' ) ) ) ), 'link_code with day' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'link_code', 'path' ) ) ) ), 'link_code with path: refused, no other pair is stored' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'link_code' ), 'referrer' => 'x.com' ) ) ), 'a referrer filter: refused' );
ok( is_wp_error( $v( array( 'dimensions' => array( 'link_code' ), 'path' => '/r/hera' ) ) ), 'a path filter: refused (path or day only)' );
ok( ! is_wp_error( $v( array( 'dimensions' => array( 'link_code' ), 'sort' => 'time' ) ) ), 'time sort: allowed, a coded path is a page' );
ok( false !== strpos( (string) ( is_wp_error( $e = $v( array( 'dimensions' => array( 'nope' ) ) ) ) ? $e->code : '' ), 'ability_invalid_input' ), 'an unknown dimension is still refused' );

echo "\nGroup: the stored read\n";
$q = $v( array( 'dimensions' => array( 'link_code', 'day' ) ) );
snt_arows_fetch( $q, '2026-10-01', '2026-10-31' );
$sql = $GLOBALS['wpdb']->sql;
ok( false !== strpos( $sql, 'FROM wp_sn_analytics_daily' ) && false !== strpos( $sql, 'CANON(path) AS link_code' ), 'from the daily rollup, the stored path as link_code' );
ok( false !== strpos( $sql, 'AND path LIKE %s' ) && in_array( '/r/%', $GLOBALS['wpdb']->args, true ), 'coded paths only (a prepared LIKE)' );
ok( false !== strpos( $sql, 'GROUP BY CANON(path), day' ), 'grouped by path and day' );

echo "\nGroup: the answer\n";
$out = snt_arows_shape( array(
	array( 'link_code' => '/r/hera', 'views' => 2, 'visits' => 1, 'pageview_visits' => 1 ),
	array( 'link_code' => '/r/hera/', 'views' => 1, 'visits' => 1, 'pageview_visits' => 1 ),
	array( 'link_code' => '/r/zed', 'views' => 1, 'visits' => 1, 'pageview_visits' => 1 ),
	array( 'link_code' => '/r/Bad_Code', 'views' => 1, 'visits' => 1, 'pageview_visits' => 1 ),
), $v( array( 'dimensions' => array( 'link_code' ) ) ), array() );
ok( array( 'hera', '(invalid)', 'zed' ) === array_column( $out['rows'], 'link_code' ), 'the code itself, both spellings merged, most views first then by name; a malformed code is (invalid)' );
ok( 3 === $out['rows'][0]['views'] && 2 === $out['rows'][0]['pageview_visits'], 'counts summed per code' );

echo "\nGroup: the schema and the words\n";
$schema = snt_analytics_rows_output_schema();
ok( array( 'type' => 'string' ) === ( $schema['properties']['rows']['items']['properties']['link_code'] ?? null ), 'each row may carry link_code (a string)' );
ok( false !== strpos( snt_analytics_rows_description(), 'link_code' ), 'the description names link_code' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
