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
 * @param array<string,mixed> $data Keys: views_7d, views_delta, search_clicks_7d,
 *                                  ai_spend_30d, anchored, citations. An absent or
 *                                  null key is UNMEASURED.
 * @return array<int,array<string,mixed>>
 */
function sn_dash_measurement_figures( array $data ) {
	$spec = array(
		array( 'key' => 'views_7d', 'label' => __( 'views 7d', 'signal-and-noise-tools' ), 'hero' => true ),
		array( 'key' => 'search_clicks_7d', 'label' => __( 'clicks 7d', 'signal-and-noise-tools' ), 'hero' => false ),
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

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		echo '<span class="sn-dash-fig-value">' . esc_html( (string) ( $fig['value'] ?? '' ) ) . '</span> ';
		echo '<span class="sn-dash-fig-label">' . esc_html( (string) ( $fig['label'] ?? '' ) ) . '</span>';
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
 * `search_clicks_7d` is deliberately absent. The Search Console read sits
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
		$deltas = sn_analytics_period_deltas(
			gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ),
			gmdate( 'Y-m-d', time() ),
			'human'
		);
		if ( is_array( $deltas ) && isset( $deltas['views'] ) ) {
			$data['views_7d'] = (int) ( $deltas['views']['current'] ?? 0 );
			if ( isset( $deltas['views']['delta'] ) ) {
				$data['views_delta'] = (int) $deltas['views']['delta'];
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

	return $data;
}
