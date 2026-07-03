<?php
/**
 * Signal & Noise Tools — Analytics header region (v8.5.0).
 *
 * The persistent frame every shared-chrome view gets: controls, class strip,
 * then the 2/3 + 1/3 header grid — full Overview (KPIs + trend) beside the
 * rail (uptime strip + movers) — then the collapsed uptime detail panel.
 * Owner layout decision 2026-07-03: "B, with the full overview like in A."
 * The snt_analytics_after_overview seam KEEPS FIRING (after the region) —
 * v8.5.0 moves the uptime widget off it but removes nothing.
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the shared header region. Caller (the dashboard dispatcher) has
 * already resolved every parameter and gated on ! $owns_chrome.
 *
 * @param string $view        Active view slug.
 * @param string $range       Range token ('7' | '30' | … | 'all').
 * @param string $class       Traffic class.
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $granularity 'day' | 'week' | 'month'.
 * @return array Range totals — the dashboard's tail empty-hint reads them.
 */
function snt_analytics_render_header_region( $view, $range, $class, $from, $to, $granularity ) {
	$totals       = sn_analytics_range_totals( $from, $to, $class );
	$class_totals = sn_analytics_class_totals( $from, $to );
	$now          = sn_analytics_realtime( $class );
	$series       = sn_analytics_daily_series( $from, $to, $class, $granularity );
	$deltas       = ( 'all' === $range ) ? array() : sn_analytics_period_deltas( $from, $to, $class );
	$engaged      = ( 'all' === $range )
		? array( 'current' => sn_analytics_engaged_rate( $from, $to, $class ) )
		: sn_analytics_engaged_rate_delta( $from, $to, $class );

	snt_analytics_render_controls( $range, $class, $from, $to );
	snt_analytics_render_separation( $class_totals, $class );

	echo '<div class="sn-an-header-grid">';
	echo '<div class="sn-an-header-main">';
	// The fused Overview panel (KPI strip + trend chart footer) — the v6.5.2
	// contract, now emitted through the primitive.
	snt_an_panel_open( __( 'Overview', 'signal-and-noise-tools' ), array(
		'panel_class'  => 'sn-overview',
		'inside_class' => 'inside inside-flush sn-overview-inside',
	) );
	snt_analytics_render_cards( $now, $totals, $deltas, $engaged );
	snt_analytics_render_trend( $series, $granularity );
	snt_an_panel_close();
	echo '</div>';
	echo '<div class="sn-an-header-rail">';
	if ( function_exists( 'sn_uptime_status_rail_strip' ) ) {
		echo sn_uptime_status_rail_strip(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
	}
	snt_analytics_render_movers_tile( $from, $to, $class );
	echo '</div>';
	echo '</div>';

	if ( function_exists( 'sn_uptime_status_detail_panel' ) ) {
		echo sn_uptime_status_detail_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
	}

	// v8.4.2 composition seam — kept firing (v8.5.0 moved the uptime widget
	// into the rail but removed nothing; the hook remains an extension point).
	do_action( 'snt_analytics_after_overview', $view );

	return $totals;
}
