<?php
/**
 * Signal & Noise — first-party analytics dashboard widgets.
 *
 * Registers two WP dashboard-home widgets that read the first-party analytics
 * rollups via the sn_analytics_* accessors (the Plausible retirement completed in
 * the v6.0.0 / v6.20.0 arc; "Plausible" survives only in the widget IDs, see below):
 *
 *   - "Analytics — Overview"    (sn_aw_overview)    — visitors right now (last 5 min)
 *       plus the last-7-days KPIs (Views, Visits, Avg scroll %, Avg time, Engaged %,
 *       Filtered), each trended KPI carrying a week-over-week delta badge (v6.38.0).
 *   - "Analytics — Top content" (sn_aw_top_content) — top 7 pages + top 7 sources, 7d.
 *
 * History: four discrete widgets were consolidated to these two in v6.19.2 to cut
 * dashboard clutter. The widget IDs are intentionally kept as sn_plausible_snapshot
 * / sn_plausible_pages — NOT renamed — so existing per-user dashboard layout and
 * visibility meta survive; the two retired IDs (sn_plausible_realtime /
 * sn_plausible_sources) orphan harmlessly (WP simply stops rendering them).
 *
 * Requires SN_CF_ANALYTICS_TOKEN + SN_CF_ACCOUNT_ID in wp-config.php; renders a
 * config empty-state otherwise.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_dashboard_setup', function() {
	if ( ! current_user_can( 'view_stats' ) && ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Two consolidated widgets; IDs kept as sn_plausible_* to preserve per-user
	// dashboard layout/visibility meta (full history + retired IDs in the file docblock).
	wp_add_dashboard_widget( 'sn_plausible_snapshot', 'Analytics — Overview',    'sn_aw_overview' );
	wp_add_dashboard_widget( 'sn_plausible_pages',    'Analytics — Top content', 'sn_aw_top_content' );
} );

/**
 * Enqueue the dashboard-widget stylesheet, scoped to the Dashboard home screen.
 *
 * Mirrors the analytics-admin.css enqueue (inc/admin-menu.php): an external,
 * SNT_VERSION-cache-busted stylesheet loaded in <head>. Replaces the former
 * inline <style> echoed mid-body by sn_aw_styles(), which could render the four
 * widgets UNSTYLED on the live page — a body-injected <style> is subject to
 * edge/cache HTML rewriting and a strict `style-src 'self'` CSP, and the old
 * once-guard was fragile (the v6.5.0-class bug fixed for the analytics dashboard
 * in v6.5.1; same fix applied here). Gated to index.php because the .sn-aw-*
 * rules only appear in these dashboard-home widgets; loading them on any other
 * admin screen would be dead weight.
 *
 * @param string $hook Current admin page hook suffix.
 */
function sn_aw_enqueue_styles( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'sn-analytics-widget',
		SNT_URL . 'assets/analytics/analytics-widget.css',
		array(),
		SNT_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'sn_aw_enqueue_styles' );

/**
 * Shared analytics not-configured copy. Renders inside the widget body
 * with a single line + a code snippet showing the wp-config constants to add.
 * Extracted in v1.14.0 (was duplicated across snapshot + realtime widgets).
 */
function sn_aw_not_configured() {
	echo '<p class="sn-aw-err">Analytics not configured. Deploy the edge worker, then add the read credentials to <code>wp-config.php</code>:</p>';
	echo '<pre class="sn-aw-config-snippet">define( \'SN_CF_ANALYTICS_TOKEN\', \'…\' );' . "\n" . 'define( \'SN_CF_ACCOUNT_ID\', \'…\' );</pre>';
}

/**
 * Inclusive last-7-days UTC window for the widgets: [from, to] YYYY-MM-DD.
 */
function sn_aw_window7() {
	$now = time();
	return array( gmdate( 'Y-m-d', $now - 6 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now ) );
}

/**
 * Preamble: gate on analytics config. Returns true when data may be read, false
 * (after printing the config copy) otherwise. Widget styling is enqueued
 * separately via sn_aw_enqueue_styles() (admin_enqueue_scripts), not printed here.
 */
function sn_aw_preamble() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		sn_aw_not_configured();
		return false;
	}
	return true;
}

