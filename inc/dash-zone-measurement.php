<?php
/**
 * Signal & Noise — Dashboard measurement strip.
 *
 * The one zone that never collapses: it has no green/red state, so there is
 * nothing to fold. Capped at five figures, and the cap is enforced by the narrow
 * reflow — views is the hero (it carries the sparkline) and takes a full row,
 * leaving a clean 2x2 below. A sixth figure would strand.
 *
 * An ABSENT value is unknown and must never render as 0. A Search Console read
 * that failed is missing evidence, not zero clicks.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the five figures in display order.
 *
 * @param array<string,mixed> $data Keys: views_7d, views_delta, search_clicks,
 *                                  ai_spend_30d, anchored, citations. An absent or
 *                                  null key is UNMEASURED.
 * @return array<int,array<string,mixed>>
 */
function sn_dash_measurement_figures( array $data ) {
	// The Search Console window is whatever the last sync used — 28 days by
	// default, ending a few days back because Google has not finished counting
	// the most recent ones. Labelling that "clicks 7d" would overstate a week by
	// a month's worth and read as entirely plausible, so the label is DERIVED.
	// An unknown length says "clicks" rather than inventing "clicks 0d".
	$clicks_days  = isset( $data['search_clicks_days'] ) ? (int) $data['search_clicks_days'] : 0;
	$clicks_label = $clicks_days > 0
		/* translators: %d number of days in the Search Console window */
		? sprintf( __( 'clicks %dd', 'signal-and-noise-tools' ), $clicks_days )
		: __( 'clicks', 'signal-and-noise-tools' );

	$spec = array(
		array( 'key' => 'views_7d', 'label' => __( 'views 7d', 'signal-and-noise-tools' ), 'hero' => true ),
		array( 'key' => 'search_clicks', 'label' => $clicks_label, 'hero' => false ),
		array( 'key' => 'ai_spend_30d', 'label' => __( 'AI 30d', 'signal-and-noise-tools' ), 'hero' => false, 'money' => true ),
		array( 'key' => 'anchored', 'label' => __( 'anchored', 'signal-and-noise-tools' ), 'hero' => false ),
		array( 'key' => 'citations', 'label' => __( 'citations', 'signal-and-noise-tools' ), 'hero' => false ),
	);
	$out = array();
	foreach ( $spec as $f ) {
		$measured = array_key_exists( $f['key'], $data ) && null !== $data[ $f['key'] ];
		$raw      = $measured ? $data[ $f['key'] ] : null;
		$value    = '—';
		if ( $measured ) {
			$value = ! empty( $f['money'] )
				? '$' . number_format( (float) $raw, 2 )
				: (string) (int) $raw;
		}
		$out[] = array(
			'key'      => $f['key'],
			'label'    => $f['label'],
			'hero'     => (bool) $f['hero'],
			'measured' => $measured,
			'value'    => $value,
			'delta'    => ( 'views_7d' === $f['key'] && array_key_exists( 'views_delta', $data ) )
				? (int) $data['views_delta'] : null,
			// The hero alone carries the trend. Kept as DATA, not markup: this
			// builder is pure, and the renderer decides how to draw it.
			'series'   => ( 'views_7d' === $f['key'] && ! empty( $data['views_series'] ) && is_array( $data['views_series'] ) )
				? $data['views_series'] : null,
		);
	}
	return $out;
}

/**
 * Render the measurement strip.
 *
 * The unmeasured class is the whole point of the markup: an em dash on its own
 * reads as data at a glance, so a figure that was never measured is dimmed by
 * `.sn-dash-fig--unmeasured` rather than left to look like a value. The hero
 * carries its own class because the narrow reflow gives it a full row.
 *
 * @since 11.28.0
 * @param array<int,array<string,mixed>> $figures From sn_dash_measurement_figures().
 * @return void
 */
