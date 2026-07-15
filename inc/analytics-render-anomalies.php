<?php
/**
 * Signal & Noise — Analytics engagement-anomalies panel: cross-metric divergence
 * (deep-scroll/fast-leave, long-dwell/low-scroll) + per-metric statistical
 * outliers, from sn_analytics_engagement_anomalies() (v8.9.0 anomaly arc).
 * Native wp-admin markup via the panel primitive. Extracted from
 * analytics-admin-render.php (v8.9.x split).
 *
 * @package SignalNoiseTools
 * @since 8.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // panel chrome + empty-fold collector (snt_an_note_empty)

/**
 * Render the engagement-anomalies panel. Folds to a one-line empty note when
 * there is nothing to show (matching the dashboard's compact-empty convention).
 *
 * @param array{divergence:array,outliers:array} $anom
 * @return void
 */
function snt_analytics_render_anomalies( $anom ) {
	$title = __( 'Engagement anomalies', 'signal-and-noise-tools' );
	$div   = isset( $anom['divergence'] ) && is_array( $anom['divergence'] ) ? $anom['divergence'] : array();
	$out   = isset( $anom['outliers'] ) && is_array( $anom['outliers'] ) ? $anom['outliers'] : array();
	if ( empty( $div ) && empty( $out ) ) {
		snt_an_note_empty( $title );
		return;
	}
	snt_an_panel_open( $title );
	snt_an_annotation( sn_annotation_anomalies( $anom ) );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Signal', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Detail', 'signal-and-noise-tools' ) . '</th><th>' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $div as $d ) {
		$label = 'skim' === $d['type'] ? __( 'Deep scroll, fast leave', 'signal-and-noise-tools' ) : __( 'Long dwell, low scroll', 'signal-and-noise-tools' );
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $d['path'] ),
			esc_html( $label ),
			esc_html( $d['scroll_avg'] . '% scroll · ' . round( $d['time_avg_ms'] / 1000, 1 ) . 's' ),
			esc_html( number_format_i18n( $d['views'] ) )
		);
	}
	foreach ( $out as $o ) {
		$metric = 'scroll_avg' === $o['metric'] ? 'scroll depth' : 'dwell time';
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $o['path'] ),
			esc_html( ucfirst( $o['dir'] ) . ' outlier · ' . $metric ),
			esc_html( $o['value'] . ' vs ' . $o['mean'] . ' avg (z ' . $o['z'] . ')' ),
			esc_html( number_format_i18n( $o['views'] ) )
		);
	}
	echo '</tbody></table>';
	snt_an_panel_close();
}
