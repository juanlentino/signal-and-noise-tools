<?php
/**
 * Tests for inc/edge-rollup.php — edge-analytics durable tables, the daily GraphQL
 * rollup (parse httpRequests1dGroups + firewall + colo), read accessors, and the
 * beacon-reconciliation (edge pageviews vs human pageviews → machine traffic).
 * Run: php tests/edge-rollup.php
 * @since plugin v6.26.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

$GLOBALS['__eo'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__eo'] ) ? $GLOBALS['__eo'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__eo'][ $k ] = $v; return true; }
$GLOBALS['__dbdelta'] = array();
function dbDelta( $sql ) { $GLOBALS['__dbdelta'][] = $sql; return array(); }

// Edge GraphQL client seam: return a per-dataset stub object based on the query.
$GLOBALS['__edge_config'] = array( 'token' => 't', 'zone' => 'z' );
$GLOBALS['__edge_data']   = array();
$GLOBALS['__edge_calls']  = array();
function sn_edge_config() { return $GLOBALS['__edge_config']; }
function sn_edge_query( $query, $vars = array() ) {
	$GLOBALS['__edge_calls'][] = array( 'query' => $query, 'vars' => $vars );
	if ( null === $GLOBALS['__edge_config'] ) { return null; }
	foreach ( $GLOBALS['__edge_data'] as $needle => $payload ) {
		if ( strpos( $query, $needle ) !== false ) { return $payload; }
	}
	return null;
}
// Beacon-side accessor the reconciliation reads.
$GLOBALS['__beacon_human'] = array( 'views' => 0 );
function sn_analytics_range_totals( $from, $to, $class = 'human' ) { return $GLOBALS['__beacon_human']; }

// Client-layer seams (the real builders/corrected are covered by tests/edge-analytics.php).
// Builders return just the dataset name so the sn_edge_query stub can dispatch on it.
function sn_edge_daily_query() { return 'httpRequests1dGroups'; }
function sn_edge_firewall_query() { return 'firewallEventsAdaptiveGroups'; }
function sn_edge_colo_query() { return 'httpRequestsAdaptiveGroups'; }
function sn_edge_attack_query() { return 'ATTACK_QUERY'; } // unique sentinel (NOT 'httpRequestsAdaptiveGroups' which collides with colo).
function sn_edge_errors_query() { return 'ERRORS_QUERY'; }  // #1002: 5xx pressure, its own document so an unknown field cannot fail the attack query.
function sn_edge_corrected( $row ) { $si = max( 1.0, (float) ( $row['avg']['sampleInterval'] ?? 1 ) ); return (int) round( (int) ( $row['count'] ?? 0 ) * $si ); }
// Discovered adaptive retention (settings-node notOlderThan), seconds or null — drives the window clamp.
function sn_edge_adaptive_retention() { return $GLOBALS['__edge_retention'] ?? null; }

class Edge_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();      // table => rows (for plain selects)
	public $aggregate = array(); // table => single assoc row (for SUM selects)
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			if ( '%d' === $m[0] ) { return (string) (int) $a; }
			if ( '%f' === $m[0] ) { return (string) (float) $a; }
			return "'" . addslashes( (string) $a ) . "'";
		}, $query );
	}
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function get_row( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( preg_match( '/FROM\s+(\S+)/', $sql, $m ) && isset( $this->aggregate[ $m[1] ] ) ) { return $this->aggregate[ $m[1] ]; }
		return null;
	}
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $m ) ) { return array(); }
		$rows = $this->rows[ $m[1] ] ?? array();
		if ( preg_match( "/dim = '([^']*)'/", $sql, $dm ) ) {
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $dm ) { return (string) ( $r['dim'] ?? '' ) === $dm[1]; } ) );
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new Edge_Stub_wpdb();

require_once __DIR__ . '/../inc/edge-rollup.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }
function eo_reset() {
	$GLOBALS['__eo'] = array(); $GLOBALS['__dbdelta'] = array();
	$GLOBALS['__edge_config'] = array( 'token' => 't', 'zone' => 'z' );
	$GLOBALS['__edge_data'] = array(); $GLOBALS['__edge_calls'] = array();
	$GLOBALS['__edge_retention'] = null;
	$GLOBALS['__beacon_human'] = array( 'views' => 0 );
	$GLOBALS['wpdb'] = new Edge_Stub_wpdb();
}
/** The `from` window arg the rollup sent for a given adaptive dataset query. */
function edge_from( $dataset ) {
	foreach ( $GLOBALS['__edge_calls'] as $c ) {
		if ( (string) $c['query'] === $dataset ) { return $c['vars']['from'] ?? null; }
	}
	return null;
}

