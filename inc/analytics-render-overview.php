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

/**
 * Slim SVG sparkline trend (polyline + area fill + last-point dot + axis labels).
 * Replaces the old chunky bar strip. Inside a native .postbox (no collapse toggle).
 * All injected SVG coords are numeric/esc_attr'd; static SVG chrome is phpcs-clean.
 *
 * @param array  $series      [{day,views,visits}] ascending.
 * @param string $granularity 'day' (default) or 'week' — controls the aria-label.
 */
function snt_analytics_render_trend( $series, $granularity = 'day' ) {
	if ( empty( $series ) ) {
		return;
	}
	$n    = count( $series );
	$max  = 1;
	foreach ( $series as $r ) {
		$max = max( $max, (int) $r['views'] );
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
	echo '<div class="sn-spark-wrap">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
	echo '<svg class="sn-spark" viewBox="0 0 600 84" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $aria ) . '">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG defs, no dynamic values.
	echo '<defs><linearGradient id="snSparkFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2271b1" stop-opacity="0.16"/><stop offset="55%" stop-color="#2271b1" stop-opacity="0.04"/><stop offset="100%" stop-color="#2271b1" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#snSparkFill)" stroke="none"/>';
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
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	echo ' <span class="sn-an-delta sn-an-delta--' . esc_attr( $dir ) . '">' . esc_html( $arrow . ' ' . $text ) . '</span>';
}

/**
 * Echo a period-over-period delta badge in the new KPI strip style (▲/▼/■ + signed pct).
 * pct null → "new" (prev window was empty). No-op when no valid delta is supplied.
 *
 * @param array|null $delta {pct:?int, dir:string}
 */
function snt_analytics_render_delta_badge_kpi( $delta ) {
	if ( ! is_array( $delta ) || ! isset( $delta['dir'] ) ) {
		return;
	}
	$dir   = (string) $delta['dir'];
	$cls   = 'up' === $dir ? 'sn-delta-up' : ( 'down' === $dir ? 'sn-delta-down' : 'sn-delta-flat' );
	$arrow = 'up' === $dir ? '▲' : ( 'down' === $dir ? '▼' : '■' );
	$pct   = $delta['pct'] ?? null;
	$text  = ( null === $pct )
		? ( 'up' === $dir ? 'new' : '—' )
		: ( ( $pct > 0 ? '+' : '' ) . (int) $pct . '%' );
	// v8.5.0 (data-obsessed pass): the absolute prior-period value rides a
	// tooltip so the % is never the whole story. Escaping at the point of
	// output (the sniff cannot see through a pre-built attribute string).
	$prev_title = '';
	if ( isset( $delta['previous'] ) && is_numeric( $delta['previous'] ) ) {
		$prev       = (float) $delta['previous'];
		$prev_title = 'previous period: ' . number_format_i18n( $prev, ( $prev == (int) $prev ) ? 0 : 1 );
	}
	echo '<span class="sn-kpi-delta ' . esc_attr( $cls ) . '"'
		. ( '' !== $prev_title ? ' title="' . esc_attr( $prev_title ) . '"' : '' )
		. '><span class="sn-delta-arrow">' . esc_html( $arrow ) . '</span> ' . esc_html( $text ) . '</span>';
}

/**
 * Fused dense KPI strip: Views + Visits (promoted), Now, Avg scroll, Avg time,
 * and optionally Engaged. Views/Visits/Avg scroll/Avg time carry a
 * period-over-period delta badge when $deltas is given. "Now" is always live.
 * Wraps in a native .postbox (no collapse toggle — clean static header).
 *
 * @param int|null   $now     Realtime visitor count (null = not available).
 * @param array      $totals  {views,visits,scroll_avg,time_avg}
 * @param array      $deltas  {views,visits,scroll_avg,time_avg} => {pct,dir}
 * @param array{current:?int,previous?:?int,pct?:?int,dir?:string}|null $engaged Engaged-rate data,
 *                                                                                or null to omit the card.
 */
function snt_analytics_render_cards( $now, $totals, $deltas = array(), $engaged = null ) {
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
	echo '<div class="sn-kpi-row">';
	foreach ( $cards as $c ) {
		echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
		echo '<p class="sn-kpi-label">' . esc_html( $c['l'] ) . '</p>';
		echo '<p class="sn-kpi-value">' . esc_html( $c['n'] ) . '</p>';
		if ( ! empty( $c['live'] ) ) {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'live', 'signal-and-noise-tools' ) . '</span>';
		} elseif ( ! empty( $c['delta'] ) ) {
			snt_analytics_render_delta_badge_kpi( $c['delta'] );
		} else {
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html__( 'no change', 'signal-and-noise-tools' ) . '</span>';
		}
		echo '</div>';
	}
	echo '</div>';
}
