<?php
/**
 * Tests for inc/analytics-rollup.php — the durable daily-rollup data layer.
 *
 * Exercises the pure-logic seams without a live DB / AE / WP:
 *   - sn_analytics_daily_schema_sql()  — dbDelta CREATE TABLE shape.
 *   - sn_analytics_rollup_sql( $days )  — AE SQL builder (event-type-correct
 *                                          conditional aggregation, $days cast).
 *   - sn_analytics_rollup_upsert()      — AE rows → batched ON DUPLICATE KEY UPDATE.
 *   - sn_analytics_run_rollup()         — orchestration: query → upsert → fresh stamp;
 *                                          no-op when AE not configured (query → null).
 *   - sn_analytics_daily_range()        — read accessor: range filter + type normalize.
 *   - sn_analytics_rollup_warm()        — SWR warmer scheduling decision (stale/fresh/
 *                                          already-scheduled/cap-gated).
 *   - sn_analytics_rollup_schedule()    — idempotent daily backstop registration.
 *   - sn_analytics_daily_maybe_install()— version-gated install dispatch.
 *
 * Run: php tests/analytics-rollup.php
 *
 * @since plugin v5.0.1
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// Defined by inc/analytics-api.php in production (the read-client owns it); this
// fixture doesn't load that file, so provide it for the rollup SQL builder.
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );
if ( ! defined( 'OBJECT' )   ) { define( 'OBJECT',   'OBJECT' ); }
if ( ! defined( 'ARRAY_A' )  ) { define( 'ARRAY_A',  'ARRAY_A' ); }

// ── WP function stubs ────────────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}

// Options.
$GLOBALS['__ar_options'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__ar_options'] ) ? $GLOBALS['__ar_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__ar_options'][ $key ] = $value;
	return true;
}

// Transients.
$GLOBALS['__ar_transients'] = array();
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__ar_transients'] ) ? $GLOBALS['__ar_transients'][ $key ] : false;
}
function set_transient( $key, $value, $exp = 0 ) {
	$GLOBALS['__ar_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__ar_transients'][ $key ] );
	return true;
}

// Cron scheduling — capture calls.
$GLOBALS['__ar_scheduled']        = array(); // hooks with a live next-run
$GLOBALS['__ar_single_events']    = array();
$GLOBALS['__ar_recurring_events'] = array();
function wp_next_scheduled( $hook ) {
	return in_array( $hook, $GLOBALS['__ar_scheduled'], true ) ? ( time() + 100 ) : false;
}
function wp_schedule_single_event( $ts, $hook ) {
	$GLOBALS['__ar_single_events'][] = array( 'ts' => $ts, 'hook' => $hook );
	$GLOBALS['__ar_scheduled'][]     = $hook;
	return true;
}
function wp_schedule_event( $ts, $recurrence, $hook ) {
	$GLOBALS['__ar_recurring_events'][] = array( 'ts' => $ts, 'recurrence' => $recurrence, 'hook' => $hook );
	$GLOBALS['__ar_scheduled'][]        = $hook;
	return true;
}

// Capability gate.
$GLOBALS['__ar_cap'] = true;
function current_user_can( $cap ) {
	return (bool) $GLOBALS['__ar_cap'];
}

// dbDelta — defined so the module's install() skips the upgrade.php require.
$GLOBALS['__ar_dbdelta_calls'] = array();
function dbDelta( $sql ) {
	$GLOBALS['__ar_dbdelta_calls'][] = $sql;
	return array();
}

// ── AE read-client seam (analytics-api.php is NOT loaded here; we inject) ─────
// run_rollup() depends on sn_analytics_query() + sn_analytics_config(); in
// production the main loader requires analytics-api.php first. Here we stub
// them so we can drive run_rollup's orchestration deterministically.
$GLOBALS['__ar_query_return']  = null;  // main rollup query result (rows array | RAW envelope JSON string | null)
$GLOBALS['__ar_gated_return']  = null;  // gated pageview_visits query result (same forms)
$GLOBALS['__ar_query_calls']   = array();
$GLOBALS['__ar_config_present'] = true;
function sn_analytics_config() {
	return $GLOBALS['__ar_config_present']
		? array( 'account_id' => 'acct', 'token' => 'tok' )
		: null;
}
// Task 3 stub: routes the SECOND (pv-gated) rollup query to its own fixture,
// and — when the fixture is a STRING — models the RAW AE transport pinned in
// the plan's P0 results: the envelope {meta,data,rows,rows_before_limit_at_least}
// travels as JSON and the real client (inc/analytics-api.php) returns
// json_decode(body, true)['data'] ?? null. Decoding here (instead of handing
// the module pre-cooked PHP arrays) means the rows under test carry the
// transport's TRUE types — UInt64 counts as JSON STRINGS ("views":"6"),
// Float64 sums as numbers, avgIf null beside 0 sums — the transform a
// transport stub must model (wp_localize / stub-drift memories).
// Truncation flag stub — models the REAL client's contract exactly
// (inc/analytics-api.php sn_analytics_last_result_truncated): request-scoped,
// re-recorded on every sn_analytics_query() call from the envelope counters —
// truncated iff rows >= SN_ANALYTICS_AE_ROW_CAP AND
// rows_before_limit_at_least > rows (v9.63.1: ClickHouse's before-counter can
// exceed rows on GROUP BY queries WITHOUT truncation — pre-merge aggregation
// partials are counted — so truncation requires the applied cap to have been
// REACHED); false on failure paths and on envelopes without counters. The
// real implementation is pinned by tests/analytics-api.php; this stub only
// has to carry the same verdict so the rollup module's consumers of the flag
// are drivable here.
// Mirrors inc/analytics-api.php SN_ANALYTICS_AE_ROW_CAP (the AE SQL API's
// default LIMIT — neither rollup query sets an explicit LIMIT). That module
// is deliberately NOT loaded here, so the mirror cannot collide with it.
if ( ! defined( 'SN_ANALYTICS_AE_ROW_CAP' ) ) {
	define( 'SN_ANALYTICS_AE_ROW_CAP', 10000 );
}
$GLOBALS['__ar_last_truncated'] = false;
function sn_analytics_last_result_truncated( $set = null ) {
	if ( null !== $set ) {
		$GLOBALS['__ar_last_truncated'] = (bool) $set;
	}
	return $GLOBALS['__ar_last_truncated'];
}
function sn_analytics_query( $sql ) {
	$GLOBALS['__ar_query_calls'][] = $sql;
	sn_analytics_last_result_truncated( false ); // the real client resets per call.
	$ret = ( false !== strpos( (string) $sql, 'AS pageview_visits' ) )
		? $GLOBALS['__ar_gated_return']
		: $GLOBALS['__ar_query_return'];
	if ( is_string( $ret ) ) {
		$decoded = json_decode( $ret, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		if ( isset( $decoded['rows'], $decoded['rows_before_limit_at_least'] )
			&& is_numeric( $decoded['rows'] ) && is_numeric( $decoded['rows_before_limit_at_least'] ) ) {
			sn_analytics_last_result_truncated(
				(int) $decoded['rows'] >= SN_ANALYTICS_AE_ROW_CAP
				&& (int) $decoded['rows_before_limit_at_least'] > (int) $decoded['rows']
			);
		}
		return $decoded['data'] ?? null;
	}
	return $ret; // array = legacy direct-rows fixture; null = transport failure.
}

// ── wpdb stub ────────────────────────────────────────────────────────────────
class AR_Stub_wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $queries    = array();      // recorded raw SQL
	public $rows       = array();       // table => list of row arrays (for SELECT)

	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		// Honor the PLACEHOLDER type (like real $wpdb->prepare), not the arg's
		// PHP type — so a %f→%d mutation (float truncation) is observable.
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? '';
			++$i;
			switch ( $m[0] ) {
				case '%d':
					return (string) (int) $a;
				case '%f':
					return (string) (float) $a;
				default:
					return "'" . addslashes( (string) $a ) . "'";
			}
		}, $query );
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		// Fail-mode lets a test exercise the write-failure accounting path.
		return ! empty( $GLOBALS['__ar_query_fail'] ) ? false : 1;
	}

	public function get_results( $sql, $output = ARRAY_A ) {
		$this->queries[] = $sql; // record the SELECT so its real clauses are assertable
		// daily_range SELECT: "... FROM wp_sn_analytics_daily WHERE day >= '<from>' AND day <= '<to>' ORDER BY ..."
		if ( ! preg_match( '/FROM\s+(\S+)/', $sql, $tm ) ) {
			return array();
		}
		$rows = isset( $this->rows[ $tm[1] ] ) ? $this->rows[ $tm[1] ] : array();
		if ( preg_match( "/day >= '([^']*)'/", $sql, $fm ) ) {
			$from = $fm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $from ) {
				return (string) $r['day'] >= $from;
			} ) );
		}
		if ( preg_match( "/day <= '([^']*)'/", $sql, $to_m ) ) {
			$to = $to_m[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $to ) {
				return (string) $r['day'] <= $to;
			} ) );
		}
		if ( preg_match( "/class = '([^']*)'/", $sql, $cm ) ) {
			$cls  = $cm[1];
			$rows = array_values( array_filter( $rows, function ( $r ) use ( $cls ) {
				return (string) ( $r['class'] ?? 'human' ) === $cls;
			} ) );
		}
		// GROUP BY class → return per-class SUM(views)/SUM(visits) rows.
		if ( stripos( $sql, 'GROUP BY class' ) !== false ) {
			$agg = array();
			foreach ( $rows as $r ) {
				$c = (string) ( $r['class'] ?? 'human' );
				if ( ! isset( $agg[ $c ] ) ) { $agg[ $c ] = array( 'class' => $c, 'views' => 0, 'visits' => 0 ); }
				$agg[ $c ]['views']  += (int) $r['views'];
				$agg[ $c ]['visits'] += (int) $r['visits'];
			}
			return array_values( $agg );
		}
		usort( $rows, function ( $a, $b ) {
			$cmp = strcmp( (string) $b['day'], (string) $a['day'] );
			return 0 !== $cmp ? $cmp : ( (int) $b['views'] - (int) $a['views'] );
		} );
		return $rows;
	}
}

$GLOBALS['wpdb'] = new AR_Stub_wpdb();

// P3 wire: run_rollup must drive the dims roll when the function exists.
$GLOBALS['__ar_dims_called'] = 0;
function sn_analytics_dims_run_rollup() { $GLOBALS['__ar_dims_called']++; }

// ── Load the module under test ───────────────────────────────────────────────
require_once __DIR__ . '/../inc/analytics-rollup.php';

// ── Harness ──────────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $msg\n";
	} else {
		++$fail;
		echo "FAIL: $msg\n";
	}
}
function ar_reset() {
	$GLOBALS['__ar_options']           = array();
	$GLOBALS['__ar_transients']        = array();
	$GLOBALS['__ar_scheduled']         = array();
	$GLOBALS['__ar_single_events']     = array();
	$GLOBALS['__ar_recurring_events']  = array();
	$GLOBALS['__ar_cap']               = true;
	$GLOBALS['__ar_query_return']      = null;
	$GLOBALS['__ar_gated_return']      = null;
	$GLOBALS['__ar_query_calls']       = array();
	$GLOBALS['__ar_config_present']    = true;
	$GLOBALS['__ar_dbdelta_calls']     = array();
	$GLOBALS['__ar_query_fail']        = false;
	$GLOBALS['__ar_dims_called']       = 0;
	$GLOBALS['__ar_last_truncated']    = false;
	$GLOBALS['wpdb']                   = new AR_Stub_wpdb();
}

echo "Analytics daily-rollup data layer — plugin v5.0.1\n\n";

// ── Schema SQL ────────────────────────────────────────────────────────────────
echo "Group: schema SQL\n";
ar_reset();
$schema = sn_analytics_daily_schema_sql();
ok( is_string( $schema ) && '' !== $schema, 'schema: returns a non-empty string' );
ok( strpos( $schema, 'wp_sn_analytics_daily' ) !== false, 'schema: targets the prefixed table name' );
ok( strpos( $schema, 'PRIMARY KEY  (id)' ) !== false, 'schema: PRIMARY KEY has the dbDelta two-space form' );
ok( strpos( $schema, 'UNIQUE KEY' ) !== false, 'schema: declares a UNIQUE KEY' );
foreach ( array( 'day', 'path', 'views', 'visits', 'scroll_avg', 'time_avg' ) as $col ) {
	ok( preg_match( '/\b' . $col . '\b/', $schema ) === 1, "schema: declares the $col column" );
}
// v5 — engagement-sum columns + the gated pageview_visits denominator (five
// columns total; pageview_visits was amended in post-review — spec §4/§8 store
// it per daily row so the read layer can range-sum it). NULLABLE is load-
// bearing: legacy rows (rolled before v5) must read NULL ("never measured"),
// never a fabricated 0, so the derive layer can tell "no data" from a real
// zero (realtime-zero-vs-null).
foreach ( array(
	'scroll_sum FLOAT NULL DEFAULT NULL',
	'scroll_events INT UNSIGNED NULL DEFAULT NULL',
	'time_sum FLOAT NULL DEFAULT NULL',
	'time_events INT UNSIGNED NULL DEFAULT NULL',
	'pageview_visits INT UNSIGNED NULL DEFAULT NULL',
) as $decl ) {
	ok( strpos( $schema, $decl ) !== false, "schema: declares nullable v5 column: $decl" );
}
ok( strpos( $schema, 'utf8mb4' ) !== false, 'schema: includes the charset collate' );
ok( preg_match( '/\bclass\b/', $schema ) === 1, 'schema: declares the class column' );
ok( preg_match( '/UNIQUE KEY\s+\w+\s*\(\s*day\s*,\s*path\s*,\s*class\s*\)/', $schema ),
	'schema: UNIQUE KEY is now (day, path, class)' );
ok( strpos( $schema, 'VARCHAR(180)' ) !== false, 'schema: path shrunk to 180 so the 3-col key fits 767 bytes' );

// ── Rollup SQL builder ────────────────────────────────────────────────────────
echo "\nGroup: rollup SQL builder\n";
ar_reset();
$sql = sn_analytics_rollup_sql( 7 );
ok( strpos( $sql, 'FROM sn_pageviews' ) !== false, 'rollup-sql: FROM the sn_pageviews dataset' );
ok( strpos( $sql, "sumIf(_sample_interval, blob1 = 'pv')" ) !== false, 'rollup-sql: views = sumIf(_sample_interval, pv)' );
ok( strpos( $sql, 'count(DISTINCT index1)' ) !== false, 'rollup-sql: visits = count(DISTINCT index1)' );
ok( strpos( $sql, "avgIf(double1, blob1 = 'sc')" ) !== false, 'rollup-sql: scroll_avg = avgIf(double1, sc)' );
ok( strpos( $sql, "avgIf(double2, blob1 = 'tm')" ) !== false, 'rollup-sql: time_avg = avgIf(double2, tm)' );
ok( strpos( $sql, "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')" ) !== false, 'rollup-sql: day-bucket via toStartOfDay+formatDateTime' );
ok( strpos( $sql, 'blob2 AS path' ) !== false, 'rollup-sql: path = blob2' );
ok( strpos( $sql, 'blob7 AS class' ) !== false, 'rollup-sql: selects blob7 AS class' );
ok( preg_match( "/INTERVAL '7' DAY/", $sql ) === 1, 'rollup-sql: window uses the $days arg' );
ok( strpos( $sql, "toStartOfDay(now() - INTERVAL '7' DAY)" ) !== false,
	'rollup-sql: window lower bound is floored to a day boundary (oldest bucket is a complete day)' );
ok( strpos( $sql, 'GROUP BY day, path, class' ) !== false, 'rollup-sql: groups by day, path AND class' );
// Injection guard — $days must be integer-cast, never interpolated raw.
$evil = sn_analytics_rollup_sql( "7; DROP TABLE x" );
ok( strpos( $evil, 'DROP TABLE' ) === false && preg_match( "/INTERVAL '7' DAY/", $evil ) === 1,
	'rollup-sql: $days is integer-cast (no SQL injection)' );
$zero = sn_analytics_rollup_sql( 0 );
ok( preg_match( "/INTERVAL '1' DAY/", $zero ) === 1, 'rollup-sql: a non-positive window floors to 1 day' );

// v9.26.4 — timezone-aware bucketing. A valid IANA zone buckets each row by the
// SITE-LOCAL calendar day (AE's formatDateTime/toStartOfInterval timezone arg), so
// the durable table's "day" matches the live "views today" (same zone) instead of a
// UTC day that rolls mid-evening for western zones.
$tzsql = sn_analytics_rollup_sql( 7, 'America/New_York' );
ok( strpos( $tzsql, "formatDateTime(timestamp, '%Y-%m-%d', 'America/New_York')" ) !== false,
	'rollup-sql: zoned day-bucket formats in the site IANA zone' );
ok( strpos( $tzsql, "toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York') - INTERVAL '7' DAY" ) !== false,
	'rollup-sql: zoned lower bound floors to local-day start, N days back' );
ok( strpos( $tzsql, 'toStartOfDay(' ) === false, 'rollup-sql: zoned query drops the UTC toStartOfDay bucketing' );
// An empty zone keeps the UTC path (unchanged); an injectable string is rejected → UTC.
$utcsql = sn_analytics_rollup_sql( 7, '' );
ok( strpos( $utcsql, "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')" ) !== false, 'rollup-sql: empty zone → UTC day bucket' );
$eviltz = sn_analytics_rollup_sql( 7, "UTC'; DROP TABLE x --" );
ok( strpos( $eviltz, 'DROP TABLE' ) === false && strpos( $eviltz, "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')" ) !== false,
	'rollup-sql: an injectable zone string is rejected → UTC path' );

// ── Task 3: weighted engagement columns on the main SELECT (P0.2 live-verified) ─
// The extended SELECT is EXACTLY the probe's P0.2 weighted shape that returned
// HTTP 200 on live AE: the four weighted columns appended after the kept avgIf
// pair. Event counts are the WEIGHTED sumIf(_sample_interval, cond) — never a
// raw countIf (under sampling, counts are sum(_sample_interval)).
echo "\nGroup: rollup SQL — weighted engagement columns (Task 3)\n";
$sql = sn_analytics_rollup_sql( 7 );
ok( strpos( $sql, "sumIf(double1 * _sample_interval, blob1 = 'sc') AS scroll_sum" ) !== false,
	'rollup-sql: scroll_sum = sumIf(double1 * _sample_interval, sc) — the P0.2 multiplication form' );
ok( strpos( $sql, "sumIf(_sample_interval, blob1 = 'sc') AS scroll_events" ) !== false,
	'rollup-sql: scroll_events = sumIf(_sample_interval, sc) — weighted count, not countIf' );
ok( strpos( $sql, "sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum" ) !== false,
	'rollup-sql: time_sum = sumIf(double2 * _sample_interval, tm)' );
ok( strpos( $sql, "sumIf(_sample_interval, blob1 = 'tm') AS time_events" ) !== false,
	'rollup-sql: time_events = sumIf(_sample_interval, tm)' );
// Pin the FULL live-verified SELECT: kept avgIf pair, then the four weighted
// columns, in this exact order — the query AE parsed on 2026-07-17.
$expected_main = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
	. 'blob2 AS path, blob7 AS class, '
	. "sumIf(_sample_interval, blob1 = 'pv') AS views, "
	. 'count(DISTINCT index1) AS visits, '
	. "avgIf(double1, blob1 = 'sc') AS scroll_avg, "
	. "avgIf(double2, blob1 = 'tm') AS time_avg, "
	. "sumIf(double1 * _sample_interval, blob1 = 'sc') AS scroll_sum, "
	. "sumIf(_sample_interval, blob1 = 'sc') AS scroll_events, "
	. "sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum, "
	. "sumIf(_sample_interval, blob1 = 'tm') AS time_events "
	. 'FROM sn_pageviews '
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '7' DAY) "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, views DESC';
ok( $expected_main === $sql, 'rollup-sql: FULL SELECT === the pinned P0.2 live-verified shape' );
// The banned dialect form must never sneak in — live AE 422s it (v5.2.0 +
// re-confirmed by the P0.1 primary probe); the dialect guard ban STAYS.
ok( strpos( $sql, 'count(DISTINCT if(' ) === false, 'rollup-sql: no count(DISTINCT <expr>) — the banned 422 form' );

// ── Task 3: gated pageview_visits SQL (P0.1 verdict: FALLBACK A — second query) ─
// The live P0.1 probe rejected the single-query gated distinct (HTTP 422:
// IF() branches must share a type — String vs Null), so pageview_visits comes
// from a SECOND query: the existing verified visits shape with AND blob1='pv'
// in WHERE and count(DISTINCT index1) — the exact form that returned HTTP 200.
echo "\nGroup: gated pageview_visits SQL builder (Task 3)\n";
$gated_sql = sn_analytics_rollup_gated_sql( 7 );
$expected_gated = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
	. 'blob2 AS path, blob7 AS class, '
	. 'count(DISTINCT index1) AS pageview_visits '
	. 'FROM sn_pageviews '
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '7' DAY) "
	. "AND blob1 = 'pv' "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, pageview_visits DESC';
ok( $expected_gated === $gated_sql, 'gated-sql: FULL SELECT === the pinned P0.1 Fallback A live-verified shape' );
// Alias-only ORDER BY gotcha: the `views` alias does not exist in this SELECT,
// so ordering by it would 422 — the ORDER BY must use the defined alias.
// (Substring-match `AS views` / `views DESC`, not bare `views` — the dataset
// name sn_pageviews legitimately contains it.)
ok( strpos( $gated_sql, 'AS views' ) === false && strpos( $gated_sql, 'views DESC' ) === false,
	'gated-sql: no stale `views` alias in SELECT or ORDER BY' );
ok( strpos( $gated_sql, 'avgIf' ) === false && strpos( $gated_sql, 'count(DISTINCT if(' ) === false,
	'gated-sql: no engagement aggregates, no banned gated-distinct form' );
// Same window/zone plumbing as the main query — keys must align for the merge.
$gated_tz = sn_analytics_rollup_gated_sql( 7, 'America/New_York' );
ok( strpos( $gated_tz, "formatDateTime(timestamp, '%Y-%m-%d', 'America/New_York')" ) !== false
	&& strpos( $gated_tz, "toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York') - INTERVAL '7' DAY" ) !== false,
	'gated-sql: zoned day bucket + floored lower bound mirror the main query exactly' );
$gated_evil = sn_analytics_rollup_gated_sql( '7; DROP TABLE x', "UTC'; DROP TABLE x --" );
ok( strpos( $gated_evil, 'DROP TABLE' ) === false && preg_match( "/INTERVAL '7' DAY/", $gated_evil ) === 1,
	'gated-sql: $days integer-cast and injectable zone rejected (same guards as main)' );

// ── Task 3: merging the gated second query into the main rows ─────────────────
echo "\nGroup: gated merge (Task 3)\n";
$main_rows = array(
	array( 'day' => '2026-07-15', 'path' => '/',      'class' => 'human', 'views' => '6', 'visits' => '4' ),
	array( 'day' => '2026-07-15', 'path' => '/feed/', 'class' => 'human', 'views' => '0', 'visits' => '3' ),
	array( 'day' => '2026-07-15', 'path' => '/',      'class' => 'bot',   'views' => '9', 'visits' => '2' ),
);
// Gated query FAILED (null): pageview_visits must stay ABSENT on every row —
// never a fabricated 0 ("we did not measure" ≠ "we measured zero").
$merged_fail = sn_analytics_rollup_merge_gated( $main_rows, null );
ok( count( $merged_fail ) === 3 && ! array_key_exists( 'pageview_visits', $merged_fail[0] )
	&& ! array_key_exists( 'pageview_visits', $merged_fail[1] ),
	'merge: gated-query failure (null) leaves pageview_visits ABSENT on every row' );
// Gated query SUCCEEDED: matched keys take the gated value; a (day,path,class)
// with no gated row means genuinely zero pv-gated visitor-days — a REAL 0
// (empty result is an ANSWER), not null.
$gated_rows = array(
	array( 'day' => '2026-07-15', 'path' => '/', 'class' => 'human', 'pageview_visits' => '4' ),
);
$merged = sn_analytics_rollup_merge_gated( $main_rows, $gated_rows );
ok( ( $merged[0]['pageview_visits'] ?? null ) === '4',
	'merge: matched (day, path, class) key carries the gated value (transport numeric-string intact)' );
ok( array_key_exists( 'pageview_visits', $merged[1] ) && 0 === $merged[1]['pageview_visits'],
	'merge: key absent from a SUCCESSFUL gated result = REAL 0 (viewless row), never null' );
ok( 0 === ( $merged[2]['pageview_visits'] ?? null ),
	'merge: class is part of the key — a human gated row does not attach to the bot row' );
// Empty gated result (quiet window) → every row gets the real 0.
$merged_empty = sn_analytics_rollup_merge_gated( $main_rows, array() );
ok( 0 === $merged_empty[0]['pageview_visits'] && 0 === $merged_empty[1]['pageview_visits'],
	'merge: empty gated result (an ANSWER) → real 0 on every row' );
// Immutability: the input array is not mutated.
ok( ! array_key_exists( 'pageview_visits', $main_rows[0] ), 'merge: input rows are not mutated (new array returned)' );

// ── Upsert ────────────────────────────────────────────────────────────────────
echo "\nGroup: upsert\n";
ar_reset();
$rows = array(
	array( 'day' => '2026-06-11', 'path' => '/notes/a', 'class' => 'human', 'views' => '42', 'visits' => '30', 'scroll_avg' => '58.5', 'time_avg' => '12345' ),
	array( 'day' => '2026-06-11', 'path' => '/',        'class' => 'bot',   'views' => 100,  'visits' => 80,   'scroll_avg' => 40,     'time_avg' => 5000 ),
);
$n = sn_analytics_rollup_upsert( $rows );
ok( 2 === $n, 'upsert: returns the number of rows written' );
$wpdb = $GLOBALS['wpdb'];
ok( count( $wpdb->queries ) === 1, 'upsert: writes a single batched query for both rows' );
$q = $wpdb->queries[0];
ok( stripos( $q, 'INSERT INTO wp_sn_analytics_daily' ) !== false, 'upsert: INSERT into the table' );
ok( stripos( $q, 'ON DUPLICATE KEY UPDATE' ) !== false, 'upsert: uses ON DUPLICATE KEY UPDATE' );
// Full VALUES tuple in EXACT column order — distinct sentinels pin both
// position and per-column binding (catches swapped columns). scroll_avg/time_avg
// are number_format()'d '.'-decimal strings bound via %s, so they read as quoted
// '58.50' / '12345.00', not raw %f floats.
ok( strpos( $q, "'2026-06-11', '/notes/a', 'human', 42, 30, '58.50', '12345.00'" ) !== false,
	'upsert: binds (day, path, class, views, visits, scroll_avg, time_avg) in exact order' );
// Every metric column is refreshed on conflict, not just views — this is the
// recomputed-partial-day self-correction guarantee. The five v5 columns join
// the refresh set so a re-roll updates them too.
foreach ( array( 'views', 'visits', 'scroll_avg', 'time_avg', 'scroll_sum', 'scroll_events', 'time_sum', 'time_events', 'pageview_visits' ) as $col ) {
	ok( strpos( $q, "{$col}=VALUES({$col})" ) !== false, "upsert: ON DUPLICATE refreshes $col" );
}
// Legacy rows (the five v5 keys ABSENT) bind literal NULL — "never measured" —
// NOT the `?? 0` fabricated zero the four NOT NULL legacy columns rightly use.
ok( strpos( $q, "'2026-06-11', '/notes/a', 'human', 42, 30, '58.50', '12345.00', NULL, NULL, NULL, NULL, NULL" ) !== false,
	'upsert: v5 keys absent → the five nullable columns bind literal NULL (never a fabricated 0)' );

// Malformed rows are skipped, not written.
ar_reset();
$bad = array(
	array( 'path' => '/no-day', 'views' => 1 ),                // missing day
	array( 'day' => '2026-06-11', 'views' => 1 ),              // missing path
	array( 'day' => 'not-a-date', 'path' => '/x', 'views' => 1 ), // malformed day
);
$n = sn_analytics_rollup_upsert( $bad );
ok( 0 === $n, 'upsert: skips rows missing day/path or with a malformed day' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'upsert: no query issued when every row is malformed' );

// Empty input → no query.
ar_reset();
ok( 0 === sn_analytics_rollup_upsert( array() ), 'upsert: empty input returns 0' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'upsert: empty input issues no query' );

// Value normalization: negative views/visits clamp to 0, averages round to 2dp,
// and an over-long path truncates to 180 chars.
ar_reset();
$long_path = '/' . str_repeat( 'a', 250 );
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => $long_path, 'views' => -5, 'visits' => -1, 'scroll_avg' => '58.567', 'time_avg' => '12345.6789' ),
) );
$qn = $GLOBALS['wpdb']->queries[0];
ok( strpos( $qn, "'/" . str_repeat( 'a', 179 ) . "'" ) !== false && strpos( $qn, str_repeat( 'a', 181 ) ) === false,
	'upsert: path truncated to 180 chars' );
ok( strpos( $qn, ", 0, 0, '58.57', '12345.68'" ) !== false,
	'upsert: negative counts clamp to 0; averages round to 2 decimals' );

// Unknown class is rejected (never stored; defensive allow-list).
ar_reset();
$n = sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'class' => 'martian', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( 0 === $n, 'upsert: a row with an unknown class is skipped' );

// Missing class defaults to human.
ar_reset();
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'views' => 1, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'/x', 'human'" ) !== false, 'upsert: a row with no class defaults to human' );

// A failed $wpdb->query() (false) must NOT be counted as written.
ar_reset();
$GLOBALS['__ar_query_fail'] = true;
$nf = sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'views' => 1, 'visits' => 1, 'scroll_avg' => 1, 'time_avg' => 1 ),
) );
ok( 0 === $nf, 'upsert: a failed write (query returns false) is not counted' );

// ── Task 3: v5 nullable-column binding discipline ─────────────────────────────
echo "\nGroup: upsert v5 columns — NULL vs 0 discipline (Task 3)\n";
// Present-but-NULL is the same answer as absent: literal NULL, never 0.
ar_reset();
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/x', 'class' => 'human',
		'views' => 1, 'visits' => 1, 'scroll_avg' => 2.5, 'time_avg' => 100,
		'scroll_sum' => null, 'scroll_events' => null, 'time_sum' => null, 'time_events' => null, 'pageview_visits' => null,
	),
) );
$qnull = $GLOBALS['wpdb']->queries[0];
ok( strpos( $qnull, "'2026-06-11', '/x', 'human', 1, 1, '2.50', '100.00', NULL, NULL, NULL, NULL, NULL" ) !== false,
	'upsert: present-but-NULL v5 keys bind literal NULL (absent ≡ null ≡ never measured)' );
ok( strpos( $qnull, "'0.0000'" ) === false, 'upsert: a NULL sum is never rewritten as a 0.0000 string' );
// When the weighted inputs are unknown, the legacy scroll_avg/time_avg pass
// through unchanged (2.50 / 100.00 above) — the weighted switch needs BOTH
// the sum and the event count.

// Real zeros are a MEASURED answer: bound as 0, never erased into NULL.
ar_reset();
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/quiet', 'class' => 'human',
		'views' => 0, 'visits' => 2, 'scroll_avg' => null, 'time_avg' => null,
		'scroll_sum' => 0, 'scroll_events' => '0', 'time_sum' => 0, 'time_events' => '0', 'pageview_visits' => 0,
	),
) );
$qzero = $GLOBALS['wpdb']->queries[0];
ok( strpos( $qzero, "'2026-06-11', '/quiet', 'human', 0, 2, '0.00', '0.00', '0.0000', 0, '0.0000', 0, 0" ) !== false,
	'upsert: measured zeros (incl. transport "0" strings) bind as real 0s — never NULL' );

// FLOAT sums bind as 4dp dot-decimal strings via %s — the %f LC_NUMERIC hazard
// applies to the new columns exactly as it does to the legacy averages.
ar_reset();
$__saved_numeric_v5 = setlocale( LC_NUMERIC, '0' );
setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.ISO8859-1' ); // no-op if uninstalled
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/x', 'class' => 'human',
		'views' => 3, 'visits' => 2, 'scroll_avg' => 0, 'time_avg' => 0,
		'scroll_sum' => 190.5, 'scroll_events' => 4, 'time_sum' => 294971.25, 'time_events' => 3, 'pageview_visits' => 2,
	),
) );
$qfloat = $GLOBALS['wpdb']->queries[0];
// v9.66.0 (schema v6): the STORED scroll_sum is the depth identity 25 ×
// scroll_events (25 × 4 = 100.0000), NOT the transported raw milestone-point
// sum (190.5). time_sum is genuinely event-summed ms — passthrough untouched.
ok( strpos( $qfloat, "'100.0000'" ) !== false && strpos( $qfloat, "'294971.2500'" ) !== false,
	'upsert: v5 FLOAT sums bind as 4dp dot-decimal %s strings (scroll_sum in true depth units)' );
ok( strpos( $qfloat, '100,0' ) === false && strpos( $qfloat, '294971,25' ) === false && strpos( $qfloat, '294,971' ) === false,
	'upsert: no comma decimal/thousands in the v5 sums under a de_DE LC_NUMERIC' );
if ( false !== $__saved_numeric_v5 ) { setlocale( LC_NUMERIC, $__saved_numeric_v5 ); }

// Legacy scroll_avg/time_avg switch to the weighted ratio sum/events when both
// are known — identical to avgIf at sample interval 1. The fixture's transported
// avgIf (40) deliberately DIFFERS from the weighted ratio (190/4 = 47.5) so a
// passthrough that skips the switch cannot pass. Zero events → ratio undefined
// (null) → the NOT NULL legacy column's 0, exactly what avgIf-null → `?? 0`
// produced before — no visible change at interval 1.
ar_reset();
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/w', 'class' => 'human',
		'views' => 4, 'visits' => 4, 'scroll_avg' => 40, 'time_avg' => 999,
		'scroll_sum' => 190, 'scroll_events' => '4', 'time_sum' => 0, 'time_events' => '0', 'pageview_visits' => 4,
	),
) );
$qw = $GLOBALS['wpdb']->queries[0];
// The RAW transported sum (190) still weights the legacy scroll_avg (190/4 =
// 47.50) while the STORED scroll_sum column carries the depth identity
// (25 × 4 = 100.0000) — both on the same tuple, so a passthrough store OR a
// legacy avg computed from the re-based sum (25) would each fail this pin.
ok( strpos( $qw, "'2026-06-11', '/w', 'human', 4, 4, '47.50', '0.00', '100.0000', 4, '0.0000', 0, 4" ) !== false,
	'upsert: legacy avgs = weighted RAW sum/events (47.50 not 40 or 25.00); stored scroll_sum = 25 x events (100.0000, not 190)' );

// ── v9.66.0: stored scroll_sum invariant — 25 × scroll_events or NULL ─────────
echo "\nGroup: stored scroll_sum = 25 x scroll_events (true depth units, v9.66.0)\n";
// scroll_events known but the raw scroll_sum key ABSENT (legacy caller shape):
// the identity needs only the event count — the stored column still gets it.
ar_reset();
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/x', 'class' => 'human',
		'views' => 3, 'visits' => 2, 'scroll_avg' => 40, 'time_avg' => 0,
		'scroll_events' => '4',
	),
) );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'2026-06-11', '/x', 'human', 3, 2, '40.00', '0.00', '100.0000', 4, NULL, NULL, NULL" ) !== false,
	'identity: events known + raw sum absent → stored scroll_sum still 25 x events (100.0000); legacy avg falls back to transported' );
// scroll_events NULL beside a present raw sum: the identity is unknown — the
// stored column binds NULL. Storing the raw 190 milestone-point sum again
// would silently reintroduce the shipped-113% unit.
ar_reset();
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/x', 'class' => 'human',
		'views' => 3, 'visits' => 2, 'scroll_avg' => 40, 'time_avg' => 0,
		'scroll_sum' => 190, 'scroll_events' => null,
	),
) );
$qi = $GLOBALS['wpdb']->queries[0];
ok( strpos( $qi, "'40.00', '0.00', NULL, NULL" ) !== false && strpos( $qi, "'190.0000'" ) === false,
	'identity: events NULL → stored scroll_sum NULL (raw milestone-points are NEVER stored again)' );
// Measured-zero events → a REAL 0.0000 depth sum (identity holds at 0), never NULL.
ar_reset();
sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-06-11', 'path' => '/x', 'class' => 'human',
		'views' => 3, 'visits' => 2, 'scroll_avg' => 0, 'time_avg' => 0,
		'scroll_sum' => '0', 'scroll_events' => '0',
	),
) );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'0.00', '0.00', '0.0000', 0, NULL, NULL, NULL" ) !== false,
	'identity: measured-zero events → stored scroll_sum is a real 0.0000 (25 x 0), never NULL' );

// ── Task 3: rollup-side never-invert guard (human class) ─────────────────────
echo "\nGroup: integrity guard (Task 3)\n";
// views < pageview_visits on a human row is arithmetically impossible (spec §5)
// — if it happens the ALARM is the feature: error_log + a timestamped option
// payload, and the row is STILL WRITTEN unmodified. Never clamp, never skip.
ar_reset();
$guard_log = tempnam( sys_get_temp_dir(), 'sn_guard' );
$old_error_log = ini_set( 'error_log', $guard_log );
$n = sn_analytics_rollup_upsert( array(
	array(
		'day' => '2026-07-16', 'path' => '/', 'class' => 'human',
		'views' => 2, 'visits' => 5, 'scroll_avg' => null, 'time_avg' => null,
		'scroll_sum' => 0, 'scroll_events' => '0', 'time_sum' => 0, 'time_events' => '0', 'pageview_visits' => '5',
	),
) );
ini_set( 'error_log', (string) $old_error_log );
ok( 1 === $n, 'guard: the inverted row is STILL WRITTEN (return counts it)' );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'2026-07-16', '/', 'human', 2, 5, '0.00', '0.00', '0.0000', 0, '0.0000', 0, 5" ) !== false,
	'guard: the written tuple carries the raw inverted values un-clamped (views 2, pageview_visits 5)' );
$alert = get_option( 'sn_analytics_integrity_alert' );
ok( is_array( $alert ) && is_int( $alert['time'] ?? null )
	&& '2026-07-16' === ( $alert['day'] ?? '' ) && '/' === ( $alert['path'] ?? '' )
	&& 2 === ( $alert['views'] ?? null ) && 5 === ( $alert['pageview_visits'] ?? null ),
	'guard: sn_analytics_integrity_alert option holds the timestamped violation payload' );
$logged = (string) @file_get_contents( $guard_log );
ok( strpos( $logged, '[sn-analytics] integrity violation' ) !== false && strpos( $logged, '2026-07-16' ) !== false,
	'guard: error_log records the violation with the offending (day, path)' );
@unlink( $guard_log );

// The guard is HUMAN-class only, and silent when pageview_visits is NULL or
// the arithmetic holds — no alert on healthy or unmeasured rows.
ar_reset();
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-07-16', 'path' => '/b', 'class' => 'bot', 'views' => 1, 'visits' => 3, 'scroll_avg' => 0, 'time_avg' => 0, 'scroll_sum' => 0, 'scroll_events' => 0, 'time_sum' => 0, 'time_events' => 0, 'pageview_visits' => 3 ),
	array( 'day' => '2026-07-16', 'path' => '/ok', 'class' => 'human', 'views' => 9, 'visits' => 4, 'scroll_avg' => 0, 'time_avg' => 0, 'scroll_sum' => 0, 'scroll_events' => 0, 'time_sum' => 0, 'time_events' => 0, 'pageview_visits' => 4 ),
	array( 'day' => '2026-07-16', 'path' => '/legacy', 'class' => 'human', 'views' => 1, 'visits' => 5, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( false === get_option( 'sn_analytics_integrity_alert' ),
	'guard: silent for bot inversion, healthy human rows, and NULL (unmeasured) pageview_visits' );

// ── Admin/login path exclusion (ingestion guard) ──────────────────────────────
// Admin & login paths are never real human pageviews — the front-end beacon can't
// fire in wp-admin/wp-login.php. If a stray beacon still lands one in AE, the
// rollup must drop it rather than store it (matches the retired importer's rule).
ar_reset();
$n = sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/wp-admin',             'views' => 5, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/wp-admin/',            'views' => 5, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/wp-admin/options.php', 'views' => 5, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/wp-login.php',         'views' => 5, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/wp-login.php?action=logout', 'views' => 5, 'visits' => 1, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( 0 === $n, 'upsert: admin/login paths are skipped (never stored as pageviews)' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'upsert: an all-admin batch issues no query' );

// Boundary-aware: a legit front-end path that merely starts with the "/wp-admin"
// TEXT must NOT be caught (regression against a loose strpos prefix match).
ar_reset();
$n = sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/wp-admin-guide/', 'views' => 3, 'visits' => 2, 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/wp-admin',        'views' => 9, 'visits' => 4, 'scroll_avg' => 0, 'time_avg' => 0 ),
) );
ok( 1 === $n, 'upsert: only the real admin path is skipped; /wp-admin-guide/ is kept' );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'/wp-admin-guide/'" ) !== false, 'upsert: /wp-admin-guide/ is stored' );
ok( strpos( $GLOBALS['wpdb']->queries[0], "'/wp-admin'," ) === false, 'upsert: the exact /wp-admin path is not stored' );

// ── excluded-path predicate ───────────────────────────────────────────────────
echo "\nGroup: excluded-path predicate\n";
foreach ( array( '/wp-admin', '/wp-admin/', '/wp-admin/options-general.php', '/wp-login.php', '/wp-login.php?action=logout' ) as $p ) {
	ok( sn_analytics_is_excluded_path( $p ), "excluded-path: $p is excluded" );
}
foreach ( array( '/', '/notes/a', '/wp-admin-guide/', '/resume/', '/about/' ) as $p ) {
	ok( ! sn_analytics_is_excluded_path( $p ), "excluded-path: $p is NOT excluded" );
}

// ── Locale-safe float binding (regression) ────────────────────────────────────
// $wpdb->prepare() routes %f through vsprintf(), which is LC_NUMERIC-sensitive:
// under a comma-decimal server locale (de_DE, pt_BR, …) a raw-float %f renders
// 58.5 as "58,5" — corrupt SQL. scroll_avg/time_avg must therefore be bound as
// '.'-decimal strings (number_format → %s), never as %f floats, so the generated
// SQL is identical regardless of the server's LC_NUMERIC.
echo "\nGroup: locale-safe float binding\n";
$__saved_numeric = setlocale( LC_NUMERIC, '0' ); // query current, for restore
setlocale( LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE.ISO8859-1' ); // no-op if uninstalled
ar_reset();
sn_analytics_rollup_upsert( array(
	array( 'day' => '2026-06-11', 'path' => '/x', 'class' => 'human', 'views' => 1, 'visits' => 1, 'scroll_avg' => 58.5, 'time_avg' => 12345.5 ),
) );
$ql = $GLOBALS['wpdb']->queries[0];
ok( strpos( $ql, "'58.50'" ) !== false,
	'locale-safe: scroll_avg bound as a dot-decimal 2dp string (%s), not a %f float' );
ok( strpos( $ql, "'12345.50'" ) !== false,
	'locale-safe: time_avg bound as a dot-decimal string with NO thousands separator' );
ok( strpos( $ql, '58,5' ) === false && strpos( $ql, '12345,5' ) === false && strpos( $ql, '12,345' ) === false,
	'locale-safe: no comma decimal or thousands comma under a de_DE LC_NUMERIC' );
if ( false !== $__saved_numeric ) { setlocale( LC_NUMERIC, $__saved_numeric ); }

// ── run_rollup orchestration ──────────────────────────────────────────────────
echo "\nGroup: run_rollup\n";
ar_reset();
$GLOBALS['__ar_config_present'] = true;
$GLOBALS['__ar_query_return']   = array(
	array( 'day' => '2026-06-11', 'path' => '/', 'views' => 9, 'visits' => 7, 'scroll_avg' => 50, 'time_avg' => 3000 ),
);
sn_analytics_run_rollup();
// Task 3: a non-empty main result triggers the SECOND (pv-gated) query — the
// P0.1 Fallback A verdict made two queries the production shape.
ok( count( $GLOBALS['__ar_query_calls'] ) === 2, 'run_rollup: issues two AE queries (main + gated pageview_visits)' );
ok( strpos( $GLOBALS['__ar_query_calls'][1], "AND blob1 = 'pv'" ) !== false
	&& strpos( $GLOBALS['__ar_query_calls'][1], 'AS pageview_visits' ) !== false,
	'run_rollup: the second query is the pv-gated Fallback A shape' );
ok( count( $GLOBALS['wpdb']->queries ) === 1, 'run_rollup: upserts the returned rows' );
// Gated fixture defaulted to null (failure) → the row writes pageview_visits
// as literal NULL, never a fabricated 0.
ok( strpos( $GLOBALS['wpdb']->queries[0], 'NULL)' ) !== false,
	'run_rollup: gated-query failure → pageview_visits binds NULL on the written row' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) !== false, 'run_rollup: stamps the freshness transient on success' );
ok( $GLOBALS['__ar_dims_called'] === 1, 'run_rollup: drives the dims roll on a configured success' );

// ── Task 3: full two-query merge over the RAW P0-pinned transport ────────────
echo "\nGroup: run_rollup — raw-envelope merge (Task 3)\n";
// Fixtures are the RAW AE envelope from the live P0 probe run: meta/data/rows/
// rows_before_limit_at_least; UInt64 as JSON STRINGS ("views":"6"), Float64 as
// numbers (scroll_sum:190), avgIf null beside 0 sums on the same row ("/feed/"
// and the time_* pair on "/"). The transported scroll_avg (40) deliberately
// differs from the weighted ratio (190/4 = 47.5) to pin the weighted switch.
$main_envelope = '{"meta":[{"name":"day","type":"String"},{"name":"path","type":"String"},'
	. '{"name":"class","type":"String"},{"name":"views","type":"UInt64"},{"name":"visits","type":"UInt64"},'
	. '{"name":"scroll_avg","type":"Float64"},{"name":"time_avg","type":"Float64"},'
	. '{"name":"scroll_sum","type":"Float64"},{"name":"scroll_events","type":"UInt64"},'
	. '{"name":"time_sum","type":"Float64"},{"name":"time_events","type":"UInt64"}],'
	. '"data":['
	. '{"day":"2026-07-15","path":"/","class":"human","views":"6","visits":"4","scroll_avg":40,"time_avg":null,"scroll_sum":190,"scroll_events":"4","time_sum":0,"time_events":"0"},'
	. '{"day":"2026-07-15","path":"/feed/","class":"human","views":"0","visits":"3","scroll_avg":null,"time_avg":null,"scroll_sum":0,"scroll_events":"0","time_sum":0,"time_events":"0"}'
	. '],"rows":2,"rows_before_limit_at_least":2}';
$gated_envelope = '{"meta":[{"name":"day","type":"String"},{"name":"path","type":"String"},'
	. '{"name":"class","type":"String"},{"name":"pageview_visits","type":"UInt64"}],'
	. '"data":[{"day":"2026-07-15","path":"/","class":"human","pageview_visits":"4"}],'
	. '"rows":1,"rows_before_limit_at_least":1}';
ar_reset();
$GLOBALS['__ar_query_return'] = $main_envelope;
$GLOBALS['__ar_gated_return'] = $gated_envelope;
sn_analytics_run_rollup();
ok( count( $GLOBALS['__ar_query_calls'] ) === 2, 'raw-merge: two AE queries issued' );
ok( count( $GLOBALS['wpdb']->queries ) === 1, 'raw-merge: one batched upsert' );
$qm = $GLOBALS['wpdb']->queries[0];
// "/" — merged gated 4; weighted scroll_avg from the RAW transported sum
// (190/4 = 47.50, not the transported 40); stored scroll_sum re-based to the
// depth identity (25 × 4 = 100.0000, v9.66.0); time pair measured-zero →
// time_avg 0.00 beside real 0 sums.
ok( strpos( $qm, "('2026-07-15', '/', 'human', 6, 4, '47.50', '0.00', '100.0000', 4, '0.0000', 0, 4)" ) !== false,
	'raw-merge: "/" tuple pins numeric-string coercion, weighted avgs, true-unit scroll_sum, merged pageview_visits' );
// "/feed/" — the viewless class: views 0, visits 3, no gated row → REAL 0.
ok( strpos( $qm, "('2026-07-15', '/feed/', 'human', 0, 3, '0.00', '0.00', '0.0000', 0, '0.0000', 0, 0)" ) !== false,
	'raw-merge: viewless "/feed/" tuple gets pageview_visits 0 (absent from a successful gated result)' );
ok( false === get_option( 'sn_analytics_integrity_alert' ),
	'raw-merge: no integrity alert on healthy data (views ≥ pageview_visits everywhere)' );

// Idempotent re-roll: the same day rolled twice writes IDENTICAL rows.
sn_analytics_run_rollup();
ok( count( $GLOBALS['wpdb']->queries ) === 2 && $GLOBALS['wpdb']->queries[0] === $GLOBALS['wpdb']->queries[1],
	'raw-merge: re-rolling the same day twice produces an identical upsert (idempotent)' );

// ── Task 3: the guard fires end-to-end on an inverted RAW stub ────────────────
echo "\nGroup: run_rollup — inverted stub fires the guard (Task 3)\n";
// The live inversion shape ("/": views 2, visits 5) with a gated count of 5 —
// views < pageview_visits on a human row. The alarm fires AND the row writes.
$inverted_main = '{"meta":[],"data":[{"day":"2026-07-16","path":"/","class":"human","views":"2","visits":"5",'
	. '"scroll_avg":null,"time_avg":null,"scroll_sum":0,"scroll_events":"0","time_sum":0,"time_events":"0"}],'
	. '"rows":1,"rows_before_limit_at_least":1}';
$inverted_gated = '{"meta":[],"data":[{"day":"2026-07-16","path":"/","class":"human","pageview_visits":"5"}],'
	. '"rows":1,"rows_before_limit_at_least":1}';
ar_reset();
$GLOBALS['__ar_query_return'] = $inverted_main;
$GLOBALS['__ar_gated_return'] = $inverted_gated;
$guard_log2 = tempnam( sys_get_temp_dir(), 'sn_guard' );
$old_error_log2 = ini_set( 'error_log', $guard_log2 );
sn_analytics_run_rollup();
ini_set( 'error_log', (string) $old_error_log2 );
$alert2 = get_option( 'sn_analytics_integrity_alert' );
ok( is_array( $alert2 ) && 2 === ( $alert2['views'] ?? null ) && 5 === ( $alert2['pageview_visits'] ?? null ),
	'inverted-stub: the alert option carries the inverted pair (2 < 5)' );
ok( strpos( (string) @file_get_contents( $guard_log2 ), '[sn-analytics] integrity violation' ) !== false,
	'inverted-stub: error_log fired' );
ok( strpos( $GLOBALS['wpdb']->queries[0] ?? '', "('2026-07-16', '/', 'human', 2, 5, '0.00', '0.00', '0.0000', 0, '0.0000', 0, 5)" ) !== false,
	'inverted-stub: the row is STILL written, un-clamped' );
@unlink( $guard_log2 );

// ── Finding 3: a row-cap-TRUNCATED gated result is refused, never trusted ─────
echo "\nGroup: gated query wrapper — truncation refusal (finding 3, verdict fixed v9.63.1)\n";
// "Missing key on a successful gated result = real 0" is only sound while the
// gated set is COMPLETE. The two queries order differently (views DESC vs
// pageview_visits DESC), so AE's row cap can truncate them ASYMMETRICALLY —
// a (day, path, class) merely cut from the gated tail would be fabricated
// into a measured-0 pageview_visits. When the result actually HIT the applied
// cap (rows >= SN_ANALYTICS_AE_ROW_CAP) AND the envelope says
// rows_before_limit_at_least > rows, the whole gated result degrades to the
// FAILED shape (null → keys absent → upsert binds SQL NULL, "never measured").
// A bare before>rows envelope BELOW the cap is the ClickHouse GROUP BY quirk
// (pre-merge aggregation partials), NOT truncation — the 2026-07-18 production
// reroll misfired on 25 of 35 days exactly there.
ok( function_exists( 'sn_analytics_rollup_gated_query' ), 'wrapper: sn_analytics_rollup_gated_query() exists (shared by cron + reroll tool)' );

// A REAL cap hit: rows landed on the applied cap (10000) with more behind it.
// (Fixture abbreviates the 10,000-row data body — the envelope COUNTERS are
// the transport contract the verdict reads.)
$trunc_gated_envelope = '{"meta":[{"name":"day","type":"String"},{"name":"path","type":"String"},'
	. '{"name":"class","type":"String"},{"name":"pageview_visits","type":"UInt64"}],'
	. '"data":[{"day":"2026-07-15","path":"/","class":"human","pageview_visits":"4"}],'
	. '"rows":10000,"rows_before_limit_at_least":12000}';

// The live-misfire envelope (2026-07-18 production reroll): a complete gated
// GROUP BY result far below the cap, with the before-counter inflated by
// pre-merge partials. Must pass through INTACT — refusing it discarded 25
// complete days and pinned exact_metrics_since at 2026-07-17.
$misfire_gated_envelope = '{"meta":[{"name":"day","type":"String"},{"name":"path","type":"String"},'
	. '{"name":"class","type":"String"},{"name":"pageview_visits","type":"UInt64"}],'
	. '"data":[{"day":"2026-06-20","path":"/","class":"human","pageview_visits":"4"},'
	. '{"day":"2026-06-20","path":"/notes/","class":"human","pageview_visits":"2"},'
	. '{"day":"2026-06-20","path":"/about/","class":"human","pageview_visits":"1"},'
	. '{"day":"2026-06-20","path":"/","class":"bot","pageview_visits":"3"},'
	. '{"day":"2026-06-20","path":"/feed/","class":"bot","pageview_visits":"1"}],'
	. '"rows":5,"rows_before_limit_at_least":7}';

if ( function_exists( 'sn_analytics_rollup_gated_query' ) ) {
	// Transport failure passes through as null (unchanged semantics).
	ar_reset();
	$GLOBALS['__ar_gated_return'] = null;
	ok( null === sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( 7 ) ),
		'wrapper: transport failure (null) → null' );

	// Complete envelope → the rows pass through untouched.
	ar_reset();
	$GLOBALS['__ar_gated_return'] = $gated_envelope;
	$w_rows = sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( 7 ) );
	ok( is_array( $w_rows ) && '4' === ( $w_rows[0]['pageview_visits'] ?? null ),
		'wrapper: complete envelope (rows === rows_before_limit_at_least) → rows returned intact' );

	// The live-misfire envelope → NOT refused: below-cap before>rows is the
	// GROUP BY quirk, and the 5 complete rows must survive.
	ar_reset();
	$GLOBALS['__ar_gated_return'] = $misfire_gated_envelope;
	$w_misfire = sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( 7 ) );
	ok( is_array( $w_misfire ) && 5 === count( $w_misfire ),
		'wrapper: below-cap GROUP BY quirk (rows 5, before 7) → rows returned intact, NOT refused (2026-07-18 live misfire)' );

	// Truncated (cap-hit) envelope → refused (null) + logged, never a partial set.
	ar_reset();
	$GLOBALS['__ar_gated_return'] = $trunc_gated_envelope;
	$trunc_log     = tempnam( sys_get_temp_dir(), 'sn_trunc' );
	$old_trunc_log = ini_set( 'error_log', $trunc_log );
	$w_trunc       = sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( 7 ) );
	ini_set( 'error_log', (string) $old_trunc_log );
	ok( null === $w_trunc, 'wrapper: cap-hit envelope (rows 10000, rows_before_limit_at_least 12000) → refused as FAILED (null)' );
	ok( strpos( (string) @file_get_contents( $trunc_log ), '[sn-analytics]' ) !== false
		&& strpos( (string) @file_get_contents( $trunc_log ), 'truncated' ) !== false,
		'wrapper: the refusal is never silent — error_log names the truncation' );
	@unlink( $trunc_log );
}

echo "\nGroup: run_rollup — truncated gated envelope binds NULL end-to-end (finding 3)\n";
// The finding's exact scenario: the main set is complete (2 rows) but the
// gated set was row-capped (10,000 returned of ≥12,000 — a REAL cap hit).
// BOTH written rows must bind pageview_visits NULL — the row missing from the
// gated set (the fabricated-0 hazard) AND the row present in it (a truncated
// set carries no completeness claim at all).
ar_reset();
$GLOBALS['__ar_query_return'] = $main_envelope;
$GLOBALS['__ar_gated_return'] = $trunc_gated_envelope;
sn_analytics_run_rollup();
ok( count( $GLOBALS['wpdb']->queries ) === 1, 'truncated-e2e: main rows still write (degrade, not corrupt)' );
$qt = $GLOBALS['wpdb']->queries[0] ?? '';
ok( strpos( $qt, "('2026-07-15', '/feed/', 'human', 0, 3, '0.00', '0.00', '0.0000', 0, '0.0000', 0, NULL)" ) !== false,
	'truncated-e2e: the row MISSING from the truncated gated set binds NULL — never the fabricated measured 0' );
ok( strpos( $qt, "('2026-07-15', '/', 'human', 6, 4, '47.50', '0.00', '100.0000', 4, '0.0000', 0, NULL)" ) !== false,
	'truncated-e2e: even the row PRESENT in the truncated set binds NULL (the whole set is refused)' );
ok( strpos( $qt, ', 4)' ) === false && strpos( $qt, ', 0)' ) === false,
	'truncated-e2e: no tuple carries a numeric pageview_visits anywhere in the write' );

// Control: the SAME data with a complete (non-truncated) gated envelope keeps
// the existing behavior — matched key '4', missing key real 0. Pinned so the
// truncation gate cannot over-fire.
ar_reset();
$GLOBALS['__ar_query_return'] = $main_envelope;
$GLOBALS['__ar_gated_return'] = $gated_envelope;
sn_analytics_run_rollup();
$qc = $GLOBALS['wpdb']->queries[0] ?? '';
ok( strpos( $qc, "('2026-07-15', '/', 'human', 6, 4, '47.50', '0.00', '100.0000', 4, '0.0000', 0, 4)" ) !== false
	&& strpos( $qc, "('2026-07-15', '/feed/', 'human', 0, 3, '0.00', '0.00', '0.0000', 0, '0.0000', 0, 0)" ) !== false,
	'truncated-e2e control: a complete gated envelope keeps matched 4 + real 0 exactly as before' );

// Live-misfire control (v9.63.1): the SAME data with the gated envelope in the
// 2026-07-18 production-reroll shape — its complete rows carried, before-
// counter inflated by GROUP BY pre-merge partials (rows 1 < cap, before 3).
// The gated data must be USED: matched key binds 4, missing key binds the real
// 0 — NEVER the NULLs the old bare before>rows verdict fabricated on 25 of 35
// reroll days.
$quirk_gated_envelope = '{"meta":[{"name":"day","type":"String"},{"name":"path","type":"String"},'
	. '{"name":"class","type":"String"},{"name":"pageview_visits","type":"UInt64"}],'
	. '"data":[{"day":"2026-07-15","path":"/","class":"human","pageview_visits":"4"}],'
	. '"rows":1,"rows_before_limit_at_least":3}';
ar_reset();
$GLOBALS['__ar_query_return'] = $main_envelope;
$GLOBALS['__ar_gated_return'] = $quirk_gated_envelope;
sn_analytics_run_rollup();
$qq = $GLOBALS['wpdb']->queries[0] ?? '';
ok( strpos( $qq, "('2026-07-15', '/', 'human', 6, 4, '47.50', '0.00', '100.0000', 4, '0.0000', 0, 4)" ) !== false
	&& strpos( $qq, "('2026-07-15', '/feed/', 'human', 0, 3, '0.00', '0.00', '0.0000', 0, '0.0000', 0, 0)" ) !== false,
	'misfire-e2e: a below-cap GROUP BY-quirk gated envelope (rows 1, before 3) is TRUSTED — matched 4 + real 0, no fabricated NULLs' );

// Not configured → AE query returns null → no upsert, no fresh stamp.
ar_reset();
$GLOBALS['__ar_config_present'] = false;
$GLOBALS['__ar_query_return']   = null;
sn_analytics_run_rollup();
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'run_rollup: no upsert when AE is not configured' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) === false, 'run_rollup: no freshness stamp when unconfigured' );
ok( $GLOBALS['__ar_dims_called'] === 0, 'run_rollup: skips the dims roll when unconfigured' );

// Configured but AE returns an empty set → no upsert, but still stamps fresh
// (a successful "nothing happened today" must not re-fire every 15 min).
ar_reset();
$GLOBALS['__ar_config_present'] = true;
$GLOBALS['__ar_query_return']   = array();
sn_analytics_run_rollup();
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'run_rollup: empty AE result → no upsert' );
ok( count( $GLOBALS['__ar_query_calls'] ) === 1, 'run_rollup: empty main result skips the gated query (nothing to merge)' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) !== false, 'run_rollup: empty-but-successful result still stamps fresh' );

// Configured but the AE query FAILS (null: transport / non-200 / parse error).
// Distinct from the config gate above — this exercises the !is_array null-guard.
// Must NOT stamp fresh, so the warmer keeps retrying rather than treating a
// failure as a successful idle day.
ar_reset();
$GLOBALS['__ar_config_present'] = true;
$GLOBALS['__ar_query_return']   = null;
sn_analytics_run_rollup();
ok( count( $GLOBALS['__ar_query_calls'] ) === 1, 'run_rollup: a configured failure still issued the AE query' );
ok( count( $GLOBALS['wpdb']->queries ) === 0, 'run_rollup: AE failure (null) → no upsert' );
ok( get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY ) === false, 'run_rollup: AE failure (null) → NOT stamped fresh (warmer retries)' );

// ── daily_range read accessor ─────────────────────────────────────────────────
echo "\nGroup: daily_range\n";
ar_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'id' => 1, 'day' => '2026-06-09', 'path' => '/',        'class' => 'human', 'views' => '10', 'visits' => '8',  'scroll_avg' => '33.3', 'time_avg' => '2000' ),
	array( 'id' => 2, 'day' => '2026-06-11', 'path' => '/notes/a', 'class' => 'human', 'views' => '42', 'visits' => '30', 'scroll_avg' => '58.5', 'time_avg' => '12345' ),
	array( 'id' => 3, 'day' => '2026-06-11', 'path' => '/notes/a', 'class' => 'bot',   'views' => '500','visits' => '5',  'scroll_avg' => '0',    'time_avg' => '0' ),
);
$human = sn_analytics_daily_range( '2026-06-08', '2026-06-12' ); // default class = human
ok( count( $human ) === 2, 'daily_range: defaults to human, excludes the bot row' );
ok( strpos( end( $GLOBALS['wpdb']->queries ), "class = 'human'" ) !== false, 'daily_range: SQL filters class = human by default' );

$bots = sn_analytics_daily_range( '2026-06-08', '2026-06-12', 'bot' );
ok( count( $bots ) === 1 && $bots[0]['views'] === 500, 'daily_range: explicit class returns that bucket' );

// human[0] is the 2026-06-11 /notes/a row (newest day, highest views in the human bucket).
ok( ( $human[0]['day'] ?? '' ) === '2026-06-11', 'daily_range: newest day first' );
ok( is_int( $human[0]['views'] ?? null ) && $human[0]['views'] === 42, 'daily_range: views normalized to int' );
ok( is_float( $human[0]['scroll_avg'] ?? null ), 'daily_range: scroll_avg normalized to float' );
ok( ( $human[0]['path'] ?? '' ) === '/notes/a', 'daily_range: path preserved' );
// Pin the PRODUCTION SQL clauses (not the stub's reimplemented filter), so a
// broken upper bound or flipped sort can't ship green through the stub.
$range_sql = end( $GLOBALS['wpdb']->queries );
ok( strpos( $range_sql, 'day >= ' ) !== false && strpos( $range_sql, 'day <= ' ) !== false,
	'daily_range: SQL applies BOTH the lower and upper day bound' );
ok( strpos( $range_sql, 'ORDER BY day DESC' ) !== false,
	'daily_range: SQL orders newest day first' );

// ── class totals accessor ─────────────────────────────────────────────────────
echo "\nGroup: class totals\n";
ar_reset();
$GLOBALS['wpdb']->rows['wp_sn_analytics_daily'] = array(
	array( 'day' => '2026-06-11', 'path' => '/', 'class' => 'human',   'views' => '40', 'visits' => '30', 'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/', 'class' => 'bot',     'views' => '500','visits' => '5',  'scroll_avg' => 0, 'time_avg' => 0 ),
	array( 'day' => '2026-06-11', 'path' => '/', 'class' => 'suspect', 'views' => '12', 'visits' => '4',  'scroll_avg' => 0, 'time_avg' => 0 ),
);
$tot = sn_analytics_class_totals( '2026-06-08', '2026-06-12' );
ok( ( $tot['human']['views'] ?? null ) === 40, 'class_totals: human views summed' );
ok( ( $tot['bot']['views'] ?? null ) === 500, 'class_totals: bot views summed' );
ok( ( $tot['suspect']['visits'] ?? null ) === 4, 'class_totals: suspect visits summed' );

// ── SWR warmer scheduling decision ────────────────────────────────────────────
echo "\nGroup: warmer\n";
// Stale (no fresh stamp) + capable user → schedules a single rollup event.
ar_reset();
$GLOBALS['__ar_cap'] = true;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 1
	&& $GLOBALS['__ar_single_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_HOOK,
	'warmer: stale + capable → schedules the rollup hook' );

// Fresh stamp within TTL → no schedule.
ar_reset();
set_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY, time(), 0 );
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'warmer: fresh within TTL → no schedule' );

// Stale but an event is already queued → no duplicate schedule.
ar_reset();
$GLOBALS['__ar_scheduled'][] = SN_ANALYTICS_ROLLUP_HOOK;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'warmer: already-scheduled → no duplicate' );

// REGRESSION: the daily backstop must NOT block the on-demand warmer. They use
// distinct hooks — otherwise the always-scheduled recurring event makes
// wp_next_scheduled() permanently truthy and the warmer never fires (the 15-min
// SWR freshness would silently degrade to once-daily).
ar_reset();
$GLOBALS['__ar_scheduled'][] = SN_ANALYTICS_ROLLUP_DAILY_HOOK;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 1
	&& $GLOBALS['__ar_single_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_HOOK,
	'warmer: a scheduled daily backstop does not block the single-event warmer' );

// Non-capable user → never schedules (no warming work for users who can't see stats).
ar_reset();
$GLOBALS['__ar_cap'] = false;
sn_analytics_rollup_warm();
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'warmer: capability-gated' );

// ── daily backstop scheduling ─────────────────────────────────────────────────
echo "\nGroup: backstop schedule\n";
ar_reset();
sn_analytics_rollup_schedule();
ok( count( $GLOBALS['__ar_recurring_events'] ) === 1
	&& $GLOBALS['__ar_recurring_events'][0]['recurrence'] === 'daily'
	&& $GLOBALS['__ar_recurring_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_DAILY_HOOK,
	'schedule: registers a daily recurring rollup on its OWN hook when none exists' );
// Idempotent — already scheduled → no second registration.
ar_reset();
$GLOBALS['__ar_scheduled'][] = SN_ANALYTICS_ROLLUP_DAILY_HOOK;
sn_analytics_rollup_schedule();
ok( count( $GLOBALS['__ar_recurring_events'] ) === 0, 'schedule: idempotent when already scheduled' );

// ── maybe_install version gate ────────────────────────────────────────────────
echo "\nGroup: maybe_install\n";
// Option already current → install (dbDelta) NOT called.
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, SN_ANALYTICS_DAILY_DB_VERSION );
sn_analytics_daily_maybe_install();
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 0, 'maybe_install: current version → no dbDelta' );
ok( SN_ANALYTICS_DAILY_DB_VERSION === '6', 'db version is 6 (scroll_sum re-based to true depth units)' );

// Upgrading from a pre-v2 version drops the old table (dbDelta cannot rotate the
// unique key) then recreates. The stub records the DROP via query().
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '1' );
sn_analytics_daily_maybe_install();
$dropped = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'DROP TABLE IF EXISTS wp_sn_analytics_daily' ) !== false ) { $dropped = true; }
}
ok( $dropped, 'maybe_install: pre-v2 upgrade drops the old table before recreating' );
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 1, 'maybe_install: pre-v2 upgrade runs dbDelta to recreate' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === '6', 'maybe_install: stamps the current db version (6)' );

// v2→v3 must NOT drop the table (it now holds real history). It runs a targeted
// one-time DELETE of the admin/login rows that leaked in before the ingestion
// guard existed — history-preserving, unlike the structural pre-v2 rotation.
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '2' );
sn_analytics_daily_maybe_install();
$dropped_v3 = false;
$purged     = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'DROP TABLE' ) !== false ) { $dropped_v3 = true; }
	if ( stripos( $q, 'DELETE FROM wp_sn_analytics_daily' ) !== false && stripos( $q, '/wp-admin' ) !== false ) { $purged = true; }
}
ok( ! $dropped_v3, 'maybe_install: v2→v3 does NOT drop the populated table' );
ok( $purged, 'maybe_install: v2→v3 purges admin/login rows with a targeted DELETE' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === '6', 'maybe_install: upgrading past v3 stamps the current db version (6)' );

// v3→v4: the rollup day boundary moved from UTC to the SITE-LOCAL day. No drop, no
// purge — just schedule a one-time re-roll so the trailing window is overwritten
// with local-day buckets (idempotent by (day, path, class); history preserved).
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '3' );
sn_analytics_daily_maybe_install();
$dropped_v4 = false;
$purged_v4  = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'DROP TABLE' ) !== false ) { $dropped_v4 = true; }
	if ( stripos( $q, 'DELETE FROM wp_sn_analytics_daily' ) !== false ) { $purged_v4 = true; }
}
ok( ! $dropped_v4 && ! $purged_v4, 'maybe_install: v3→v4 neither drops nor purges the table' );
ok( count( $GLOBALS['__ar_single_events'] ) === 1
	&& $GLOBALS['__ar_single_events'][0]['hook'] === SN_ANALYTICS_ROLLUP_HOOK,
	'maybe_install: v3→v4 schedules a one-time re-roll' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === '6', 'maybe_install: the v3→v4 path stamps the current db version (6)' );

// v4→v5: purely additive — dbDelta ADDs the four nullable engagement-sum
// columns from the schema string. No drop, no purge, and NO install-side
// re-roll: the trailing-90d backfill is an explicit owner-run tool (Task 6),
// never an install side effect.
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '4' );
sn_analytics_daily_maybe_install();
$dropped_v5 = false;
$purged_v5  = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'DROP TABLE' ) !== false ) { $dropped_v5 = true; }
	if ( stripos( $q, 'DELETE FROM wp_sn_analytics_daily' ) !== false ) { $purged_v5 = true; }
}
ok( ! $dropped_v5 && ! $purged_v5, 'maybe_install: v4→v5 neither drops nor purges the populated table' );
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'maybe_install: v4→v5 schedules no install-side re-roll (backfill is owner-run)' );
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 1
	&& strpos( $GLOBALS['__ar_dbdelta_calls'][0], 'scroll_sum FLOAT NULL DEFAULT NULL' ) !== false,
	'maybe_install: v4→v5 runs dbDelta with the nullable engagement-column schema' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === '6', 'maybe_install: v4→v5 upgrades stamp the current db version (6)' );

// v5→v6 (v9.66.0): scroll_sum re-based to TRUE depth units. dbDelta cannot run
// UPDATEs, so the install runs the retroactive repair explicitly post-dbDelta:
// UPDATE ... SET scroll_sum = 25 * scroll_events WHERE scroll_events IS NOT
// NULL — an exact identity (both columns come from the same 'sc' events), a
// fixed point (idempotent by construction), and NULL rows stay NULL.
ar_reset();
update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '5' );
sn_analytics_daily_maybe_install();
$rebased_v6 = false;
$dropped_v6 = false;
$purged_v6  = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'UPDATE wp_sn_analytics_daily' ) !== false
		&& stripos( $q, 'scroll_sum = 25 * scroll_events' ) !== false
		&& stripos( $q, 'scroll_events IS NOT NULL' ) !== false ) { $rebased_v6 = true; }
	if ( stripos( $q, 'DROP TABLE' ) !== false ) { $dropped_v6 = true; }
	if ( stripos( $q, 'DELETE FROM wp_sn_analytics_daily' ) !== false ) { $purged_v6 = true; }
}
ok( $rebased_v6, 'maybe_install: v5→v6 runs the one-time scroll_sum = 25 * scroll_events repair (scroll_events IS NOT NULL gate)' );
ok( ! $dropped_v6 && ! $purged_v6, 'maybe_install: v5→v6 neither drops nor purges the populated table' );
ok( count( $GLOBALS['__ar_single_events'] ) === 0, 'maybe_install: v5→v6 schedules no install-side re-roll (the identity repairs in place)' );
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 1, 'maybe_install: v5→v6 still runs dbDelta first (schema unchanged, additive-safe)' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === '6', 'maybe_install: v5→v6 stamps db version 6' );

// Re-running with the option already at 6 must NOT repeat the repair (the
// UPDATE is idempotent anyway — 25*events is a fixed point — but the gate is
// the machinery under test).
sn_analytics_daily_maybe_install();
$update_count = 0;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'scroll_sum = 25 * scroll_events' ) !== false ) { ++$update_count; }
}
ok( 1 === $update_count, 'maybe_install: at version 6 the repair does not run again (gated exactly once)' );

// Option absent → install runs dbDelta with the schema + stamps the version.
ar_reset();
sn_analytics_daily_maybe_install();
ok( count( $GLOBALS['__ar_dbdelta_calls'] ) === 1, 'maybe_install: missing version → runs dbDelta' );
ok( strpos( $GLOBALS['__ar_dbdelta_calls'][0], 'wp_sn_analytics_daily' ) !== false, 'maybe_install: dbDelta gets the CREATE TABLE' );
ok( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) === SN_ANALYTICS_DAILY_DB_VERSION, 'maybe_install: stamps the db version option' );
$fresh_rebase = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( stripos( $q, 'scroll_sum = 25 * scroll_events' ) !== false ) { $fresh_rebase = true; }
}
ok( ! $fresh_rebase, 'maybe_install: a FRESH install (option absent, no data) skips the v6 repair UPDATE' );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
