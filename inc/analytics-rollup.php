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
 * Note: `views` is sample-corrected (×_sample_interval) but `visits` is a raw
 * distinct count of the hashes that survived sampling — under AE sampling the
 * two diverge (views/visit inflates). This site's volume rarely trips sampling
 * (interval=1 → both exact), and this matches the locked spec, so consumers
 * should treat views/visits as an estimate, not a precise ratio.
 *
 * ── Refresh architecture (mirrors inc/plausible-api.php SWR) ──────────────────
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
const SN_ANALYTICS_DAILY_DB_VERSION     = '2';
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

/**
 * dbDelta CREATE TABLE for the daily aggregate.
 *
 * Pure builder (no DB / no upgrade.php require) so it's unit-testable. `day` is
 * DATE; `path` is VARCHAR(180) and `class` VARCHAR(10), so the composite
 * UNIQUE(day, path, class) key is 763 bytes — inside InnoDB's 767-byte prefix.
 * Paths longer than 180 chars are truncated at write time (rare on this site).
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
	// dbDelta only ADDS — it can't rotate a UNIQUE KEY. The table is dormant
	// (never populated before the worker deploys), so drop + recreate is safe
	// and avoids leaving the old (day, path) key in place.
	if ( get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) && get_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT ) !== SN_ANALYTICS_DAILY_DB_VERSION ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
	dbDelta( sn_analytics_daily_schema_sql() );
	update_option( SN_ANALYTICS_DAILY_DB_VERSION_OPT, SN_ANALYTICS_DAILY_DB_VERSION );
}

/**
 * One autoloaded-option compare per request; install only runs on the delta.
 * Matches the cron-history / rss-plausible-tracker install-once pattern.
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
function sn_analytics_rollup_sql( $days ) {
	$days = max( 1, (int) $days );

	return implode( ' ', array(
		"SELECT formatDateTime(toStartOfDay(timestamp), '%Y-%m-%d') AS day,",
		'blob2 AS path,',
		'blob7 AS class,',
		"sumIf(_sample_interval, blob1 = 'pv') AS views,",
		'count(DISTINCT index1) AS visits,',
		"avgIf(double1, blob1 = 'sc') AS scroll_avg,",
		"avgIf(double2, blob1 = 'tm') AS time_avg",
		'FROM ' . SN_ANALYTICS_DATASET,
		// Floor the lower bound to a day boundary so the OLDEST in-window bucket
		// is a COMPLETE calendar day. A bare `now() - INTERVAL` lower bound is a
		// wall-clock instant, so the boundary day would be aggregated as a
		// partial slice (only events after that instant-of-day) and the UPSERT
		// would clobber its previously-complete row — silently corrupting the
		// durable forever-table. Flooring keeps every re-roll genuinely idempotent.
		"WHERE timestamp >= toStartOfDay(now() - INTERVAL '{$days}' DAY)",
		'GROUP BY day, path, class',
		'ORDER BY day DESC, views DESC',
	) );
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
		if ( ! in_array( $class, SN_ANALYTICS_CLASSES, true ) ) {
			continue; // defensive: never store an unexpected class
		}
		$clean[] = array(
			'day'        => $day,
			'path'       => substr( $path, 0, 180 ),
			'class'      => $class,
			'views'      => max( 0, (int) round( (float) ( $r['views'] ?? 0 ) ) ),
			'visits'     => max( 0, (int) round( (float) ( $r['visits'] ?? 0 ) ) ),
			'scroll_avg' => round( (float) ( $r['scroll_avg'] ?? 0 ), 2 ),
			'time_avg'   => round( (float) ( $r['time_avg'] ?? 0 ), 2 ),
		);
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
			$placeholders[] = '(%s, %s, %s, %d, %d, %f, %f)';
			array_push( $values, $c['day'], $c['path'], $c['class'], $c['views'], $c['visits'], $c['scroll_avg'], $c['time_avg'] );
		}
		$sql = "INSERT INTO {$table} (day, path, class, views, visits, scroll_avg, time_avg) VALUES "
			. implode( ', ', $placeholders )
			. ' ON DUPLICATE KEY UPDATE views=VALUES(views), visits=VALUES(visits), scroll_avg=VALUES(scroll_avg), time_avg=VALUES(time_avg)';

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

	$rows = sn_analytics_query( sn_analytics_rollup_sql( SN_ANALYTICS_ROLLUP_WINDOW_DAYS ) );
	if ( ! is_array( $rows ) ) {
		return; // transport / non-200 / parse failure — already captured by the read-client.
	}

	if ( ! empty( $rows ) ) {
		sn_analytics_rollup_upsert( $rows );
	}

	// P3: roll the referrer/country/device breakdowns in the same pass (their
	// own AE queries). Guarded so a half-wired install never fatals the cron.
	if ( function_exists( 'sn_analytics_dims_run_rollup' ) ) {
		sn_analytics_dims_run_rollup();
	}

	set_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY, time(), SN_ANALYTICS_ROLLUP_RETENTION );
}
add_action( SN_ANALYTICS_ROLLUP_HOOK, 'sn_analytics_run_rollup' );
add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_analytics_run_rollup' );

/**
 * Read accessor for downstream surfaces: rolled-up rows for an inclusive
 * [$from, $to] day range filtered to a single traffic class (default 'human'),
 * newest day first, type-normalized for the JSON layer.
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
