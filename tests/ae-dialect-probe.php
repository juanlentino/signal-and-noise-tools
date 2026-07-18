<?php
/**
 * Tests for tools/ae-dialect-probe.php — the P0.1/P0.2 pre-flight SQL builders.
 *
 * The probe's whole risk surface is its str_replace transforms: a silent
 * needle no-op would make the live probe execute the UNMODIFIED rollup query
 * and report a false PASS of the wrong candidate. These tests ground every
 * transform against the REAL sn_analytics_rollup_sql() output (never a
 * hand-invented base — the stub-drift trap) and pin the full transformed SQL
 * strings exactly.
 *
 * NOTE (deliberate coupling): when Task 3 extends the rollup SELECT, the
 * pinned base changes, these tests fail, and the probe's needles must be
 * re-verified or the probe retired — it is a pre-flight tool for the CURRENT
 * query shape, and a loud break here is the intended signal.
 *
 * Run: php tests/ae-dialect-probe.php
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
function probe_assert( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "PASS: $label\n";
	} else {
		++$fail;
		echo "FAIL: $label\n";
	}
}

// ── Load the probe's pure builders (execution section is gated off) ──────────
define( 'SN_AE_PROBE_TEST', true );
$tool = dirname( __DIR__ ) . '/tools/ae-dialect-probe.php';
if ( ! file_exists( $tool ) ) {
	echo "FAIL: tools/ae-dialect-probe.php does not exist\n";
	echo "Result: $pass passed, 1 failed.\n";
	exit( 1 );
}
require $tool;

// ── Load the REAL rollup SQL builder (same stub block as tests/analytics-rollup.php) ──
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

$base_utc = sn_analytics_rollup_sql( 1, '' );
$base_tz  = sn_analytics_rollup_sql( 1, 'America/New_York' );

// ── Group: P0.1 PRIMARY — gated distinct appended to the rollup SELECT ───────
echo "Group: P0.1 primary transform\n";

$expected_primary = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
	. 'blob2 AS path, blob7 AS class, '
	. "sumIf(_sample_interval, blob1 = 'pv') AS views, "
	. 'count(DISTINCT index1) AS visits, '
	. "avgIf(double1, blob1 = 'sc') AS scroll_avg, "
	. "avgIf(double2, blob1 = 'tm') AS time_avg, "
	. "count(DISTINCT if(blob1 = 'pv', index1, NULL)) AS pageview_visits "
	. 'FROM sn_pageviews '
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '1' DAY) "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, views DESC';

$primary = sn_ae_probe_primary_sql( $base_utc );
probe_assert( $expected_primary === $primary, 'primary(UTC base) === pinned full SQL (gated distinct appended before FROM)' );
probe_assert( null === sn_ae_probe_primary_sql( 'SELECT 1' ), 'primary(garbage base) === null — never a silently-unchanged query' );

$primary_tz = sn_ae_probe_primary_sql( $base_tz );
probe_assert( is_string( $primary_tz ) && false !== strpos( $primary_tz, "count(DISTINCT if(blob1 = 'pv', index1, NULL)) AS pageview_visits FROM" ), 'primary(zoned base): gated distinct present, needle tz-independent' );
probe_assert( is_string( $primary_tz ) && false !== strpos( $primary_tz, "'America/New_York'" ), 'primary(zoned base): zone survives the transform' );

// ── Group: P0.1 FALLBACK A — pv-gated WHERE + bare-column count(DISTINCT) ────
echo "\nGroup: P0.1 fallback A transform\n";

$expected_fallback_a = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
	. 'blob2 AS path, blob7 AS class, '
	. 'count(DISTINCT index1) AS pageview_visits '
	. 'FROM sn_pageviews '
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '1' DAY) "
	. "AND blob1 = 'pv' "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, pageview_visits DESC';

$fallback_a = sn_ae_probe_fallback_a_sql( $base_utc );
probe_assert( $expected_fallback_a === $fallback_a, 'fallback A(UTC base) === pinned full SQL (pv-gated WHERE, bare-column DISTINCT)' );
probe_assert( null === sn_ae_probe_fallback_a_sql( 'SELECT 1' ), 'fallback A(garbage base) === null — never a silently-unchanged query' );

// The alias-only ORDER BY gotcha: the removed `views` alias must not survive in
// ORDER BY, and the replacement alias must be the one the SELECT now defines.
// (Substring-match `AS views` / `views DESC`, not bare `views` — the dataset
// name sn_pageviews legitimately contains it.)
probe_assert( is_string( $fallback_a ) && false === strpos( $fallback_a, 'AS views' ) && false === strpos( $fallback_a, 'views DESC' ), 'fallback A: no stale `views` alias in SELECT or ORDER BY (alias-only ORDER BY would 422)' );
probe_assert( is_string( $fallback_a ) && false === strpos( $fallback_a, 'avgIf' ) && false === strpos( $fallback_a, 'sumIf' ), 'fallback A: engagement aggregates stripped (avgIf over an empty pv-only set risks non-JSON NaN)' );
probe_assert( is_string( $fallback_a ) && false === strpos( $fallback_a, 'count(DISTINCT if(' ), 'fallback A: stays inside the proven dialect surface — no count(DISTINCT <expr>)' );

$fallback_a_tz = sn_ae_probe_fallback_a_sql( $base_tz );
probe_assert( is_string( $fallback_a_tz ) && false !== strpos( $fallback_a_tz, "AND blob1 = 'pv' GROUP BY day, path, class" ), 'fallback A(zoned base): pv gate present, needle tz-independent' );

// ── Group: P0.2 WEIGHTED — sumIf(double * _sample_interval, cond) forms ──────
echo "\nGroup: P0.2 weighted transform\n";

$expected_weighted = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
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
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '1' DAY) "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, views DESC';

$weighted = sn_ae_probe_weighted_sql( $base_utc );
probe_assert( $expected_weighted === $weighted, 'weighted(UTC base) === pinned full SQL (4 weighted columns beside the proven sumIf views)' );
probe_assert( null === sn_ae_probe_weighted_sql( 'SELECT 1' ), 'weighted(garbage base) === null — never a silently-unchanged query' );
probe_assert( is_string( $weighted ) && false !== strpos( $weighted, "sumIf(_sample_interval, blob1 = 'pv') AS views" ), 'weighted: the proven sumIf(_sample_interval, blob1 = \'pv\') runs alongside (same-query comparison)' );

// ── Group: P0.2 WEIGHTED FALLBACK — sum(if(cond, double * _sample_interval, 0)) ──
echo "\nGroup: P0.2 weighted fallback transform\n";

$expected_weighted_fb = "SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day, "
	. 'blob2 AS path, blob7 AS class, '
	. "sumIf(_sample_interval, blob1 = 'pv') AS views, "
	. 'count(DISTINCT index1) AS visits, '
	. "avgIf(double1, blob1 = 'sc') AS scroll_avg, "
	. "avgIf(double2, blob1 = 'tm') AS time_avg, "
	. "sum(if(blob1 = 'sc', double1 * _sample_interval, 0)) AS scroll_sum, "
	. "sumIf(_sample_interval, blob1 = 'sc') AS scroll_events, "
	. "sum(if(blob1 = 'tm', double2 * _sample_interval, 0)) AS time_sum, "
	. "sumIf(_sample_interval, blob1 = 'tm') AS time_events "
	. 'FROM sn_pageviews '
	. "WHERE timestamp >= toStartOfDay(now() - INTERVAL '1' DAY) "
	. 'GROUP BY day, path, class '
	. 'ORDER BY day DESC, views DESC';

$weighted_fb = sn_ae_probe_weighted_fallback_sql( $base_utc );
probe_assert( $expected_weighted_fb === $weighted_fb, 'weighted fallback(UTC base) === pinned full SQL (sum(if()) multiplication, sumIf counts kept)' );
probe_assert( null === sn_ae_probe_weighted_fallback_sql( 'SELECT 1' ), 'weighted fallback(garbage base) === null — never a silently-unchanged query' );
// The counts stay sumIf(_sample_interval, cond) — that form is already proven
// live (existing views aggregate); only the multiplication needs a fallback.
probe_assert( is_string( $weighted_fb ) && 3 === substr_count( $weighted_fb, 'sumIf(_sample_interval,' ), 'weighted fallback: exactly 3 sumIf(_sample_interval, …) (views + 2 event counts) — counts never regress to sum(if())' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
