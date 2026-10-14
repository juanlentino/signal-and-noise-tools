<?php
/**
 * Signal & Noise Tools: Analytics panel primitive, the trend sparkline SVG.
 *
 * Split from inc/analytics-panels.php (house line cap, 2026-10-14); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * THE trend renderer (D5 §3): one smooth-line SVG sparkline, geometry copied
 * byte-for-byte from the Overview canonical (snt_analytics_render_trend()).
 * Intended callers (Task 3 adopts; a caller that can't decompose cleanly stays
 * bespoke — parity beats purity):
 *  - snt_analytics_render_trend()          inc/analytics-render-overview.php
 *  - sn_login_defense_render_trend_chart() inc/login-defense-analytics.php
 *  - snt_analytics_render_bot_trend()      inc/analytics-render-quality.php
 *  - snt_analytics_render_post_trajectory() inc/analytics-posts-admin.php
 *    (dual-scale, age-based x-axis, no area/gradient/baseline — may stay
 *    bespoke if it doesn't decompose onto this single-area-fill shape).
 *
 * $series is a PLAIN NUMERIC ARRAY (not the copies' {day,views}-shaped rows) —
 * geometry is domain-agnostic; the caller extracts whichever field it means
 * (views, bot_pct, cumulative count, …) before calling, and supplies any axis
 * labels itself via $opts['axis']. Fewer than 2 points renders nothing (the
 * empty-fold, if any, is the CALLER's concern, per snt_an_note_empty above).
 * Callers also own their <2-point/1-point pre-processing: bot-trend pads a
 * 1-point series to a flat [v, v] before calling; the Overview canonical's n=1
 * degenerate sliver deliberately becomes nothing at adoption.
 *
 * @param array $series Numeric values, ascending, >= 2 points to render anything.
 * @param array $opts   {
 *     @type array  $overlay_series Optional second numeric series, rendered as a
 *                                  dashed overlay sharing this call's y-max (its
 *                                  own x-spacing, from its own point count).
 *     @type string $stroke         Main line + gradient hex. Default '#2271b1'
 *                                  (the Overview canonical's hardcoded color).
 *     @type string $head           Trend-head title text. Omit to skip the head row.
 *     @type string $meta           Trend-head meta text (only shown alongside $head).
 *     @type array  $axis           [start_label, end_label]. Omit to skip the axis row.
 *     @type string $id_suffix      Appended to the gradient id ('snSparkFill' + suffix).
 *                                  Forward-looking seam, not a live-bug fix: today's
 *                                  two 'snSparkFill' copies (Overview, login-defense)
 *                                  are mutually exclusive views behind the
 *                                  analytics-admin.php view switch and never co-render.
 *     @type string $wrap_attrs     PRE-ESCAPED attribute string appended inside the
 *                                  .sn-spark-wrap open tag (the canonical's brush
 *                                  data-attrs live on that element — the caller
 *                                  assembles them from esc_attr'd fragments exactly
 *                                  as inc/analytics-render-overview.php does today).
 *                                  Default '' = the bare wrap, byte-identical.
 *     @type string $aria_label     svg aria-label. Default '' = fall back to $head;
 *                                  both absent = no aria-label attribute at all
 *                                  (the headless trajectory copy's precedent).
 *     @type string $wrap_class     Outer wrapper class. Default 'sn-overview-trend'
 *                                  (bot-trend needs its CSS-load-bearing 'sn-an-bot-trend').
 *     @type string $svg_class      svg class. Default 'sn-spark' (bot-trend:
 *                                  'sn-an-bot-spark'). The inner .sn-spark-wrap is
 *                                  shared by every copy and stays fixed.
 * }
 */
