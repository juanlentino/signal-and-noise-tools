<?php
/**
 * Tests for sn_analytics_class_series() in inc/analytics-read.php.
 * Run: php tests/analytics-class-series.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

class CS_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
		$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();

		// GROUP BY bucket + class — return per-(day,class) aggregates.
		if ( stripos( $sql, 'GROUP BY' ) !== false && stripos( $sql, 'class' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$d   = (string) $r['day'];
				$cls = (string) ( $r['class'] ?? 'human' );
				$key = $d . '::' . $cls;
				if ( ! isset( $agg[ $key ] ) ) {
					$agg[ $key ] = array( 'day' => $d, 'class' => $cls, 'views' => 0 );
				}
				$agg[ $key ]['views'] += (int) $r['views'];
			}
			ksort( $agg );
			return array_values( $agg );
		}

		return $rows;
	}
}
$GLOBALS['wpdb'] = new CS_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-read.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-06-11', 'class' => 'human', 'views' => 80 ),
	array( 'day' => '2026-06-11', 'class' => 'bot',   'views' => 20 ),
);

echo "\nGroup: class_series\n";
$rows = sn_analytics_class_series( '2026-06-01', '2026-06-12', 'day' );
$sql  = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'GROUP BY' ) !== false && strpos( $sql, 'class' ) !== false, 'groups by bucket + class' );
ok( ! empty( $rows ), 'returns a per-bucket series' );
$last = end( $rows );
ok( isset( $last['bot_pct'] ), 'each bucket exposes bot_pct' );
ok( $last['bot_pct'] === 20, '20 bot / 100 total = 20%' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
