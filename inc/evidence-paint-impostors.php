<?php
/**
 * Signal & Noise Tools: Reader evidence's "Failed a check" box (2026-10-18).
 * The reads that named a known crawler and failed a check, one row per
 * reason in ladder order, each with its long description: what the run-on
 * sentence in the lead used to say, as rows. Host-agnostic: one function
 * paints both twins (kit and classic). Loaded by inc/evidence-paint-tiles.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reads that failed a check, by reason. '' when not measured.
 *
 * @param int|null   $total   Reads that failed a check; null when not measured.
 * @param array|null $reasons Reason => hits (snt_mr_impostor_reasons()).
 * @param string     $surface kit|classic.
 * @return string
 */
function snt_ev_paint_impostors( $total, $reasons, $surface = 'classic' ) {
	if ( null === $total ) {
		return '';
	}
	$l     = snt_mr_reader_labels();
	$title = ucfirst( $l['failed_check']['label'] );
	if ( (int) $total < 1 ) {
		return snt_ev_box( $surface, $title, snt_ev_lead( $surface, __( 'No read failed a check in this window.', 'signal-and-noise-tools' ) ) );
	}
	$rows = array();
	foreach ( snt_mr_reason_rows( $reasons ) as $r ) {
		$rows[] = array( 'check' => ucfirst( $l[ $r['reason'] ]['label'] ), 'means' => $l[ $r['reason'] ]['title'], 'reads' => number_format_i18n( $r['hits'] ) );
	}
	/* translators: 1: reads, 2: what failing a check means. */
	$lead = sprintf( __( '%1$s reads: %2$s', 'signal-and-noise-tools' ), number_format_i18n( (int) $total ), lcfirst( $l['failed_check']['title'] ) );
	$cols = array( 'check' => __( 'Check', 'signal-and-noise-tools' ), 'means' => __( 'What it means', 'signal-and-noise-tools' ), 'reads' => __( 'Reads', 'signal-and-noise-tools' ) );
	return snt_ev_box( $surface, $title, snt_ev_lead( $surface, $lead ) . ( array() === $rows ? '' : snt_ev_table( $surface, $cols, $rows, 0, $title ) ) );
}