function snt_an_trend_svg( $series, $opts = array() ) {
	if ( ! is_array( $series ) || count( $series ) < 2 ) {
		return;
	}
	$series = array_values( $series );
	$n      = count( $series );
	$w      = 600.0;
	$top    = 8.0;
	$base   = 78.0;

	$overlay = ( isset( $opts['overlay_series'] ) && is_array( $opts['overlay_series'] ) ) ? array_values( $opts['overlay_series'] ) : array();

	// The overlay shares this $max so both lines are on the same scale — an
	// overlay on its own scale would lie about relative volume (parity with
	// the Overview canonical's compare-series handling).
	$max = 1.0;
	foreach ( $series as $v ) {
		$max = max( $max, (float) $v );
	}
	foreach ( $overlay as $v ) {
		$max = max( $max, (float) $v );
	}

	$step = ( $n > 1 ) ? $w / ( $n - 1 ) : 0.0;
	$px   = array();
	$py   = array();
	foreach ( $series as $i => $v ) {
		$px[] = round( $i * $step, 2 );
		$py[] = round( $base - ( (float) $v / $max ) * ( $base - $top ), 2 );
	}

	// Smooth line via the shared helper (clamped Catmull-Rom → bézier).
	$line_d = snt_analytics_smooth_path( $px, $py, $top, $base );
	$last_x = $px[ $n - 1 ];
	// Area = the smooth line dropped to the baseline and closed.
	$area_d = 'M ' . $px[0] . ',' . $base . ' L ' . substr( $line_d, 2 ) . ' L ' . $last_x . ',' . $base . ' Z';

	$stroke      = (string) ( $opts['stroke'] ?? '#2271b1' );
	$gradient_id = 'snSparkFill' . (string) ( $opts['id_suffix'] ?? '' );
	$head        = (string) ( $opts['head'] ?? '' );
	$meta        = (string) ( $opts['meta'] ?? '' );
	$axis        = ( isset( $opts['axis'] ) && is_array( $opts['axis'] ) && 2 === count( $opts['axis'] ) ) ? array_values( $opts['axis'] ) : array();
	$wrap_attrs  = (string) ( $opts['wrap_attrs'] ?? '' );
	$wrap_class  = (string) ( $opts['wrap_class'] ?? 'sn-overview-trend' );
	$svg_class   = (string) ( $opts['svg_class'] ?? 'sn-spark' );
	// Explicit aria wins; else fall back to the head; both absent → omit the
	// attribute entirely (the headless trajectory copy's precedent).
	$aria = (string) ( $opts['aria_label'] ?? '' );
	if ( '' === $aria ) {
		$aria = $head;
	}

	echo '<div class="' . esc_attr( $wrap_class ) . '">';
	if ( '' !== $head ) {
		echo '<div class="sn-trend-head"><span class="sn-trend-title">' . esc_html( $head ) . '</span>';
		if ( '' !== $meta ) {
			echo '<span class="sn-trend-meta">' . esc_html( $meta ) . '</span>';
		}
		echo '</div>';
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $wrap_attrs is a pre-escaped attribute string assembled from esc_attr'd fragments at the caller (the canonical's brush-attr pattern).
	echo '<div class="sn-spark-wrap"' . ( '' !== $wrap_attrs ? ' ' . $wrap_attrs : '' ) . '>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- class + aria esc_attr'd, static SVG chrome otherwise.
	echo '<svg class="' . esc_attr( $svg_class ) . '" viewBox="0 0 600 84" preserveAspectRatio="none" role="img"' . ( '' !== $aria ? ' aria-label="' . esc_attr( $aria ) . '"' : '' ) . '>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gradient id + color esc_attr'd, static SVG chrome otherwise.
	echo '<defs><linearGradient id="' . esc_attr( $gradient_id ) . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0.16"/><stop offset="55%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0.04"/><stop offset="100%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0"/></linearGradient></defs>';
	echo '<line x1="0" y1="78" x2="600" y2="78" stroke="#dcdcde" stroke-width="1" vector-effect="non-scaling-stroke"/>';
	echo '<path d="' . esc_attr( $area_d ) . '" fill="url(#' . esc_attr( $gradient_id ) . ')" stroke="none"/>';
	if ( count( $overlay ) > 1 ) {
		$cn  = count( $overlay );
		$cst = $w / ( $cn - 1 );
		$cpx = array();
		$cpy = array();
		foreach ( $overlay as $i => $v ) {
			$cpx[] = round( $i * $cst, 2 );
			$cpy[] = round( $base - ( (float) $v / $max ) * ( $base - $top ), 2 );
		}
		$cmp_d = snt_analytics_smooth_path( $cpx, $cpy, $top, $base );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric coords esc_attr'd, static SVG chrome.
		echo '<path d="' . esc_attr( $cmp_d ) . '" fill="none" stroke="#a7aaad" stroke-width="2" stroke-dasharray="4 3" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	}
	// non-scaling-stroke keeps the line a crisp 2px regardless of the horizontal stretch (preserveAspectRatio=none).
	echo '<path d="' . esc_attr( $line_d ) . '" fill="none" stroke="' . esc_attr( $stroke ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>';
	echo '</svg></div>';
	if ( ! empty( $axis ) ) {
		echo '<div class="sn-spark-axis"><span>' . esc_html( (string) $axis[0] ) . '</span><span>' . esc_html( (string) $axis[1] ) . '</span></div>';
	}
	echo '</div>';
}

