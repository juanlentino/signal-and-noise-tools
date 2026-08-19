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
