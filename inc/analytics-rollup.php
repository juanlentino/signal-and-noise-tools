<?php
/**
 * Signal & Noise — first-party analytics daily-rollup data layer (P2).
 *
 * Turns the raw, sampled, ~3-month-retained Cloudflare Analytics Engine
 * stream (written by the edge worker, read via inc/analytics-api.php) into a
 * durable per-day-per-path aggregate in `wp_sn_analytics_daily`. Downstream
 * widgets / insights / the front-end `[sn_popular]` block (P3/P4) read this
 * table, never AE directly, so the render path never blocks on a network call
 * and history survives AE's retention window.
 *
 * ── Why a rollup table ───────────────────────────────────────────────────────
 *
 *   - AE retains raw events ~90 days; this table keeps the daily aggregate
 *     forever (one row per day per path — slow-growing, no prune needed).
 *   - AE SQL is a network call; the table is a local indexed read.
 *   - AE samples; the rollup applies sum(_sample_interval) once at write time.
 *
 * ── Event-type correctness (the load-bearing detail) ─────────────────────────
 *
 * The worker writes ONE AE row per beacon event, and the doubles are sparse by
 * event type (see signal-and-noise-analytics-worker/src/index.js):
 *
 *   blob1='pv'  → a pageview;    double1=0,            double2=0
 *   blob1='sc'  → scroll depth;  double1=scroll_pct,   double2=0
 *   blob1='tm'  → time on page;  double1=0,            double2=time_ms
 *
 * blob2 (path) + index1 (visitor-day hash) are present on ALL three. So a naive
 * avg(double1) over every row for a path would be dragged toward 0 by the pv/tm
 * rows. Each metric therefore gets its own event-type filter via conditional
 * aggregation — sumIf/avgIf (Cloudflare AE SQL, GA since 2025-11):
 *
 *   views      = sumIf(_sample_interval, blob1 = 'pv')   true pageview count
 *   visits     = count(DISTINCT index1)                  approx unique visitor-days
 *   scroll_avg = avgIf(double1, blob1 = 'sc')            mean scroll milestone
 *   time_avg   = avgIf(double2, blob1 = 'tm')            mean visible ms per exit
 *
 * Phase A (schema v5) adds the exact weighted engagement sums to the same
 * SELECT — scroll_sum/scroll_events/time_sum/time_events, every one
 * _sample_interval-weighted — and a SECOND query for pageview_visits (distinct
 * visitor-days with ≥1 pv), merged per (day, path, class) in PHP, because the
 * single-query gated distinct 422s on live AE (P0.1, docs/analytics-integrity-plan.md).
 * The stored scroll_avg/time_avg now come from the weighted ratio sum/events
 * (identical to avgIf at sample interval 1; correct under sampling).
 *
 * Note: `views` is sample-corrected (×_sample_interval) but `visits` is a raw
 * distinct count of the hashes that survived sampling — under AE sampling the
 * two diverge (views/visit inflates). This site's volume rarely trips sampling
 * (interval=1 → both exact), and this matches the locked spec, so consumers
 * should treat views/visits as an estimate, not a precise ratio.
 *
 * ── Refresh architecture (the shared outbound-client SWR pattern) ──────────────────
 *
 *   - sn_analytics_run_rollup() (the cron callback) queries AE for the trailing
 *     SN_ANALYTICS_ROLLUP_WINDOW_DAYS and UPSERTs each (day, path). Re-rolling a
 *     window is idempotent: each run recomputes the full-day aggregate, so a
 *     partial "today" self-corrects and late-arriving samples are absorbed.
 *   - sn_analytics_rollup_warm() (admin_init) schedules a non-blocking single
 *     event when the freshness stamp is older than SN_ANALYTICS_ROLLUP_TTL, so
 *     an active admin sees ~15-min-fresh data without ever blocking a render.
 *   - sn_analytics_rollup_schedule() (init) registers a daily recurring backstop
 *     so the rollup still happens on days nobody opens wp-admin.
 *
 * Dormant until AE is configured (via the wp-config constants OR the admin settings
 * options) — sn_analytics_config() returns null and run_rollup() no-ops. The empty
 * table is created but never written, exactly like the Plausible widgets stay blank
 * until their token lands.
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_DAILY_TABLE          = 'sn_analytics_daily';
// v3: one-time purge of admin/login rows that leaked in before the ingestion
// guard (sn_analytics_is_excluded_path) existed. Data-only — no schema change.
// v5: additive engagement-sum columns (scroll_sum/scroll_events/time_sum/
// time_events) for exact per-view / per-visit denominators (Phase A spec §6).
// NULL DEFAULT NULL is load-bearing: legacy rows must read NULL ("never
// measured"), never a fabricated 0 — the derive layer distinguishes the two.
const SN_ANALYTICS_DAILY_DB_VERSION     = '5';
const SN_ANALYTICS_DAILY_DB_VERSION_OPT = 'sn_analytics_daily_db_version';

// SN_ANALYTICS_DATASET (the AE "sn_pageviews" dataset) is defined by the
// read-client inc/analytics-api.php, which the loader requires before this file.

// Two distinct hooks, same callback. The warmer schedules SINGLE events on the
// on-demand hook (which clears after firing, so wp_next_scheduled() reads false
// between runs and the warmer can re-fire). The daily backstop uses its OWN
// recurring hook — sharing one hook would leave wp_next_scheduled() permanently
// truthy and silently neuter the 15-min SWR warmer.
const SN_ANALYTICS_ROLLUP_HOOK         = 'sn_analytics_rollup';        // on-demand (warmer single events)
const SN_ANALYTICS_ROLLUP_DAILY_HOOK   = 'sn_analytics_rollup_daily';  // recurring daily backstop
const SN_ANALYTICS_ROLLUP_WINDOW_DAYS  = 7;                            // trailing window each run re-aggregates
const SN_ANALYTICS_ROLLUP_FRESH_KEY    = 'sn_analytics_rollup_fresh';
const SN_ANALYTICS_ROLLUP_TTL          = 15 * MINUTE_IN_SECONDS;  // freshness target for the admin warmer
const SN_ANALYTICS_ROLLUP_RETENTION    = DAY_IN_SECONDS;          // freshness stamp outlives the TTL
const SN_ANALYTICS_CLASSES             = array( 'human', 'suspect', 'bot' );
// Never-invert integrity alarm (Phase A spec §5): set by the upsert guard when
// a human row arrives with views < pageview_visits (arithmetically impossible —
// a genuine rollup/sampling bug), read by the Health surface. The row is still
// written un-clamped; the alarm is the feature.
const SN_ANALYTICS_INTEGRITY_ALERT_OPT = 'sn_analytics_integrity_alert';

/**
 * Is this an admin/login path that should never be counted as a human pageview?
 *
 * The front-end beacon (theme) only enqueues on wp_enqueue_scripts, so it can't
 * fire in wp-admin or on wp-login.php — any such path in the pipeline is noise
 * (a stray/forged beacon, a cache edge case), never a real visit. This is the
 * ingestion-side half of the invariant the retired Plausible importer enforced;
 * the collector Worker enforces the same rule at the edge. Boundary-aware so a
 * legitimate front-end slug like `/wp-admin-guide/` is NOT swept up.
 *
 * @param string $path Request path (already query/hash-stripped upstream).
 * @return bool
 */