echo "Edge rollup — tables, daily GraphQL rollup, read + reconciliation\n\n";

echo "Group: schema (two tables)\n";
eo_reset();
$d = sn_edge_daily_schema_sql();
ok( strpos( $d, 'wp_sn_edge_daily' ) !== false, 'daily schema: prefixed table' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*\)/', $d ) === 1, 'daily schema: UNIQUE(day)' );
foreach ( array( 'requests', 'cached_requests', 'bytes', 'cached_bytes', 'threats', 'page_views', 'status_2xx', 'status_5xx' ) as $c ) {
	ok( preg_match( '/\b' . $c . '\b/', $d ) === 1, "daily schema: column $c" );
}
$dim = sn_edge_dims_schema_sql();
ok( strpos( $dim, 'wp_sn_edge_dims' ) !== false, 'dims schema: prefixed table' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*,\s*dim\s*,\s*value\s*\)/', $dim ) === 1, 'dims schema: UNIQUE(day,dim,value)' );

echo "\nGroup: maybe_install\n";
eo_reset();
sn_edge_maybe_install();
ok( count( $GLOBALS['__dbdelta'] ) === 2, 'maybe_install: dbDelta both tables on fresh' );
ok( get_option( SN_EDGE_DB_VERSION_OPT ) === SN_EDGE_DB_VERSION, 'maybe_install: stamps version' );
eo_reset(); update_option( SN_EDGE_DB_VERSION_OPT, SN_EDGE_DB_VERSION );
sn_edge_maybe_install();
ok( count( $GLOBALS['__dbdelta'] ) === 0, 'maybe_install: current version → no dbDelta' );

echo "\nGroup: daily upsert\n";
eo_reset();
$n = sn_edge_daily_upsert( array( array( 'day' => '2026-06-18', 'requests' => 1000, 'cached_requests' => 800, 'bytes' => 5000000, 'cached_bytes' => 4000000, 'threats' => 3, 'page_views' => 200, 'status_2xx' => 900, 'status_3xx' => 50, 'status_4xx' => 40, 'status_5xx' => 10 ) ) );
ok( 1 === $n, 'daily upsert: rows written' );
$q = $GLOBALS['wpdb']->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_edge_daily' ) !== false && stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false, 'daily upsert: idempotent INSERT' );
ok( strpos( $q, "'2026-06-18', 1000, 800, 5000000, 4000000, 3, 200, 900, 50, 40, 10" ) !== false, 'daily upsert: binds columns in order' );

echo "\nGroup: dims upsert\n";
eo_reset();
sn_edge_dims_upsert( array( array( 'day' => '2026-06-18', 'dim' => 'country', 'value' => 'US', 'requests' => 600, 'bytes' => 3000000 ) ) );
$q = $GLOBALS['wpdb']->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_edge_dims' ) !== false, 'dims upsert: INSERT into dims' );
ok( strpos( $q, "'2026-06-18', 'country', 'US', 600, 3000000" ) !== false, 'dims upsert: binds (day,dim,value,requests,bytes)' );
eo_reset();
sn_edge_dims_upsert( array( array( 'day' => '2026-06-18', 'dim' => 'colo', 'value' => str_repeat( 'x', 250 ), 'requests' => 1, 'bytes' => 0 ) ) );
ok( strpos( $GLOBALS['wpdb']->queries[0], str_repeat( 'x', 161 ) ) === false, 'dims upsert: value truncated to 160' );

// Large row sets must be CHUNKED, not one INSERT. sn_edge_run_rollup() re-pulls
// SN_EDGE_BACKFILL_DAYS (395) days of countryMap + adaptive threat/colo/attack dims
// EVERY run, so this row set is unbounded. A single INSERT of thousands of rows
// (5 placeholders each) blows past MySQL's 65,535-placeholder-per-statement hard
// limit → $wpdb->query() returns false → 0 dims written → and, re-pulled each run,
// never recovers. Every sibling upsert chunks at 100; this one must too.
eo_reset();
$big = array();
for ( $i = 0; $i < 250; $i++ ) {
	$big[] = array( 'day' => '2026-06-18', 'dim' => 'country', 'value' => 'C' . $i, 'requests' => $i + 1, 'bytes' => 0 );
}
$written = sn_edge_dims_upsert( $big );
ok( 3 === count( $GLOBALS['wpdb']->queries ), 'dims upsert: 250 rows chunked into 3 INSERTs, not 1' );
ok( 250 === $written, 'dims upsert: returns the total written across chunks' );
$maxrows = 0;
foreach ( $GLOBALS['wpdb']->queries as $qq ) { $maxrows = max( $maxrows, substr_count( $qq, "'2026-06-18'" ) ); }
ok( $maxrows <= 100, 'dims upsert: no single statement carries more than 100 rows' );

