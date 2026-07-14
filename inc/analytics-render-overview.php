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
	$n = count( $series );

	// Peak stays at the caller (drives the head's "peak N" meta text); the geometry
	// itself only needs the plain views array.
	$views    = array();
	$peak     = 0;
	$peak_day = '';
	foreach ( $series as $r ) {
		$v       = (int) $r['views'];
		$views[] = $v;
		if ( $v >= $peak ) {
			$peak     = $v;
			$peak_day = (string) $r['day'];
		}
	}
	$overlay_views = array();
	foreach ( (array) $compare_series as $r ) {
		$overlay_views[] = (int) ( $r['views'] ?? 0 );
	}

	$aria = ( 'week' === $granularity )
		? __( 'Weekly views trend', 'signal-and-noise-tools' )
		: __( 'Daily views trend', 'signal-and-noise-tools' );

	// v9.34.0 (maturity I5): brush-to-select — the chart becomes the range control.
	// The JS (analytics-brush.js) maps drag fractions onto these attributes and
	// navigates to sn_range=custom; snt_analytics_resolve_custom_window validates
	// whatever arrives server-side, so the JS only ever BUILDS a URL. Assembled from
	// esc_attr'd fragments here — the helper appends this string verbatim onto
	// .sn-spark-wrap (its $wrap_attrs contract, D5 §3).
	$wrap_attrs = ( 'day' === $granularity && $n > 1 )
		? 'data-brush-from="' . esc_attr( (string) $series[0]['day'] ) . '" data-brush-days="' . esc_attr( (string) $n ) . '"'
		: '';

	// Body-only trend band (v6.5.2): rendered inside the fused Overview panel,
	// directly below the KPI strip — no separate postbox/header. D5 §3: routes
	// through the shared trend-SVG primitive (inc/analytics-panels.php); geometry
	// is byte-identical to the pre-D5 inline copy this replaces.
	snt_an_trend_svg(
		$views,
		array(
			'overlay_series' => $overlay_views,
			'head'           => __( 'Views per day', 'signal-and-noise-tools' ),
			'meta'           => sprintf( /* translators: %s peak view count */ __( 'peak %s', 'signal-and-noise-tools' ), number_format_i18n( $peak ) ),
			'axis'           => array( (string) $series[0]['day'], (string) end( $series )['day'] ),
			'wrap_attrs'     => $wrap_attrs,
			'aria_label'     => $aria,
		)
	);
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