function sn_analytics_is_excluded_path( $path ) {
	$path = (string) $path;
	return '/wp-admin' === $path
		|| 0 === strpos( $path, '/wp-admin/' )
		|| 0 === strpos( $path, '/wp-login.php' );
}

/**
 * dbDelta CREATE TABLE for the daily aggregate.
 *
 * Pure builder (no DB / no upgrade.php require) so it's unit-testable. `day` is
 * DATE; `path` is VARCHAR(180) and `class` VARCHAR(10), so the composite
 * UNIQUE(day, path, class) key is 763 bytes — inside InnoDB's 767-byte prefix.
 * Paths longer than 180 chars are truncated at write time (rare on this site).
 *
 * The five v5 columns (scroll_sum/scroll_events/time_sum/time_events plus the
 * gated pageview_visits denominator, stored per daily row so the read layer
 * can range-sum it — spec §4/§8) are NULL DEFAULT NULL on purpose: a legacy
 * row that predates them must read NULL ("never measured"), never a fabricated
 * 0 — downstream derivation treats null and zero as different answers.
 *
 * @return string CREATE TABLE statement.
 */
function sn_analytics_daily_schema_sql() {
	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$charset = $wpdb->get_charset_collate();

	return "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		day DATE NOT NULL,
		path VARCHAR(180) NOT NULL,
		class VARCHAR(10) NOT NULL DEFAULT 'human',
		views INT UNSIGNED NOT NULL DEFAULT 0,
		visits INT UNSIGNED NOT NULL DEFAULT 0,
		scroll_avg FLOAT NOT NULL DEFAULT 0,
		time_avg FLOAT NOT NULL DEFAULT 0,
		scroll_sum FLOAT NULL DEFAULT NULL,
		scroll_events INT UNSIGNED NULL DEFAULT NULL,
		time_sum FLOAT NULL DEFAULT NULL,
		time_events INT UNSIGNED NULL DEFAULT NULL,
		pageview_visits INT UNSIGNED NULL DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY day_path_class (day, path, class)
	) {$charset};";
}

/**
 * Create/upgrade the table. The function_exists('dbDelta') guard keeps install
 * unit-testable (a stubbed dbDelta skips the upgrade.php require) and is correct
 * production behaviour — don't re-require a file core may already have loaded.
 *
 * v1→v2 migration: dbDelta only ADDs columns and indexes — it cannot rotate a
 * UNIQUE KEY (it would leave the old (day, path) key in place). The table is
 * dormant (never populated before the analytics worker deploys), so drop +
 * recreate is safe and leaves no orphaned index. The DROP is gated on the option
 * being present AND stale so a first install (option absent) goes straight to
 * dbDelta without a spurious DROP.
 */
