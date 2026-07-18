<?php
/**
 * Tests for sn_analytics_dimension_series() — batched per-dimension view series.
 * Run: php tests/analytics-dim-series.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DIMS_TABLE', 'sn_analytics_dims' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'SN_ANALYTICS_DIM_COLUMNS' ) ) {
	define( 'SN_ANALYTICS_DIM_COLUMNS', array(
		'referrer' => 'blob3',
		'country'  => 'blob4',
		'device'   => 'blob5',
		'browser'  => 'blob8',
		'os'       => 'blob9',
	) );
}
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

class DS_Stub_wpdb {
	public $prefix  = 'wp_';
	public $queries = array();
	public $rows    = array();
	// v9.68.1: model the REAL wpdb error channel — query() flush()es last_error
	// per query; a FAILED read is [] from get_results(ARRAY_A) WITH last_error set.
	public $last_error = '';
	public $fail_reads = false;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d': return (string) (int) $a;
				case '%f': return (string) (float) $a;
				default:   return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}

	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = "Table 'wp_sn_analytics_dims' doesn't exist";
			return array();
		}

		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) {
			return array();
		}
		$table = $tm[1];
		$rows  = isset( $this->rows[ $table ] ) ? $this->rows[ $table ] : array();

		// Filter by class.
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cm ) {
				return (string) ( $r['class'] ?? 'human' ) === $cm[1];
			} ) );
		}

		// Filter by dim.
		if ( preg_match( "/dim = '([^']*)'/", $sql, $dm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $dm ) {
				return (string) ( $r['dim'] ?? '' ) === $dm[1];
			} ) );
		}

		// Filter IN list for value.
		if ( preg_match( "/value IN \(([^)]+)\)/", $sql, $vm ) ) {
			$quoted = array_map( 'trim', explode( ',', $vm[1] ) );
			$vals   = array_map( function ( $q ) { return trim( $q, "'" ); }, $quoted );
			$rows   = array_values( array_filter( $rows, function ( $r ) use ( $vals ) {
				return in_array( (string) ( $r['value'] ?? '' ), $vals, true );
			} ) );
		}

		// GROUP BY expr, value → aggregate per (bucket, value).
		if ( stripos( $sql, 'GROUP BY' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$v   = (string) $r['value'];
				$day = (string) $r['day'];
				$key = $day . '|' . $v;
				if ( ! isset( $agg[ $key ] ) ) {
					$agg[ $key ] = array( 'day' => $day, 'value' => $v, 'views' => 0 );
				}
				$agg[ $key ]['views'] += (int) $r['views'];
			}
			ksort( $agg );
			return array_values( $agg );
		}

		return $rows;
	}
}
$GLOBALS['wpdb'] = new DS_Stub_wpdb();

require __DIR__ . '/../inc/analytics-read.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$GLOBALS['wpdb']->rows['wp_sn_analytics_dims'] = array(
	array( 'day' => '2026-06-10', 'dim' => 'browser', 'value' => 'Chrome',  'class' => 'human', 'views' => 5 ),
	array( 'day' => '2026-06-11', 'dim' => 'browser', 'value' => 'Chrome',  'class' => 'human', 'views' => 7 ),
	array( 'day' => '2026-06-11', 'dim' => 'browser', 'value' => 'Firefox', 'class' => 'human', 'views' => 2 ),
);

echo "\nGroup: dimension_series\n";
$map = sn_analytics_dimension_series( 'browser', array( 'Chrome', 'Firefox' ), '2026-06-01', '2026-06-12', 'human', 'day' );
$sql = end( $GLOBALS['wpdb']->queries );
ok( substr_count( strtoupper( $sql ), 'SELECT' ) === 1, 'single batched query (no N+1)' );
ok( strpos( $sql, "value IN ('Chrome','Firefox')" ) !== false, 'values batched via IN list' );
ok( isset( $map['Chrome'] ) && isset( $map['Firefox'] ), 'returns a per-value series map' );
ok( $map['Chrome'][1]['views'] === 7, 'series carries per-bucket views' );
ok( sn_analytics_dimension_series( 'browser', array(), 'a', 'b' ) === array(), 'empty value list → empty map (no query)' );

echo "\nGroup: v9.68.1 null-on-failure contract\n";
$GLOBALS['wpdb']->fail_reads = true;
ok( null === sn_analytics_dimension_series( 'browser', array( 'Chrome' ), '2026-06-01', '2026-06-12' ),
	'failure: a failed read ([] + last_error) returns NULL, never an empty series map' );
$q_before_ds = count( $GLOBALS['wpdb']->queries );
ok( array() === sn_analytics_dimension_series( 'browser', array(), '2026-06-01', '2026-06-12' )
	&& count( $GLOBALS['wpdb']->queries ) === $q_before_ds,
	'failure: an empty value list still short-circuits to [] with NO query (a known-empty answer)' );
$GLOBALS['wpdb']->fail_reads = false;
$GLOBALS['wpdb']->last_error = 'stale error from an EARLIER unrelated query';
$ok_map = sn_analytics_dimension_series( 'browser', array( 'Chrome' ), '2026-06-01', '2026-06-12' );
ok( is_array( $ok_map ) && isset( $ok_map['Chrome'] ), 'recovery: a successful read flushes a stale last_error and serves the map' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
