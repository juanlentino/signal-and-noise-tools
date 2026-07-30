<?php
/**
 * Signal & Noise — the Topics panel (v10.21.0): which arguments moved.
 *
 * Renders per-topic aggregated views/visits from sn_analytics_topic_totals()
 * on the Content view's main column. Owns its THREE states explicitly:
 *   1. topic index never built → the empty fold SAYS not-built (never a
 *      fabricated quiet window);
 *   2. rollup read failed → the fold speaks the shared read-failure copy
 *      (the v9.68.1 geography idiom) — unknown is spoken, never zeroed;
 *   3. rows → the standard panel, with the maturity-migration annotation
 *      pass over member paths (the 2026-07-30 re-parenting splits path
 *      history, and topics aggregate paths).
 *
 * @package SignalNoiseTools
 * @since 10.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Topics panel.
 *
 * @param string $from Window start (Y-m-d).
 * @param string $to   Window end (Y-m-d).
 */
function snt_analytics_render_topics( $from, $to ) {
	if ( ! function_exists( 'snt_ml_topics_get' ) || ! function_exists( 'sn_analytics_topic_totals' ) ) {
		return; // Partial install: no panel, no claim.
	}
	$title = __( 'Topics', 'signal-and-noise-tools' );
	if ( null === snt_ml_topics_get() ) {
		snt_an_note_empty( $title, __( 'The topic index has not built yet — it builds on the next publish or overnight.', 'signal-and-noise-tools' ) );
		return;
	}
	$rows = sn_analytics_topic_totals( $from, $to );
	if ( null === $rows ) {
		snt_an_note_empty( $title, snt_an_read_failed_copy( $title ) );
		return;
	}
	if ( array() === $rows ) {
		snt_an_note_empty( $title, __( 'No topic had measured traffic in this window.', 'signal-and-noise-tools' ) );
		return;
	}

	snt_an_panel_open( $title );

	// The re-parenting annotation reads path-keyed rows; flatten member paths.
	if ( function_exists( 'sn_annotation_maturity_migration' ) ) {
		$flat = array();
		foreach ( $rows as $r ) {
			foreach ( (array) ( $r['member_paths'] ?? array() ) as $p ) {
				$flat[] = array( 'path' => $p );
			}
		}
		snt_an_annotation( sn_annotation_maturity_migration( $flat, $from, $to ) );
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Topic', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Notes', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Views', 'signal-and-noise-tools' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Visits', 'signal-and-noise-tools' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		echo '<td>' . esc_html( (string) $r['label'] ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $r['notes'] ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $r['views'] ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( (int) $r['visits'] ) ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';

	snt_an_panel_close();
}
