<?php
/**
 * Tests for inc/analytics-dims.php — the referrer/country/device breakdown layer.
 * Run: php tests/analytics-dims.php
 * @since plugin v5.0.1
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
// Constants the dims module reuses from sibling modules (not loaded here).
define( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS', 7 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

$GLOBALS['__ad_options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__ad_options'] ) ? $GLOBALS['__ad_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__ad_options'][ $k ] = $v; return true; }

$GLOBALS['__ad_dbdelta_calls'] = array();
function dbDelta( $sql ) { $GLOBALS['__ad_dbdelta_calls'][] = $sql; return array(); }

// AE read-client seam (analytics-api.php not loaded here).
$GLOBALS['__ad_query_return']  = null;
$GLOBALS['__ad_query_calls']   = array();
$GLOBALS['__ad_config_present'] = true;
function sn_analytics_config() { return $GLOBALS['__ad_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
function sn_analytics_query( $sql ) { $GLOBALS['__ad_query_calls'][] = $sql; return $GLOBALS['__ad_query_return']; }

class AD_Stub_wpdb {
    public $prefix = 'wp_';
    public $queries = array();
    public $rows = array();
    // v9.68.1: model the REAL wpdb error channel (the 3rd-bite lesson — a
    // transport stub must model the transport's FAILURE shape too): query()
    // flush()es last_error per query, and a FAILED read comes back from
    // get_results(ARRAY_A) as [] WITH last_error set.
    public $last_error = '';
    public $fail_reads = false;
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
    public function query( $sql ) { $this->queries[] = $sql; return empty( $GLOBALS['__ad_query_fail'] ) ? 1 : false; }
    public function get_results( $sql, $output = ARRAY_A ) {
        $this->queries[] = $sql;
        $this->last_error = ''; // real wpdb: query() flush()es last_error before every query.
        if ( $this->fail_reads ) {
            $this->last_error = "Table 'wp_sn_analytics_dims' doesn't exist";
            return array(); // real get_results(ARRAY_A) failure shape: [] beside last_error.
        }
        if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
        $rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
        foreach ( array( 'dim', 'class' ) as $f ) {
            if ( preg_match( "/{$f} = '([^']*)'/", $sql, $mm ) ) {
                $val  = $mm[1];
                $rows = array_values( array_filter( $rows, function ( $r ) use ( $f, $val ) { return (string) ( $r[ $f ] ?? '' ) === $val; } ) );
            }
        }
        // GROUP BY value → aggregate per value.
        if ( stripos( $sql, 'GROUP BY value' ) !== false ) {
            $agg = array();
            foreach ( $rows as $r ) {
                $v = (string) $r['value'];
                if ( ! isset( $agg[ $v ] ) ) { $agg[ $v ] = array( 'value' => $v, 'views' => 0, 'visits' => 0 ); }
                $agg[ $v ]['views']  += (int) $r['views'];
                $agg[ $v ]['visits'] += (int) $r['visits'];
            }
            usort( $agg, function ( $a, $b ) { return (int) $b['views'] - (int) $a['views']; } );
            return array_values( $agg );
        }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new AD_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-dims.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }
function ad_reset() {
    $GLOBALS['__ad_options'] = array();
    $GLOBALS['__ad_dbdelta_calls'] = array();
    $GLOBALS['__ad_query_return'] = null;
    $GLOBALS['__ad_query_calls'] = array();
    $GLOBALS['__ad_config_present'] = true;
    $GLOBALS['__ad_query_fail'] = false;
    $GLOBALS['wpdb'] = new AD_Stub_wpdb();
}

echo "Analytics dims (referrer/country/device) layer\n\n";

echo "Group: schema SQL\n";
ad_reset();
$schema = sn_analytics_dims_schema_sql();
ok( is_string( $schema ) && strpos( $schema, 'wp_sn_analytics_dims' ) !== false, 'schema: targets the prefixed dims table' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: dbDelta two-space PRIMARY KEY form' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*,\s*dim\s*,\s*value\s*,\s*class\s*\)/', $schema ) === 1, 'schema: UNIQUE KEY (day, dim, value, class)' );
ok( strpos( $schema, 'VARCHAR(160)' ) !== false, 'schema: value is VARCHAR(160) so the key fits 767 bytes' );
ok( strpos( $schema, 'utf8mb4' ) !== false, 'schema: includes the charset collate' );
foreach ( array( 'day', 'dim', 'value', 'class', 'views', 'visits' ) as $col ) {
    ok( preg_match( '/\b' . $col . '\b/', $schema ) === 1, "schema: declares the $col column" );
}

echo "\nGroup: maybe_install\n";
ad_reset();
sn_analytics_dims_maybe_install();
ok( count( $GLOBALS['__ad_dbdelta_calls'] ) === 1, 'maybe_install: missing version runs dbDelta' );
ok( get_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT ) === SN_ANALYTICS_DIMS_DB_VERSION, 'maybe_install: stamps the version option' );
ad_reset();
update_option( SN_ANALYTICS_DIMS_DB_VERSION_OPT, SN_ANALYTICS_DIMS_DB_VERSION );
sn_analytics_dims_maybe_install();
ok( count( $GLOBALS['__ad_dbdelta_calls'] ) === 0, 'maybe_install: current version → no dbDelta' );

echo "\nGroup: rollup SQL builder\n";
ad_reset();
$sql = sn_analytics_dims_rollup_sql( 'referrer', 7 );
ok( strpos( $sql, 'blob3 AS value' ) !== false, 'dims-sql: referrer → blob3 AS value' );
ok( sn_analytics_dims_rollup_sql( 'country', 7 ) && strpos( sn_analytics_dims_rollup_sql( 'country', 7 ), 'blob4 AS value' ) !== false, 'dims-sql: country → blob4' );
ok( strpos( sn_analytics_dims_rollup_sql( 'device', 7 ), 'blob5 AS value' ) !== false, 'dims-sql: device → blob5' );
// v5.4.0: the 8 new edge dimensions (blob8–15, captured by worker v1.1.0).
ok( strpos( sn_analytics_dims_rollup_sql( 'browser', 7 ),  'blob8 AS value' )  !== false, 'dims-sql: browser → blob8' );
ok( strpos( sn_analytics_dims_rollup_sql( 'os', 7 ),       'blob9 AS value' )  !== false, 'dims-sql: os → blob9' );
ok( strpos( sn_analytics_dims_rollup_sql( 'region', 7 ),   'blob10 AS value' ) !== false, 'dims-sql: region → blob10' );
ok( strpos( sn_analytics_dims_rollup_sql( 'city', 7 ),     'blob11 AS value' ) !== false, 'dims-sql: city → blob11' );
ok( strpos( sn_analytics_dims_rollup_sql( 'network', 7 ),  'blob12 AS value' ) !== false, 'dims-sql: network → blob12' );
ok( strpos( sn_analytics_dims_rollup_sql( 'colo', 7 ),     'blob13 AS value' ) !== false, 'dims-sql: colo → blob13' );
ok( strpos( sn_analytics_dims_rollup_sql( 'protocol', 7 ), 'blob14 AS value' ) !== false, 'dims-sql: protocol → blob14' );
ok( strpos( sn_analytics_dims_rollup_sql( 'tls', 7 ),      'blob15 AS value' ) !== false, 'dims-sql: tls → blob15' );
ok( strpos( sn_analytics_dims_rollup_sql( 'timezone', 7 ), 'blob19 AS value' ) !== false, 'dims-sql: timezone → blob19 (v6.27.0)' );
ok( count( SN_ANALYTICS_DIM_COLUMNS ) === 12, 'dims-sql: 12 dimensions registered (3 original + 8 edge + timezone)' );
ok( strpos( $sql, 'blob7 AS class' ) !== false, 'dims-sql: selects class' );
// v5.3.0: pv-filtered window lets both aggregates use AE's documented forms
// (sum() + count(DISTINCT <column>)). AE rejects count(*)/count(DISTINCT <expr>).
ok( strpos( $sql, "WHERE blob1 = 'pv'" ) !== false, 'dims-sql: window filtered to pv events' );
ok( strpos( $sql, 'sum(_sample_interval) AS views' ) !== false, 'dims-sql: views = sample-corrected sum over pv window' );
ok( strpos( $sql, 'count(DISTINCT index1) AS visits' ) !== false, 'dims-sql: visits = distinct visitor-day hashes (plain-column DISTINCT)' );
ok( strpos( $sql, 'count(*)' ) === false && strpos( $sql, 'count(DISTINCT if' ) === false, 'dims-sql: avoids AE-invalid count(*) / count(DISTINCT <expr>)' );
ok( strpos( $sql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false, 'dims-sql: floored trailing window' );
ok( strpos( $sql, 'GROUP BY day, value, class' ) !== false, 'dims-sql: groups by day, value, class' );
ok( strpos( sn_analytics_dims_rollup_sql( 'referrer', '7; DROP TABLE x' ), 'DROP TABLE' ) === false, 'dims-sql: $days integer-cast (no injection)' );
ok( sn_analytics_dims_rollup_sql( 'martian', 7 ) === '', 'dims-sql: unknown dim → empty string (no query)' );

echo "\nGroup: dims upsert\n";
ad_reset();
$rows = array(
	array( 'day' => '2026-06-11', 'dim' => 'referrer', 'value' => 'news.ycombinator.com', 'class' => 'human', 'views' => '312', 'visits' => '98' ),
	array( 'day' => '2026-06-11', 'dim' => 'country',  'value' => 'US', 'class' => 'human', 'views' => 600, 'visits' => 200 ),
);
$n = sn_analytics_dims_upsert( $rows );
ok( 2 === $n, 'upsert: returns rows written' );
$q = $GLOBALS['wpdb']->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_analytics_dims' ) !== false, 'upsert: INSERT into dims table' );
ok( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false, 'upsert: idempotent upsert' );
ok( strpos( $q, "'2026-06-11', 'referrer', 'news.ycombinator.com', 'human', 312, 98" ) !== false, 'upsert: binds (day, dim, value, class, views, visits) in exact order' );
foreach ( array( 'views', 'visits' ) as $col ) {
	ok( strpos( $q, "{$col}=VALUES({$col})" ) !== false, "upsert: ON DUPLICATE refreshes $col" );
}
// Unknown dim / class rejected; blank referrer value normalized; over-long value truncated.
ad_reset();
sn_analytics_dims_upsert( array( array( 'day' => '2026-06-11', 'dim' => 'referrer', 'value' => '', 'class' => 'human', 'views' => 5, 'visits' => 2 ) ) );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'(direct)'" ) !== false, 'upsert: blank referrer value → (direct)' );
ad_reset();
ok( 0 === sn_analytics_dims_upsert( array( array( 'day' => '2026-06-11', 'dim' => 'martian', 'value' => 'x', 'class' => 'human', 'views' => 1, 'visits' => 1 ) ) ), 'upsert: unknown dim skipped' );
ad_reset();
$long = str_repeat( 'a', 250 );
sn_analytics_dims_upsert( array( array( 'day' => '2026-06-11', 'dim' => 'country', 'value' => $long, 'class' => 'human', 'views' => 1, 'visits' => 1 ) ) );
ok( strpos( $GLOBALS['wpdb']->queries[0], str_repeat( 'a', 161 ) ) === false, 'upsert: value truncated to 160 chars' );

echo "\nGroup: dims run_rollup\n";
ad_reset();
$GLOBALS['__ad_config_present'] = true;
// Each of the 3 dim queries returns one row; run should issue 3 queries + 1 upsert.
$GLOBALS['__ad_query_return'] = array( array( 'day' => '2026-06-11', 'value' => 'x', 'class' => 'human', 'views' => 3, 'visits' => 2 ) );
sn_analytics_dims_run_rollup();
ok( count( $GLOBALS['__ad_query_calls'] ) === count( SN_ANALYTICS_DIM_COLUMNS ), 'run: one AE query per dimension (all ' . count( SN_ANALYTICS_DIM_COLUMNS ) . ')' );
ok( count( $GLOBALS['wpdb']->queries ) === 1, 'run: one batched upsert for all dims' );
$uq = $GLOBALS['wpdb']->queries[0];
ok( substr_count( $uq, "'referrer'" ) === 1 && substr_count( $uq, "'country'" ) === 1 && substr_count( $uq, "'device'" ) === 1, 'run: tags each original row with its dim' );
ok( substr_count( $uq, "'browser'" ) === 1 && substr_count( $uq, "'network'" ) === 1 && substr_count( $uq, "'tls'" ) === 1, 'run: tags each new edge dim too' );
// Not configured → no queries.
ad_reset();
$GLOBALS['__ad_config_present'] = false;
sn_analytics_dims_run_rollup();
ok( count( $GLOBALS['__ad_query_calls'] ) === 0, 'run: no AE query when unconfigured' );

echo "\nGroup: top_dimension accessor\n";
ad_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_dims'] = array(
	array( 'day' => '2026-06-11', 'dim' => 'referrer', 'value' => 'a.com', 'class' => 'human', 'views' => 100, 'visits' => 40 ),
	array( 'day' => '2026-06-10', 'dim' => 'referrer', 'value' => 'a.com', 'class' => 'human', 'views' => 50,  'visits' => 20 ),
	array( 'day' => '2026-06-11', 'dim' => 'referrer', 'value' => 'b.com', 'class' => 'human', 'views' => 200, 'visits' => 70 ),
	array( 'day' => '2026-06-11', 'dim' => 'referrer', 'value' => 'spam',  'class' => 'bot',   'views' => 999, 'visits' => 1 ),
	array( 'day' => '2026-06-11', 'dim' => 'country',  'value' => 'US',    'class' => 'human', 'views' => 300, 'visits' => 90 ),
);
$ref = sn_analytics_top_dimension( 'referrer', '2026-06-01', '2026-06-12' ); // default human
ok( count( $ref ) === 2, 'top_dimension: human referrers only (bot excluded), grouped across days' );
ok( $ref[0]['value'] === 'b.com' && $ref[0]['views'] === 200, 'top_dimension: ordered by views desc' );
$acom = array_values( array_filter( $ref, function ( $r ) { return $r['value'] === 'a.com'; } ) );
ok( $acom[0]['views'] === 150, 'top_dimension: sums views across the day range' );
ok( is_int( $ref[0]['views'] ) && is_int( $ref[0]['visits'] ), 'top_dimension: counts normalized to int' );
$range_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $range_sql, "dim = 'referrer'" ) !== false && strpos( $range_sql, "class = 'human'" ) !== false, 'top_dimension: SQL filters dim + class' );
ok( strpos( $range_sql, 'GROUP BY value' ) !== false && strpos( $range_sql, 'ORDER BY views DESC' ) !== false, 'top_dimension: groups by value, orders by views' );
ok( strpos( $range_sql, 'day >= ' ) !== false && strpos( $range_sql, 'day <= ' ) !== false,
	'top_dimension: SQL applies BOTH the lower and upper day bound' );
// SUM(col) AS alias mapping: a SUM(visits) AS views swap must fail.
ok(
	preg_match( '/SUM\(\s*views\s*\)\s+AS\s+views/i', $range_sql ) === 1,
	'top_dimension: SUM(views) AS views — alias mapping correct'
);
ok(
	preg_match( '/SUM\(\s*visits\s*\)\s+AS\s+visits/i', $range_sql ) === 1,
	'top_dimension: SUM(visits) AS visits — alias mapping correct'
);
sn_analytics_top_dimension( 'referrer', '2026-06-01', '2026-06-12', 'human', 9999 );
ok( strpos( end( $GLOBALS['wpdb']->queries ), 'LIMIT 500' ) !== false,
	'top_dimension: limit clamps to 500 max' );
ok( strpos( $range_sql, 'LIMIT' ) !== false, 'top_dimension: applies a LIMIT' );
ok( sn_analytics_top_dimension( 'martian', '2026-06-01', '2026-06-12' ) === array(), 'top_dimension: unknown dim → empty array, no query' );
$bots = sn_analytics_top_dimension( 'referrer', '2026-06-01', '2026-06-12', 'bot' );
ok( count( $bots ) === 1 && $bots[0]['value'] === 'spam', 'top_dimension: explicit class returns that bucket' );

echo "\nGroup: top_dimension request-scope memo (D5 perf)\n";
// Distinct date range from every earlier group (and a limit that survives
// clamping unchanged) so this memo group can't collide with a key already
// primed above — e.g. the referrer/2026-06-01..12/human/25 key from the
// accessor group, or the /500-clamped key from the limit-clamp assertion.
ad_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_dims'] = array(
	array( 'day' => '2026-08-11', 'dim' => 'referrer', 'value' => 'a.com', 'class' => 'human', 'views' => 10, 'visits' => 4 ),
	array( 'day' => '2026-08-11', 'dim' => 'country',  'value' => 'US',    'class' => 'human', 'views' => 20, 'visits' => 8 ),
);
$reads_before = count( $GLOBALS['wpdb']->queries );
$m1 = sn_analytics_top_dimension( 'referrer', '2026-08-01', '2026-08-31', 'human', 10 );
$m2 = sn_analytics_top_dimension( 'referrer', '2026-08-01', '2026-08-31', 'human', 10 );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 1, 'top_dimension: identical repeat call issues exactly one underlying read' );
ok( $m1 === $m2, 'top_dimension: memoized calls return the identical cached result' );

$m3 = sn_analytics_top_dimension( 'referrer', '2026-08-01', '2026-08-31', 'bot', 10 );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 2, 'top_dimension: a different class is a distinct memo key (second read)' );

$m4 = sn_analytics_top_dimension( 'country', '2026-08-01', '2026-08-31', 'human', 10 );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 3, 'top_dimension: a different dim is a distinct memo key (third read)' );

$m5 = sn_analytics_top_dimension( 'referrer', '2026-08-01', '2026-08-31', 'human', 20 );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 4, 'top_dimension: a different limit is a distinct memo key (fourth read)' );

$m6 = sn_analytics_top_dimension( 'referrer', '2026-08-01', '2026-08-31', 'human', 10, true );
ok( count( $GLOBALS['wpdb']->queries ) === $reads_before + 5, 'top_dimension: $refresh=true re-primes the memo (fifth read)' );
ok( $m6 === $m1, 'top_dimension: refreshed read still shapes an identical result over unchanged fixture data' );

echo "\nGroup: v9.68.1 null-on-failure contract (failed read must not impersonate a quiet window)\n";
ad_reset();
$GLOBALS['wpdb']->fail_reads = true;
ok( null === sn_analytics_top_dimension( 'referrer', '2026-09-01', '2026-09-07' ),
	'failure: a failed read ([] + last_error, the real wpdb shape) returns NULL, never an empty-window []' );
$q_after_fail = count( $GLOBALS['wpdb']->queries );
ok( null === sn_analytics_top_dimension( 'referrer', '2026-09-01', '2026-09-07' )
	&& count( $GLOBALS['wpdb']->queries ) === $q_after_fail + 1,
	'failure: never memoized — the identical repeat call re-queries instead of inheriting a cached failure' );
$GLOBALS['wpdb']->fail_reads = false;
ok( array() === sn_analytics_top_dimension( 'referrer', '2026-09-01', '2026-09-07' ),
	'recovery: the table healed → the same window reads [] (a real empty window is an ANSWER)' );
$GLOBALS['wpdb']->last_error = 'stale error from an EARLIER unrelated query';
ok( array() === sn_analytics_top_dimension( 'referrer', '2026-09-02', '2026-09-08' ),
	'stale: a successful read flushes a pre-existing last_error (wpdb flush-per-query) — [] stays an empty window' );
$GLOBALS['wpdb']->fail_reads = true;
ok( array() === sn_analytics_top_dimension( 'martian', '2026-09-01', '2026-09-07' ),
	'unknown dim: still [] with NO query — a known-empty answer, not a read failure' );
$GLOBALS['wpdb']->fail_reads = false;

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
