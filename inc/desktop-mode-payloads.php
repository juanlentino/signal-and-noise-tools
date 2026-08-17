<?php
/**
 * Signal & Noise Tools — the data layer behind the widgets.
 *
 * Every payload builder the widgets read: the health summary and machine-
 * readers summary that ride the localize, the 14-day view series behind its
 * own REST route, and the living-tree traffic filter.
 *
 * THE SPLIT RULE (v9.52.0): cheap + durable → localize; anything costing real
 * queries → REST, fetched on render. The block comment below states why.
 *
 * null IS NOT 0 throughout. A source that has never been measured returns
 * null so the widget can render an honest empty state instead of a fabricated
 * zero or a fake pass.
 *
 * Split out of inc/desktop-mode-integration.php in v10.87.2; the code is
 * unchanged. That file is now the loader and still carries the architectural
 * notes covering all seven modules — read it first.
 *
 * @package SignalNoiseTools
 * @since 9.52.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ─────────────────────────────────────────────────────────────────────
 * v9.52.0 — analytics widget data layer
 *
 * The widgets need three shapes of data.
 * The split follows the plugin-wide "keep it off the request path"
 * discipline:
 *
 *   - CHEAP + DURABLE (health scan, uptime last-good) → localized into
 *     window.snDesktopData. Each is one non-autoloaded option read, and
 *     the payload rides a <script> tag we already emit.
 *   - The 14-DAY VIEW SERIES → REST, fetched on render. It is NOT
 *     expensive in the Analytics-Engine sense (inc/analytics-read.php
 *     reads the durable wp_sn_analytics_daily rollup table via $wpdb;
 *     the AE path is sn_analytics_query() in inc/analytics-api.php and
 *     is never touched here). It stays out of the localize because it
 *     costs TWO aggregate SQL queries — a SUM over the window and a
 *     GROUP BY series — and localizing would spend them on EVERY
 *     wp-admin page load, for a widget the user may not have enabled.
 *     Fetch-on-render spends them only when a widget actually mounts.
 * ───────────────────────────────────────────────────────────────────── */

/**
 * Percentage change between two windows.
 *
 * Returns null rather than INF/NAN when the prior window is zero: there is
 * no meaningful "percent up from nothing", and a JSON-encoded INF is not
 * valid JSON. The widget renders a bare total when delta_pct is null.
 *
 * @param int|float $current Current-window total.
 * @param int|float $prior   Prior-window total.
 * @return float|null Signed percentage, or null when incomputable.
 */
function snt_desktop_delta_pct( $current, $prior ) {
	if ( ! is_numeric( $current ) || ! is_numeric( $prior ) || (float) $prior === 0.0 ) {
		return null;
	}
	return round( ( ( (float) $current - (float) $prior ) / (float) $prior ) * 100, 1 );
}

/**
 * Content-health summary for the localize payload.
 *
 * DERIVES pass/fail through the existing single-source-of-truth helpers in
 * inc/health-summary.php rather than re-deriving it here. A scan's `checks`
 * is a MAP of key => { count, findings, label, fix_hint } — there is no
 * "passed" flag in the model; "passed" means a check with zero findings, and
 * advisory-tier checks (external_links, link_opportunities) carry findings by
 * nature and must never read as failures. sn_health_flagged_checks() already
 * encodes both rules, so use it.
 *
 * NULL when no scan has ever run — deliberately not a synthetic "0/0 passed",
 * which would render as a green all-clear and tell the owner the opposite of
 * the truth. (Same silent-wrong-answer class as the v10.42.2 reading-time
 * "5 min" fallback.)
 *
 * @return array{passed:int,total:int,all_passed:bool,scanned_at:int}|null
 */
