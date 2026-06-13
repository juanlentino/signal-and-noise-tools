<?php
/**
 * Tests for inc/analytics-read.php — sn_analytics_min_day() All-time lower bound.
 * Run: php tests/analytics-min-day.php
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

require __DIR__ . '/../inc/analytics-read.php';

echo "\nGroup: min_day\n";
$GLOBALS['wpdb']->var = '2026-05-08';
ok( sn_analytics_min_day() === '2026-05-08', 'returns MIN(day) from the table' );
ok( strpos( end( $GLOBALS['wpdb']->queries ), 'MIN(day)' ) !== false, 'SQL uses MIN(day)' );
ok( get_transient( 'sn_analytics_min_day' ) === '2026-05-08', 'caches the result in a transient' );

$GLOBALS['__t'] = array();
$GLOBALS['wpdb']->var = null;
ok( sn_analytics_min_day() === gmdate( 'Y-m-d' ), 'empty table falls back to today' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