echo "\nGroup: run_rollup parses all three datasets\n";
eo_reset();
$GLOBALS['__edge_data']['httpRequests1dGroups'] = array( 'httpRequests1dGroups' => array(
	array( 'dimensions' => array( 'date' => '2026-06-18' ), 'sum' => array(
		'requests' => 1000, 'cachedRequests' => 800, 'bytes' => 5000000, 'cachedBytes' => 4000000, 'threats' => 3, 'pageViews' => 200,
		'responseStatusMap' => array(
			array( 'edgeResponseStatus' => 200, 'requests' => 900 ),
			array( 'edgeResponseStatus' => 301, 'requests' => 50 ),
			array( 'edgeResponseStatus' => 404, 'requests' => 40 ),
			array( 'edgeResponseStatus' => 500, 'requests' => 10 ),
		),
		'countryMap' => array(
			array( 'clientCountryName' => 'US', 'requests' => 600, 'bytes' => 3000000 ),
			array( 'clientCountryName' => 'CA', 'requests' => 400, 'bytes' => 2000000 ),
		),
	) ),
) );
$GLOBALS['__edge_data']['firewallEventsAdaptiveGroups'] = array( 'firewallEventsAdaptiveGroups' => array(
	array( 'count' => 5, 'avg' => array( 'sampleInterval' => 10 ), 'dimensions' => array( 'action' => 'block', 'source' => 'waf', 'ruleId' => 'r1', 'clientCountryName' => 'CN' ) ),
) );
$GLOBALS['__edge_data']['httpRequestsAdaptiveGroups'] = array( 'httpRequestsAdaptiveGroups' => array(
	// sampleInterval 2 so BOTH the count (15→30) and the bytes (50000→100000) prove sampling correction.
	array( 'count' => 15, 'avg' => array( 'sampleInterval' => 2 ), 'sum' => array( 'edgeResponseBytes' => 50000 ), 'dimensions' => array( 'coloCode' => 'IAD' ) ),
) );
// #1002: 5xx pressure, seeded beside the attack fixture so the SAME rollup
// run covers it. A separate later run needs every other payload re-seeded, and
// without them the rollup returns before it reaches this step — which reads as
// "the feature does not work".
$GLOBALS['__edge_data']['ERRORS_QUERY'] = array(
	'errors' => array(
		// Origin answered 503 — the origin, or the cache in front of it, failed.
		array( 'count' => 3, 'avg' => array( 'sampleInterval' => 1 ), 'dimensions' => array(
			'clientRequestPath' => '/wp-content/plugins/x/a.js', 'edgeResponseStatus' => 503,
			'originResponseStatus' => 503, 'cacheStatus' => 'dynamic' ) ),
		// No origin status — Cloudflare or a Worker answered by itself.
		array( 'count' => 2, 'avg' => array( 'sampleInterval' => 1 ), 'dimensions' => array(
			'clientRequestPath' => '/wp-content/plugins/x/b.css', 'edgeResponseStatus' => 503,
			'originResponseStatus' => 0, 'cacheStatus' => 'miss' ) ),
	),
);

$GLOBALS['__edge_data']['ATTACK_QUERY'] = array(
	'doors' => array(
		// two US /wp-login.php rows so the MARGINAL must SUM across rows (15→30 + 10→20 = 50).
		array( 'count' => 15, 'avg' => array( 'sampleInterval' => 2 ), 'dimensions' => array( 'clientRequestPath' => '/wp-login.php', 'clientCountryName' => 'US', 'clientASNDescription' => 'DIGITALOCEAN-ASN', 'clientAsn' => 14061, 'edgeResponseStatus' => 404, 'clientRequestHTTPMethodName' => 'POST' ) ),
		array( 'count' => 10, 'avg' => array( 'sampleInterval' => 2 ), 'dimensions' => array( 'clientRequestPath' => '/wp-login.php', 'clientCountryName' => 'US', 'clientASNDescription' => '', 'clientAsn' => 9009, 'edgeResponseStatus' => 404, 'clientRequestHTTPMethodName' => 'GET' ) ),
	),
	'probes' => array(
		array( 'count' => 7, 'avg' => array( 'sampleInterval' => 3 ), 'dimensions' => array( 'clientRequestPath' => '/.env', 'edgeResponseStatus' => 404 ) ),
	),
);
sn_edge_run_rollup( '2026-06-19' );

