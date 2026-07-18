<?php
/**
 * Tests for tools/reroll-analytics-90d.php — the Phase A trailing-90d backfill tool.
 *
 * The tool's risk surface is (a) its per-day SQL window transforms — a silent
 * str_replace no-op would re-roll the WRONG window (a trailing multi-day slice
 * instead of one bounded day) and silently clobber complete neighbouring days —
 * and (b) its day-window bookkeeping (offset list, site-zone day labels, the
 * exact_metrics_since streak rule). These tests ground every transform against
 * the REAL sn_analytics_rollup_sql() / sn_analytics_rollup_gated_sql() /
 * sn_analytics_rollup_window_exprs() output (never a hand-invented base — the
 * stub-drift trap) and pin the full transformed SQL strings exactly.
 *
 * NOTE (deliberate coupling): if a later task reshapes the rollup SELECT or the
 * window expressions, the pinned strings change, these tests fail, and the
 * tool's needles must be re-verified — a backfill tool for the CURRENT query
 * shape, and a loud break here is the intended signal.
 *
 * Run: php tests/reroll-analytics-90d.php
 *
 * @package SignalNoiseTools
 */

// SECURITY: CLI-only fixture.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;
function reroll_assert( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $label\n";
	} else {
		++$fail;
		echo "FAIL: $label\n";
	}
}

// ── Load the tool's pure builders (execution section is gated off) ───────────
define( 'SN_REROLL_TEST', true );
$tool = dirname( __DIR__ ) . '/tools/reroll-analytics-90d.php';
if ( ! file_exists( $tool ) ) {
	echo "FAIL: tools/reroll-analytics-90d.php does not exist\n";
	echo "Result: $pass passed, 1 failed.\n";
	exit( 1 );
}
require $tool;

// ── Load the REAL rollup SQL builders (same stub block as tests/analytics-rollup.php) ──
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
// Defined by inc/analytics-api.php in production; this fixture doesn't load it.
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}

require dirname( __DIR__ ) . '/inc/analytics-rollup.php';

// ── Group: bounded offset plan (89..2; today+yesterday ride the trailing-1 window) ──
echo "Group: bounded offset plan\n";

$offsets = sn_reroll_bounded_offsets( 90 );
reroll_assert( is_array( $offsets ) && 88 === count( $offsets ), 'offsets(90): 88 bounded day windows (89..2)' );
reroll_assert( isset( $offsets[0] ) && 89 === $offsets[0], 'offsets(90): first bounded offset is 89 (earliest day, ascending-day order)' );
reroll_assert( isset( $offsets[1] ) && 88 === $offsets[1], 'offsets(90): descending offsets step by 1 (= ascending days)' );
reroll_assert( isset( $offsets[87] ) && 2 === $offsets[87], 'offsets(90): last bounded offset is 2 — offsets 1 and 0 belong to the trailing-1 production window' );
reroll_assert( array( 2 ) === sn_reroll_bounded_offsets( 3 ), 'offsets(3): single bounded window at offset 2' );
reroll_assert( array() === sn_reroll_bounded_offsets( 2 ), 'offsets(2): empty — the trailing-1 window alone covers today+yesterday' );

// ── Group: site-zone day labels (civil-day arithmetic, DST-safe) ─────────────
echo "\nGroup: day labels\n";

$today_ny = new DateTimeImmutable( '2026-07-17', new DateTimeZone( 'America/New_York' ) );
reroll_assert( '2026-07-17' === sn_reroll_day_label( 0, $today_ny ), 'label(0): today' );
reroll_assert( '2026-07-16' === sn_reroll_day_label( 1, $today_ny ), 'label(1): yesterday' );
reroll_assert( '2026-07-01' === sn_reroll_day_label( 16, $today_ny ), 'label(16): month boundary crossed correctly' );
reroll_assert( '2026-04-19' === sn_reroll_day_label( 89, $today_ny ), 'label(89): the 90-day span start (earliest re-rolled day)' );

