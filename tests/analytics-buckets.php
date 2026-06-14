<?php
/**
 * Tests for inc/analytics-buckets.php — the time-of-day heatmap + scroll/time
 * distribution rollup. Mirrors tests/analytics-dims.php. Every new AE builder
 * uses only formatDateTime + sum()/sum(if()) — the primitives v5.3.0 proves work.
 * Run: php tests/analytics-buckets.php
 * @since plugin v5.4.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
define( 'SN_ANALYTICS_ROLLUP_WINDOW_DAYS', 7 );
define( 'SN_ANALYTICS_CLASSES', array( 'human', 'suspect', 'bot' ) );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }

$GLOBALS['__ab_options'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__ab_options'] ) ? $GLOBALS['__ab_options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__ab_options'][ $k ] = $v; return true; }

$GLOBALS['__ab_dbdelta_calls'] = array();
function dbDelta( $sql ) { $GLOBALS['__ab_dbdelta_calls'][] = $sql; return array(); }

// AE read-client seam. Returns a per-query fixture chosen by the SQL shape so a
// single run_rollup() pass can feed the hour query AND the two distribution
// queries distinct wide-row shapes.
$GLOBALS['__ab_config_present'] = true;
$GLOBALS['__ab_query_calls']    = array();
function sn_analytics_config() { return $GLOBALS['__ab_config_present'] ? array( 'account_id' => 'a', 'token' => 't' ) : null; }
function sn_analytics_query( $sql ) {
	$GLOBALS['__ab_query_calls'][] = $sql;
	if ( strpos( $sql, "'%H'" ) !== false ) {
		return array( array( 'day' => '2026-06-11', 'bucket' => '14', 'class' => 'human', 'views' => 12 ) );
	}
	if ( strpos( $sql, 'double1' ) !== false ) { // scroll
		return array( array( 'day' => '2026-06-11', 'class' => 'human', 'b0' => 5, 'b1' => 10, 'b2' => 20, 'b3' => 40 ) );
	}
	if ( strpos( $sql, 'double2' ) !== false ) { // time
		return array( array( 'day' => '2026-06-11', 'class' => 'human', 'b0' => 3, 'b1' => 6, 'b2' => 9, 'b3' => 12, 'b4' => 15 ) );
	}
	if ( strpos( $sql, 'double3' ) !== false ) { // botscore
		return array( array( 'day' => '2026-06-11', 'class' => 'human', 'b0' => 30, 'b1' => 15, 'b2' => 5 ) );
	}
	return array();
}

class AB_Stub_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows = array();
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
	public function query( $sql ) { $this->queries[] = $sql; return empty( $GLOBALS['__ab_query_fail'] ) ? 1 : false; }
	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql;
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) { return array(); }
		$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
		foreach ( array( 'metric', 'class' ) as $f ) {
			if ( preg_match( "/{$f} = '([^']*)'/", $sql, $mm ) ) {
				$val  = $mm[1];
				$rows = array_values( array_filter( $rows, function ( $r ) use ( $f, $val ) { return (string) ( $r[ $f ] ?? '' ) === $val; } ) );
			}
		}
		return $rows;
	}
}
$GLOBALS['wpdb'] = new AB_Stub_wpdb();

require_once __DIR__ . '/../inc/analytics-buckets.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) { global $pass, $fail; if ( $cond ) { ++$pass; echo "PASS: $msg\n"; } else { ++$fail; echo "FAIL: $msg\n"; } }
function ab_reset() {
	$GLOBALS['__ab_options']     = array();
	$GLOBALS['__ab_dbdelta_calls'] = array();
	$GLOBALS['__ab_query_calls'] = array();
	$GLOBALS['__ab_config_present'] = true;
	$GLOBALS['__ab_query_fail']  = false;
	$GLOBALS['wpdb'] = new AB_Stub_wpdb();
}

echo "Analytics buckets (heatmap + distributions) layer\n\n";

echo "Group: schema SQL\n";
ab_reset();
$schema = sn_analytics_buckets_schema_sql();
ok( is_string( $schema ) && strpos( $schema, 'wp_sn_analytics_buckets' ) !== false, 'schema: targets the prefixed buckets table' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: dbDelta two-space PRIMARY KEY form' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*,\s*metric\s*,\s*bucket\s*,\s*class\s*\)/', $schema ) === 1, 'schema: UNIQUE KEY (day, metric, bucket, class)' );
ok( strpos( $schema, 'utf8mb4' ) !== false, 'schema: includes the charset collate' );
foreach ( array( 'day', 'metric', 'bucket', 'class', 'views' ) as $col ) {
	ok( preg_match( '/\b' . $col . '\b/', $schema ) === 1, "schema: declares the $col column" );
}

echo "\nGroup: maybe_install\n";
ab_reset();
sn_analytics_buckets_maybe_install();
ok( count( $GLOBALS['__ab_dbdelta_calls'] ) === 1, 'maybe_install: missing version runs dbDelta' );
ok( get_option( SN_ANALYTICS_BUCKETS_DB_VERSION_OPT ) === SN_ANALYTICS_BUCKETS_DB_VERSION, 'maybe_install: stamps the version option' );
ab_reset();
update_option( SN_ANALYTICS_BUCKETS_DB_VERSION_OPT, SN_ANALYTICS_BUCKETS_DB_VERSION );
sn_analytics_buckets_maybe_install();
ok( count( $GLOBALS['__ab_dbdelta_calls'] ) === 0, 'maybe_install: current version → no dbDelta' );

echo "\nGroup: hour SQL builder (derives hour via formatDateTime, NOT toHour)\n";
ab_reset();
$hsql = sn_analytics_buckets_hour_sql( 7 );
ok( strpos( $hsql, "formatDateTime(timestamp, '%H') AS bucket" ) !== false, 'hour-sql: hour-of-day via formatDateTime %H (proven primitive)' );
ok( strpos( $hsql, 'toHour' ) === false && strpos( $hsql, 'toDayOfWeek' ) === false, 'hour-sql: avoids the unvalidated toHour/toDayOfWeek functions' );
ok( strpos( $hsql, 'blob7 AS class' ) !== false, 'hour-sql: selects class' );
ok( strpos( $hsql, 'sum(_sample_interval) AS views' ) !== false, 'hour-sql: sample-corrected views' );
ok( strpos( $hsql, "WHERE blob1 = 'pv'" ) !== false, 'hour-sql: pv-filtered window' );
ok( strpos( $hsql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false, 'hour-sql: floored trailing window' );
ok( strpos( $hsql, 'GROUP BY day, bucket, class' ) !== false, 'hour-sql: groups by day, bucket, class' );
ok( strpos( $hsql, 'count(' ) === false, 'hour-sql: no count() at all (dialect-clean)' );
ok( strpos( sn_analytics_buckets_hour_sql( '7; DROP TABLE x' ), 'DROP TABLE' ) === false, 'hour-sql: $days integer-cast (no injection)' );

echo "\nGroup: distribution SQL builder (sum(if(...)) buckets, NOT quantile)\n";
ab_reset();
$cfg    = sn_analytics_buckets_metrics();
$scroll = sn_analytics_buckets_dist_sql( $cfg['scroll']['event'], $cfg['scroll']['col'], $cfg['scroll']['buckets'], 7 );
ok( strpos( $scroll, "WHERE blob1 = 'sc'" ) !== false, 'dist-sql(scroll): filters to sc events' );
ok( strpos( $scroll, 'sum(if(double1 >= 0 AND double1 < 25, _sample_interval, 0)) AS b0' ) !== false, 'dist-sql(scroll): first bucket sum(if()) on double1' );
ok( strpos( $scroll, 'AS b3' ) !== false, 'dist-sql(scroll): 4 buckets (b0..b3)' );
ok( strpos( $scroll, 'quantile' ) === false, 'dist-sql(scroll): avoids the unvalidated quantile* functions' );
ok( strpos( $scroll, 'count(' ) === false, 'dist-sql(scroll): no count() (dialect-clean)' );
ok( strpos( $scroll, 'GROUP BY day, class' ) !== false, 'dist-sql(scroll): groups by day, class' );
$time = sn_analytics_buckets_dist_sql( $cfg['time']['event'], $cfg['time']['col'], $cfg['time']['buckets'], 7 );
ok( strpos( $time, "WHERE blob1 = 'tm'" ) !== false, 'dist-sql(time): filters to tm events' );
ok( strpos( $time, 'double2' ) !== false && strpos( $time, 'AS b4' ) !== false, 'dist-sql(time): double2, 5 buckets (b0..b4)' );
ok( strpos( $time, 'sum(if(double2 >= 180000, _sample_interval, 0)) AS b4' ) !== false, 'dist-sql(time): open-ended top bucket (>= only, no upper bound)' );
ok( strpos( sn_analytics_buckets_dist_sql( 'sc', 'double1', $cfg['scroll']['buckets'], '7; DROP' ), 'DROP' ) === false, 'dist-sql: $days integer-cast (no injection)' );

echo "\nGroup: botscore metric (double3 bot-confidence bands)\n";
$bs = $cfg['botscore'] ?? null;
ok( is_array( $bs ), 'botscore: metric registered' );
ok( $bs && $bs['event'] === 'pv', 'botscore: reads pageview (pv) events' );
ok( $bs && $bs['col'] === 'double3', 'botscore: reads double3 (Cloudflare bot score)' );
ok( $bs && count( $bs['buckets'] ) === 3, 'botscore: three confidence bands' );
ok( $bs && (int) $bs['buckets'][0]['lo'] === 1, 'botscore: lowest band starts at 1 (excludes the -1/0 sentinels)' );
$bsql = sn_analytics_buckets_dist_sql( 'pv', 'double3', $bs['buckets'], 7 );
ok( strpos( $bsql, "WHERE blob1 = 'pv'" ) !== false, 'botscore-sql: filters to pageview events' );
ok( strpos( $bsql, 'sum(if(double3 >= 1 AND double3 < 31, _sample_interval, 0)) AS b0' ) !== false, 'botscore-sql: band 0 = 1–30 via sum(if())' );
ok( strpos( $bsql, 'sum(if(double3 >= 61, _sample_interval, 0)) AS b2' ) !== false, 'botscore-sql: open top band 61–99' );
ok( strpos( $bsql, 'count(' ) === false, 'botscore-sql: no count() (dialect-safe)' );

echo "\nGroup: metrics config\n";
ok( count( $cfg['scroll']['buckets'] ) === 4, 'config: scroll has 4 buckets' );
ok( count( $cfg['time']['buckets'] ) === 5, 'config: time has 5 buckets' );
ok( isset( $cfg['scroll']['buckets'][0]['label'] ) && '' !== $cfg['scroll']['buckets'][0]['label'], 'config: buckets carry display labels' );

echo "\nGroup: buckets upsert\n";
ab_reset();
$rows = array(
	array( 'day' => '2026-06-11', 'metric' => 'hour',   'bucket' => '14', 'class' => 'human', 'views' => '12' ),
	array( 'day' => '2026-06-11', 'metric' => 'scroll', 'bucket' => 'b0', 'class' => 'human', 'views' => 5 ),
);
$n = sn_analytics_buckets_upsert( $rows );
ok( 2 === $n, 'upsert: returns rows written' );
$q = $GLOBALS['wpdb']->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_analytics_buckets' ) !== false, 'upsert: INSERT into buckets table' );
ok( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false && strpos( $q, 'views=VALUES(views)' ) !== false, 'upsert: idempotent views upsert' );
ok( strpos( $q, "'2026-06-11', 'hour', '14', 'human', 12" ) !== false, 'upsert: binds (day, metric, bucket, class, views) in exact order' );
ab_reset();
ok( 0 === sn_analytics_buckets_upsert( array( array( 'day' => 'bad', 'metric' => 'hour', 'bucket' => '1', 'class' => 'human', 'views' => 1 ) ) ), 'upsert: malformed day skipped' );
ab_reset();
ok( 0 === sn_analytics_buckets_upsert( array( array( 'day' => '2026-06-11', 'metric' => 'martian', 'bucket' => '1', 'class' => 'human', 'views' => 1 ) ) ), 'upsert: unknown metric skipped' );
ab_reset();
ok( 0 === sn_analytics_buckets_upsert( array( array( 'day' => '2026-06-11', 'metric' => 'hour', 'bucket' => '1', 'class' => 'martian', 'views' => 1 ) ) ), 'upsert: unknown class skipped' );

echo "\nGroup: run_rollup (melts dist wide rows into per-bucket rows)\n";
ab_reset();
sn_analytics_buckets_run_rollup();
ok( count( $GLOBALS['__ab_query_calls'] ) === 4, 'run: 4 AE queries (hour + scroll + time + botscore)' );
ok( count( $GLOBALS['wpdb']->queries ) === 1, 'run: one batched upsert' );
$uq = $GLOBALS['wpdb']->queries[0];
ok( substr_count( $uq, "'hour'" ) === 1, 'run: one hour row from the hour query fixture' );
ok( substr_count( $uq, "'scroll'" ) === 4, 'run: scroll wide-row melted into 4 bucket rows' );
ok( substr_count( $uq, "'time'" ) === 5, 'run: time wide-row melted into 5 bucket rows' );
ok( substr_count( $uq, "'botscore'" ) === 3, 'run: botscore wide-row melted into 3 bucket rows' );
ok( strpos( $uq, "'scroll', 'b3', 'human', 40" ) !== false, 'run: melt maps b3 column → bucket b3 with its count' );
ab_reset();
$GLOBALS['__ab_config_present'] = false;
sn_analytics_buckets_run_rollup();
ok( count( $GLOBALS['__ab_query_calls'] ) === 0, 'run: no AE query when unconfigured' );

echo "\nGroup: hour×dow grid accessor\n";
ab_reset();
$dow = (int) gmdate( 'N', strtotime( '2026-06-11 00:00:00 UTC' ) ); // 1=Mon..7=Sun
$GLOBALS['wpdb']->rows['wp_sn_analytics_buckets'] = array(
	array( 'day' => '2026-06-11', 'metric' => 'hour', 'bucket' => '14', 'class' => 'human', 'views' => 10 ),
	array( 'day' => '2026-06-18', 'metric' => 'hour', 'bucket' => '14', 'class' => 'human', 'views' => 5 ), // same weekday+hour → sums
	array( 'day' => '2026-06-11', 'metric' => 'hour', 'bucket' => '09', 'class' => 'human', 'views' => 3 ),
	array( 'day' => '2026-06-11', 'metric' => 'hour', 'bucket' => '14', 'class' => 'bot',   'views' => 99 ), // wrong class → excluded
);
$grid = sn_analytics_hour_dow_grid( '2026-06-01', '2026-06-30', 'human' );
ok( isset( $grid['grid'], $grid['max'] ), 'grid: returns {grid, max}' );
ok( $grid['grid'][ $dow ][14] === 15, 'grid: same dow+hour across two days sums (10+5)' );
ok( $grid['grid'][ $dow ][9] === 3, 'grid: distinct hour bucketed separately' );
ok( $grid['max'] === 15, 'grid: max is the peak cell (drives heatmap intensity)' );
ok( count( $grid['grid'] ) === 7 && count( $grid['grid'][1] ) === 24, 'grid: full 7×24 shape (zero-filled)' );
$gq = end( $GLOBALS['wpdb']->queries );
ok( strpos( $gq, "metric = 'hour'" ) !== false && strpos( $gq, "class = 'human'" ) !== false, 'grid: SQL filters metric=hour + class' );

echo "\nGroup: distribution accessor\n";
ab_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_buckets'] = array(
	array( 'day' => '2026-06-10', 'metric' => 'scroll', 'bucket' => 'b0', 'class' => 'human', 'views' => 4 ),
	array( 'day' => '2026-06-11', 'metric' => 'scroll', 'bucket' => 'b0', 'class' => 'human', 'views' => 6 ),
	array( 'day' => '2026-06-11', 'metric' => 'scroll', 'bucket' => 'b3', 'class' => 'human', 'views' => 40 ),
	array( 'day' => '2026-06-11', 'metric' => 'time',   'bucket' => 'b0', 'class' => 'human', 'views' => 7 ),
);
$dist = sn_analytics_distribution( 'scroll', '2026-06-01', '2026-06-30', 'human' );
ok( count( $dist ) === 4, 'distribution: returns one entry per configured scroll bucket' );
ok( $dist[0]['label'] === sn_analytics_buckets_metrics()['scroll']['buckets'][0]['label'], 'distribution: maps bucket key → config label, in order' );
ok( (int) $dist[0]['views'] === 10, 'distribution: sums a bucket across days (4+6)' );
ok( (int) $dist[3]['views'] === 40, 'distribution: top bucket value carried through' );
ok( (int) $dist[1]['views'] === 0, 'distribution: empty buckets zero-filled (not dropped)' );
$dq = end( $GLOBALS['wpdb']->queries );
ok( strpos( $dq, "metric = 'scroll'" ) !== false, 'distribution: SQL filters metric' );

echo "\nGroup: botscore distribution accessor\n";
ab_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_buckets'] = array(
	array( 'day' => '2026-06-11', 'metric' => 'botscore', 'bucket' => 'b0', 'class' => 'human', 'views' => 30 ),
	array( 'day' => '2026-06-11', 'metric' => 'botscore', 'bucket' => 'b2', 'class' => 'human', 'views' => 9 ),
);
$bsd = sn_analytics_distribution( 'botscore', '2026-06-01', '2026-06-30', 'human' );
ok( count( $bsd ) === 3, 'distribution(botscore): one entry per configured band (zero-filled)' );
ok( (int) $bsd[0]['views'] === 30 && (int) $bsd[2]['views'] === 9, 'distribution(botscore): band values carried; middle band zero-filled' );
ok( (int) $bsd[1]['views'] === 0, 'distribution(botscore): empty middle band zero-filled (not dropped)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
