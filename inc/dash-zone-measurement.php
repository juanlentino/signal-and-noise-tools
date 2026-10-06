<?php
/**
 * Signal & Noise — Dashboard measurement data.
 *
 * Gathers the site measurement figures the dashboard signals and widget read.
 * The v11.28.0 measurement-strip renderer and its figure builder were removed
 * once nothing rendered the strip.
 *
 * An ABSENT value is unknown and must never render as 0. A Search Console read
 * that failed is missing evidence, not zero clicks.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gather the measurement figures from the accessors that exist.
 *
 * An accessor that is not present contributes NO KEY, which consumers
 * read as unmeasured and render as an em dash. That is the point: a missing
 * module is missing evidence, and a 0 here would be a claim we cannot support.
 *
 * `search_clicks` is deliberately absent. The Search Console read sits
 * outside the glance cache and needs its own transient; until that lands the
 * figure renders unknown, which is the same thing the proposal specifies for a
 * cache miss or an API error. Citations reads the local table, where 0 is a
 * genuine measured zero.
 *
 * @since 11.28.0
 * @return array<string,mixed>
 */
function snt_dashboard_measurement_data() {
	$data = array();

	if ( function_exists( 'sn_analytics_config' ) && sn_analytics_config()
		&& function_exists( 'sn_analytics_period_deltas' ) ) {
		$from   = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$to     = gmdate( 'Y-m-d', time() );
		$deltas = sn_analytics_period_deltas( $from, $to, 'human' );
		if ( is_array( $deltas ) && isset( $deltas['views'] ) ) {
			$data['views_7d'] = (int) ( $deltas['views']['current'] ?? 0 );
			// DERIVED, not read. sn_analytics_period_deltas() returns
			// current/previous/pct/dir and has never returned a `delta` key —
			// so the isset() this replaces was always false and the Views
			// figure has been silently missing its comparison since v11.28.0.
			if ( isset( $deltas['views']['previous'] ) ) {
				$data['views_prior']  = (int) $deltas['views']['previous'];
				$data['views_delta']  = (int) ( $deltas['views']['current'] ?? 0 ) - (int) $deltas['views']['previous'];
			}
		}

		// The hero's trend, from the same accessor the Analytics widget uses.
		// sn_analytics_daily_series() memoises on from|to|class|granularity, so
		// this costs nothing beyond the first call in a request.
		if ( function_exists( 'sn_analytics_daily_series' ) && function_exists( 'snt_analytics_sparkline' ) ) {
			$series = sn_analytics_daily_series( $from, $to, 'human', 'day' );
			if ( ! empty( $series ) && is_array( $series ) ) {
				$data['views_series'] = $series;
			}
		}
	}

	if ( function_exists( 'snt_ai_usage_summary' ) ) {
		$s30 = snt_ai_usage_summary( 30 );
		if ( is_array( $s30 ) ) {
			$data['ai_spend_30d'] = (float) ( $s30['cost'] ?? 0 );
			// Context for the spend figure: cost alone cannot be judged, but
			// cost across N calls can.
			$data['ai_calls_30d'] = (int) ( $s30['calls'] ?? 0 );
		}
	}

	if ( function_exists( 'snt_prov_anchor_overview' ) ) {
		$ov = snt_prov_anchor_overview();
		if ( is_array( $ov ) && array_key_exists( 'confirmed', $ov ) ) {
			$data['anchored'] = (int) $ov['confirmed'];
			// The denominator IS the context: "33" means nothing, "33 of 33"
			// is an answer.
			if ( array_key_exists( 'total', $ov ) ) {
				$data['anchored_total'] = (int) $ov['total'];
			}
		}
	}

	if ( function_exists( 'sn_cit_counts' ) && defined( 'SN_CIT_TIERS' ) ) {
		$counts = sn_cit_counts();
		if ( is_array( $counts ) ) {
			$total = 0;
			// Tier keys only — `never_checked` is a different axis over the same
			// rows, so adding it would double-count.
			foreach ( SN_CIT_TIERS as $tier ) {
				$total += (int) ( $counts[ $tier ] ?? 0 );
			}
			$data['citations'] = $total;
		}
	}

	// Search Console reads the STORED payload — no API call on a page render.
	// snt_gsc_window_totals() returns null until something has synced, which
	// keeps the key absent and the figure unknown. That is the same answer the
	// design specifies for a failed read: an unreachable API is missing
	// evidence, not zero clicks.
	if ( function_exists( 'snt_gsc_window_totals' ) ) {
		$gsc = snt_gsc_window_totals();
		if ( is_array( $gsc ) ) {
			$data['search_clicks']       = (int) $gsc['clicks'];
			$data['search_clicks_days']  = (int) $gsc['days'];
			$data['search_impressions']  = (int) ( $gsc['impressions'] ?? 0 );
			$data['search_clicks_capped'] = ! empty( $gsc['capped'] );
		}
	}

	return $data;
}
