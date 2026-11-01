<?php
/**
 * Signal & Noise Tools: Analytics Rollup, the runs and the reads (the rollup runs and their cron hooks, the range and class reads, the admin warmer and the daily schedule).
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
	// Every table this pass replaces is read under the human rule, and the rule
	// needs the over-cap list. A list that did not read would store the week with
	// automated visitor-days counted as readers (2026-10-11: Oct 6 stored 762
	// human views, not 10, until the next run). Keep the stored days; no fresh
	// stamp, so the next poke retries.
	if ( function_exists( 'sn_analytics_overcap_vdays' ) && empty( sn_analytics_overcap_vdays()['ok'] ) ) {
		error_log( '[sn-analytics] rollup skipped: the over-cap visitor-day list did not read; the stored days are kept' );
		return;
	}

	if ( ! sn_analytics_pageviews_run_rollup() ) {
		return; // the main read failed: no breakdowns, no fresh stamp (as before the split).
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

	// A bounded history batch (the recompute) is not the nightly window: it
	// must not tell the warmer the trailing week is fresh.
	if ( 0 === (int) sn_analytics_rollup_window()['until'] ) {
		set_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY, time(), SN_ANALYTICS_ROLLUP_RETENTION );
	}
}
/**
 * The page-view family alone (main + pageview-gated query, one day-replacing
 * write). Split out so the history recompute can run it as its own unit.
 *
 * @return bool False when the main read failed (nothing written).
 */
function sn_analytics_pageviews_run_rollup() {
	if ( ! function_exists( 'sn_analytics_query' ) || ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return false;
	}
	// Roll by the SITE-LOCAL day when a named zone is available (v9.26.4). If the
	// zoned query fails (an AE that predates the timezone functions → HTTP 422 →
	// null), fall back to the UTC rollup WITHIN THE SAME RUN so the durable table
	// never goes stale on account of the zone syntax alone — it degrades to the
	// pre-v9.26.4 behaviour, no worse. A successful-but-empty zoned result is []
	// (is_array), so the fallback only fires on a real failure.
	// The over-cap fold (bot row) has not been proven on live AE: when both the
	// zoned and the UTC read of it fail, run the pre-fold shape once more, so a
	// refused form degrades to the old figures instead of freezing the table.
	// The shape that ran is recorded (SN_ANALYTICS_ROLLUP_SHAPE_OPT).
	$tz   = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$days = sn_analytics_rollup_window()['days'];
	foreach ( array( true, false ) as $fold ) {
		foreach ( array_unique( array( $tz, '' ) ) as $used_tz ) {
			$rows = sn_analytics_query( sn_analytics_rollup_sql( $days, $used_tz, $fold ) );
			if ( is_array( $rows ) ) {
				break 2;
			}
		}
	}
	if ( ! is_array( $rows ) ) {
		return false; // transport / non-200 / parse failure — already captured by the read-client.
	}
	update_option( SN_ANALYTICS_ROLLUP_SHAPE_OPT, array( 'shape' => $fold ? 'folded' : 'unfolded', 'at' => time() ), false );
	if ( ! $fold ) {
		error_log( '[sn-analytics] rollup: the over-cap fold read failed; stored the pre-fold shape (over-cap dropped from every class)' );
	}
	// Judged NOW: the gated query below resets the truncation verdict. An
	// empty window is breakage-shaped here, never a reason to clear history.
	$complete = ! empty( $rows ) && ( ! function_exists( 'sn_analytics_last_result_truncated' ) || ! sn_analytics_last_result_truncated() );

	if ( ! empty( $rows ) ) {
		// Second query (P0.1 Fallback A): pageview-gated distinct visitor-days,
		// merged per (day, path, class) in PHP. It runs with the SAME zone the
		// main query actually succeeded with — if the zoned main query fell back
		// to UTC, a zoned gated query would bucket different "day" keys and the
		// merge would silently miss. A gated failure — transport OR a row-cap-
		// truncated result (the wrapper refuses those) — leaves pageview_visits
		// absent (SQL NULL — "never measured"), never a fabricated 0; the main
		// rows still write, so a flaky second query degrades, not corrupts.
		$gated = sn_analytics_rollup_gated_query( sn_analytics_rollup_gated_sql( $days, $used_tz, $fold ) );
		// Third query: scroll per view by pageview ID (inc/analytics-rollup-pid-scroll.php),
		// same zone and fold; null keeps the plain event count.
		$pid   = sn_analytics_rollup_pid_scroll_query( sn_analytics_rollup_pid_scroll_sql( $days, $used_tz, $fold ) );
		$write = static function () use ( $rows, $gated, $pid ) {
			sn_analytics_rollup_upsert( sn_analytics_rollup_merge_pid_scroll( sn_analytics_rollup_merge_gated( $rows, $gated ), $pid ) );
		};
		function_exists( 'sn_analytics_rollup_replace' ) ? sn_analytics_rollup_replace( $complete, SN_ANALYTICS_DAILY_TABLE, $used_tz, $write ) : $write();
	}
	return true;
}

add_action( SN_ANALYTICS_ROLLUP_HOOK, 'sn_analytics_v2_verify', 5, 0 ); // the on-demand run writes the same durable rows; it follows a verdict as fresh as the daily one.
add_action( SN_ANALYTICS_ROLLUP_HOOK, 'sn_analytics_run_rollup' );
add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_analytics_v2_verify', 5, 0 ); // before the rollup: compare the two dataset generations and store the verdict the reads follow.
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

	// (int), not is_int(): without a persistent object cache the stamp
	// round-trips the options table as a STRING, and is_int() read every
	// fresh stamp as absent — a rollup scheduled on every admin_init (#1209).
	$fresh = (int) get_transient( SN_ANALYTICS_ROLLUP_FRESH_KEY );
	$age   = $fresh > 0 ? ( time() - $fresh ) : PHP_INT_MAX;

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