// Spring-forward DST boundary (America/New_York, 2026-03-08): civil-day
// arithmetic must not slip an hour into the wrong calendar date.
$apr_ny = new DateTimeImmutable( '2026-04-19', new DateTimeZone( 'America/New_York' ) );
reroll_assert( '2026-03-05' === sn_reroll_day_label( 45, $apr_ny ), 'label(45) across the spring-forward DST boundary stays a clean civil date' );

$today_utc = new DateTimeImmutable( '2026-07-17', new DateTimeZone( 'UTC' ) );
reroll_assert( '2026-04-19' === sn_reroll_day_label( 89, $today_utc ), 'label(89) on the UTC path matches the zoned civil arithmetic' );

// ── Group: main per-day window transform (grounded on the REAL builder) ──────
echo "\nGroup: main per-day window transform\n";

list( , $lower2_utc ) = sn_analytics_rollup_window_exprs( 2, '' );
list( , $upper1_utc ) = sn_analytics_rollup_window_exprs( 1, '' );

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
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '2' DAY) "
	. "AND timestamp < toStartOfDay(now() - INTERVAL '1' DAY) "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, views DESC';

$main_day = sn_reroll_day_sql( sn_analytics_rollup_sql( 2, '' ), $lower2_utc, $upper1_utc );
reroll_assert( $expected_main === $main_day, 'main(UTC base, k=2) === pinned full SQL (bounded [start day-2, start day-1) window)' );
reroll_assert( null === sn_reroll_day_sql( 'SELECT 1', $lower2_utc, $upper1_utc ), 'main(garbage base) === null — never a silently-unchanged query' );
reroll_assert( null === sn_reroll_day_sql( sn_analytics_rollup_sql( 2, '' ), $lower2_utc, $lower2_utc ), 'main(lower === upper) === null — a degenerate empty window is a caller bug, not a query' );
reroll_assert( null === sn_reroll_day_sql( sn_analytics_rollup_sql( 2, '' ), $lower2_utc, '' ), 'main(empty upper) === null — an unbounded "day" window would clobber neighbouring days' );

// The gated base must NOT be transformable by the main needle (its WHERE
// carries the pv gate between lower bound and GROUP BY) — the transforms
// cannot be applied to the wrong query.
reroll_assert( null === sn_reroll_day_sql( sn_analytics_rollup_gated_sql( 2, '' ), $lower2_utc, $upper1_utc ), 'main transform rejects the gated base (wrong-query cross-feed is a loud null)' );

list( , $lower2_ny ) = sn_analytics_rollup_window_exprs( 2, 'America/New_York' );
list( , $upper1_ny ) = sn_analytics_rollup_window_exprs( 1, 'America/New_York' );
$main_day_ny = sn_reroll_day_sql( sn_analytics_rollup_sql( 2, 'America/New_York' ), $lower2_ny, $upper1_ny );
reroll_assert( is_string( $main_day_ny ) && false !== strpos( $main_day_ny, "AND timestamp < toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York') - INTERVAL '1' DAY GROUP BY" ), 'main(zoned base): strict upper bound carries the site zone (same expression family as the rollup lower bound)' );
reroll_assert( is_string( $main_day_ny ) && false !== strpos( $main_day_ny, "formatDateTime(timestamp, '%Y-%m-%d', 'America/New_York') AS day" ), 'main(zoned base): the site-local day bucketing survives the transform' );

// ── Group: gated per-day window transform ─────────────────────────────────────
echo "\nGroup: gated per-day window transform\n";

$expected_gated = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
	. 'blob2 AS path, blob7 AS class, '
	. 'count(DISTINCT index1) AS pageview_visits '
	. 'FROM sn_pageviews '
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '2' DAY) "
	. "AND timestamp < toStartOfDay(now() - INTERVAL '1' DAY) "
	. "AND blob1 = 'pv' "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, pageview_visits DESC';

