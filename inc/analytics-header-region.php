<?php
/**
 * Signal & Noise Tools — Analytics header region (v8.5.0; uptime merged to
 * one surface at v9.37.0/D1).
 *
 * The persistent frame every shared-chrome view gets: controls, class strip,
 * then the 2/3 + 1/3 header grid — full Overview (KPIs + trend) beside the
 * rail (the Uptime card + movers). The Uptime card is the ONE uptime surface
 * (a native <details> — status tier in the summary, detail tier lazy-loaded
 * on first expand); the standalone full-width "Uptime detail" postbox that
 * used to render below the grid is retired (v9.37.0). Owner layout decision
 * 2026-07-03: "B, with the full overview like in A." The
 * snt_analytics_after_overview seam KEEPS FIRING (after the region) — v8.5.0
 * moved the uptime widget off it but removed nothing.
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
 * @param string $compare     Comparison mode: 'prev' | 'yoy' | 'off' (default).
 * @return array Range totals — the dashboard's tail empty-hint reads them.
 */
function snt_analytics_render_header_region( $view, $range, $class, $from, $to, $granularity, $compare = 'off' ) {
	$totals       = sn_analytics_range_totals( $from, $to, $class );
	$class_totals = sn_analytics_class_totals( $from, $to );
	$now          = sn_analytics_realtime( $class );
	$series       = sn_analytics_daily_series( $from, $to, $class, $granularity );
	// v9.34.0 (maturity I5): first-class comparison — display-only; the predictive
	// baseline is $to-anchored and never reads this window.
	$cseries = array();
	$ctotals = array();
	// v9.38.0 (D2): ONE comparison frame. The basis window is resolved here,
	// once, and threaded into every delta surface — badges, engaged, the Read
	// annotation's inputs, and Movers. Window and label derive from the SAME
	// $compare so a mode/window mismatch cannot exist at this (the only) call
	// site. 'off' keeps the quiet prev basis (badges stay glanceable, no
	// overlay/note); 'yoy' switches EVERYTHING.
	$basis       = ( 'yoy' === (string) $compare ) ? 'yoy' : 'prev';
	$cwin_basis  = ( 'all' === (string) $range || ! function_exists( 'snt_analytics_compare_window' ) )
		? null
		: snt_analytics_compare_window( $from, $to, $basis );
	$basis_label = ( 'yoy' === $basis )
		? __( 'same period last year', 'signal-and-noise-tools' )
		: __( 'previous period', 'signal-and-noise-tools' );
	$cwin        = $cwin_basis ?? array( '', '' );
	if ( 'off' !== (string) $compare && 'all' !== (string) $range && function_exists( 'snt_analytics_compare_window' ) ) {
		$cseries = sn_analytics_daily_series( $cwin[0], $cwin[1], $class, $granularity );
		$ctotals = sn_analytics_range_totals( $cwin[0], $cwin[1], $class );
	}
	$deltas       = ( 'all' === $range ) ? array() : sn_analytics_period_deltas( $from, $to, $class, $cwin_basis );
	$engaged      = ( 'all' === $range )
		? array( 'current' => sn_analytics_engaged_rate( $from, $to, $class ) )
		: sn_analytics_engaged_rate_delta( $from, $to, $class, $cwin_basis );

	snt_analytics_render_controls( $range, $class, $from, $to, $compare, $class_totals );

	echo '<div class="sn-an-header-grid">';
	echo '<div class="sn-an-header-main">';
	// The fused Overview panel (KPI strip + trend chart footer) — the v6.5.2
	// contract, now emitted through the primitive.
	snt_an_panel_open( __( 'Overview', 'signal-and-noise-tools' ), array(
		'panel_class'  => 'sn-overview',
		'inside_class' => 'inside inside-flush sn-overview-inside',
		'header_meta'  => function_exists( 'snt_analytics_tier_badge' ) ? snt_analytics_tier_badge( 'descriptive' ) : '',
	) );
	snt_an_annotation( sn_annotation_overview( $deltas, $engaged ) );
	snt_analytics_render_cards( $now, $totals, $deltas, $engaged, $basis_label );
	snt_analytics_render_trend( $series, $granularity, $cseries );
	if ( function_exists( 'snt_analytics_render_compare_note' ) ) {
		snt_analytics_render_compare_note( $compare, $totals, $ctotals, $cwin[0], $cwin[1] );
	}
	// v9.37.0 (D1): the pulse micro-stats fold into the Overview as a hairline
	// footer — Content view only (the other views keep a leaner Overview).
	if ( 'content' === $view ) {
		snt_analytics_render_pulse_strip( $from, $to, $class, $series, $granularity );
	}
	snt_an_panel_close();
	echo '</div>';
	echo '<div class="sn-an-header-rail">';
	if ( function_exists( 'sn_uptime_status_rail_strip' ) ) {
		echo sn_uptime_status_rail_strip(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes at build.
	}
	snt_analytics_render_movers_tile( $from, $to, $class, $cwin_basis, $basis );
	echo '</div>';
	echo '</div>';

	// v8.4.2 composition seam — kept firing (v8.5.0 moved the uptime widget
	// into the rail but removed nothing; the hook remains an extension point).
	do_action( 'snt_analytics_after_overview', $view );

	return $totals;
}

/**
 * The Pulse strip (v8.5.0, the data-obsessed pass; folded into the Overview
 * panel's footer at v9.37.0/D1): a hairline row packing four glanceable
 * micro-stats — scroll-depth bands, time-on-page bands, bot share (+
 * microspark), and today-so-far. Rendered ONLY on the Content view, as the
 * last row inside the Overview panel's .inside (before panel close) — the
 * other shared-chrome views keep a leaner Overview with no footer.
 * DURABLE READS ONLY (bucket + rollup tables; never AE, never remote) so the
 * landing keeps its never-blocks contract. Cells render only when their data
 * exists; the whole strip stays silent on a dataless install.
 *
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param array  $series      The already-fetched daily series (today cell reads
 *                            its last bucket — zero extra queries).
 * @param string $granularity 'day' | 'week' | 'month' (today renders on 'day').
 */
function snt_analytics_render_pulse_strip( $from, $to, $class, $series, $granularity ) {
	$cells = array();

	// Scroll + time band micro-bars: proportional segments, dominant band named.
	foreach ( array( 'scroll' => 'Scroll', 'time' => 'Read time' ) as $metric => $label ) {
		$rows  = function_exists( 'sn_analytics_distribution' ) ? sn_analytics_distribution( $metric, $from, $to, $class ) : array();
		$total = 0;
		$top   = null;
		foreach ( (array) $rows as $r ) {
			$total += (int) ( $r['views'] ?? 0 );
			if ( null === $top || (int) ( $r['views'] ?? 0 ) > (int) $top['views'] ) {
				$top = $r;
			}
		}
		if ( $total <= 0 ) {
			continue;
		}
		$bar = '<span class="sn-an-pulse-bar" aria-hidden="true">';
		$n   = count( $rows );
		foreach ( array_values( $rows ) as $i => $r ) {
			$pct   = (int) round( (int) ( $r['views'] ?? 0 ) / $total * 100 );
			$alpha = 0.25 + ( $n > 1 ? ( $i / ( $n - 1 ) ) * 0.75 : 0.75 );
			$bar  .= '<span style="width:' . esc_attr( max( 1, $pct ) ) . '%;background:rgba(34,113,177,' . esc_attr( round( $alpha, 2 ) ) . ')" title="' . esc_attr( (string) ( $r['label'] ?? '' ) . ': ' . number_format_i18n( (int) ( $r['views'] ?? 0 ) ) ) . '"></span>';
		}
		$bar     .= '</span>';
		$top_pct  = (int) round( (int) $top['views'] / $total * 100 );
		$cells[] = '<span class="sn-an-pulse-cell"><span class="sn-an-pulse-k">' . esc_html( $label ) . '</span>'
			. $bar
			. '<span class="sn-an-pulse-v">' . esc_html( (string) ( $top['label'] ?? '' ) . ' · ' . $top_pct . '%' ) . '</span></span>';
	}

	// Bot share: window average + a microspark of the per-bucket trend —
	// smoothed via the shared bézier helper so even the 72px spark speaks the
	// house chart vocabulary (no angular polylines anywhere on the page).
	$class_series = function_exists( 'sn_analytics_class_series' ) ? sn_analytics_class_series( $from, $to, $granularity ) : array();
	if ( ! empty( $class_series ) && function_exists( 'snt_analytics_smooth_path' ) ) {
		$px   = array();
		$py   = array();
		$sum  = 0;
		$n    = count( $class_series );
		$step = ( $n > 1 ) ? 72.0 / ( $n - 1 ) : 0.0;
		foreach ( array_values( $class_series ) as $i => $r ) {
			$pct  = max( 0, min( 100, (int) ( $r['bot_pct'] ?? 0 ) ) );
			$sum += $pct;
			$px[] = round( $i * $step, 1 );
			$py[] = round( 16 - ( $pct / 100 ) * 14, 1 );
		}
		if ( count( $px ) < 2 ) {
			$px = array( 0.0, 72.0 );
			$py = array( $py[0], $py[0] );
		}
		$avg     = (int) round( $sum / max( 1, $n ) );
		$cells[] = '<span class="sn-an-pulse-cell"><span class="sn-an-pulse-k">' . esc_html__( 'Bot share', 'signal-and-noise-tools' ) . '</span>'
			. '<svg class="sn-an-pulse-spark" viewBox="0 0 72 18" preserveAspectRatio="none" aria-hidden="true"><path d="' . esc_attr( snt_analytics_smooth_path( $px, $py, 2.0, 16.0 ) ) . '" fill="none" stroke="#d63638" stroke-width="1.5" vector-effect="non-scaling-stroke"/></svg>'
			. '<span class="sn-an-pulse-v">' . esc_html( $avg . '%' ) . '</span></span>';
	}

	// Today so far — the already-fetched series' last bucket (day granularity). Only
	// label it "today" when that bucket's day IS today (UTC, matching the durable
	// series' boundary). Before today's bucket is rolled, end($series) is a PRIOR
	// day; showing its full-day count as "Today so far" (then dropping when today's
	// partial bucket lands) is the reported stale-today bug — suppress the cell then.
	if ( 'day' === $granularity && ! empty( $series ) ) {
		$last = end( $series );
		if ( is_array( $last ) && isset( $last['views'] )
			&& isset( $last['day'] ) && (string) $last['day'] === gmdate( 'Y-m-d' ) ) {
			$cells[] = '<span class="sn-an-pulse-cell"><span class="sn-an-pulse-k">' . esc_html__( 'Today so far', 'signal-and-noise-tools' ) . '</span>'
				. '<span class="sn-an-pulse-v sn-an-pulse-v--big">' . esc_html( number_format_i18n( (int) $last['views'] ) ) . '</span>'
				. '<span class="sn-an-pulse-v">' . esc_html__( 'views', 'signal-and-noise-tools' ) . '</span></span>';
		}
	}

	if ( empty( $cells ) ) {
		return;
	}
	echo '<div class="sn-an-pulse sn-an-pulse--footer">' . implode( '<span class="sn-an-pulse-sep"></span>', $cells ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells assembled above with esc_* at every dynamic value.
}