// v6.19.2: the four functions below are now SUB-RENDERERS composed by the two
// registered widgets (sn_aw_overview / sn_aw_top_content). $standalone = true keeps
// their original self-contained output (footer link, and the realtime label) so their
// behaviour + tests are unchanged; the composed widgets pass false to drop the inner
// footer/label and emit a single shared footer.

function sn_aw_snapshot( $standalone = true ) {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	$t = sn_analytics_range_totals( $from, $to, 'human' );
	// Week-over-week deltas for the four trended KPIs, gated on the derived accessor
	// existing (mirrors the engaged/class_totals guards below). When the derived
	// module is absent the KPIs render exactly as before — no badge.
	$d = function_exists( 'sn_analytics_period_deltas' ) ? sn_analytics_period_deltas( $from, $to, 'human' ) : array();
	echo '<div class="sn-aw-grid">';
	sn_aw_stat( 'Views',      $t['views'] ?? null,  $d['views'] ?? null );
	sn_aw_stat( 'Visits',     $t['visits'] ?? null, $d['visits'] ?? null );
	sn_aw_stat( 'Avg scroll', isset( $t['scroll_avg'] ) ? (int) round( (float) $t['scroll_avg'] ) . '%' : null, $d['scroll_avg'] ?? null );
	sn_aw_stat( 'Avg time',   isset( $t['time_avg'] ) ? sn_aw_duration( (int) round( (float) $t['time_avg'] / 1000 ) ) : null, $d['time_avg'] ?? null );
	// Engaged = signal: share of human pageviews that crossed the engaged-time threshold
	// (int 0–100, or null when no time-distribution data exists yet → renders em-dash).
	$eng   = function_exists( 'sn_analytics_engaged_rate' ) ? sn_analytics_engaged_rate( $from, $to, 'human' ) : null;
	$eng_d = function_exists( 'sn_analytics_engaged_rate_delta' ) ? sn_analytics_engaged_rate_delta( $from, $to, 'human' ) : null;
	sn_aw_stat( 'Engaged', null === $eng ? null : $eng . '%', $eng_d );
	// Filtered = noise: suspect + bot pageviews the edge classifier caught and excluded.
	// Empty class_totals (no rollups in window) → null → em-dash; a measured 0 is honest
	// ("classified traffic, zero noise") and intentionally renders 0, not em-dash.
	$ct       = function_exists( 'sn_analytics_class_totals' ) ? sn_analytics_class_totals( $from, $to ) : array();
	$filtered = empty( $ct ) ? null : (int) ( ( $ct['suspect']['views'] ?? 0 ) + ( $ct['bot']['views'] ?? 0 ) );
	// v6.44.0: Filtered was the lone KPI without a week-over-week badge. Compute the
	// prior-window noise total via the same accessor (no new data layer) and shape the
	// delta like the others. Guarded so an absent module/empty prior renders as before.
	$fd = null;
	if ( null !== $filtered && function_exists( 'sn_analytics_prior_window' ) && function_exists( 'sn_analytics_delta' ) ) {
		list( $pf, $pt ) = sn_analytics_prior_window( $from, $to );
		$ctp = sn_analytics_class_totals( $pf, $pt );
		if ( ! empty( $ctp ) ) {
			$fp = (int) ( ( $ctp['suspect']['views'] ?? 0 ) + ( $ctp['bot']['views'] ?? 0 ) );
			$fd = sn_analytics_delta( $filtered, $fp );
		}
	}
	sn_aw_stat( 'Filtered', $filtered, $fd );
	echo '</div>';
	// v6.44.0: a 7-day views trend sparkline under the KPI strip — the same series
	// accessor + shared SVG primitive used on the Analytics page (no new data layer).
	// Guarded: when either is absent the widget renders exactly as before.
	if ( function_exists( 'sn_analytics_daily_series' ) && function_exists( 'snt_analytics_sparkline' ) ) {
		$series = sn_analytics_daily_series( $from, $to, 'human', 'day' );
		if ( ! empty( $series ) ) {
			echo '<div class="sn-aw-trend">';
			// snt_analytics_sparkline returns pre-escaped SVG (coords esc_attr'd, chrome static).
			echo snt_analytics_sparkline( $series ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped SVG from the shared helper.
			echo '<span class="sn-aw-trend-l">7-day views</span>';
			echo '</div>';
		}
	}
	if ( $standalone ) {
		sn_aw_footer();
	}
}

function sn_aw_realtime( $standalone = true ) {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		sn_aw_not_configured();
		return;
	}
	$n = sn_analytics_realtime( 'human' );
	if ( $standalone ) {
		echo '<div class="sn-aw-big-l">Visitors right now</div>';
	}
	echo '<div class="sn-aw-big">' . esc_html( null === $n ? '—' : number_format_i18n( (int) $n ) ) . '</div>';
	if ( $standalone ) {
		echo '<p class="sn-aw-foot">Last 5 min · refreshes every 30 s · <a href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">Open Analytics →</a></p>';
	} else {
		echo '<p class="sn-aw-foot">Visitors · last 5 min · refreshes every 30 s</p>';
	}
}