$gated_day = sn_reroll_gated_day_sql( sn_analytics_rollup_gated_sql( 2, '' ), $lower2_utc, $upper1_utc );
reroll_assert( $expected_gated === $gated_day, 'gated(UTC base, k=2) === pinned full SQL (upper bound inserted BEFORE the pv gate)' );
reroll_assert( null === sn_reroll_gated_day_sql( 'SELECT 1', $lower2_utc, $upper1_utc ), 'gated(garbage base) === null — never a silently-unchanged query' );
reroll_assert( null === sn_reroll_gated_day_sql( sn_analytics_rollup_sql( 2, '' ), $lower2_utc, $upper1_utc ), 'gated transform rejects the main base (wrong-query cross-feed is a loud null)' );
reroll_assert( null === sn_reroll_gated_day_sql( sn_analytics_rollup_gated_sql( 2, '' ), $lower2_utc, $lower2_utc ), 'gated(lower === upper) === null' );
reroll_assert( is_string( $gated_day ) && false !== strpos( $gated_day, "AND timestamp < toStartOfDay(now() - INTERVAL '1' DAY) AND blob1 = 'pv'" ), 'gated: strict < upper bound sits between the lower bound and the pv gate' );

$gated_day_ny = sn_reroll_gated_day_sql( sn_analytics_rollup_gated_sql( 2, 'America/New_York' ), $lower2_ny, $upper1_ny );
reroll_assert( is_string( $gated_day_ny ) && false !== strpos( $gated_day_ny, "AND timestamp < toStartOfInterval(now(), INTERVAL '1' DAY, 'America/New_York') - INTERVAL '1' DAY AND blob1 = 'pv'" ), 'gated(zoned base): zoned strict upper bound before the pv gate' );

// Both per-day queries must share IDENTICAL window bounds — the PHP merge joins
// on (day, path, class), so a bound drift would silently mis-key the merge.
$main_where  = substr( (string) $main_day, strpos( (string) $main_day, 'WHERE' ), strpos( (string) $main_day, ' GROUP BY' ) - strpos( (string) $main_day, 'WHERE' ) );
$gated_where = substr( (string) $gated_day, strpos( (string) $gated_day, 'WHERE' ), strpos( (string) $gated_day, ' GROUP BY' ) - strpos( (string) $gated_day, 'WHERE' ) );
reroll_assert( $gated_where === $main_where . " AND blob1 = 'pv'", 'main and gated share the exact same window bounds (gated adds only the pv gate)' );

// ── Group: exact_metrics_since streak rule ────────────────────────────────────
echo "\nGroup: exact_metrics_since streak rule\n";

$all_ok = array(
	array( 'day' => '2026-04-19', 'ok' => true ),
	array( 'day' => '2026-04-20', 'ok' => true ),
	array( 'day' => '2026-04-21', 'ok' => true ),
);
reroll_assert( '2026-04-19' === sn_reroll_since_day( $all_ok ), 'all days OK → since = the earliest re-rolled day' );

$hole = array(
	array( 'day' => '2026-04-19', 'ok' => true ),
	array( 'day' => '2026-04-20', 'ok' => false ),
	array( 'day' => '2026-04-21', 'ok' => true ),
	array( 'day' => '2026-04-22', 'ok' => true ),
);
reroll_assert( '2026-04-21' === sn_reroll_since_day( $hole ), 'a failed day mid-range → since = start of the unbroken OK streak ending today (never a dishonest earlier date)' );

$last_failed = array(
	array( 'day' => '2026-04-19', 'ok' => true ),
	array( 'day' => '2026-04-20', 'ok' => false ),
);
reroll_assert( null === sn_reroll_since_day( $last_failed ), 'today failed → since = null (option must NOT be set on a broken tail)' );

reroll_assert( null === sn_reroll_since_day( array() ), 'empty results → null' );
reroll_assert( '2026-07-17' === sn_reroll_since_day( array( array( 'day' => '2026-07-17', 'ok' => true ) ) ), 'single OK day → that day' );
reroll_assert( null === sn_reroll_since_day( array( array( 'day' => '2026-07-17' ) ) ), 'missing ok key → treated as NOT ok (fail toward honesty), never a fabricated success' );