function snt_health_summary_for_localize() {
	if ( ! function_exists( 'sn_health_last_scan' ) || ! function_exists( 'sn_health_check_total' ) || ! function_exists( 'sn_health_flagged_checks' ) ) {
		return null;
	}
	$scan = sn_health_last_scan();
	if ( ! is_array( $scan ) || empty( $scan['checks'] ) || ! is_array( $scan['checks'] ) ) {
		return null;
	}

	$flagged_map = sn_health_flagged_checks( $scan );
	$flagged_n   = count( $flagged_map );

	// v10.83.0: report-only checks (contrast_tokens and its successors) raise
	// zero findings BY DESIGN, so `total - flagged` counted them as passes —
	// a verdict a check that cannot fail must not be able to earn.
	//
	// This widget drops them from the DENOMINATOR as well as the numerator,
	// unlike the Health tab. That is deliberate, not drift: the tab has room
	// for a meta line naming the gap ("17 of 19 · 1 report-only"), and this
	// card is a one-line glance where a green dot beside "17/19" would read as
	// two silent failures. Both surfaces agree on the number that matters —
	// 17 passed — and neither counts a report as one. `report_only` rides the
	// payload so the card can name the gap later without a server change.
	$report_n = function_exists( 'sn_health_report_checks' ) ? count( (array) sn_health_report_checks( $scan ) ) : 0;
	$total    = max( 0, sn_health_check_total( $scan ) - $report_n );
	$passed   = function_exists( 'sn_health_passing_checks' )
		? count( (array) sn_health_passing_checks( $scan ) )
		: max( 0, $total - $flagged_n );

	// v9.53.0: WHICH checks failed, not just how many. sn_health_flagged_checks()
	// already returns them count-desc and already excludes the advisory tier, so
	// reuse its ranking rather than re-deriving one. Cap at 4: the card is a
	// glance, and "+N more" is honest about the tail without pretending the
	// widget is the Health tab.
	$flagged = array();
	foreach ( $flagged_map as $key => $check ) {
		$flagged[] = array(
			'key'   => (string) $key,
			'label' => (string) ( $check['label'] ?? $key ),
			'count' => (int) ( $check['count'] ?? 0 ),
		);
	}
	$shown = array_slice( $flagged, 0, 4 );

	return array(
		'passed'         => $passed,
		// Report-only checks, excluded from BOTH passed and total above.
		'report_only'    => $report_n,
		'total'          => $total,
		'all_passed'     => 0 === $flagged_n,
		// sn_health_run_scan() stores scanned_at as time() — an INT timestamp.
		'scanned_at'     => (int) ( $scan['scanned_at'] ?? 0 ),
		'flagged'        => $shown,
		'flagged_more'   => max( 0, count( $flagged ) - count( $shown ) ),
		// Advisories are reported SEPARATELY and never as faults: external_links
		// and link_opportunities carry findings by nature (see
		// sn_health_advisory_checks()), so folding them into the fault total
		// would render a healthy site as permanently alarming.
		'findings_total' => function_exists( 'sn_health_finding_total' ) ? (int) sn_health_finding_total( $scan ) : 0,
		'advisory_total' => function_exists( 'sn_health_advisory_total' ) ? (int) sn_health_advisory_total( $scan ) : 0,
	);
}

/**
 * The 14-day view series behind the Site Views + Pulse widgets.
 *
 * Transient-cached for 15 minutes: several widgets can mount in the same
 * shell and each calls this endpoint once, and the underlying rollup only
 * changes when the rollup cron runs.
 *
 * Fail-soft shape: an empty rollup (fresh install, table not yet created,
 * or simply no traffic in the window) returns days:[] / total:0 /
 * delta_pct:null — an honest empty state, never a warning or a hang.
 *
 * @return WP_REST_Response
 */
