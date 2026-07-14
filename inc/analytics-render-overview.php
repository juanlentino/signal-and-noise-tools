<?php
/**
 * Signal & Noise — Analytics Overview panel partials: the daily/weekly trend
 * sparkline, the fused KPI strip, and the two period-over-period delta badges the
 * strip leans on. Native wp-admin markup; injected SVG coords are numeric and
 * esc_attr'd. Extracted from analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 5.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-render-helpers.php'; // snt_analytics_smooth_path (trend) + snt_analytics_fmt_time (cards)
require_once __DIR__ . '/analytics-panels.php'; // snt_an_delta_badge + snt_an_kpi_row (v9.40.0 D4 primitives)

/**
 * Slim SVG sparkline trend (polyline + area fill + last-point dot + axis labels).
 * Replaces the old chunky bar strip. Inside a native .postbox (no collapse toggle).
 * All injected SVG coords are numeric/esc_attr'd; static SVG chrome is phpcs-clean.
 *
 * @param array  $series         [{day,views,visits}] ascending.
 * @param string $granularity    'day' (default) or 'week' — controls the aria-label.
 * @param array  $compare_series Optional comparison-window series, overlaid dashed on the shared y-scale.
 */
function snt_analytics_render_trend( $series, $granularity = 'day', $compare_series = array() ) {
	if ( empty( $series ) ) {
		return;
	}
	$n    = count( $series );
	$max  = 1;
	foreach ( $series as $r ) {
		$max = max( $max, (int) $r['views'] );
	}
	// v9.34.0: the comparison overlay shares this $max so both lines are on the
	// same scale — an overlay on its own scale would lie about relative volume.
	foreach ( (array) $compare_series as $r ) {
		$max = max( $max, (int) ( $r['views'] ?? 0 ) );
	}
	$w    = 600.0;
	$top  = 8.0;
	$base = 78.0;
	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( array_values( $series ) as $i => $r ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (int) $r['views'] / $max ) * ( $base - $top ), 2 );
	}

	// Smooth line via the shared helper (clamped Catmull-Rom → bézier).
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$last_x = $px[ $n - 1 ];
	// Area = the smooth line dropped to the baseline and closed.
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';
	$peak     = 0;
	$peak_day = '';
	foreach ( $series as $r ) {
		if ( (int) $r['views'] >= $peak ) {
			$peak     = (int) $r['views'];
			$peak_day = (string) $r['day'];
		}
	}
	$aria = ( 'week' === $granularity )
		? __( 'Weekly views trend', 'signal-and-noise-tools' )
		: __( 'Daily views trend', 'signal-and-noise-tools' );

	// Body-only trend band (v6.5.2): rendered inside the fused Overview panel,
	// directly below the KPI strip — no separate postbox/header.
	echo '<div class="sn-overview-trend">';
	echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html__( 'Views per day', 'signal-and-noise-tools' ) . '</span>';
	echo '<span class="sn-trend-meta">' . esc_html( sprintf( /* translators: %s peak view count */ __( 'peak %s', 'signal-and-noise-tools' ), number_format_i18n( $peak ) ) ) . '</span></div>';
	// v9.34.0 (maturity I5): brush-to-select — the chart becomes the range control.
	// The JS (analytics-brush.js) maps drag fractions onto these attributes and
	// navigates to sn_range=custom; snt_analytics_resolve_custom_window validates
	// whatever arrives server-side, so the JS only ever BUILDS a URL.
	$brush = ( 'day' === $granularity && $n > 1 )
		? ' data-brush-from="' . esc_attr( (string) $series[0]['day'] ) . '" data-brush-days="' . esc_attr( (string) $n ) . '"'
		: '';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes assembled from esc_attr'd fragments above.
	echo '<div class="sn-spark-wrap"' . $brush . '>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $aria ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG defs, no dynamic values.
	echo '<defs><linearGradient id="snSparkFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2271b1" stop-opacity="0.16"/><stop offset="55%" stop-color="#2271b1" stop-opacity="0.04"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snSparkFill)" stroke="none"/>';
	if ( count( (array) $compare_series ) > 1 ) {
		$cn  = count( $compare_series );
		$cst = $w / ( $cn - 1 );
		$cpx = array();
		$cpy = array();
		foreach ( array_values( $compare_series ) as $i => $r ) {
			$cpx[] = round( $i * $cst, 2 );
			$cpy[] = round( $base - ( (int) ( $r['views'] ?? 0 ) / $max ) * ( $base - $top ), 2 );
		}
		$cmp_d = snt_analytics_smooth_path( $cpx, $cpy, $top, $base );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
		echo '<path d="' . esc_attr( $cmp_d ) . '" fill="none" stroke="#a7aaad" stroke-width="2" stroke-dasharray="4 3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	}
	// non-scaling-stroke keeps the line a crisp 2px regardless of the horizontal stretch (preserveAspectRatio=none).
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	echo '<div class="sn-spark-axis"><span>' . esc_html( (string) $series[0]['day'] ) . '</span><span>' . esc_html( (string) end( $series )['day'] ) . '</span></div>';
	echo '</div>';
}

/**
 * Echo a period-over-period delta badge (▲/▼/■ + signed pct). pct null → "new"
 * (prev window was empty). No-op when no delta is supplied. Echoes (rather than
 * returns) so the escaping is at the point of output (phpcs-visible).
 *
 * @param array|null $delta {pct:?int, dir:string}
 */
