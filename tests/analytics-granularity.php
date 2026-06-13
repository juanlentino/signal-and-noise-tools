<?php
/**
 * Tests for inc/analytics-read.php — granularity helpers.
 * Run: php tests/analytics-granularity.php
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

require __DIR__ . '/../inc/analytics-read.php';

echo "\nGroup: granularity\n";
ok( sn_analytics_granularity( 7 )   === 'day',  '7d → day' );
ok( sn_analytics_granularity( 90 )  === 'day',  '90d → day (boundary)' );
ok( sn_analytics_granularity( 91 )  === 'week', '91d → week' );
ok( sn_analytics_granularity( 365 ) === 'week', '365d → week' );

echo "\nGroup: bucket_expr\n";
ok( sn_analytics_bucket_expr( 'day' )  === 'day', "day → 'day'" );
ok( sn_analytics_bucket_expr( 'week' ) === 'DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)', 'week → Monday floor expr' );
ok( sn_analytics_bucket_expr( 'garbage' ) === 'day', 'unknown granularity defaults to day' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