function sn_dash_render_measurement_strip( array $figures ) {
	if ( empty( $figures ) ) {
		return;
	}

	echo '<section class="sn-dash-strip" aria-label="' . esc_attr__( 'Measurement', 'signal-and-noise-tools' ) . '">';
	foreach ( $figures as $fig ) {
		if ( ! is_array( $fig ) ) {
			continue;
		}
		$classes = array( 'sn-dash-fig' );
		if ( ! empty( $fig['hero'] ) ) {
			$classes[] = 'sn-dash-fig--hero';
		}
		// array_key_exists, not a falsy check: the same zero-vs-null rule the
		// figures themselves follow.
		if ( array_key_exists( 'measured', $fig ) && false === $fig['measured'] ) {
			$classes[] = 'sn-dash-fig--unmeasured';
		}


		$unmeasured = array_key_exists( 'measured', $fig ) && false === $fig['measured'];

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<span class="sn-dash-fig-value">' . esc_html( (string) ( $fig['value'] ?? '' ) ) . '</span> ';
		echo '<span class="sn-dash-fig-label">' . esc_html( (string) ( $fig['label'] ?? '' ) ) . '</span>';

		// Reuses the shared analytics sparkline — the same SVG treatment as the
		// Overview chart — rather than minting a second one. Never drawn for an
		// UNMEASURED figure: a trend line under an em dash would assert exactly
		// the knowledge the em dash exists to deny. An empty series draws
		// nothing at all, because an empty chart is worse than no chart.
		if ( ! $unmeasured && ! empty( $fig['series'] ) && is_array( $fig['series'] )
			&& function_exists( 'snt_analytics_sparkline' ) ) {
			// snt_analytics_sparkline returns pre-escaped SVG (coords esc_attr'd, chrome static).
			echo snt_analytics_sparkline( $fig['series'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped SVG from the shared helper.
		}

		echo '</div>';
	}
	echo '</section>';
}

/**
 * Gather the measurement-strip figures from the accessors that exist.
 *
 * An accessor that is not present contributes NO KEY, which the figure builder
 * reads as unmeasured and renders as an em dash. That is the point: a missing
 * module is missing evidence, and a 0 here would be a claim we cannot support.
 *
 * `search_clicks` is deliberately absent. The Search Console read sits
 * outside the glance cache and needs its own transient; until that lands the
 * figure renders unknown, which is the same thing the proposal specifies for a
 * cache miss or an API error. Citations reads the local table, where 0 is a
 * genuine measured zero.
 *
 * @since 11.28.0
 * @return array<string,mixed>
 */
function snt_dashboard_measurement_data() {
	$data = array();

	if ( function_exists( 'sn_analytics_config' ) && sn_analytics_config()
		&& function_exists( 'sn_analytics_period_deltas' ) ) {
		$from   = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$to     = gmdate( 'Y-m-d', time() );
		$deltas = sn_analytics_period_deltas( $from, $to, 'human' );
		if ( is_array( $deltas ) && isset( $deltas['views'] ) ) {
			$data['views_7d'] = (int) ( $deltas['views']['current'] ?? 0 );
			if ( isset( $deltas['views']['delta'] ) ) {
				$data['views_delta'] = (int) $deltas['views']['delta'];
			}
		}

		// The hero's trend, from the same accessor the Analytics widget uses.
		// sn_analytics_daily_series() memoises on from|to|class|granularity, so
		// this costs nothing beyond the first call in a request.
		if ( function_exists( 'sn_analytics_daily_series' ) && function_exists( 'snt_analytics_sparkline' ) ) {
			$series = sn_analytics_daily_series( $from, $to, 'human', 'day' );
			if ( ! empty( $series ) && is_array( $series ) ) {
				$data['views_series'] = $series;
			}
		}
	}

	if ( function_exists( 'snt_ai_usage_summary' ) ) {
		$s30 = snt_ai_usage_summary( 30 );
		if ( is_array( $s30 ) ) {
			$data['ai_spend_30d'] = (float) ( $s30['cost'] ?? 0 );
		}
	}

	if ( function_exists( 'snt_prov_anchor_overview' ) ) {
		$ov = snt_prov_anchor_overview();
		if ( is_array( $ov ) && array_key_exists( 'confirmed', $ov ) ) {
			$data['anchored'] = (int) $ov['confirmed'];
		}
	}

	if ( function_exists( 'sn_cit_counts' ) && defined( 'SN_CIT_TIERS' ) ) {
		$counts = sn_cit_counts();
		if ( is_array( $counts ) ) {
			$total = 0;
			// Tier keys only — `never_checked` is a different axis over the same
			// rows, so adding it would double-count.
			foreach ( SN_CIT_TIERS as $tier ) {
				$total += (int) ( $counts[ $tier ] ?? 0 );
			}
			$data['citations'] = $total;
		}
	}

	// Search Console reads the STORED payload — no API call on a page render.
	// snt_gsc_window_totals() returns null until something has synced, which
	// keeps the key absent and the figure unknown. That is the same answer the
	// design specifies for a failed read: an unreachable API is missing
	// evidence, not zero clicks.
	if ( function_exists( 'snt_gsc_window_totals' ) ) {
		$gsc = snt_gsc_window_totals();
		if ( is_array( $gsc ) ) {
			$data['search_clicks']      = (int) $gsc['clicks'];
			$data['search_clicks_days'] = (int) $gsc['days'];
		}
	}

	return $data;
}