/**
 * "Right now" as a two-up micro-stat: visitors now (last 5 min) beside views
 * today-so-far. Replaces the old single big number + "N views today so far"
 * sentence — two explicitly-labelled windows in the widget's number-over-label
 * vocabulary, no taller than the number it replaces. Config is already gated by
 * sn_aw_preamble() at the top of sn_aw_overview(), so there is no re-check here.
 * Today prefers the realtime tier's site-timezone "views today so far"; the UTC
 * daily rollup's last bucket is only a cold-cache fallback (it rolls at UTC
 * midnight — 8pm ET — which reset this figure mid-evening). The cell is omitted
 * when neither source has data, so the "now" figure never renders unpaired.
 */
function sn_aw_now_today() {
	$now   = function_exists( 'sn_analytics_realtime' ) ? sn_analytics_realtime( 'human' ) : null;
	$today = function_exists( 'sn_analytics_views_today' ) ? sn_analytics_views_today() : null;
	if ( null === $today && function_exists( 'sn_analytics_daily_series' ) ) {
		list( $t_from, $t_to ) = sn_aw_window7();
		$t_series = sn_analytics_daily_series( $t_from, $t_to, 'human', 'day' );
		$t_last   = ( is_array( $t_series ) && ! empty( $t_series ) ) ? end( $t_series ) : null;
		if ( is_array( $t_last ) && isset( $t_last['views'] ) ) {
			$today = (int) $t_last['views'];
		}
	}
	echo '<div class="sn-aw-nowtoday">';
	echo '<div class="sn-aw-nt"><span class="sn-aw-nt-v">' . esc_html( null === $now ? '—' : number_format_i18n( (int) $now ) ) . '</span>'
		. '<span class="sn-aw-nt-k">' . esc_html__( 'visitors now', 'signal-and-noise-tools' ) . '</span></div>';
	if ( null !== $today ) {
		echo '<div class="sn-aw-nt"><span class="sn-aw-nt-v">' . esc_html( number_format_i18n( $today ) ) . '</span>'
			. '<span class="sn-aw-nt-k">' . esc_html__( 'views today', 'signal-and-noise-tools' ) . '</span></div>';
	}
	echo '</div>';
	echo '<p class="sn-aw-foot">Last 5 min · refreshes every 30 s</p>';
}

function sn_aw_pages( $standalone = true ) {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	$rows = array_map( function ( $r ) {
		return array( 'k' => $r['path'], 'v' => $r['views'] );
	}, sn_analytics_top_paths( $from, $to, 'human', 7 ) );
	sn_aw_kv_list( $rows, __( 'No page views in the last 7 days.', 'signal-and-noise-tools' ) );
	if ( $standalone ) {
		sn_aw_footer();
	}
}

function sn_aw_sources( $standalone = true ) {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	// Brand-folded sources (self-referrals + www + multi-host providers collapsed).
	// A source with member hosts deep-links into the full Analytics page drilled to
	// that source ("Top pages where source = X"); (direct) has no hosts → plain text.
	$rows = array_map( function ( $r ) {
		$href = ! empty( $r['hosts'] )
			? add_query_arg(
				array( 'page' => 'sn-analytics', 'sn_view' => 'content', 'sn_drill' => 'referrer:' . $r['value'] ),
				admin_url( 'index.php' )
			)
			: '';
		return array( 'k' => $r['value'], 'v' => $r['views'], 'href' => $href );
	}, sn_analytics_top_sources( $from, $to, 'human', 7 ) );
	sn_aw_kv_list( $rows, __( 'No referrers in the last 7 days.', 'signal-and-noise-tools' ) );
	if ( $standalone ) {
		sn_aw_footer();
	}
}