function sn_analytics_daily_install() {
	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$prev  = (string) get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, '' );

	// Structural rotation ONLY for the pre-v2 era. dbDelta can't rotate a UNIQUE
	// KEY (v1 was (day, path); v2 added class), so v1→v2 needed a drop+recreate —
	// safe then because the table was dormant (never populated before the worker
	// deployed). From v2 on the table holds real history, so we must NEVER blanket-
	// drop it; later migrations mutate data in place.
	if ( '' !== $prev && version_compare( $prev, '2', '<' ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	dbDelta( sn_analytics_daily_schema_sql() );

	// v3 one-time purge: admin/login paths leaked into the rollup before the
	// ingestion guard (sn_analytics_is_excluded_path) existed. The schema is
	// unchanged from v2, so this is a targeted, history-preserving DELETE — not a
	// drop. LIKE patterns mirror the predicate; a static query with a trusted
	// $wpdb->prefix table name, so no bound parameters are needed.
	if ( '' !== $prev && version_compare( $prev, '3', '<' ) ) {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- static DELETE; $table is $wpdb->prefix + a plugin constant; the path literals/LIKE patterns carry no external input.
		$wpdb->query( "DELETE FROM {$table} WHERE path = '/wp-admin' OR path LIKE '/wp-admin/%' OR path LIKE '/wp-login.php%'" );
	}

	// v4: the durable "day" boundary moved from the UTC day to the SITE-LOCAL day
	// (timezone-aware rollup). No schema change and no drop/purge — existing rows are
	// UTC-keyed but share the same YYYY-MM-DD key space, so a re-roll simply
	// overwrites the trailing window's buckets (idempotent by (day, path, class);
	// history preserved). Schedule ONE immediate re-roll so "views today" / "today so
	// far" become local promptly instead of waiting for the next warmer/backstop; the
	// hook self-clears after firing. Buckets older than the window converge on later
	// scheduled runs. Guarded on '' !== $prev so a fresh install (no data) skips it.
	if ( '' !== $prev && version_compare( $prev, '4', '<' ) ) {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_ANALYTICS_ROLLUP_HOOK ) ) {
			wp_schedule_single_event( time(), SN_ANALYTICS_ROLLUP_HOOK );
		}
	}

	// v4→v5 needs no gated step: dbDelta above ADDs the four nullable engagement
	// columns (scroll_sum/scroll_events/time_sum/time_events) additively. Legacy
	// rows keep NULL there — "never measured" — and the trailing-≤90d backfill is
	// an explicit owner-run tool, deliberately NOT an install side effect.

	update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, SN_ANALYTICS_DAILY_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install only runs on the delta.
 * Matches the cron-history / rss-feed-tracker install-once pattern.
 */
function sn_analytics_daily_maybe_install() {
	if ( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) !== SN_ANALYTICS_DAILY_DB_VERSION ) {
		sn_analytics_daily_install();
	}
}
add_action( 'init', 'sn_analytics_daily_maybe_install' );

/**
 * Build the AE SQL that aggregates the trailing $days into per-day-per-path
 * rows. $days is integer-cast and floored at 1 — it interpolates into the
 * query, so it must never carry attacker/string input (it doesn't; callers
 * pass a constant, but the cast is defence in depth).
 *
 * @param int $days Trailing window in days (floored to >= 1).
 * @return string AE SQL.
 */
function sn_analytics_rollup_sql( $days, $tz = '' ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( $days, $tz );

	// The four weighted engagement columns beside the kept avgIf pair are the
	// EXACT P0.2 shape live AE parsed on 2026-07-17 (docs/analytics-integrity-plan.md,
	// "P0 results"). Event counts are the WEIGHTED sumIf(_sample_interval, cond)
	// — never a raw countIf: under sampling a count is sum(_sample_interval).
	// pageview_visits deliberately does NOT live here: the gated single-query
	// form count(DISTINCT if(...)) 422s on live AE (P0.1) — it comes from the
	// second query below (sn_analytics_rollup_gated_sql), merged in PHP.
	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		'blob2 AS path,',
		'blob7 AS class,',
		"sumIf(_sample_interval, blob1 = 'pv') AS views,",
		'count(DISTINCT index1) AS visits,',
		"avgIf(double1, blob1 = 'sc') AS scroll_avg,",
		"avgIf(double2, blob1 = 'tm') AS time_avg,",
		"sumIf(double1 * _sample_interval, blob1 = 'sc') AS scroll_sum,",
		"sumIf(_sample_interval, blob1 = 'sc') AS scroll_events,",
		"sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum,",
		"sumIf(_sample_interval, blob1 = 'tm') AS time_events",
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE timestamp >= {$lower}",
		'GROUP BY day, path, class',
		'ORDER BY day DESC, views DESC',
	) );
}