// ── Group: empty-day retention disambiguation (adversarial finding 1) ─────────
// 0 AE rows is AMBIGUOUS: a genuinely quiet day looks identical to a day that
// aged out of AE's ~90d retention (the offset-89 boundary). On this site every
// real day has durable rows (the daily RSS srv:1 beacon class), so an aged-out
// day still carries LEGACY rows (scroll_sum IS NULL) in wp_sn_analytics_daily —
// and counting it streak-OK would let exact_metrics_since claim coverage over a
// day whose range reads null exact fields (the read layer's mixed-range rule).
// A 0-AE-row day is streak-OK ONLY when the durable table provably has no
// legacy rows for it.
echo "\nGroup: empty-day retention disambiguation\n";

reroll_assert( function_exists( 'sn_reroll_empty_day_ok' ), 'sn_reroll_empty_day_ok() exists (the 0-AE-row disambiguation helper)' );

/**
 * Minimal wpdb stub for the legacy-row COUNT read — models the transport:
 * COUNT(*) travels back as a numeric STRING; a failed read returns null.
 */
class RR_Empty_Stub_wpdb {
	public $prefix     = 'wp_';
	public $var_return = '0';
	public $queries    = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return $this->var_return;
	}
}

$rr_db             = new RR_Empty_Stub_wpdb();
$rr_db->var_return = '0';
reroll_assert( true === sn_reroll_empty_day_ok( $rr_db, 'wp_sn_analytics_daily', '2026-04-19' ),
	'0 AE rows + 0 durable legacy rows → streak-OK (a genuinely quiet day; empty is an ANSWER)' );
$rr_sql = (string) end( $rr_db->queries );
// v9.63.2 UNIFICATION: the legacy check rides the same row-level completeness
// predicate as the post-write check below — scroll_sum-only would miss the
// scroll-written/gated-NULL shape (run N's main query succeeded, its gated
// query failed, and the key vanished from AE before run N+1).
reroll_assert( "SELECT COUNT(*) FROM wp_sn_analytics_daily WHERE day = '2026-04-19' AND (scroll_sum IS NULL OR pageview_visits IS NULL)" === $rr_sql,
	'legacy check === pinned full SQL — the UNIFIED completeness predicate (scroll_sum OR pageview_visits NULL) on that exact day' );

$rr_db->var_return = '3';
reroll_assert( false === sn_reroll_empty_day_ok( $rr_db, 'wp_sn_analytics_daily', '2026-04-19' ),
	'0 AE rows + durable legacy rows (the RSS srv:1 class guarantees them) → NOT ok — aged out of retention, not quiet' );

$rr_db->var_return = null;
reroll_assert( false === sn_reroll_empty_day_ok( $rr_db, 'wp_sn_analytics_daily', '2026-04-19' ),
	'failed COUNT read (null) → NOT ok — unknown fails toward honesty, matching the missing-ok-key rule' );

// Composition with the streak rule: the finding's exact scenario. Day-89
// returns 0 AE rows but the durable table holds a legacy row → the day is
// not-ok → since lands AFTER it, so exact_metrics_since never claims coverage
// over legacy NULL scroll_sum rows.
$rr_db->var_return = '5';
$rr_aged_out_ok    = sn_reroll_empty_day_ok( $rr_db, 'wp_sn_analytics_daily', '2026-04-19' );
reroll_assert( '2026-04-20' === sn_reroll_since_day( array(
	array( 'day' => '2026-04-19', 'ok' => $rr_aged_out_ok ),
	array( 'day' => '2026-04-20', 'ok' => true ),
	array( 'day' => '2026-04-21', 'ok' => true ),
) ), 'aged-out day-89 (0 AE rows, durable legacy row) is EXCLUDED: since = day-88, a range over day-89 stays honestly pre-discontinuity' );

$rr_db->var_return = '0';
$rr_quiet_ok       = sn_reroll_empty_day_ok( $rr_db, 'wp_sn_analytics_daily', '2026-04-19' );
reroll_assert( '2026-04-19' === sn_reroll_since_day( array(
	array( 'day' => '2026-04-19', 'ok' => $rr_quiet_ok ),
	array( 'day' => '2026-04-20', 'ok' => true ),
	array( 'day' => '2026-04-21', 'ok' => true ),
) ), 'genuinely quiet day-89 (0 AE rows, no durable legacy rows) stays streak-OK: since = day-89' );