/**
 * v9.31.0 (maturity I2): compact insight header for the Overview widget — the
 * narrator's one-liner + the single highest-severity signal chip. Pure reuse of
 * the I1 engine/narrator/chip; every dependency is function_exists-guarded so the
 * widget renders verbatim as before when the insights module is absent. Signals
 * empty → renders nothing (the KPIs lead, no filler). The deterministic floor is
 * a true one-liner (the top signal's plain_label), NOT the 4-item digest list.
 */
function sn_aw_insight_header() {
	if ( ! function_exists( 'sn_analytics_signals' ) || ! function_exists( 'snt_analytics_render_signal_chip' ) ) {
		return;
	}
	list( $from, $to ) = sn_aw_window7();
	$opts    = function_exists( 'sn_analytics_signal_opts' ) ? sn_analytics_signal_opts() : array();
	$signals = sn_analytics_signals( $from, $to, 'human', $opts );
	if ( ! is_array( $signals ) || empty( $signals ) ) {
		return;
	}
	$top  = $signals[0]; // producer sorts severity-desc; the widget shows only the headline signal.
	$narr = function_exists( 'sn_analytics_narrate' )
		? sn_analytics_narrate( array(), array( $top ) )
		: array( 'narrative' => '', 'source' => 'fallback' );
	$use_ai = is_array( $narr )
		&& 'fallback' !== (string) ( $narr['source'] ?? 'fallback' )
		&& '' !== trim( (string) ( $narr['narrative'] ?? '' ) );
	echo '<div class="sn-aw-subhead">Insight</div>';
	echo '<div class="sn-aw-insight" data-source="' . esc_attr( $use_ai ? (string) $narr['source'] : 'fallback' ) . '">';
	if ( $use_ai ) {
		echo wp_kses_post( (string) $narr['narrative'] );
	} else {
		echo '<p class="sn-aw-insight-line">' . esc_html( (string) ( $top['plain_label'] ?? '' ) ) . '</p>';
	}
	echo snt_analytics_render_signal_chip( $top ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chip is assembled from esc_html/esc_attr fragments in the helper.
	echo '</div>';
}

/**
 * Widget 1 (v6.19.2): "Analytics — Overview" — Right now + last-7-days KPIs.
 * Composes the realtime + snapshot sub-renderers under one config gate and one
 * shared footer (each section gets a .sn-aw-subhead label).
 */
function sn_aw_overview() {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	// v9.31.0 (maturity I2): lead with insight — compact digest + top-signal chip
	// above the KPIs. Hidden when no signals; widget verbatim when module absent.
	sn_aw_insight_header();
	echo '<div class="sn-aw-subhead">Right now</div>';
	sn_aw_now_today();
	echo '<div class="sn-aw-subhead">Last 7 days</div>';
	sn_aw_snapshot( false );
	// v8.5.0 pairing: the redesign's Movers answer ("which posts moved"),
	// compact — top 3 by views delta vs the prior 7 days, shared 15-min cache.
	if ( function_exists( 'sn_analytics_movers' ) ) {
		list( $m_from, $m_to ) = sn_aw_window7();
		$movers = sn_analytics_movers( $m_from, $m_to, 'human', 3 );
		if ( ! empty( $movers ) ) {
			echo '<div class="sn-aw-subhead">Movers</div>';
			echo '<ul class="sn-aw-list sn-aw-movers">';
			foreach ( $movers as $m ) {
				$delta = (int) ( $m['delta'] ?? 0 );
				$cls   = $delta > 0 ? 'sn-aw-mv-up' : 'sn-aw-mv-down';
				echo '<li><span class="k">' . esc_html( (string) ( $m['path'] ?? '' ) ) . '</span>'
					. '<span class="v ' . esc_attr( $cls ) . '">' . esc_html( ( $delta > 0 ? '+' : '' ) . $delta ) . '</span></li>';
			}
			echo '</ul>';
		}
	}
	sn_aw_footer();
}

/**
 * Widget 2 (v6.19.2): "Analytics — Top content" — top pages + top sources (7d).
 * Composes the pages + sources sub-renderers under one config gate + shared footer.
 */
function sn_aw_top_content() {
	if ( ! sn_aw_preamble() ) {
		return;
	}
	echo '<div class="sn-aw-subhead">Top pages</div>';
	sn_aw_pages( false );
	echo '<div class="sn-aw-subhead">Top sources</div>';
	sn_aw_sources( false );
	sn_aw_footer();
}

/**
 * Shared key/value list for a [{k,v}] set of rows. A row may carry an optional
 * 'href' — when present and non-empty the key renders as a link (used by Top
 * sources to deep-link into the Analytics drill-down); rows without it stay plain
 * text (Top pages), so the signature is backward-compatible.
 *
 * @param array  $rows  [{k, v, href?}]
 * @param string $empty
 */
function sn_aw_kv_list( $rows, $empty ) {
	if ( empty( $rows ) ) {
		echo '<p class="sn-aw-empty">' . esc_html( $empty ) . '</p>';
		return;
	}
	// v8.5.0 pairing: proportional share bars behind each row (vs the list
	// max) — the glanceable proportion the raw counts don't give. Zero new
	// data; the CSS paints via the --sn-aw-share custom property.
	$max = 0;
	foreach ( $rows as $row ) {
		$max = max( $max, (int) ( $row['v'] ?? 0 ) );
	}
	echo '<ul class="sn-aw-list sn-aw-list--bars">';
	foreach ( $rows as $row ) {
		$share = $max > 0 ? (int) round( (int) ( $row['v'] ?? 0 ) / $max * 100 ) : 0;
		echo '<li style="--sn-aw-share:' . esc_attr( (string) $share ) . '%"><span class="k">';
		if ( ! empty( $row['href'] ) ) {
			echo '<a href="' . esc_url( (string) $row['href'] ) . '">' . esc_html( (string) $row['k'] ) . '</a>';
		} else {
			echo esc_html( (string) $row['k'] );
		}
		echo '</span><span class="v">' . esc_html( number_format_i18n( (int) $row['v'] ) ) . '</span></li>';
	}
	echo '</ul>';
}

/**
 * Footer linking to the analytics dashboard — the native WP Dashboard → Analytics
 * page (v5.4.0; was the plugin Dashboard tab in v5.3.0).
 */
function sn_aw_footer() {
	echo '<p class="sn-aw-foot">7d · first-party · <a href="' . esc_url( admin_url( 'index.php?page=sn-analytics' ) ) . '">Open Analytics →</a></p>';
}

function sn_aw_stat( $label, $value, $delta = null ) {
	$display = ( null === $value || '' === $value )
		? '—'
		: ( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value );
	echo '<div class="sn-aw-stat"><div class="sn-aw-stat-n">' . esc_html( $display ) . '</div><div class="sn-aw-stat-l">' . esc_html( $label );
	sn_aw_delta_badge( $delta );
	echo '</div></div>';
}

/**
 * Period-over-period delta badge (▲/▼/■ + signed pct) appended inside a stat's
 * label cell. Mirrors the Analytics page badge (snt_analytics_render_delta_badge)
 * but emits the widget-scoped .sn-aw-delta class so it is styled by the
 * already-enqueued analytics-widget.css, not the page stylesheet. No-op for a
 * null/invalid delta, so a KPI with no comparison renders exactly as before. pct
 * null (prior window empty) → "new" (up) or em-dash, matching the page semantics.
 *
 * @param array|null $delta {pct:?int, dir:string} where dir ∈ up|down|flat.
 */
function sn_aw_delta_badge( $delta ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	// v8.5.0: prior-period absolute in a tooltip (page-badge parity);
	// escaping at the point of output.
	$prev_title = '';
	if ( isset( $delta['previous'] ) && is_numeric( $delta['previous'] ) ) {
		$prev       = (float) $delta['previous'];
		$prev_title = 'previous period: ' . number_format_i18n( $prev, ( $prev == (int) $prev ) ? 0 : 1 );
	}
	echo ' <span class="sn-aw-delta sn-aw-delta--' . esc_attr( $dir ) . '"'
		. ( '' !== $prev_title ? ' title="' . esc_attr( $prev_title ) . '"' : '' )
		. '>' . esc_html( $arrow . ' ' . $text ) . '</span>';
}

function sn_aw_duration( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds < 60 ) {
		return $seconds . 's';
	}
	$m = (int) floor( $seconds / 60 );
	$s = $seconds % 60;
	return $m . 'm ' . str_pad( (string) $s, 2, '0', STR_PAD_LEFT ) . 's';
}