/**
 * Shared window expressions for the two rollup queries: the day-bucket column
 * and the floored lower bound. Extracted so the gated pageview_visits query
 * (P0.1 Fallback A) buckets and floors IDENTICALLY to the main query — the
 * PHP-side merge joins on (day, path, class), so a drift here would silently
 * mis-key the merge.
 *
 * @param int    $days Trailing window in days (floored to >= 1).
 * @param string $tz   Optional IANA zone (charset-guarded; invalid → UTC path).
 * @return array{0:string,1:string} [ $day_col, $lower ].
 */
function sn_analytics_rollup_window_exprs( $days, $tz = '' ) {
	$days = max( 1, (int) $days );
	// Bucket each row by the SITE-LOCAL calendar day when a named IANA zone is
	// available (v9.26.4), so the durable "day" matches the site's day — and the live
	// "views today" measured in the same zone — instead of a UTC day that rolls
	// mid-evening for western zones (the 8pm-ET reset). AE's formatDateTime() and
	// toStartOfInterval() take an optional timezone arg (added 2025-11-12). The zone
	// is charset-guarded before interpolation as defence in depth; the caller already
	// validates it via sn_analytics_site_tz_name(). Empty/invalid → the UTC path.
	$tz      = ( '' !== $tz && preg_match( '#^[A-Za-z0-9_/+-]+$#', (string) $tz ) ) ? (string) $tz : '';
	$day_col = '' !== $tz
		? "formatDateTime(timestamp, '%Y-%m-%d', '{$tz}')"
		: "formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d')";
	// Floor the lower bound to a COMPLETE calendar day — LOCAL when zoned
	// (toStartOfInterval with the zone), UTC otherwise. A bare `now() - INTERVAL`
	// instant would aggregate the boundary day as a partial slice, and the UPSERT
	// would clobber its previously-complete row — silently corrupting the durable
	// forever-table. Flooring keeps every re-roll genuinely idempotent.
	$lower   = '' !== $tz
		? "toStartOfInterval(now(), INTERVAL '1' DAY, '{$tz}') - INTERVAL '{$days}' DAY"
		: "toStartOfDay(now() - INTERVAL '{$days}' DAY)";

	return array( $day_col, $lower );
}

/**
 * Build the SECOND rollup query: pageview-gated distinct visitor-days.
 *
 * P0.1 verdict (live probe, 2026-07-17): the single-query gated distinct
 * count(DISTINCT if(blob1 = 'pv', index1, NULL)) is rejected by live AE
 * (HTTP 422 — IF() branches must share a type), and the dialect guard's ban on
 * count(DISTINCT <expr>) STAYS. Fallback A passed: the existing verified
 * visits shape with AND blob1 = 'pv' in WHERE and a bare-column
 * count(DISTINCT index1). Results merge into the main rows per
 * (day, path, class) in sn_analytics_rollup_merge_gated().
 *
 * ORDER BY uses the pageview_visits alias — AE resolves aliases only, and the
 * `views` alias does not exist in this SELECT (alias-only ORDER BY gotcha).
 *
 * @param int    $days Trailing window in days (floored to >= 1).
 * @param string $tz   Optional IANA zone — MUST match the main query's zone.
 * @return string AE SQL.
 */
function sn_analytics_rollup_gated_sql( $days, $tz = '' ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( $days, $tz );

	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		'blob2 AS path,',
		'blob7 AS class,',
		'count(DISTINCT index1) AS pageview_visits',
		'FROM ' . SN_ANALYTICS_DATASET,
		"WHERE timestamp >= {$lower}",
		"AND blob1 = 'pv'",
		'GROUP BY day, path, class',
		'ORDER BY day DESC, pageview_visits DESC',
	) );
}

/**
 * Run the gated pageview_visits query, REFUSING a row-cap-truncated result.
 *
 * The merge (sn_analytics_rollup_merge_gated) treats a key missing from a
 * SUCCESSFUL gated result as a REAL 0 (empty is an answer) — sound only while
 * the gated set is COMPLETE. The two rollup queries order differently (views
 * DESC vs pageview_visits DESC), so AE's row cap can truncate them
 * ASYMMETRICALLY: a (day, path, class) present in the main set but cut from
 * the gated tail would be fabricated into a measured-0 pageview_visits. When
 * the response envelope says rows_before_limit_at_least > rows, the WHOLE
 * gated result degrades to the FAILED shape (null → keys stay absent → the
 * upsert binds SQL NULL, "never measured") — degrade, don't corrupt; the next
 * roll self-corrects. Shared by the cron (sn_analytics_run_rollup) and the
 * owner-run reroll tool so both inherit the same fail-safe. Never silent: the
 * refusal is error_log'd.
 *
 * @param string $sql Gated query SQL (sn_analytics_rollup_gated_sql() output,
 *                    or the reroll tool's bounded-window transform of it).
 * @return array|null Rows, or null on transport failure OR truncation.
 */