// ── Group: row-level completeness (2026-07-18 production discovery) ──────────
// A day can upsert every AE-returned row successfully and STILL be incomplete:
// the durable table holds "stale sibling" rows the original nightly cron wrote
// when the day's events were fresh, whose (day, path, class) keys AE has since
// consolidated away. Production diagnostic (2026-07-18, on live v9.63.1):
// 36 such rows since 2026-06-13 — e.g. day 2026-06-13 holds 6 durable rows
// while the reroll's bounded window returned only 3; the other 3 keep NULL
// scroll_sum/pageview_visits forever (their AE source is gone; they hold real
// legacy views/visits — never delete, never fabricate 0s). Day-level success
// ≠ row-level completeness: exact_metrics_since must be earned by the TABLE,
// not the run, so a post-write COUNT over the unified predicate
// (scroll_sum IS NULL OR pageview_visits IS NULL) gates the streak.
echo "\nGroup: row-level completeness\n";

reroll_assert( function_exists( 'sn_reroll_incomplete_rows' ), 'sn_reroll_incomplete_rows() exists (the post-write row-level completeness helper)' );

/**
 * Row-store wpdb stub: models the durable table AFTER a day's upsert, plus the
 * transport's transform — COUNT(*) travels back as a numeric STRING; a failed
 * read returns null. get_var evaluates the received SQL's `col IS NULL` terms
 * (OR-combined — MySQL's semantics for this query shape) over PHP-null row
 * values, so a predicate mutation (e.g. reverting to scroll_sum-only) changes
 * the COUNT and fails the OR-case assertion below; the exact-SQL pins guard
 * the predicate string itself.
 */
class RR_RowStore_Stub_wpdb {
	public $prefix   = 'wp_';
	public $rows     = array();
	public $fail_var = false;
	public $queries  = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i ] ?? ''; ++$i;
			switch ( $m[0] ) { case '%d': return (string) (int) $a; case '%f': return (string) (float) $a; default: return "'" . addslashes( (string) $a ) . "'"; }
		}, $query );
	}
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( $this->fail_var ) { return null; }
		if ( ! preg_match( "/day = '([^']+)'/", $sql, $m ) ) { return null; }
		preg_match_all( '/(\w+) IS NULL/', $sql, $terms );
		$count = 0;
		foreach ( $this->rows as $r ) {
			if ( ! is_array( $r ) || ( $r['day'] ?? null ) !== $m[1] ) { continue; }
			foreach ( $terms[1] as $col ) {
				if ( array_key_exists( $col, $r ) && null === $r[ $col ] ) { ++$count; break; }
			}
		}
		return (string) $count;
	}
}

$rr_store = new RR_RowStore_Stub_wpdb();

// (a) The production day itself: 3 fresh fully-written rows beside 3 stale
// cron-era siblings (both exact columns NULL — the pre-v5 cron never measured
// them and their AE keys are gone). A day-of-another-day row must not leak in.
$rr_store->rows = array(
	array( 'day' => '2026-06-13', 'path' => '/', 'scroll_sum' => 812.5, 'pageview_visits' => 4 ),
	array( 'day' => '2026-06-13', 'path' => '/notes/', 'scroll_sum' => 90.0, 'pageview_visits' => 1 ),
	array( 'day' => '2026-06-13', 'path' => '/feed/', 'scroll_sum' => 0.0, 'pageview_visits' => 0 ),
	array( 'day' => '2026-06-13', 'path' => '/old-a/', 'scroll_sum' => null, 'pageview_visits' => null ),
	array( 'day' => '2026-06-13', 'path' => '/old-b/', 'scroll_sum' => null, 'pageview_visits' => null ),
	array( 'day' => '2026-06-13', 'path' => '/old-c/', 'scroll_sum' => null, 'pageview_visits' => null ),
	array( 'day' => '2026-06-14', 'path' => '/', 'scroll_sum' => null, 'pageview_visits' => null ),
);
reroll_assert( 3 === sn_reroll_incomplete_rows( $rr_store, 'wp_sn_analytics_daily', '2026-06-13' ),
	'(a) upsert-OK day with 3 stale cron-era siblings → 3 incomplete rows (the live 2026-06-13 shape: 6 durable rows, reroll saw 3; other days do not leak in)' );
