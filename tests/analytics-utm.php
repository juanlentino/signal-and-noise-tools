<?php
/**
 * Tests for inc/analytics-utm.php — the UTM campaign-attribution layer.
 * Reads the worker's packed blob20 (source␟medium␟campaign␟term␟content),
 * splits it in PHP (AE has no JOINs / untrusted split fns), and rolls it into a
 * durable per-day Source/Medium + Campaign table.
 * Run: php tests/analytics-utm.php
 * @since plugin v9.28.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS', 7 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

$GLOBALS['__au_options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__au_options'] ) ? $GLOBALS['__au_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__au_options'][ $k ] = $v; return true; }

$GLOBALS['__au_dbdelta_calls'] = array();
function dbDelta( $sql ) { $GLOBALS['__au_dbdelta_calls'][] = $sql; return array(); }

// AE read-client seam.
$GLOBALS['__au_query_return']   = null;
$GLOBALS['__au_query_calls']    = array();
$GLOBALS['__au_config_present'] = true;
function sn_analytics_config() { return $GLOBALS['__au_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
function sn_analytics_query( $sql ) { $GLOBALS['__au_query_calls'][] = $sql; return $GLOBALS['__au_query_return']; }

// A fake wpdb: records queries; get_results returns pre-seeded rows for the utm table.
class AU_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $results = array(); // rows get_results should return
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) {
				case '%d': return (string) (int) $a;
				case '%f': return (string) (float) $a;
				default:   return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}
	// v9.68.1: model the REAL wpdb error channel (a transport stub must model
	// the transport's FAILURE shape too): query() flush()es last_error per
	// query, and a FAILED read is [] from get_results(ARRAY_A) WITH last_error set.
	public $last_error = '';
	public $fail_reads = false;
	public function query( $sql ) { $this->queries[] = $sql; return empty( $GLOBALS['__au_query_fail'] ) ? 1 : false; }
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = "Table 'wp_sn_analytics_utm' doesn't exist";
			return array();
		}
		return $this->results;
	}
}
$GLOBALS['wpdb'] = new AU_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-utm.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }
function au_reset() {
	$GLOBALS['__au_options']       = array();
	$GLOBALS['__au_dbdelta_calls'] = array();
	$GLOBALS['__au_query_return']  = null;
	$GLOBALS['__au_query_calls']   = array();
	$GLOBALS['__au_config_present'] = true;
	$GLOBALS['__au_query_fail']    = false;
	$GLOBALS['wpdb']               = new AU_Stub_wpdb();
}
$US = "\x1f"; // the worker's packed-UTM field separator (U+001F)

echo "Analytics UTM (campaign attribution) layer\n\n";

echo "Group: split helper\n";
au_reset();
$full = sn_analytics_utm_split( 'google' . $US . 'cpc' . $US . 'summer' . $US . 'shoes' . $US . 'varB' );
ok( is_array( $full ) && count( $full ) === 5, 'split: always returns 5 fields' );
ok( $full === array( 'google', 'cpc', 'summer', 'shoes', 'varB' ), 'split: parses source/medium/campaign/term/content in order' );
ok( sn_analytics_utm_split( 'google' . $US . $US . $US . $US ) === array( 'google', '', '', '', '' ), 'split: keeps positional empties' );
ok( sn_analytics_utm_split( 'google' ) === array( 'google', '', '', '', '' ), 'split: pads a short packed string to 5' );
ok( sn_analytics_utm_split( '' ) === array( '', '', '', '', '' ), 'split: empty → all empty' );

echo "\nGroup: schema SQL\n";
au_reset();
$schema = sn_analytics_utm_schema_sql();
ok( is_string( $schema ) && strpos( $schema, 'wp_sn_analytics_utm' ) !== false, 'schema: targets the prefixed utm table' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: dbDelta two-space PRIMARY KEY form' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*sig\s*\)/', $schema ) === 1, 'schema: UNIQUE KEY on the sig hash (wide tuple, key stays small)' );
foreach ( array( 'day', 'source', 'medium', 'campaign', 'class', 'views', 'visits', 'sig' ) as $col ) {
	ok( preg_match( '/\b' . $col . '\b/', $schema ) === 1, "schema: declares the $col column" );
}
ok( strpos( $schema, 'utf8mb4' ) !== false, 'schema: includes the charset collate' );

echo "\nGroup: maybe_install\n";
au_reset();
sn_analytics_utm_maybe_install();
ok( count( $GLOBALS['__au_dbdelta_calls'] ) === 1, 'maybe_install: missing version runs dbDelta' );
ok( get_option( SN_ANALYTICS_UTM_DB_VERSION_OPT ) === SN_ANALYTICS_UTM_DB_VERSION, 'maybe_install: stamps the version option' );
au_reset();
update_option( SN_ANALYTICS_UTM_DB_VERSION_OPT, SN_ANALYTICS_UTM_DB_VERSION );
sn_analytics_utm_maybe_install();
ok( count( $GLOBALS['__au_dbdelta_calls'] ) === 0, 'maybe_install: current version → no dbDelta' );

echo "\nGroup: rollup SQL builder\n";
au_reset();
$sql = sn_analytics_utm_rollup_sql( 7 );
ok( strpos( $sql, 'blob20 AS packed' ) !== false, 'utm-sql: selects the packed blob20' );
ok( strpos( $sql, "WHERE blob1 = 'pv'" ) !== false, 'utm-sql: pageviews only' );
ok( strpos( $sql, "blob20 != ''" ) !== false, 'utm-sql: excludes the ~99% of pageviews with no campaign tag' );
ok( strpos( $sql, 'blob7 AS class' ) !== false, 'utm-sql: selects class' );
ok( strpos( $sql, 'sum(_sample_interval) AS views' ) !== false, 'utm-sql: views = sample-corrected sum' );
ok( strpos( $sql, 'count(DISTINCT index1) AS visits' ) !== false, 'utm-sql: visits = distinct visitor-day hashes' );
ok( strpos( $sql, 'count(*)' ) === false && strpos( $sql, 'count(DISTINCT if' ) === false, 'utm-sql: avoids AE-invalid count(*) / count(DISTINCT <expr>)' );
ok( strpos( $sql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false, 'utm-sql: floored trailing window' );
ok( strpos( $sql, 'GROUP BY day, packed, class' ) !== false, 'utm-sql: groups by day, packed, class' );
ok( strpos( sn_analytics_utm_rollup_sql( '7; DROP TABLE x' ), 'DROP TABLE' ) === false, 'utm-sql: $days integer-cast (no injection)' );

echo "\nGroup: upsert (splits packed, hashes sig)\n";
au_reset();
$n = sn_analytics_utm_upsert( array(
	array( 'day' => '2026-07-11', 'packed' => 'google' . $US . 'cpc' . $US . 'summer_sale' . $US . $US, 'class' => 'human', 'views' => '120', 'visits' => '80' ),
) );
ok( 1 === $n, 'upsert: returns rows written' );
$q = $GLOBALS['wpdb']->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_analytics_utm' ) !== false, 'upsert: INSERT into utm table' );
ok( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false, 'upsert: idempotent upsert' );
ok( strpos( $q, "'google', 'cpc', 'summer_sale'" ) !== false, 'upsert: writes the split source/medium/campaign' );
ok( preg_match( '/[0-9a-f]{40}/', $q ) === 1, 'upsert: writes a sha1 sig for the unique key' );
foreach ( array( 'views', 'visits' ) as $col ) {
	ok( strpos( $q, "{$col}=VALUES({$col})" ) !== false, "upsert: ON DUPLICATE refreshes $col" );
}
// Empty split fields normalize to (none); bad day / class are skipped.
au_reset();
sn_analytics_utm_upsert( array( array( 'day' => '2026-07-11', 'packed' => 'newsletter' . $US . $US . $US . $US, 'class' => 'human', 'views' => 5, 'visits' => 4 ) ) );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'(none)'" ) !== false, 'upsert: empty medium/campaign → (none)' );
au_reset();
ok( 0 === sn_analytics_utm_upsert( array( array( 'day' => 'not-a-day', 'packed' => 'a' . $US . 'b', 'class' => 'human', 'views' => 1, 'visits' => 1 ) ) ), 'upsert: malformed day skipped' );
au_reset();
ok( 0 === sn_analytics_utm_upsert( array( array( 'day' => '2026-07-11', 'packed' => 'a' . $US . 'b', 'class' => 'martian', 'views' => 1, 'visits' => 1 ) ) ), 'upsert: unknown class skipped' );
au_reset();
ok( 0 === sn_analytics_utm_upsert( array( array( 'day' => '2026-07-11', 'packed' => $US . $US . $US . $US, 'class' => 'human', 'views' => 1, 'visits' => 1 ) ) ), 'upsert: an all-empty packed value carries no campaign → skipped' );

echo "\nGroup: run_rollup\n";
au_reset();
$GLOBALS['__au_config_present'] = false;
sn_analytics_utm_run_rollup();
ok( count( $GLOBALS['__au_query_calls'] ) === 0, 'run: no-op when AE is unconfigured' );
au_reset();
$GLOBALS['__au_config_present'] = true;
$GLOBALS['__au_query_return'] = array(
	array( 'day' => '2026-07-11', 'packed' => 'google' . $US . 'cpc' . $US . 'summer' . $US . $US, 'class' => 'human', 'views' => 10, 'visits' => 7 ),
);
sn_analytics_utm_run_rollup();
ok( count( $GLOBALS['__au_query_calls'] ) === 1, 'run: one AE query (single packed dimension)' );
ok( count( $GLOBALS['wpdb']->queries ) >= 1 && stripos( $GLOBALS['wpdb']->queries[0], 'INSERT INTO wp_sn_analytics_utm' ) !== false, 'run: upserts the split rows' );

echo "\nGroup: read — top campaigns\n";
au_reset();
$GLOBALS['wpdb']->results = array(
	array( 'value' => 'summer_sale', 'views' => 120, 'visits' => 80 ),
	array( 'value' => 'launch',      'views' => 40,  'visits' => 33 ),
);
$camps = sn_analytics_top_utm_campaigns( '2026-07-01', '2026-07-11', 'human', 10 );
$q = $GLOBALS['wpdb']->queries[0];
ok( strpos( $q, 'GROUP BY campaign' ) !== false, 'top-campaigns: groups by campaign' );
ok( strpos( $q, 'ORDER BY views DESC' ) !== false, 'top-campaigns: ordered by views' );
ok( strpos( $q, "class = 'human'" ) !== false, 'top-campaigns: class-filtered' );
ok( count( $camps ) === 2 && $camps[0]['value'] === 'summer_sale' && $camps[0]['views'] === 120, 'top-campaigns: maps value/views/visits' );

echo "\nGroup: read — top source/medium\n";
au_reset();
$GLOBALS['wpdb']->results = array(
	array( 'source' => 'google', 'medium' => 'cpc', 'views' => 90, 'visits' => 70 ),
);
$srcs = sn_analytics_top_utm_sources( '2026-07-01', '2026-07-11', 'human', 10 );
$q = $GLOBALS['wpdb']->queries[0];
ok( strpos( $q, 'GROUP BY source, medium' ) !== false, 'top-sources: groups by source + medium' );
ok( count( $srcs ) === 1 && $srcs[0]['value'] === 'google / cpc', 'top-sources: composes a "source / medium" label' );

echo "\nGroup: trend series (sparklines)\n";
au_reset();
$GLOBALS['wpdb']->results = array(
	array( 'day' => '2026-07-10', 'value' => 'summer_sale', 'views' => 5 ),
	array( 'day' => '2026-07-11', 'value' => 'summer_sale', 'views' => 8 ),
);
$ser = sn_analytics_utm_series( 'campaign', array( 'summer_sale' ), '2026-07-01', '2026-07-11', 'human', 'day' );
$q = $GLOBALS['wpdb']->queries[0];
ok( strpos( $q, 'campaign AS value' ) !== false, 'series[campaign]: keys on the campaign column' );
ok( strpos( $q, 'IN (' ) !== false, 'series: filters to the given top-N values (batched IN, no N+1)' );
ok( isset( $ser['summer_sale'] ) && count( $ser['summer_sale'] ) === 2 && $ser['summer_sale'][1]['views'] === 8, 'series: returns a value→[{day,views}] map for the sparkline renderer' );
au_reset();
$GLOBALS['wpdb']->results = array( array( 'day' => '2026-07-11', 'value' => 'google / cpc', 'views' => 3 ) );
$ser2 = sn_analytics_utm_series( 'source_medium', array( 'google / cpc' ), '2026-07-01', '2026-07-11', 'human', 'day' );
ok( strpos( $GLOBALS['wpdb']->queries[0], "CONCAT(source, ' / ', medium)" ) !== false, 'series[source_medium]: keys on the "source / medium" concatenation' );
ok( isset( $ser2['google / cpc'] ), 'series[source_medium]: maps by the composite label' );
au_reset();
$empty = sn_analytics_utm_series( 'campaign', array(), '2026-07-01', '2026-07-11' );
ok( $empty === array() && count( $GLOBALS['wpdb']->queries ) === 0, 'series: no values → empty map, no query issued' );

echo "\nGroup: v9.68.1 null-on-failure contract (failed read must not impersonate a quiet window)\n";
au_reset();
$GLOBALS['wpdb']->fail_reads = true;
ok( null === sn_analytics_top_utm_campaigns( '2026-09-01', '2026-09-07' ),
	'campaigns: a failed read ([] + last_error) returns NULL, never an empty-window []' );
ok( null === sn_analytics_top_utm_sources( '2026-09-01', '2026-09-07' ),
	'sources: a failed read returns NULL too' );
ok( null === sn_analytics_utm_series( 'campaign', array( 'summer_sale' ), '2026-09-01', '2026-09-07' ),
	'series: a failed read returns NULL too' );
$q_before_empty = count( $GLOBALS['wpdb']->queries );
ok( array() === sn_analytics_utm_series( 'campaign', array(), '2026-09-01', '2026-09-07' )
	&& count( $GLOBALS['wpdb']->queries ) === $q_before_empty,
	'series: an empty $values set still returns [] with NO query — a known-empty answer, not a failure' );
$GLOBALS['wpdb']->fail_reads = false;
ok( array() === sn_analytics_top_utm_campaigns( '2026-09-01', '2026-09-07' ),
	'recovery: the table healed → the same window reads [] (a real empty window is an ANSWER)' );
$GLOBALS['wpdb']->last_error = 'stale error from an EARLIER unrelated query';
ok( array() === sn_analytics_top_utm_sources( '2026-09-02', '2026-09-08' ),
	'stale: a successful read flushes a pre-existing last_error — [] stays an empty window' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
