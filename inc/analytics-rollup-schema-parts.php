<?php
/**
 * Signal & Noise Tools: Analytics Rollup, the table and the main query (the schema SQL, the installer and its init hook, the rollup SQL).
 *
 * Split from inc/analytics-rollup.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	// v6 (v9.66.0): re-base stored scroll_sum to TRUE depth units. The beacon
	// fires one CUMULATIVE milestone event per 25/50/75/100% reached, so the raw
	// milestone-point sum stored through v5 read 250 for a full-depth view (the
	// shipped-113% unit). Because milestones are evenly spaced and fire at most
	// once per view, 25 × scroll_events IS the sum of per-view max depths — an
	// EXACT identity (both columns aggregate the same 'sc' events), not an
	// estimate. dbDelta cannot run UPDATEs, so the repair runs explicitly
	// post-dbDelta; it is idempotent by construction (25 × events is a fixed
	// point) and NULL rows stay NULL ("never measured" survives). Gated on
	// '' !== $prev so a fresh install (no data) skips it.
	if ( '' !== $prev && version_compare( $prev, '6', '<' ) ) {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL -- static one-time migration UPDATE; $table is $wpdb->prefix + a plugin constant; no external input.
		$repair = $wpdb->query( "UPDATE {$table} SET scroll_sum = 25 * scroll_events WHERE scroll_events IS NOT NULL" );
		if ( false === $repair || '' !== (string) $wpdb->last_error ) {
			// A transient failure (lock wait, crashed table) must NOT be stamped
			// over: stamping '6' regardless would make maybe_install never
			// re-enter and the repair would be skipped permanently AND
			// invisibly. Leave the option at $prev so the next init retries —
			// the UPDATE is an idempotent fixed point (25 × events), so
			// re-running is safe, and dbDelta re-running above is harmless.
			// Never-silent: the failure reason is logged, never swallowed.
			$repair_err = (string) $wpdb->last_error;
			error_log( '[sn-analytics] v6 scroll_sum repair failed (version left at ' . $prev . ' for retry on next init): '
				. ( '' !== $repair_err ? $repair_err : 'wpdb::query returned false' ) );
			return;
		}
	}

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
	// The scroll_sum alias transports the RAW milestone-point sum and since
	// v9.66.0 feeds ONLY the weighted legacy scroll_avg — the STORED scroll_sum
	// is re-based to 25 × scroll_events in the upsert (PHP-side on purpose: no
	// new SQL arithmetic forms enter this live-AE-verified query).
	return implode( ' ', array(
		"SELECT {$day_col} AS day,",
		'blob2 AS path,',
		sn_analytics_counted_class_select( sn_analytics_overcap_vdays()['hashes'] ) . ' AS class,',
		"sumIf(_sample_interval, blob1 = 'pv') AS views,",
		'count(DISTINCT index1) AS visits,',
		"avgIf(double1, blob1 = 'sc') AS scroll_avg,",
		"avgIf(double2, blob1 = 'tm') AS time_avg,",
		"sumIf(double1 * _sample_interval, blob1 = 'sc') AS scroll_sum,",
		"sumIf(_sample_interval, blob1 = 'sc') AS scroll_events,",
		"sumIf(double2 * _sample_interval, blob1 = 'tm') AS time_sum,",
		"sumIf(_sample_interval, blob1 = 'tm') AS time_events",
		'FROM ' . sn_analytics_source( sn_analytics_trailing_from( $days ) ),
		"WHERE timestamp >= {$lower}" . sn_analytics_window_upper( $tz ),
		'GROUP BY day, path, class',
		'ORDER BY day DESC, views DESC',
	) );
}