function sn_analytics_rollup_gated_query( $sql ) {
	$rows = sn_analytics_query( $sql );
	if ( ! is_array( $rows ) ) {
		return null;
	}
	// function_exists is defence in depth for a half-wired install; in
	// production the loader requires inc/analytics-api.php before this module.
	if ( function_exists( 'sn_analytics_last_result_truncated' ) && sn_analytics_last_result_truncated() ) {
		error_log( '[sn-analytics] gated pageview_visits result truncated by the AE row cap (rows_before_limit_at_least > rows) — treated as failed; pageview_visits stays NULL, never a fabricated 0' );
		return null;
	}
	return $rows;
}

/**
 * Merge the gated second-query result into the main rollup rows.
 *
 * Null discipline (the realtime-zero-vs-null rule, both directions):
 *   - Gated query FAILED (non-array): pageview_visits stays ABSENT on every
 *     row → the upsert binds SQL NULL ("never measured") — a failure must
 *     never fabricate a 0.
 *   - Gated query SUCCEEDED: a (day, path, class) with no gated row had zero
 *     pageview-gated visitor-days — a REAL 0 (an empty result is an ANSWER;
 *     this is exactly the viewless srv-beacon class) — never null.
 *
 * Pure and immutable: returns a new array; the inputs are not mutated.
 *
 * @param array      $rows  Main rollup rows (day/path/class/... keys).
 * @param array|null $gated Gated query rows, or null/non-array on failure.
 * @return array Merged rows.
 */
function sn_analytics_rollup_merge_gated( $rows, $gated ) {
	if ( ! is_array( $rows ) ) {
		return array();
	}
	if ( ! is_array( $gated ) ) {
		return $rows; // gated query failed — leave pageview_visits absent (NULL), never 0.
	}

	$map = array();
	foreach ( $gated as $g ) {
		if ( is_array( $g ) && array_key_exists( 'pageview_visits', $g ) ) {
			$map[ sn_analytics_rollup_row_key( $g ) ] = $g['pageview_visits'];
		}
	}

	$merged = array();
	foreach ( $rows as $r ) {
		if ( is_array( $r ) ) {
			$key                   = sn_analytics_rollup_row_key( $r );
			$r['pageview_visits']  = array_key_exists( $key, $map ) ? $map[ $key ] : 0;
		}
		$merged[] = $r;
	}
	return $merged;
}

/**
 * The merge join key. Mirrors the upsert's normalization (class defaults to
 * 'human') so both queries' rows key identically.
 *
 * @param array $row AE row with day/path/class keys.
 * @return string
 */
function sn_analytics_rollup_row_key( $row ) {
	$class = isset( $row['class'] ) && '' !== (string) $row['class'] ? (string) $row['class'] : 'human';
	return trim( (string) ( $row['day'] ?? '' ) ) . '|' . (string) ( $row['path'] ?? '' ) . '|' . $class;
}

/**
 * Read one of the five v5 nullable metrics from an AE row, preserving the
 * absent/null/non-numeric ⇒ null ("never measured") distinction. Uses
 * array_key_exists — `??`/isset() cannot tell a present-but-null key from an
 * absent one and would silently conflate the two. Numeric strings (AE returns
 * UInt64 as JSON strings) pass through untouched; the caller casts per column.
 *
 * @param array  $row AE result row.
 * @param string $key Column key.
 * @return int|float|string|null Numeric value (possibly a numeric string), or null.
 */
function sn_analytics_rollup_nullable_num( $row, $key ) {
	if ( ! array_key_exists( $key, $row ) ) {
		return null;
	}
	$value = $row[ $key ];
	return ( null === $value || ! is_numeric( $value ) ) ? null : $value;
}

/**
 * The legacy scroll_avg/time_avg value for one row.
 *
 * When BOTH the weighted sum and the weighted event count are known, the
 * stored mean is the weighted ratio sum/events — identical to the transported
 * avgIf at sample interval 1 (no visible change) and correct under sampling,
 * where the unweighted avgIf is wrong. Zero events ⇒ the ratio is undefined
 * (null), which the NOT NULL legacy column stores as 0 — exactly what the old
 * `avgIf → null → ?? 0` path produced. When either weighted input is unknown
 * (legacy caller shape), the transported value passes through as before.
 *
 * @param int|float|string|null $sum         Weighted sum, or null.
 * @param int|float|string|null $events      Weighted event count, or null.
 * @param mixed                 $transported The row's transported avg (legacy fallback).
 * @return float Rounded to 2dp.
 */
function sn_analytics_rollup_legacy_avg( $sum, $events, $transported ) {
	if ( null !== $sum && null !== $events ) {
		$ratio = (float) $events > 0 ? (float) $sum / (float) $events : null; // guard ÷0 — null when no events.
		return round( null === $ratio ? 0.0 : $ratio, 2 );
	}
	return round( (float) ( is_numeric( $transported ) ? $transported : 0 ), 2 );
}

/**
 * UPSERT AE result rows into the daily table.
 *
 * Validates + normalizes each row (a YYYY-MM-DD `day` and a non-empty `path`
 * are required; malformed rows are skipped, not written), then issues a single
 * batched INSERT ... ON DUPLICATE KEY UPDATE per chunk of 100. Re-running over
 * the same (day, path) overwrites the aggregate — exactly what a recomputed
 * partial day needs.
 *
 * @param array $rows AE rows keyed by the SELECT aliases (day/path/views/...).
 * @return int Number of rows written.
 */