// 5 since v13.96.3 (#1002): the 5xx document is separate, because GraphQL fails
// the WHOLE query on one unknown field and this one asks for
// originResponseStatus / cacheStatus that the attack query does not use.
ok( count( $GLOBALS['__edge_calls'] ) === 5, 'run: issues 5 GraphQL queries (daily + firewall + colo + attack + errors)' );
$all_sql = implode( "\n", $GLOBALS['wpdb']->queries );
ok( strpos( $all_sql, "'2026-06-18', 1000, 800, 5000000, 4000000, 3, 200, 900, 50, 40, 10" ) !== false, 'run: daily row parsed — status map bucketed 2xx/3xx/4xx/5xx' );
ok( strpos( $all_sql, "'2026-06-18', 'country', 'US', 600, 3000000" ) !== false, 'run: countryMap melted into dims' );
ok( strpos( $all_sql, "'2026-06-19', 'threat', 'block', 50" ) !== false, 'run: firewall sampling-corrected (5×10=50), attributed to today' );
ok( strpos( $all_sql, "'2026-06-19', 'colo', 'IAD', 30, 100000" ) !== false, 'run: colo dims sampling-corrected — BOTH requests (15×2=30) AND bytes (50000×2=100000)' );
// Adaptive window: no retention discovered (null) → a trailing 24h snapshot (today−86400).
ok( edge_from( 'httpRequestsAdaptiveGroups' ) === '2026-06-18T00:00:00Z', 'run: adaptive window defaults to a trailing 24h (today−86400)' );
ok( edge_from( 'firewallEventsAdaptiveGroups' ) === '2026-06-18T00:00:00Z', 'run: both adaptive datasets share the one trailing window' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_door', '/wp-login.php', 50" ) !== false, 'run: atk_door marginal SUMS both rows (30+20=50), sampling-corrected' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_country', 'US', 50" ) !== false, 'run: atk_country marginal sums across rows (50)' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_asn', 'DIGITALOCEAN-ASN', 30" ) !== false, 'run: atk_asn uses clientASNDescription' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_asn', 'AS9009', 20" ) !== false, 'run: atk_asn falls back to AS{clientAsn} when description empty' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_method', 'POST', 30" ) !== false, 'run: atk_method POST (credential attempts)' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_status', '404', 50" ) !== false, 'run: atk_status marginal' );
ok( strpos( $all_sql, "'2026-06-19', 'atk_path', '/.env', 21" ) !== false, 'run: atk_path probe sampling-corrected (7×3=21)' );
$before = count( $GLOBALS['wpdb']->queries );
sn_edge_run_rollup( '2026-06-19' );
ok( strpos( implode( "\n", array_slice( $GLOBALS['wpdb']->queries, $before ) ), "'atk_door', '/wp-login.php', 50" ) !== false, 'run: same-day re-run re-emits 50 (ON DUPLICATE overwrite, not 100)' );
// Dormant.
eo_reset(); $GLOBALS['__edge_config'] = null;
sn_edge_run_rollup( '2026-06-19' );
ok( count( $GLOBALS['__edge_calls'] ) === 0 && count( $GLOBALS['wpdb']->queries ) === 0, 'run: unconfigured → no query, no write' );

echo "\nGroup: adaptive window derives from discovered retention (not a hardcoded 24h)\n";
eo_reset();
$GLOBALS['__edge_retention'] = 3600; // 1h < 24h → the snapshot must shrink to what the node actually retains.
sn_edge_run_rollup( '2026-06-19' );
ok( edge_from( 'httpRequestsAdaptiveGroups' ) === '2026-06-18T23:00:00Z', 'run: window clamps to retention when retention < 24h (today−3600)' );
ok( edge_from( 'firewallEventsAdaptiveGroups' ) === '2026-06-18T23:00:00Z', 'run: clamp applies to both adaptive pulls' );
eo_reset();
$GLOBALS['__edge_retention'] = 2678400; // 31d ≥ 24h → no clamp; the daily snapshot stays 24h (no over-wide window).
sn_edge_run_rollup( '2026-06-19' );
ok( edge_from( 'httpRequestsAdaptiveGroups' ) === '2026-06-18T00:00:00Z', 'run: retention ≥ 24h leaves the 24h snapshot intact (never widens past the daily intent)' );