$rr_sql = (string) end( $rr_store->queries );
reroll_assert( "SELECT COUNT(*) FROM wp_sn_analytics_daily WHERE day = '2026-06-13' AND (scroll_sum IS NULL OR pageview_visits IS NULL)" === $rr_sql,
	'completeness COUNT === pinned full SQL (unified OR predicate, exact day key, durable table)' );

// (b) The OR arm: scroll_sum was written by run N's main query but the gated
// query failed and the (day,path,class) key vanished from AE before run N+1 —
// pageview_visits NULL forever. A scroll_sum-only predicate calls this day
// complete; the OR predicate must not.
$rr_store->rows = array(
	array( 'day' => '2026-06-20', 'path' => '/', 'scroll_sum' => 44.0, 'pageview_visits' => 7 ),
	array( 'day' => '2026-06-20', 'path' => '/talks/', 'scroll_sum' => 3.0, 'pageview_visits' => null ),
);
reroll_assert( 1 === sn_reroll_incomplete_rows( $rr_store, 'wp_sn_analytics_daily', '2026-06-20' ),
	'(b) sibling with scroll_sum=3.0 but pageview_visits NULL → 1 incomplete row (the OR arm: gated-NULL alone breaks completeness)' );

// (c) Fully clean day — including measured-0 rows (0 is an ANSWER, not NULL).
$rr_store->rows = array(
	array( 'day' => '2026-07-01', 'path' => '/', 'scroll_sum' => 100.0, 'pageview_visits' => 12 ),
	array( 'day' => '2026-07-01', 'path' => '/feed/', 'scroll_sum' => 0.0, 'pageview_visits' => 0 ),
);
reroll_assert( 0 === sn_reroll_incomplete_rows( $rr_store, 'wp_sn_analytics_daily', '2026-07-01' ),
	'(c) fully clean day (measured-0 rows included) → 0 incomplete rows — streak-OK unchanged' );

// (d) A failed COUNT (wpdb returns null) is NOT a clean answer.
$rr_store->fail_var = true;
reroll_assert( null === sn_reroll_incomplete_rows( $rr_store, 'wp_sn_analytics_daily', '2026-07-01' ),
	'(d) failed COUNT read (wpdb null) → null, never a fabricated clean 0 — unknown is not an answer' );
$rr_store->fail_var = false;

// Composition with the streak rule — the fix's exact production consequence:
// the upsert succeeded on 2026-06-13 but stale siblings remain, so the day is
// NOT streak-OK and `since` lands AFTER it. exact_metrics_since stops claiming
// exactness the durable table cannot support (the read layer nulls exact
// fields over that day either way — the marker lied, the read layer did not).
$rr_store->rows = array(
	array( 'day' => '2026-06-13', 'path' => '/', 'scroll_sum' => 812.5, 'pageview_visits' => 4 ),
	array( 'day' => '2026-06-13', 'path' => '/old-a/', 'scroll_sum' => null, 'pageview_visits' => null ),
);
$rr_stale = sn_reroll_incomplete_rows( $rr_store, 'wp_sn_analytics_daily', '2026-06-13' );
reroll_assert( '2026-06-14' === sn_reroll_since_day( array(
	array( 'day' => '2026-06-13', 'ok' => 0 === $rr_stale ),
	array( 'day' => '2026-06-14', 'ok' => true ),
) ), '(a) composition: stale-sibling day EXCLUDED from the streak → since moves FORWARD to the truly-clean boundary (honest > flattering)' );