function snt_analytics_render_delta_badge( $delta ) {
	// v9.40.0 D4: thin wrapper — delegates to the shared primitive (inline variant).
	snt_an_delta_badge( $delta );
}

/**
 * Echo a period-over-period delta badge in the new KPI strip style (▲/▼/■ + signed pct).
 * pct null → "new" (prev window was empty). No-op when no valid delta is supplied.
 *
 * @param array|null $delta       {pct:?int, dir:string}
 * @param string     $basis_label Comparison-basis tooltip label; '' = previous period.
 */
function snt_analytics_render_delta_badge_kpi( $delta, $basis_label = '' ) {
	// v9.40.0 D4: thin wrapper — delegates to the shared primitive (kpi variant).
	snt_an_delta_badge( $delta, array( 'variant' => 'kpi', 'basis_label' => $basis_label ) );
}

/**
 * Fused dense KPI strip: Views + Visits (promoted), Now, Avg scroll, Avg time,
 * and optionally Engaged. Views/Visits/Avg scroll/Avg time carry a
 * period-over-period delta badge when $deltas is given. "Now" is always live.
 * Wraps in a native .postbox (no collapse toggle — clean static header).
 *
 * @param int|null   $now         Realtime visitor count (null = not available).
 * @param array      $totals      {views,visits,scroll_avg,time_avg}
 * @param array      $deltas      {views,visits,scroll_avg,time_avg} => {pct,dir}
 * @param array{current:?int,previous?:?int,pct?:?int,dir?:string}|null $engaged Engaged-rate data,
 *                                                                                or null to omit the card.
 * @param string     $basis_label Comparison-basis tooltip label; '' = previous period.
 */
function snt_analytics_render_cards( $now, $totals, $deltas = array(), $engaged = null, $basis_label = '' ) {
	$cards = array(
		array( 'l' => 'Views',      'n' => number_format_i18n( (int) ( $totals['views'] ?? 0 ) ),  'delta' => $deltas['views'] ?? null,      'promoted' => true ),
		array( 'l' => 'Visits',     'n' => number_format_i18n( (int) ( $totals['visits'] ?? 0 ) ), 'delta' => $deltas['visits'] ?? null,     'promoted' => true ),
		array( 'l' => 'Now',        'n' => ( null === $now ? '—' : number_format_i18n( (int) $now ) ), 'live' => true ),
		array( 'l' => 'Avg scroll', 'n' => (int) round( (float) ( $totals['scroll_avg'] ?? 0 ) ) . '%', 'delta' => $deltas['scroll_avg'] ?? null ),
		array( 'l' => 'Avg time',   'n' => snt_analytics_fmt_time( (float) ( $totals['time_avg'] ?? 0 ) ), 'delta' => $deltas['time_avg'] ?? null ),
	);
	if ( is_array( $engaged ) && null !== ( $engaged['current'] ?? null ) ) {
		$cards[] = array( 'l' => 'Engaged', 'n' => (int) $engaged['current'] . '%', 'delta' => ( isset( $engaged['dir'] ) ? $engaged : null ) );
	}
	// Body-only (no postbox wrapper): render_dashboard fuses this KPI strip and the
	// trend chart into a single "Overview" panel (v6.5.2) so the sparkline is the
	// panel's footer rather than a lonely half-empty box.
	// v9.40.0 D4: routes through the shared row primitive — l/n/delta/live keys
	// are already primitive-compatible; its default slot prints "no change"
	// exactly like the old else-branch.
	snt_an_kpi_row( $cards, array( 'basis_label' => $basis_label ) );
}

/**
 * One-line comparison summary under the trend (maturity I5): names the compare
 * window and its views total, with a signed delta vs the current window. No-op
 * when compare is off or the comparison totals are empty.
 *
 * @param string $mode    'prev' | 'yoy' | 'off'.
 * @param array  $totals  Current-window totals.
 * @param array  $ctotals Comparison-window totals.
 * @param string $cfrom   Comparison window start.
 * @param string $cto     Comparison window end.
 */
function snt_analytics_render_compare_note( $mode, $totals, $ctotals, $cfrom, $cto ) {
	if ( ! in_array( (string) $mode, array( 'prev', 'yoy' ), true ) || empty( $ctotals ) ) {
		return;
	}
	$label = ( 'yoy' === (string) $mode )
		? __( 'same period last year', 'signal-and-noise-tools' )
		: __( 'previous period', 'signal-and-noise-tools' );
	$cur  = (int) ( $totals['views'] ?? 0 );
	$prev = (int) ( $ctotals['views'] ?? 0 );
	$pct  = ( $prev > 0 ) ? (int) round( ( $cur - $prev ) / $prev * 100 ) : null;
	$note = sprintf(
		/* translators: 1: comparison label, 2: window start, 3: window end, 4: comparison views. */
		__( 'vs %1$s (%2$s – %3$s): %4$s views', 'signal-and-noise-tools' ),
		$label, (string) $cfrom, (string) $cto, number_format_i18n( $prev )
	);
	if ( null !== $pct ) {
		$note .= ' (' . ( $pct > 0 ? '+' : '' ) . $pct . '% now)';
	}
	echo '<p class="sn-an-compare-note">' . esc_html( $note ) . '</p>';
}