function snt_desktop_site_views_payload() {
	$today = substr( (string) current_time( 'mysql' ), 0, 10 );
	$from  = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );

	// Date-stamped key: a flat key cached at 23:58 would keep serving the
	// PREVIOUS day's 14-day window for up to 15 minutes after local midnight.
	// Stamping the local day makes the rollover exact and self-expiring.
	$cache_key = 'sn_desktop_site_views_' . $today;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return new WP_REST_Response( $cached, 200 );
	}

	// v9.53.0 — THE FIT WINDOW. The forecast engine suppresses below
	// SN_ANALYTICS_FORECAST_MIN_POINTS (21), so fitting on the 14-day DISPLAY
	// window would return null every single time and the forecast would never
	// once render. sn_analytics_signal_forecasts() already solves this the same
	// way: "trailing fit history ending $to, decoupled from the display range".
	// So fetch ONE longer series and slice it — the last 14 days draw the
	// sparkline, the whole 60 feed the fit. One query, not two.
	$fit_from = gmdate( 'Y-m-d', strtotime( $today . ' -59 days' ) );

	$fit_series = array();
	if ( function_exists( 'sn_analytics_daily_series' ) ) {
		$raw = sn_analytics_daily_series( $fit_from, $today, 'human', 'day' );
		if ( is_array( $raw ) ) {
			$fit_series = $raw;
		}
	}

	// The sparkline shows only the display window.
	$days = array();
	foreach ( $fit_series as $row ) {
		$day = (string) ( $row['day'] ?? '' );
		if ( '' !== $day && $day >= $from ) {
			$days[] = array(
				'date'  => $day,
				'views' => (int) ( $row['views'] ?? 0 ),
			);
		}
	}

	$total     = 0;
	$visits    = 0;
	$delta_pct = null;
	if ( function_exists( 'sn_analytics_range_totals' ) ) {
		$this_window = sn_analytics_range_totals( $from, $today, 'human' );
		$total       = (int) ( $this_window['views'] ?? 0 );
		$visits      = (int) ( $this_window['visits'] ?? 0 );

		// Prior 14-day window, for the week-over-week style delta.
		$prior_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$prior_from = gmdate( 'Y-m-d', strtotime( $from . ' -14 days' ) );
		$prior      = sn_analytics_range_totals( $prior_from, $prior_to, 'human' );
		$delta_pct  = snt_desktop_delta_pct( $total, (int) ( $prior['views'] ?? 0 ) );
	}

	// Bot share across the DISPLAY window, weighted by volume — a plain average
	// of daily bot_pct would let a 3-view day count as much as a 300-view one.
	// null (not 0) when there is nothing to divide by: "no data" is not "0% bots".
	$bot_pct = null;
	if ( function_exists( 'sn_analytics_class_series' ) ) {
		$classes = sn_analytics_class_series( $from, $today, 'day' );
		if ( is_array( $classes ) && $classes ) {
			$tot = 0;
			$bot = 0;
			foreach ( $classes as $row ) {
				$tot += (int) ( $row['total'] ?? 0 );
				$bot += (int) ( $row['bot'] ?? 0 );
			}
			if ( $tot > 0 ) {
				$bot_pct = (int) round( ( $bot / $tot ) * 100 );
			}
		}
	}

	$top_path  = null;
	$top_paths = array();
	if ( function_exists( 'sn_analytics_top_paths' ) ) {
		// Limit 3: the tile renders a Top pages list. top_path stays the
		// first row (same shape as before) so cached/older consumers keep
		// working; top_paths is additive.
		$top = sn_analytics_top_paths( $from, $today, 'human', 3 );
		if ( is_array( $top ) ) {
			foreach ( $top as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['path'] ) ) {
					continue;
				}
				$top_paths[] = array(
					'path'  => (string) $row['path'],
					'views' => (int) ( $row['views'] ?? 0 ),
				);
				if ( count( $top_paths ) >= 3 ) {
					break;
				}
			}
			if ( isset( $top_paths[0] ) ) {
				$top_path = $top_paths[0];
			}
		}
	}

	// v9.57.0: top sources — the one thing the desktop had NO surface for. The
	// retired sn-analytics-hud existed largely to show this; the rest of what it
	// showed (views/visits) this tile already covered, better. Three rows, not
	// five: a tile is a glance, and the full list is one click away on the
	// analytics page.
	//
	// Row shape is the accessor's OWN: `value` / `views` / `visits` / `hosts`
	// (inc/analytics-sources.php), sorted by views DESC. NOT `source` — that was
	// an invented key that cost a release (v9.56.0). We surface `value` + `visits`
	// to sit beside the tile's existing visits framing.
	$top_sources = array();
	if ( function_exists( 'sn_analytics_top_sources' ) ) {
		$srcs = sn_analytics_top_sources( $from, $today, 'human', 3 );
		if ( is_array( $srcs ) ) {
			foreach ( $srcs as $src ) {
				if ( ! is_array( $src ) || ! isset( $src['value'] ) ) {
					continue;
				}
				$top_sources[] = array(
					'value'  => (string) $src['value'],
					'visits' => (int) ( $src['visits'] ?? 0 ),
				);
			}
		}
	}

	// The forecast, or nothing. sn_analytics_forecast_of() already encodes the
	// honesty gates — under 21 points → null, zero median level → null, and
	// `confidence` is the backtest's MEASURED interval coverage, not a vibe.
	// We pass its verdict through UNCHANGED and never synthesise a fallback:
	// a point without an interval, or a number invented from thin history, is
	// the dishonest version of this feature.
	$forecast = null;
	if ( function_exists( 'sn_analytics_forecast_of' ) && $fit_series ) {
		$signal = sn_analytics_forecast_of( 'site_views', 'Site views', $fit_series, $fit_from, $today );
		if ( is_array( $signal ) && isset( $signal['value'], $signal['interval']['low'], $signal['interval']['high'] ) ) {
			$forecast = array(
				'value'      => $signal['value'],
				'interval'   => array(
					'low'  => $signal['interval']['low'],
					'high' => $signal['interval']['high'],
				),
				'confidence' => (string) ( $signal['confidence'] ?? 'low' ),
				'direction'  => (string) ( $signal['direction'] ?? 'flat' ),
				// Mirrors the engine's SN_ANALYTICS_FORECAST_HORIZON default, which
				// is what forecast_of() used above (we pass no $opts override).
				'horizon'    => 7,
			);
		}
	}

	$payload = array(
		'days'        => $days,
		'total'       => $total,
		'visits'      => $visits,
		'delta_pct'   => $delta_pct,
		'bot_pct'     => $bot_pct,
		'top_path'    => $top_path,
		'top_paths'   => $top_paths,
		'top_sources' => $top_sources,
		'forecast'    => $forecast,
	);

	set_transient( $cache_key, $payload, 15 * MINUTE_IN_SECONDS );
	return new WP_REST_Response( $payload, 200 );
}

