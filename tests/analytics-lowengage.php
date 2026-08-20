<?php
/**
 * Tests for sn_analytics_low_engagement_paths() — low-engagement pages accessor.
 * Run: php tests/analytics-lowengage.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
define( 'SN_ANALYTICS_DAILY_TABLE', 'sn_analytics_daily' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

class LE_Stub_wpdb {
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
		// `FROM` also appears inside TRIM(TRAILING '/' FROM path) — resolve
		// against a table this mock actually holds, never by position.
		if ( ! preg_match_all( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
		$rows = array();
		foreach ( $tm[1] as $cand ) {
			if ( isset( $this->rows[ $cand ] ) ) { $rows = $this->rows[ $cand ]; break; }
		}
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cm ) { return (string) ( $r['class'] ?? 'human' ) === $cm[1]; } ) );
		}
		// GROUP BY path → per-path views-weighted aggregate with HAVING filter.
		// Read the group key OUT OF THE GROUP BY CLAUSE — never assume a merge
		// the query has not asked for.
		$canonical = 1 === preg_match( '/GROUP BY\s+CASE\b/i', $sql );
		if ( $canonical || stripos( $sql, 'GROUP BY path' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$p = $canonical ? sn_analytics_canonical_path( (string) $r['path'] ) : (string) $r['path'];
				if ( ! isset( $agg[ $p ] ) ) { $agg[ $p ] = array( 'path' => $p, 'views' => 0, 'visits' => 0, 'sw' => 0.0, 'tw' => 0.0 ); }
				$agg[ $p ]['views']  += (int) $r['views'];
				$agg[ $p ]['visits'] += (int) $r['visits'];
				$agg[ $p ]['sw']     += (float) $r['scroll_avg'] * (int) $r['views'];
				$agg[ $p ]['tw']     += (float) $r['time_avg'] * (int) $r['views'];
			}
			$out = array();
			foreach ( $agg as $a ) {
				$scroll = $a['views'] ? $a['sw'] / $a['views'] : 0;
				$time   = $a['views'] ? $a['tw'] / $a['views'] : 0;
				// Parse the real HAVING thresholds from the prepared SQL so a transposed
				// param order or flipped predicate is actually caught (no hardcoded consts).
				$min_views  = preg_match( '/views\s*>=\s*(\d+)/i', $sql, $mv ) ? (int) $mv[1] : 0;
				$scroll_max = preg_match( '/scroll_avg\s*<\s*(\d+)/i', $sql, $sm ) ? (int) $sm[1] : PHP_INT_MAX;
				$time_max   = preg_match( '/time_avg\s*<\s*(\d+)/i', $sql, $tmx ) ? (int) $tmx[1] : PHP_INT_MAX;
				// Apply HAVING filter using parsed thresholds, not hardcoded constants.
				if ( $a['views'] >= $min_views
					&& $scroll < $scroll_max
					&& $time   < $time_max ) {
					$out[] = array( 'path' => $a['path'], 'views' => $a['views'], 'visits' => $a['visits'],
						'scroll_avg' => $scroll, 'time_avg' => $time );
				}
			}
			usort( $out, function ( $x, $y ) { return (int) $y['views'] - (int) $x['views']; } );
			return $out;
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new LE_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-read.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'path' => '/skip-me', 'views' => 100, 'visits' => 90, 'scroll_avg' => 80, 'time_avg' => 40000, 'class' => 'human' ),
	array( 'path' => '/bouncy',  'views' => 60,  'visits' => 58, 'scroll_avg' => 8,  'time_avg' => 1500,  'class' => 'human' ),
);
echo "\nGroup: low_engagement_paths\n";
$rows = sn_analytics_low_engagement_paths( '2026-06-01', '2026-06-12', 'human' );
$sql  = end( $GLOBALS['wpdb']->queries );
ok( strpos( $sql, 'HAVING' ) !== false, 'uses HAVING to filter on aggregates' );
ok( strpos( $sql, 'scroll_avg * views' ) !== false, 'weights scroll by views (consistent with range_totals)' );
ok( (int) SN_ANALYTICS_LOWENGAGE_MIN_VIEWS > 0, 'min-views threshold constant defined' );
ok( count( $rows ) === 1, 'HAVING filter: only the low-engagement path returns' );
ok( isset( $rows[0]['path'] ) && $rows[0]['path'] === '/bouncy', 'HAVING filter: /bouncy survives, /skip-me excluded' );

echo "\nGroup: low_engagement_paths — a split page clears the floor as ONE page (v11.31.2)\n";
// The min-views floor is 20. Split across two spellings a bouncy page shows
// 12 and 11 — NEITHER half clears it, so the page the owner most needs to see
// is silently absent from the report. Merged it is 23 and qualifies. This is
// why the canonicalisation belongs in the GROUP BY: HAVING filters the group.
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'path' => '/bouncy',  'views' => 12, 'visits' => 12, 'scroll_avg' => 8, 'time_avg' => 1500, 'class' => 'human' ),
	array( 'path' => '/bouncy/', 'views' => 11, 'visits' => 11, 'scroll_avg' => 8, 'time_avg' => 1500, 'class' => 'human' ),
);
$split = sn_analytics_low_engagement_paths( '2026-06-01', '2026-06-12', 'human' );
ok( count( $split ) === 1, 'A SPLIT PAGE IS STILL ONE PAGE — 12 + 11 clears the 20-view floor that neither half does' );
ok( isset( $split[0]['path'] ) && $split[0]['path'] === '/bouncy', 'low_engagement: the canonical spelling is what is reported' );
ok( isset( $split[0]['views'] ) && 23 === (int) $split[0]['views'], 'low_engagement: views are summed across both spellings' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
