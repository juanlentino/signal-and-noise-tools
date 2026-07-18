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
	$views = array();
	$peak  = 0;
	foreach ( $series as $r ) {
		$v       = (int) $r['views'];
		$views[] = $v;
		$peak    = max( $peak, $v );
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
 * Fused dense KPI strip — the honest vocabulary (v9.64.0, spec §4): Views +
 * Visits (promoted; Visits IS the gated `pageview_visits`, which cannot exceed
 * views by construction), Now, exact Scroll / view + Time / view (the
 * v9.64.0-corrected depth unit and exact ms-per-view), and optionally Engaged.
 * A secondary hairline line under the strip surfaces the raw
 * `unique_visitor_days` + `viewless_visits` counts ("show the most").
 *
 * Null discipline: a derived field may be ABSENT (legacy caller) or NULL (the
 * selected range predates `exact_metrics_since`, or the read failed) — both
 * mean "never measured" and render an em-dash with a caveat naming the
 * discontinuity date, NEVER a fabricated 0. array_key_exists, not
 * isset()/`??` — both are blind to a present-but-null key. The deprecated
 * ungated `visits` and per-event `scroll_avg`/`time_avg` no longer render
 * here (their delta keys are deliberately not wired onto the exact cards —
 * a different unit's verdict would lie).
 *
 * @param int|null   $now         Realtime visitor count (null = not available).
 * @param array      $totals      The full sn_analytics_range_totals() contract
 *                                (legacy quartet + spec-§4 derived fields +
 *                                exact_metrics_since). Legacy quartet-only
 *                                arrays degrade gracefully (em-dash + caveat).
 * @param array      $deltas      sn_analytics_period_deltas() output
 *                                (views/pageview_visits/scroll_avg_per_view/
 *                                time_avg_per_view are the wired keys).
 * @param array{current:?int,previous?:?int,pct?:?int,dir?:string}|null $engaged Engaged-rate data,
 *                                                                                or null to omit the card.
 * @param string     $basis_label Comparison-basis tooltip label; '' = previous period.
 */
function snt_analytics_render_cards( $now, $totals, $deltas = array(), $engaged = null, $basis_label = '' ) {
	$totals = is_array( $totals ) ? $totals : array();
	$known  = function ( $key ) use ( $totals ) {
		return array_key_exists( $key, $totals ) && null !== $totals[ $key ];
	};
	// The em-dash caveat: WHY an exact value is unknown. When the backfill
	// marker exists the range predates it (the read layer nulls exactly then);
	// without a marker there is no date to name — say so, never invent one.
	$since  = ( $known( 'exact_metrics_since' ) && is_string( $totals['exact_metrics_since'] ) ) ? $totals['exact_metrics_since'] : '';
	$caveat = ( '' !== $since )
		/* translators: %s: first day carrying exact metrics (Y-m-d). */
		? sprintf( __( 'exact since %s', 'signal-and-noise-tools' ), $since )
		: __( 'no exact data yet', 'signal-and-noise-tools' );

	$cards   = array(
		array( 'l' => 'Views', 'n' => number_format_i18n( (int) ( $totals['views'] ?? 0 ) ), 'delta' => $deltas['views'] ?? null, 'promoted' => true ),
	);
	$cards[] = $known( 'pageview_visits' )
		? array( 'l' => 'Visits', 'n' => number_format_i18n( (int) $totals['pageview_visits'] ), 'delta' => $deltas['pageview_visits'] ?? null, 'promoted' => true )
		: array( 'l' => 'Visits', 'n' => '—', 'sub' => $caveat, 'promoted' => true );
	$cards[] = array( 'l' => 'Now', 'n' => ( null === $now ? '—' : number_format_i18n( (int) $now ) ), 'live' => true );
	$cards[] = $known( 'scroll_avg_per_view' )
		? array( 'l' => 'Scroll / view', 'n' => (int) round( (float) $totals['scroll_avg_per_view'] ) . '%', 'delta' => $deltas['scroll_avg_per_view'] ?? null )
		: array( 'l' => 'Scroll / view', 'n' => '—', 'sub' => $caveat );
	$cards[] = $known( 'time_avg_per_view' )
		? array( 'l' => 'Time / view', 'n' => snt_analytics_fmt_time( (float) $totals['time_avg_per_view'] ), 'delta' => $deltas['time_avg_per_view'] ?? null )
		: array( 'l' => 'Time / view', 'n' => '—', 'sub' => $caveat );
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

	// The visitor-day secondary line (spec §4 "show the most"): the raw
	// visitor-day count the deprecated 'visits' used to headline, plus its
	// viewless share. Muted hairline note (the compare-note idiom). Renders
	// only what is KNOWN — a null viewless count omits the clause, never 0.
	if ( $known( 'unique_visitor_days' ) ) {
		$note = sprintf(
			/* translators: %s: unique visitor-day count. */
			__( '%s visitor-days', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $totals['unique_visitor_days'] )
		);
		if ( $known( 'viewless_visits' ) ) {
			$note .= ' · ' . sprintf(
				/* translators: %s: count of visitor-days that fired no pageview. */
				__( '%s viewless (no pageview)', 'signal-and-noise-tools' ),
				number_format_i18n( (int) $totals['viewless_visits'] )
			);
		}
		echo '<p class="sn-an-visitor-note">' . esc_html( $note ) . '</p>';
	}
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