function sn_analytics_rollup_upsert( $rows ) {
	if ( ! is_array( $rows ) || empty( $rows ) ) {
		return 0;
	}

	$clean = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$day   = isset( $r['day'] ) ? trim( (string) $r['day'] ) : '';
		$path  = isset( $r['path'] ) ? (string) $r['path'] : '';
		$class = isset( $r['class'] ) && '' !== (string) $r['class'] ? (string) $r['class'] : 'human';
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || '' === $path ) {
			continue;
		}
		if ( sn_analytics_is_excluded_path( $path ) ) {
			continue; // admin/login paths are never human pageviews — ingestion guard
		}
		if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
			continue; // defensive: never store an unexpected class
		}

		// The five v5 nullable columns: absent ≡ null ≡ "never measured" (kept
		// null through to a literal SQL NULL bind), read via array_key_exists —
		// NEVER the legacy `?? 0`, which would fabricate a measurement. A real
		// transported 0 (incl. AE's UInt64-as-string "0") stays a real 0.
		$scroll_sum      = sn_analytics_rollup_nullable_num( $r, 'scroll_sum' );
		$scroll_events   = sn_analytics_rollup_nullable_num( $r, 'scroll_events' );
		$time_sum        = sn_analytics_rollup_nullable_num( $r, 'time_sum' );
		$time_events     = sn_analytics_rollup_nullable_num( $r, 'time_events' );
		$pageview_visits = sn_analytics_rollup_nullable_num( $r, 'pageview_visits' );

		$c = array(
			'day'             => $day,
			'path'            => substr( $path, 0, 180 ),
			'class'           => $class,
			'views'           => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
			'visits'          => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
			// Legacy avgs switch to the weighted ratio sum/events when both are
			// known (identical to avgIf at sample interval 1, correct under
			// sampling); legacy passthrough otherwise. See the helper for the
			// 0-events (division-by-zero) rule.
			'scroll_avg'      => sn_analytics_rollup_legacy_avg( $scroll_sum, $scroll_events, $r['scroll_avg'] ?? 0 ),
			'time_avg'        => sn_analytics_rollup_legacy_avg( $time_sum, $time_events, $r['time_avg'] ?? 0 ),
			'scroll_sum'      => null === $scroll_sum ? null : max( 0.0, (float) $scroll_sum ),
			'scroll_events'   => null === $scroll_events ? null : max( 0, (int) round( (float) $scroll_events ) ),
			'time_sum'        => null === $time_sum ? null : max( 0.0, (float) $time_sum ),
			'time_events'     => null === $time_events ? null : max( 0, (int) round( (float) $time_events ) ),
			'pageview_visits' => null === $pageview_visits ? null : max( 0, (int) round( (float) $pageview_visits ) ),
		);

		// Never-invert guard (spec §5, human class): views ≥ pageview_visits
		// holds by construction, so an inversion is a genuine rollup/sampling
		// bug. Surface it — error_log + a timestamped option the Health scan
		// reads — and STILL write the row un-clamped. The alarm is the feature;
		// a clamp or skip would silently serve corrupt arithmetic as clean.
		if ( 'human' === $c['class'] && null !== $c['pageview_visits'] && $c['views'] < $c['pageview_visits'] ) {
			error_log( sprintf(
				'[sn-analytics] integrity violation: views < pageview_visits for %s %s (%d < %d) — row written unmodified',
				$c['day'],
				$c['path'],
				$c['views'],
				$c['pageview_visits']
			) );
			update_option( SN_ANALYTICS_INTEGRITY_ALERT_OPT, array(
				'time'            => time(),
				'day'             => $c['day'],
				'path'            => $c['path'],
				'class'           => $c['class'],
				'views'           => $c['views'],
				'pageview_visits' => $c['pageview_visits'],
			), false );
		}

		$clean[] = $c;
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	global $wpdb;
	$table   = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	$written = 0;

	foreach ( array_chunk( $clean, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();
		foreach ( $chunk as $c ) {
			// FLOAT columns bind as %s carrying a number_format()'d string,
			// NOT %f. %f routes through $wpdb->prepare()'s vsprintf(), which honours
			// LC_NUMERIC — under a comma-decimal server locale (de_DE, pt_BR, …) it
			// would emit "1,50" and corrupt the SQL. number_format( …, '.', '' )
			// forces a '.' decimal and empty thousands separator regardless of
			// locale, and MySQL coerces the quoted numeric string into the FLOAT column.
			$tuple = '(%s, %s, %s, %d, %d, %s, %s';
			array_push(
				$values,
				$c['day'],
				$c['path'],
				$c['class'],
				$c['views'],
				$c['visits'],
				number_format( (float) $c['scroll_avg'], 2, '.', '' ),
				number_format( (float) $c['time_avg'], 2, '.', '' )
			);
			// The five v5 nullable columns: null binds a LITERAL SQL NULL (no
			// placeholder, no value) so "never measured" survives the write;
			// known values bind %s (4dp dot-decimal, same locale rule) for the
			// FLOAT sums and %d for the INT counts.
			foreach ( array(
				'scroll_sum'      => 'float',
				'scroll_events'   => 'int',
				'time_sum'        => 'float',
				'time_events'     => 'int',
				'pageview_visits' => 'int',
			) as $col => $type ) {
				if ( null === $c[ $col ] ) {
					$tuple .= ', NULL';
				} elseif ( 'float' === $type ) {
					$tuple   .= ', %s';
					$values[] = number_format( (float) $c[ $col ], 4, '.', '' );
				} else {
					$tuple   .= ', %d';
					$values[] = (int) $c[ $col ];
				}
			}
			$placeholders[] = $tuple . ')';
		}
		$sql = "INSERT INTO {$table} (day, path, class, views, visits, scroll_avg, time_avg, scroll_sum, scroll_events, time_sum, time_events, pageview_visits) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE views=VALUES(views), visits=VALUES(visits), scroll_avg=VALUES(scroll_avg), time_avg=VALUES(time_avg),'
			. ' scroll_sum=VALUES(scroll_sum), scroll_events=VALUES(scroll_events), time_sum=VALUES(time_sum), time_events=VALUES(time_events), pageview_visits=VALUES(pageview_visits)';

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- $sql is a static INSERT ... VALUES template with a generated %s/%d placeholder group per row; $table is $wpdb->prefix + a plugin constant and every value is bound via prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );
		if ( false !== $result ) {
			$written += count( $chunk );
		}
	}

	return $written;
}

