<?php
/**
 * Signal & Noise Tools: the evidence painters' two surfaces.
 *
 * The section painters (inc/evidence-paint-*.php) take data and return HTML
 * for either surface: `kit` (the OpenStation leaves, os-* elements) or
 * `classic` (wp-admin markup). These adapters are the only place the two
 * differ, so a section says the same words on both. Host-agnostic: nothing
 * here knows which leaf or tab it lands on.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A box: heading, then its inner HTML. */
function snt_ev_box( $surface, $heading, $inner ) {
	if ( 'kit' === $surface ) {
		return snt_kit_section( $heading, $inner );
	}
	return '<div class="sn-fieldset sn-ev-box"><h3 class="sn-fieldset-h">' . esc_html( $heading ) . '</h3>' . $inner . '</div>';
}

/** The one-sentence finding a box leads with. */
function snt_ev_lead( $surface, $text ) {
	return '' === (string) $text ? '' : '<p class="' . ( 'kit' === $surface ? 'snt-hint' : 'sn-an-settings-help' ) . ' snt-ev-lead">' . esc_html( $text ) . '</p>';
}

/** A verdict that IS a finding (warning tone), as a notice on either surface. */
function snt_ev_verdict( $surface, $html ) {
	return 'kit' === $surface ? snt_kit_notice( 'warn', $html ) : '<div class="notice notice-warning inline"><p>' . $html . '</p></div>';
}

/** Two boxes on one row (one column when narrow); a lone box stands alone. */
function snt_ev_pair( $surface, $a, $b ) {
	if ( '' === (string) $a || '' === (string) $b ) {
		return (string) $a . (string) $b;
	}
	return 'kit' === $surface ? snt_kit_grid( array( $a, $b ) ) : '<div class="sn-2up sn-ev-pair">' . $a . $b . '</div>';
}

/** A stat tile; $chip is a painted basis chip or ''. */
function snt_ev_stat( $surface, $value, $label, $caption = '', $chip = '' ) {
	if ( 'kit' === $surface ) {
		return snt_kit_stat( $value, $label, $caption ) . $chip;
	}
	return '<div class="sn-kpi"><p class="sn-kpi-label">' . esc_html( $label ) . ' ' . $chip . '</p><p class="sn-kpi-value">' . esc_html( (string) $value ) . '</p>'
		. ( '' !== (string) $caption ? '<p class="sn-kpi-caption">' . esc_html( $caption ) . '</p>' : '' ) . '</div>';
}

/** A row of stat tiles. */
function snt_ev_tiles( $surface, array $tiles ) {
	return 'kit' === $surface ? snt_kit_grid( $tiles, 160, 10 ) : '<div class="sn-kpi-row sn-mr-kpi-row">' . implode( '', $tiles ) . '</div>';
}

/**
 * A table. Cells are text, or ['html' => ..., 'text' => ...] for a painted
 * cell (an evidence strip); $more > 0 adds the "+N more" line.
 *
 * @param string $surface kit|classic.
 * @param array  $columns key => heading.
 * @param array  $rows    Rows keyed like $columns.
 * @param int    $more    Rows not shown.
 * @param string $caption Classic caption (the box heading).
 * @return string
 */
function snt_ev_table( $surface, array $columns, array $rows, $more = 0, $caption = '' ) {
	$tail = $more > 0
		? '<p class="' . ( 'kit' === $surface ? 'snt-hint' : 'description' ) . '">' . esc_html( sprintf( /* translators: %s: rows not shown. */ __( '+%s more, the quiet end: the list is capped, not complete.', 'signal-and-noise-tools' ), number_format_i18n( (int) $more ) ) ) . '</p>'
		: '';
	if ( 'kit' === $surface ) {
		$cols = array();
		foreach ( $columns as $k => $label ) {
			$cols[] = array( 'key' => (string) $k, 'label' => (string) $label );
		}
		return snt_kit_table( $cols, $rows, array( 'stack_on_phone' => true ) ) . $tail;
	}
	$out = '<table class="widefat striped sn-an-table sn-ev-table"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
	foreach ( $columns as $label ) {
		$out .= '<th scope="col">' . esc_html( $label ) . '</th>';
	}
	$out .= '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$out .= '<tr>';
		foreach ( array_keys( $columns ) as $k ) {
			$cell = $r[ $k ] ?? '';
			$out .= '<td>' . ( is_array( $cell ) ? (string) $cell['html'] : esc_html( (string) $cell ) ) . '</td>';
		}
		$out .= '</tr>';
	}
	return $out . '</tbody></table>' . $tail;
}

/** A strip cell for snt_ev_table(): the strip, with its words for search and copy. */
function snt_ev_strip_cell( $split, $size = 'cell' ) {
	return array(
		'html' => snt_evidence_strip( $split, array( 'size' => $size ) ),
		'text' => is_array( $split ) ? number_format_i18n( (int) ( $split['verified'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $split['claimed'] ?? 0 ) ) : __( 'not measured', 'signal-and-noise-tools' ),
	);
}