$rr_store->fail_var = true;
$rr_unknown = sn_reroll_incomplete_rows( $rr_store, 'wp_sn_analytics_daily', '2026-06-13' );
$rr_store->fail_var = false;
reroll_assert( '2026-06-14' === sn_reroll_since_day( array(
	array( 'day' => '2026-06-13', 'ok' => 0 === $rr_unknown ),
	array( 'day' => '2026-06-14', 'ok' => true ),
) ), '(d) composition: a failed completeness COUNT excludes the day too (0 === null is false) — never silently OK' );

// The 0-AE-row legacy check is UNIFIED onto the same predicate: an empty day
// whose only durable row is scroll-written/gated-NULL is NOT ok either.
$rr_store->rows = array(
	array( 'day' => '2026-04-19', 'path' => '/t/', 'scroll_sum' => 3.0, 'pageview_visits' => null ),
);
reroll_assert( false === sn_reroll_empty_day_ok( $rr_store, 'wp_sn_analytics_daily', '2026-04-19' ),
	'sn_reroll_empty_day_ok() rides the unified predicate: a scroll-written/gated-NULL row excludes an empty day (scroll_sum-only would have passed it)' );
$rr_store->rows = array(
	array( 'day' => '2026-04-19', 'path' => '/t/', 'scroll_sum' => 3.0, 'pageview_visits' => 5 ),
);
reroll_assert( true === sn_reroll_empty_day_ok( $rr_store, 'wp_sn_analytics_daily', '2026-04-19' ),
	'sn_reroll_empty_day_ok() with a fully-written row → still ok (unification changes the predicate, not the convention)' );

// ── Group: owner-run contract (the binding run-location requirement) ─────────
echo "\nGroup: owner-run contract\n";

$tool_src = (string) file_get_contents( $tool );
reroll_assert( false !== strpos( $tool_src, 'public_html' ), 'header documents running FROM public_html (Cloudways requires the WP root cwd)' );
reroll_assert( false !== strpos( $tool_src, 'wp eval-file' ), 'header documents the wp eval-file invocation' );
reroll_assert( false !== strpos( $tool_src, 'sn_analytics_exact_metrics_since' ), 'tool sets the sn_analytics_exact_metrics_since option the read layer consumes' );
// Finding 3 inheritance: the gated queries must ride the truncation-refusing
// wrapper (sn_analytics_rollup_gated_query), never raw sn_analytics_query() —
// a row-cap-truncated gated set would otherwise fabricate measured-0
// pageview_visits through the merge's missing-key-is-0 rule.
reroll_assert( substr_count( $tool_src, 'sn_analytics_rollup_gated_query(' ) >= 2,
	'both gated query call sites go through the truncation-refusing wrapper' );
reroll_assert( false === strpos( $tool_src, 'sn_analytics_query( $sn_reroll_gated_sql' )
	&& false === strpos( $tool_src, 'sn_analytics_query( sn_analytics_rollup_gated_sql' ),
	'no raw sn_analytics_query() call remains for the gated SQL' );
reroll_assert( false !== strpos( $tool_src, "'sn_analytics_rollup_gated_query'," ),
	'the wrapper is in the tool\'s required-functions pre-flight list' );
// v9.63.2 row-level completeness (2026-07-18 production discovery — 36 stale
// sibling rows since 2026-06-13): both write paths must run the post-write
// completeness check, the WARN wording must name the stale-sibling cause, and
// the old scroll_sum-only predicate must be GONE (one unified predicate — two
// diverging checks would reopen the gap).
reroll_assert( substr_count( $tool_src, "sn_reroll_incomplete_rows( \$GLOBALS['wpdb']" ) >= 2,
	'both write paths (bounded loop + trailing-2 production window) run the post-write row-level completeness check' );
reroll_assert( false !== strpos( $tool_src, 'stale cron-era rows remain' ),
	'WARN wording names the stale-sibling cause (keys AE no longer returns) and the exclusion' );
reroll_assert( false === strpos( $tool_src, 'AND scroll_sum IS NULL' ),
	'the old scroll_sum-only predicate is gone — the legacy 0-AE-row check and the post-write check share ONE unified predicate' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