add_action( 'rest_api_init', function() {
	register_rest_route( 'signal-noise/v1', '/desktop/site-views', array(
		'methods'             => 'GET',
		'callback'            => 'snt_desktop_site_views_payload',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );

	register_rest_route( 'signal-noise/v1', '/desktop/machine-readers', array(
		'methods'             => 'GET',
		'callback'            => 'snt_desktop_machine_readers_payload',
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
} );

/**
 * Living-tree traffic.
 *
 * desktop-mode's wallpaper renders a tree whose wind responds to site
 * traffic, and its docs invite analytics plugins to supply the real
 * number via this filter. Feed it our 14-day first-party human view
 * total so the desktop breathes with the actual site.
 *
 * Cast to int: desktop-mode types the filtered value as int.
 *
 * Post-#475 OpenStation renames this to `openstation_living_tree_traffic`
 * (includes/living-tree/helpers.php:91) — dual-registered via
 * snt_os_compat_add_filter(), idempotent (pure function of the current
 * analytics totals), no double-fire guard needed.
 */
snt_os_compat_add_filter( 'desktop_mode_living_tree_traffic', 'openstation_living_tree_traffic', function( $views ) {
	if ( ! function_exists( 'sn_analytics_range_totals' ) ) {
		return (int) $views;
	}
	$today  = substr( (string) current_time( 'mysql' ), 0, 10 );
	$from   = gmdate( 'Y-m-d', strtotime( $today . ' -13 days' ) );
	$totals = sn_analytics_range_totals( $from, $today, 'human' );
	return (int) ( $totals['views'] ?? $views );
} );

/**
 * Payload for the SN Machine Readers tile (v10.1.0).
 *
 * Shapes the same aggregates the Machine Readers tab renders into the small
 * set a glance needs: total, the top three families, declared AI-training
 * reads (and how many of those touched the rights files), plus the sensor's
 * version and crawler-list verdict so the reader knows whether to trust the
 * numbers. A failed/unconfigured read returns ok:false with the reason — the
 * tile says so rather than painting a zero, because "no data" is not "no
 * crawlers".
 *
 * @return array
 */
function snt_desktop_machine_readers_payload() {
	// v10.2.0: delegates to the ONE builder (inc/machine-readers-api.php) that
	// the get-machine-readers-summary ability also calls, so the tile and the
	// ability can never drift.
	if ( ! function_exists( 'snt_mr_summary_payload' ) ) {
		return array( 'ok' => false, 'error' => 'unavailable', 'days' => 30 );
	}
	return snt_mr_summary_payload( 30 );
}