/**
 * Cron callback: pull the trailing window from AE and UPSERT it.
 *
 * No-ops (no fresh stamp) when AE isn't configured or the query fails, so the
 * warmer keeps retrying rather than treating a failure as "done". A successful
 * query — even an empty result on an idle day — stamps freshness so the 15-min
 * warmer doesn't re-fire on every admin pageview.
 */
function sn_analytics_run_rollup() {
	// Defence in depth: the read-client (inc/analytics-api.php) is required
	// immediately before this module, but never fatal a cron run if a half-
	// wired install reaches here.
	if ( ! function_exists( 'sn_analytics_config' ) || ! function_exists( 'sn_analytics_query' ) ) {
		return;
	}
	if ( ! sn_analytics_config() ) {
		return;
	}

	// Roll by the SITE-LOCAL day when a named zone is available (v9.26.4). If the
	// zoned query fails (an AE that predates the timezone functions → HTTP 422 →
	// null), fall back to the UTC rollup WITHIN THE SAME RUN so the durable table
	// never goes stale on account of the zone syntax alone — it degrades to the
	// pre-v9.26.4 behaviour, no worse. A successful-but-empty zoned result is []
	// (is_array), so the fallback only fires on a real failure.
	$tz      = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$used_tz = $tz;
	$rows    = sn_analytics_query( sn_analytics_rollup_sql( SN_ANALYTICS_ROLLUP_WINDOW_DAYS, $tz ) );
	if ( '' !== $tz && ! is_array( $rows ) ) {
		$used_tz = '';
		$rows    = sn_analytics_query( sn_analytics_rollup_sql( SN_ANALYTICS_ROLLUP_WINDOW_DAYS, '' ) );
	}
	if ( ! is_array( $rows ) ) {
		return; // transport / non-200 / parse failure — already captured by the read-client.
	}

	if ( ! empty( $rows ) ) {
		// Second query (P0.1 Fallback A): pageview-gated distinct visitor-days,
		// merged per (day, path, class) in PHP. It runs with the SAME zone the
		// main query actually succeeded with — if the zoned main query fell back
		// to UTC, a zoned gated query would bucket different "day" keys and the
		// merge would silently miss. A gated failure — transport OR a row-cap-
		// truncated result (the wrapper refuses those) — leaves pageview_visits
		// absent (SQL NULL — "never measured"), never a fabricated 0; the main
		// rows still write, so a flaky second query degrades, not corrupts.
		$gated = sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( SN_ANALYTICS_ROLLUP_WINDOW_DAYS, $used_tz ) );
		sn_analytics_rollup_upsert( sn_analytics_rollup_merge_gated( $rows, $gated ) );
	}

	// P3: roll the referrer/country/device breakdowns in the same pass (their
	// own AE queries). Guarded so a half-wired install never fatals the cron.
	if ( function_exists( 'sn_analytics_dims_run_rollup' ) ) {
		sn_analytics_dims_run_rollup();
	}

	// v9.28.0: roll the packed UTM (blob20) into the Source/Medium + Campaign
	// table in the same pass — its own AE query, same guard.
	if ( function_exists( 'sn_analytics_utm_run_rollup' ) ) {
		sn_analytics_utm_run_rollup();
	}

	// v5.4.0: roll the derived views (hour-of-day heatmap + scroll/time
	// distributions) in the same pass — their own AE queries, same guard.
	if ( function_exists( 'sn_analytics_buckets_run_rollup' ) ) {
		sn_analytics_buckets_run_rollup();
	}

	// v6.10.0: roll entry (landing) pages in the same pass — its own AE query
	// (referrer external/direct), same function_exists guard. No new cron.
	if ( function_exists( 'sn_analytics_pageroles_run_rollup' ) ) {
		sn_analytics_pageroles_run_rollup();
	}

	// v6.10.0: roll live custom events (ce → events, cp → event_props) in the
	// same pass — their own AE queries, same function_exists guard. No new cron.
	if ( function_exists( 'sn_analytics_events_run_rollup' ) ) {
		sn_analytics_events_run_rollup();
	}

	set_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY, time(), SN_ANALYTICS_ROLLUP_RETENTION );
}
add_action( SN_ANALYTICS_ROLLUP_HOOK, 'sn_analytics_run_rollup' );
add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_analytics_run_rollup' );

