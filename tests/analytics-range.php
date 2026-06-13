<?php
/**
 * Tests for range resolution + date calculation in inc/analytics-admin.php.
 * Run: php tests/analytics-range.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
define( 'SN_ANALYTICS_DIMS_TABLE', 'sn_analytics_dims' );
define( 'SN_ANALYTICS_BUCKETS_TABLE', 'sn_analytics_buckets' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

class T_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	public $var = null;
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_var( $sql ) { $this->queries[] = $sql; return $this->var; }
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
		return isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
	}
}
$GLOBALS['wpdb'] = new T_Stub_wpdb();

$GLOBALS['__t'] = array();
function get_transient( $k ) { return $GLOBALS['__t'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__t'][ $k ] = $v; return true; }
require __DIR__ . '/../inc/analytics-read.php';   // for sn_analytics_min_day
require __DIR__ . '/../inc/analytics-admin.php';

echo "\nGroup: resolve_range\n";
ok( snt_analytics_resolve_range( 365 )   === 365,   '365 is accepted' );
ok( snt_analytics_resolve_range( 'all' ) === 'all', "'all' is accepted verbatim" );
ok( snt_analytics_resolve_range( 999 )   === 7,     'unknown int → 7' );
ok( snt_analytics_resolve_range( 90 )    === 90,    '90 still accepted' );

echo "\nGroup: range_dates\n";
$now = strtotime( '2026-06-12 12:00:00 UTC' );
list( $f, $t ) = snt_analytics_range_dates( 365, $now );
ok( $t === '2026-06-12', '365: to = today' );
ok( $f === '2025-06-13', '365: from = today-364' );
$GLOBALS['wpdb']->var = '2026-05-08';
$GLOBALS['__t'] = array();
list( $f2, $t2 ) = snt_analytics_range_dates( 'all', $now );
ok( $f2 === '2026-05-08', "all: from = MIN(day)" );
ok( $t2 === '2026-06-12', 'all: to = today' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
