<?php
/**
 * Signal & Noise — pure analytics derive layer (Analytics Integrity Phase A).
 *
 * Computes every spec-§4 derived field from ONE daily-aggregate row (or a
 * range total shaped like one): the honest denominators (`unique_visitor_days`,
 * `pageview_visits`, `viewless_visits`), the transparent ratios, exact
 * per-view / per-visit engagement, and the never-invert `integrity_violation`
 * flag. Input keys mirror the real rollup surface (inc/analytics-rollup.php):
 * views / visits / pageview_visits / scroll_sum / scroll_events / time_sum /
 * time_events — any of which may be ABSENT or NULL.
 *
 * ── PURE MODULE — the load-bearing constraint ────────────────────────────────
 *
 * ZERO WordPress calls, zero globals, zero I/O. Tests require() this real file
 * directly (never a stub), so the asserted behaviour IS the shipped behaviour.
 * Declarations are function_exists-guarded so a test that already loaded the
 * file (or a WP boot that double-requires) never fatals on redeclare.
 *
 * ── Null discipline (each rule is a shipped-bug class from project memory) ───
 *
 *   - absent key ≡ null value ≡ "never measured" → the derived field is null.
 *     Distinguished via array_key_exists(): `??`/isset() are blind to a
 *     present-but-null key and would silently conflate the two.
 *   - A ratio is null when ANY of its inputs is null/absent OR its denominator
 *     is 0. A zero-traffic day therefore yields REAL 0 counts and null ratios.
 *   - Never cast null → 0 (fabricates a measurement) and never 0 → null
 *     (erases one): a measured scroll_sum of 0 over live views is exactly 0.0.
 *   - `integrity_violation` is a strict bool, never null: true only when
 *     `views` and `pageview_visits` are BOTH known and views < pageview_visits
 *     (arithmetically impossible per spec §5 — so true means a genuine
 *     rollup/sampling bug upstream). Values are still reported un-clamped;
 *     the alarm is the feature.
 *
 * @package SignalNoiseTools
 * @since 9.62.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sn_analytics_derive_num' ) ) {
	/**
	 * Read one numeric input from a daily row, honouring absent-vs-null.
	 *
	 * @param array  $daily Daily-aggregate row (or range total).
	 * @param string $key   Input key (rollup column spelling).
	 * @return int|float|null Numeric value, or null when absent / null /
	 *                        non-numeric ("never measured" / untrustworthy —
	 *                        never coerced to a fabricated 0).
	 */
	function sn_analytics_derive_num( array $daily, $key ) {
		if ( ! array_key_exists( $key, $daily ) ) {
			return null; // absent — isset()/`??` could not tell this from null.
		}
		$value = $daily[ $key ];
		if ( null === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return $value + 0; // numeric-string (wpdb reality) → real int|float.
	}
}

if ( ! function_exists( 'sn_analytics_derive_ratio' ) ) {
	/**
	 * Honest division: null unless both operands are known and the denominator
	 * is non-zero. A known-0 numerator over a live denominator returns 0.0.
	 *
	 * @param int|float|null $numerator   Known value or null.
	 * @param int|float|null $denominator Known value or null.
	 * @return float|null
	 */
	function sn_analytics_derive_ratio( $numerator, $denominator ) {
		if ( null === $numerator || null === $denominator || 0.0 === (float) $denominator ) {
			return null;
		}
		return (float) $numerator / (float) $denominator;
	}
}

if ( ! function_exists( 'sn_analytics_derive_metrics' ) ) {
	/**
	 * Derive every spec-§4 field from one daily row (or range total).
	 *
	 * @param array $daily Row with any of: views, visits (≡ unique visitor-
	 *                     days), pageview_visits, scroll_sum, scroll_events,
	 *                     time_sum, time_events. Any key may be absent or null.
	 * @return array {
	 *     @type int|null   $unique_visitor_days       Honest alias of `visits`.
	 *     @type int|null   $pageview_visits           Gated visitor-days (headline).
	 *     @type int|null   $viewless_visits           unique_visitor_days − pageview_visits.
	 *     @type float|null $view_visit_ratio          views / pageview_visits.
	 *     @type float|null $pageviews_per_visitor_day views / unique_visitor_days.
	 *     @type float|null $scroll_avg_per_view       scroll_sum / views (exact).
	 *     @type float|null $time_avg_per_view         time_sum / views (exact).
	 *     @type float|null $scroll_avg_per_visit      scroll_sum / unique_visitor_days (diluted by viewless days).
	 *     @type float|null $time_avg_per_visit        time_sum / unique_visitor_days (diluted by viewless days).
	 *     @type bool       $integrity_violation       true iff both known AND views < pageview_visits.
	 * }
	 */
	function sn_analytics_derive_metrics( array $daily ) {
		$views      = sn_analytics_derive_num( $daily, 'views' );
		$scroll_sum = sn_analytics_derive_num( $daily, 'scroll_sum' );
		$time_sum   = sn_analytics_derive_num( $daily, 'time_sum' );

		$visitor_days = sn_analytics_derive_num( $daily, 'visits' );
		$gated        = sn_analytics_derive_num( $daily, 'pageview_visits' );
		$visitor_days = null === $visitor_days ? null : (int) $visitor_days;
		$gated        = null === $gated ? null : (int) $gated;

		return array(
			'unique_visitor_days'       => $visitor_days,
			'pageview_visits'           => $gated,
			'viewless_visits'           => ( null !== $visitor_days && null !== $gated ) ? $visitor_days - $gated : null,
			'view_visit_ratio'          => sn_analytics_derive_ratio( $views, $gated ),
			'pageviews_per_visitor_day' => sn_analytics_derive_ratio( $views, $visitor_days ),
			'scroll_avg_per_view'       => sn_analytics_derive_ratio( $scroll_sum, $views ),
			'time_avg_per_view'         => sn_analytics_derive_ratio( $time_sum, $views ),
			'scroll_avg_per_visit'      => sn_analytics_derive_ratio( $scroll_sum, $visitor_days ),
			'time_avg_per_visit'        => sn_analytics_derive_ratio( $time_sum, $visitor_days ),
			'integrity_violation'       => null !== $views && null !== $gated && $views < $gated,
		);
	}
}