/**
 * General-purpose read accessor: rolled-up rows for an inclusive [$from, $to]
 * day range filtered to a single traffic class (default 'human'), newest day
 * first, type-normalized for the JSON layer.
 *
 * Reserved for future/downstream use. The shipped dashboard surfaces (stat cards,
 * trend strip, top pages) use the purpose-built accessors in inc/analytics-read.php
 * (sn_analytics_range_totals / sn_analytics_daily_series / sn_analytics_top_paths)
 * rather than this function, because those accessors apply views-weighted aggregation
 * and per-field type normalization suited to their specific render contracts.
 * The class-separation line is fed by sn_analytics_class_totals().
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class to return: 'human' | 'suspect' | 'bot'. Defaults to 'human'.
 * @return array<int, array{day:string,path:string,class:string,views:int,visits:int,scroll_avg:float,time_avg:float}>
 */
function sn_analytics_daily_range( $from, $to, $class = 'human' ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;
	if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
		$class = 'human';
	}

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT day, path, class, views, visits, scroll_avg, time_avg
		 FROM {$table}
		 WHERE day >= %s AND day <= %s AND class = %s
		 ORDER BY day DESC, views DESC",
		(string) $from,
		(string) $to,
		$class
	), ARRAY_A );

	if ( ! is_array( $results ) ) {
		return array();
	}

	$out = array();
	foreach ( $results as $r ) {
		$out[] = array(
			'day'        => (string) $r['day'],
			'path'       => (string) $r['path'],
			'class'      => (string) $r['class'],
			'views'      => (int) $r['views'],
			'visits'     => (int) $r['visits'],
			'scroll_avg' => (float) $r['scroll_avg'],
			'time_avg'   => (float) $r['time_avg'],
		);
	}
	return $out;
}

/**
 * Per-class view/visit totals across a day range — feeds the "N automated
 * filtered" separation line. Returns a map keyed by class.
 *
 * @param string $from Inclusive start day, YYYY-MM-DD.
 * @param string $to   Inclusive end day, YYYY-MM-DD.
 * @return array<string, array{views:int, visits:int}>
 */
function sn_analytics_class_totals( $from, $to ) {
	global $wpdb;
	$table = $wpdb->prefix . SN_ANALYTICS_DAILY_TABLE;

	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT class, SUM(views) AS views, SUM(visits) AS visits
		 FROM {$table}
		 WHERE day >= %s AND day <= %s
		 GROUP BY class",
		(string) $from,
		(string) $to
	), ARRAY_A );

	$out = array();
	if ( is_array( $results ) ) {
		foreach ( $results as $r ) {
			$out[ (string) $r['class'] ] = array(
				'views'  => (int) $r['views'],
				'visits' => (int) $r['visits'],
			);
		}
	}
	return $out;
}

/**
 * Admin warmer: schedule a non-blocking background rollup when the freshness
 * stamp is older than the TTL. Capability-gated so we don't warm for users who
 * can never see the stats; wp_next_scheduled() prevents event stacking.
 */
function sn_analytics_rollup_warm() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$fresh = get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY );
	$age   = is_int( $fresh ) ? ( time() - $fresh ) : PHP_INT_MAX;

	if ( $age > SN_ANALYTICS_ROLLUP_TTL && ! wp_next_scheduled( SN_ANALYTICS_ROLLUP_HOOK ) ) {
		wp_schedule_single_event( time(), SN_ANALYTICS_ROLLUP_HOOK );
	}
}
add_action( 'admin_init', 'sn_analytics_rollup_warm', 5 );

/**
 * Daily recurring backstop so the rollup runs even on days nobody opens
 * wp-admin. Idempotent via wp_next_scheduled(); hooked on init (not admin_init)
 * so it registers on front-end / WP-CLI requests too.
 */
function sn_analytics_rollup_schedule() {
	if ( ! wp_next_scheduled( SN_ANALYTICS_ROLLUP_DAILY_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SN_ANALYTICS_ROLLUP_DAILY_HOOK );
	}
}
add_action( 'init', 'sn_analytics_rollup_schedule' );
