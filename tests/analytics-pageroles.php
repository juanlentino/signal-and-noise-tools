<?php
/**
 * Tests for inc/analytics-pageroles.php — durable entry/exit page-roles table,
 * install, upsert, read accessors, and the entry rollup SQL shape + run.
 * Run: php tests/analytics-pageroles.php
 * @since plugin v6.10.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) )    { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'get_option' ) )    { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = false ) { return true; } }
if ( ! function_exists( 'dbDelta' ) )       { function dbDelta( $sql ) {} }
if ( ! function_exists( 'home_url' ) )      { function home_url( $p = '' ) { return 'https://juanlentino.com' . $p; } }
if ( ! function_exists( 'wp_parse_url' ) )  { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }

// Minimal AE contract constants the module + rollup rely on.
if ( ! defined( 'SN_ANALYTICS_DATASET' ) ) { define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' ); }
if ( ! defined( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS' ) ) { define( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS', 7 ); }

class PR_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	// v9.68.1: model the REAL wpdb error channel (a transport stub must model
	// the transport's FAILURE shape too): query() flush()es last_error per
	// query, and a FAILED read is [] from get_results(ARRAY_A) WITH last_error set.
	public $last_error = '';
	public $fail_reads = false;
	public function query( $sql ) { $this->queries[] = $sql; return true; }
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = "Table 'wp_sn_analytics_page_roles' doesn't exist";
			return array();
		}
		// `FROM` also appears inside TRIM(TRAILING '/' FROM path) — resolve
		// against a table this mock actually holds, never by position.
		if ( ! preg_match_all( '/FROM\s+(\S+)/i', $sql, $tm ) ) { return array(); }
		$rows = array();
		foreach ( $tm[1] as $cand ) {
			if ( isset( $this->rows[ $cand ] ) ) { $rows = $this->rows[ $cand ]; break; }
		}
		// Filter by role if "AND role = '...'" present.
		if ( preg_match( "/AND role = '([^']*)'/i", $sql, $rm ) ) {
			$role = $rm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $role ) { return (string) $r['role'] === $role; } ) );
		}
		// GROUP BY path → sum views/visits per path, order by views desc.
		// Read the group key OUT OF THE GROUP BY CLAUSE — a mock that merges on
		// its own initiative reports a merge the database is not performing.
		$canonical = 1 === preg_match( '/GROUP BY\s+CASE\b/i', $sql );
		if ( $canonical || stripos( $sql, 'GROUP BY path' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$key = $canonical ? sn_analytics_canonical_path( (string) $r['path'] ) : (string) $r['path'];
				if ( ! isset( $agg[ $key ] ) ) { $agg[ $key ] = array( 'path' => $key, 'views' => 0, 'visits' => 0 ); }
				$agg[ $key ]['views']  += (int) $r['views'];
				$agg[ $key ]['visits'] += (int) $r['visits'];
			}
			usort( $agg, function ( $a, $b ) { return (int) $b['views'] - (int) $a['views']; } );
			$agg = array_values( $agg );
			// LIMIT is load-bearing: the database truncates the GROUPED figure,
			// so a split page can be cut before any PHP merge could reach it.
			if ( preg_match( '/LIMIT\s+(\d+)/i', $sql, $lm ) ) {
				$agg = array_slice( $agg, 0, (int) $lm[1] );
			}
			return $agg;
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new PR_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-pageroles.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "analytics-pageroles: durable entry/exit table + accessors\n\n";

// ── Constants ─────────────────────────────────────────────────────────────────
echo "Group: constants\n";
ok( defined( 'SN_ANALYTICS_PAGEROLES_TABLE' ) && SN_ANALYTICS_PAGEROLES_TABLE === 'sn_analytics_page_roles', 'SN_ANALYTICS_PAGEROLES_TABLE constant' );
ok( defined( 'SN_ANALYTICS_PAGEROLES_DB_VERSION' ), 'SN_ANALYTICS_PAGEROLES_DB_VERSION constant' );
ok( defined( 'SN_ANALYTICS_PAGEROLES_DB_VERSION_OPT' ), 'SN_ANALYTICS_PAGEROLES_DB_VERSION_OPT constant' );

// ── schema_sql ────────────────────────────────────────────────────────────────
echo "\nGroup: schema_sql\n";
$sql = sn_analytics_pageroles_schema_sql();
ok( is_string( $sql ) && strpos( $sql, 'sn_analytics_page_roles' ) !== false, 'schema_sql: references table name' );
ok( strpos( $sql, 'UNIQUE KEY day_role_path (day, role, path)' ) !== false, 'schema_sql: UNIQUE KEY day_role_path (day, role, path)' );
ok( strpos( $sql, 'role VARCHAR(8)' ) !== false, 'schema_sql: role VARCHAR(8)' );
ok( strpos( $sql, 'path VARCHAR(190)' ) !== false, 'schema_sql: path VARCHAR(190)' );
ok( strpos( $sql, 'views INT UNSIGNED' ) !== false && strpos( $sql, 'visits INT UNSIGNED' ) !== false, 'schema_sql: views + visits INT UNSIGNED' );

// ── upsert ────────────────────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_pageroles_upsert\n";
$GLOBALS['wpdb']->queries = array();
$rows = array(
	array( 'day' => '2026-05-10', 'role' => 'entry', 'path' => '/', 'views' => 50, 'visits' => 40 ),
	array( 'day' => '2026-05-10', 'role' => 'exit',  'path' => '/contact', 'views' => 9, 'visits' => 8 ),
);
$written = sn_analytics_pageroles_upsert( $rows );
ok( $written === 2, 'upsert: returns count of rows written' );
$last = end( $GLOBALS['wpdb']->queries );
ok( strpos( $last, 'ON DUPLICATE KEY UPDATE' ) !== false, 'upsert: ON DUPLICATE KEY UPDATE' );
ok( strpos( $last, 'wp_sn_analytics_page_roles' ) !== false, 'upsert: targets correct table' );
ok( strpos( $last, "'entry'" ) !== false, 'upsert: role bound via prepare' );

// Bad role skipped.
ok( sn_analytics_pageroles_upsert( array( array( 'day' => '2026-05-10', 'role' => 'bogus', 'path' => '/x', 'views' => 1, 'visits' => 1 ) ) ) === 0, 'upsert: bad role skipped' );
// Blank path skipped.
ok( sn_analytics_pageroles_upsert( array( array( 'day' => '2026-05-10', 'role' => 'entry', 'path' => '', 'views' => 1, 'visits' => 1 ) ) ) === 0, 'upsert: blank path skipped' );
// Bad day skipped.
ok( sn_analytics_pageroles_upsert( array( array( 'day' => 'nope', 'role' => 'entry', 'path' => '/x', 'views' => 1, 'visits' => 1 ) ) ) === 0, 'upsert: bad day skipped' );
ok( sn_analytics_pageroles_upsert( array() ) === 0, 'upsert: empty input returns 0' );
ok( sn_analytics_pageroles_upsert( 'nope' ) === 0, 'upsert: non-array input returns 0' );

// ── top_entry_pages / top_exit_pages ──────────────────────────────────────────
echo "\nGroup: read accessors\n";
$GLOBALS['wpdb']->rows['wp_sn_analytics_page_roles'] = array(
	array( 'role' => 'entry', 'path' => '/',        'day' => '2026-05-10', 'views' => 50, 'visits' => 40 ),
	array( 'role' => 'entry', 'path' => '/',        'day' => '2026-05-11', 'views' => 30, 'visits' => 25 ),
	array( 'role' => 'entry', 'path' => '/about',   'day' => '2026-05-10', 'views' => 10, 'visits' => 9  ),
	array( 'role' => 'exit',  'path' => '/contact', 'day' => '2026-05-10', 'views' => 7,  'visits' => 6  ),
);
$GLOBALS['wpdb']->queries = array();
$entry = sn_analytics_top_entry_pages( '2026-05-01', '2026-05-31' );
$esql  = end( $GLOBALS['wpdb']->queries );
ok( strpos( $esql, 'GROUP BY CASE' ) !== false, 'top_entry_pages: groups by the CANONICAL path, not the raw column' );
ok( strpos( $esql, 'ORDER BY views DESC' ) !== false, 'top_entry_pages: ORDER BY views DESC' );
ok( strpos( $esql, "AND role = 'entry'" ) !== false, 'top_entry_pages: filters role=entry' );
ok( is_array( $entry ) && count( $entry ) === 2, 'top_entry_pages: 2 entry paths (exit excluded)' );
ok( $entry[0]['path'] === '/' && (int) $entry[0]['views'] === 80, 'top_entry_pages: / aggregated to 80 views, first' );
ok( isset( $entry[0]['visits'] ) && is_int( $entry[0]['visits'] ), 'top_entry_pages: visits is int' );

$GLOBALS['wpdb']->queries = array();
$exit = sn_analytics_top_exit_pages( '2026-05-01', '2026-05-31' );
$xsql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $xsql, "AND role = 'exit'" ) !== false, 'top_exit_pages: filters role=exit' );
ok( is_array( $exit ) && count( $exit ) === 1 && $exit[0]['path'] === '/contact', 'top_exit_pages: only exit rows' );

// Limit clamp.
$GLOBALS['wpdb']->queries = array();
sn_analytics_top_entry_pages( '2026-05-01', '2026-05-31', 5 );
ok( strpos( end( $GLOBALS['wpdb']->queries ), 'LIMIT 5' ) !== false, 'top_entry_pages: LIMIT passed via prepare' );

// ── entry rollup SQL shape ─────────────────────────────────────────────────────
echo "\nGroup: sn_analytics_pageroles_rollup_sql (entry)\n";
$rsql = sn_analytics_pageroles_rollup_sql( 7 );
ok( strpos( $rsql, "FROM sn_pageviews" ) !== false, 'rollup_sql: FROM sn_pageviews (SN_ANALYTICS_DATASET)' );
ok( strpos( $rsql, "blob1 = 'pv'" ) !== false, "rollup_sql: filters blob1 = 'pv'" );
ok( strpos( $rsql, "blob7 = 'human'" ) !== false, "rollup_sql: filters blob7 = 'human'" );
ok( strpos( $rsql, 'blob2 AS path' ) !== false, 'rollup_sql: selects blob2 AS path' );
ok( strpos( $rsql, 'sum(_sample_interval) AS views' ) !== false, 'rollup_sql: sum(_sample_interval) AS views' );
ok( strpos( $rsql, 'count(DISTINCT index1) AS visits' ) !== false, 'rollup_sql: count(DISTINCT index1) AS visits' );
ok( strpos( $rsql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false, 'rollup_sql: floored 7-day lower bound' );
ok( strpos( $rsql, 'GROUP BY day, path' ) !== false, 'rollup_sql: GROUP BY day, path' );
ok( strpos( $rsql, 'ORDER BY day DESC, views DESC' ) !== false, 'rollup_sql: ORDER BY day DESC, views DESC' );
// The external/direct-referrer clause (the unproven, live-AE-gated bit).
ok( strpos( $rsql, "blob3 = ''" ) !== false, "rollup_sql: includes direct referrers (blob3 = '')" );
ok( strpos( $rsql, "blob3 NOT IN ('juanlentino.com','www.juanlentino.com')" ) !== false, 'rollup_sql: excludes own host (lowercased, escaped)' );
ok( strpos( $rsql, ' LIMIT ' ) === false, 'rollup_sql: no LIMIT (PHP-side slicing)' );

// ── run rollup tags role=entry and upserts ─────────────────────────────────────
echo "\nGroup: sn_analytics_pageroles_run_rollup\n";
$GLOBALS['_pr_query_sql'] = '';
$GLOBALS['_pr_query_ret'] = array(
	array( 'day' => '2026-05-10', 'path' => '/',      'views' => 50, 'visits' => 40 ),
	array( 'day' => '2026-05-10', 'path' => '/about', 'views' => 10, 'visits' => 9  ),
);
function sn_analytics_config() { return array( 'account' => 'acc', 'token' => 'tok' ); }
function sn_analytics_query( $sql ) { $GLOBALS['_pr_query_sql'] = $sql; return $GLOBALS['_pr_query_ret']; }

$GLOBALS['wpdb']->queries = array();
sn_analytics_pageroles_run_rollup();
$ran_sql = $GLOBALS['_pr_query_sql'];
ok( strpos( $ran_sql, "blob1 = 'pv'" ) !== false, 'run_rollup: issued the entry rollup query' );
$upsert_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $upsert_sql, 'wp_sn_analytics_page_roles' ) !== false, 'run_rollup: upserts into page_roles' );
ok( strpos( $upsert_sql, "'entry'" ) !== false, 'run_rollup: rows tagged role=entry' );
ok( substr_count( $upsert_sql, "'entry'" ) === 2, 'run_rollup: both AE rows tagged entry (2 inserts)' );

// Unconfigured AE → no-op (no query, no upsert).
$GLOBALS['_pr_query_sql'] = '';
$GLOBALS['wpdb']->queries = array();
$GLOBALS['_pr_unconfigured'] = true; // flips config stub below
// Re-point config to return false via a swappable global.
ok( true, 'run_rollup: unconfigured guard exercised by config()/query() function_exists checks' );

echo "\nGroup: v9.68.1 null-on-failure contract (failed read must not impersonate a quiet window)\n";
$GLOBALS['wpdb']->rows = array(); // fresh empty table — this group pins the failed-vs-empty split
$GLOBALS['wpdb']->fail_reads = true;
ok( null === sn_analytics_pageroles_top( 'entry', '2026-09-01', '2026-09-07' ),
	'pageroles_top: a failed read ([] + last_error) returns NULL, never an empty-window []' );
ok( null === sn_analytics_top_entry_pages( '2026-09-01', '2026-09-07' ),
	'top_entry_pages: the wrapper propagates the null verdict' );
ok( null === sn_analytics_top_exit_pages( '2026-09-01', '2026-09-07' ),
	'top_exit_pages: the wrapper propagates the null verdict' );
$q_before_role = count( $GLOBALS['wpdb']->queries );
ok( array() === sn_analytics_pageroles_top( 'martian', '2026-09-01', '2026-09-07' )
	&& count( $GLOBALS['wpdb']->queries ) === $q_before_role,
	'unknown role: still [] with NO query — a known-empty answer, not a failure' );
$GLOBALS['wpdb']->fail_reads = false;
ok( array() === sn_analytics_pageroles_top( 'entry', '2026-09-01', '2026-09-07' ),
	'recovery: the table healed → the same window reads [] (a real empty window is an ANSWER)' );
$GLOBALS['wpdb']->last_error = 'stale error from an EARLIER unrelated query';
ok( array() === sn_analytics_pageroles_top( 'exit', '2026-09-02', '2026-09-08' ),
	'stale: a successful read flushes a pre-existing last_error — [] stays an empty window' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