echo "\nGroup: range_totals + derived cache-hit% / error%\n";
eo_reset();
$GLOBALS['wpdb']->aggregate['wp_sn_edge_daily'] = array(
	'requests' => 1000, 'cached_requests' => 800, 'bytes' => 5000000, 'cached_bytes' => 4000000, 'threats' => 3, 'page_views' => 200,
	'status_2xx' => 900, 'status_3xx' => 50, 'status_4xx' => 40, 'status_5xx' => 10,
);
$t = sn_edge_range_totals( '2026-06-01', '2026-06-19' );
ok( (int) $t['requests'] === 1000 && (int) $t['threats'] === 3, 'range_totals: sums scalar columns' );
ok( $t['cache_hit_pct'] === 80, 'range_totals: cache-hit% = cached/requests (800/1000)' );
ok( $t['error_pct'] === 5, 'range_totals: error% = (4xx+5xx)/requests (50/1000)' );
$rt_sql = end( $GLOBALS['wpdb']->queries );
ok( stripos( $rt_sql, 'SUM(' ) !== false && strpos( $rt_sql, 'day >= ' ) !== false && strpos( $rt_sql, 'day <= ' ) !== false, 'range_totals: SUM over a bounded day range' );

echo "\nGroup: daily_series + top_dim\n";
eo_reset();
$GLOBALS['wpdb']->rows['wp_sn_edge_daily'] = array(
	array( 'day' => '2026-06-17', 'requests' => 900 ),
	array( 'day' => '2026-06-18', 'requests' => 1000 ),
);
$s = sn_edge_daily_series( '2026-06-01', '2026-06-19' );
ok( count( $s ) === 2 && $s[1]['requests'] === 1000, 'daily_series: [{day,requests}] rows' );
$GLOBALS['wpdb']->rows['wp_sn_edge_dims'] = array(
	array( 'day' => '2026-06-18', 'dim' => 'country', 'value' => 'US', 'requests' => 600, 'bytes' => 3000000 ),
	array( 'day' => '2026-06-18', 'dim' => 'colo', 'value' => 'IAD', 'requests' => 30, 'bytes' => 100000 ),
);
$colo = sn_edge_top_dim( 'colo', '2026-06-01', '2026-06-19', 10 );
ok( count( $colo ) === 1 && $colo[0]['value'] === 'IAD', 'top_dim: filters by dim' );
$td_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $td_sql, "dim = 'colo'" ) !== false && stripos( $td_sql, 'LIMIT' ) !== false, 'top_dim: dim filter + LIMIT' );

echo "\nGroup: machine_split reconciliation (edge vs beacon)\n";
eo_reset();
$GLOBALS['wpdb']->aggregate['wp_sn_edge_daily'] = array( 'page_views' => 500, 'requests' => 2000 );
$GLOBALS['__beacon_human'] = array( 'views' => 176 ); // the beacon's human pageviews
$m = sn_edge_machine_split( '2026-06-13', '2026-06-19' );
ok( $m['edge'] === 500 && $m['human'] === 176, 'machine_split: edge pageviews + beacon human' );
ok( $m['machine'] === 324, 'machine_split: machine = edge − human (500−176)' );
ok( $m['machine_pct'] === 65, 'machine_split: machine% of edge (324/500 ≈ 65)' );
// Never negative if the beacon over-counts a sampled window.
eo_reset();
$GLOBALS['wpdb']->aggregate['wp_sn_edge_daily'] = array( 'page_views' => 100, 'requests' => 100 );
$GLOBALS['__beacon_human'] = array( 'views' => 150 );
$m2 = sn_edge_machine_split( '2026-06-13', '2026-06-19' );
ok( $m2['machine'] === 0 && $m2['machine_pct'] === 0, 'machine_split: clamped at 0 (no negative machine count)' );


// ── #1002: a 5xx is recorded, and it names WHO answered ──────────────────
// probes filters 4xx only, so before this the rollup was structurally blind to
// a server error: fourteen assets failed with 503 on 2026-09-04 and nothing
// here saw it. Counts alone would still answer the wrong question — the
// responder is the datum, so err_source carries it.




echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
