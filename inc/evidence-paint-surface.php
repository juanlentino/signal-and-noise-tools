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
	return '<div class="sn-fieldset"><h3 class="sn-fieldset-h">' . esc_html( $heading ) . '</h3>' . $inner . '</div>';
}

/** The one-sentence finding a box leads with. */
function snt_ev_lead( $surface, $text ) {
	return '' === (string) $text ? '' : '<p class="' . ( 'kit' === $surface ? 'snt-hint' : 'sn-an-settings-help' ) . ' snt-ev-lead">' . esc_html( $text ) . '</p>';
}

/** A verdict that IS a finding (warning tone), as a notice on either surface. */
function snt_ev_verdict( $surface, $html ) {
	return 'kit' === $surface ? snt_kit_notice( 'warn', $html ) : wp_get_admin_notice( $html, array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
}

/**
 * Two boxes on one row (one column when narrow); a lone box stands alone. With
 * $rows (each box's row count), the pair shares a row only when the taller is
 * within 1.5x of the shorter; otherwise each stacks full width.
 */
function snt_ev_pair( $surface, $a, $b, $rows = null ) {
	$apart = is_array( $rows ) && 1.5 * max( 1, min( $rows ) ) < max( $rows );
	if ( '' === (string) $a || '' === (string) $b || $apart ) {
		return (string) $a . (string) $b;
	}
	return 'kit' === $surface ? snt_kit_grid( array( $a, $b ) ) : '<div class="sn-2up">' . $a . $b . '</div>';
}

/**
 * A stat tile as ONE element: the stat, its basis chip and any $extra (an
 * evidence strip) inside one wrapper, so a grid holds one child per tile.
 * Classic: the chip sits under the value, never in the label (the label's
 * nowrap, ellipsis and uppercase clip it).
 */
function snt_ev_stat( $surface, $value, $label, $caption = '', $chip = '', $extra = '' ) {
	if ( 'kit' === $surface ) {
		return '<div class="snt-ev-tile">' . snt_kit_stat( $value, $label, $caption ) . $chip . $extra . '</div>';
	}
	return '<div class="sn-kpi"><p class="sn-kpi-label">' . esc_html( $label ) . '</p><p class="sn-kpi-value">' . esc_html( (string) $value ) . '</p>'
		. ( '' !== (string) $caption ? '<p class="sn-kpi-caption">' . esc_html( $caption ) . '</p>' : '' )
		. ( '' !== (string) $chip ? '<p class="sn-kpi-basis">' . $chip . '</p>' : '' ) . $extra . '</div>';
}

/**
 * A row of stat tiles. Classic: up to four on a row, then four columns; an
 * odd count lets the first tile span two, so no row ends on a lone card.
 */
function snt_ev_tiles( $surface, array $tiles ) {
	if ( 'kit' === $surface ) {
		return snt_kit_grid( $tiles, 160, 10 );
	}
	$n     = count( $tiles );
	$class = 'sn-kpi-row sn-mr-kpi-row' . ( $n >= 5 ? ' sn-mr-kpi-row--wrap' : '' ) . ( 1 === $n % 2 && $n > 1 ? ' sn-mr-kpi-row--odd' : '' );
	return '<div class="' . $class . '" style="--sn-kpi-cols:' . (int) min( 4, max( 1, $n ) ) . '">' . implode( '', $tiles ) . '</div>';
}

/**
 * A table. Cells are text, or ['html' => ..., 'text' => ...] for a painted
 * cell (an evidence strip); $more > 0 adds the "+N more" line, which names the
 * cut end: the quiet end of a list ranked by volume, or the older end of a
 * list newest first. A kit column of counts aligns to the end.
 *
 * @param string $surface kit|classic.
 * @param array  $columns key => heading.
 * @param array  $rows    Rows keyed like $columns.
 * @param int    $more    Rows not shown.
 * @param string $caption Classic caption (the box heading).
 * @param string $cut     quiet|older: which end the cap cut.
 * @return string
 */
function snt_ev_table( $surface, array $columns, array $rows, $more = 0, $caption = '', $cut = 'quiet' ) {
	$tail = $more > 0
		? '<p class="' . ( 'kit' === $surface ? 'snt-hint' : 'description' ) . '">' . esc_html(
			'older' === $cut
				/* translators: %s: rows not shown. */
				? sprintf( __( '+%s more, older: the list is capped, not complete.', 'signal-and-noise-tools' ), number_format_i18n( (int) $more ) )
				/* translators: %s: rows not shown. */
				: sprintf( __( '+%s more, the quiet end: the list is capped, not complete.', 'signal-and-noise-tools' ), number_format_i18n( (int) $more ) )
		) . '</p>'
		: '';
	if ( 'kit' === $surface ) {
		$cols = array();
		foreach ( $columns as $k => $label ) {
			$cols[] = array( 'key' => (string) $k, 'label' => (string) $label ) + ( snt_ev_numeric_column( $rows, $k ) ? array( 'align' => 'end' ) : array() );
		}
		return snt_kit_table( $cols, $rows, array( 'stack_on_phone' => true ) ) . $tail;
	}
	$out = '<table class="widefat striped sn-an-table"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
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

/** A column of counts: every cell a number (or "not measured"), at least one a number. */
function snt_ev_numeric_column( array $rows, $key ) {
	$seen = false;
	foreach ( $rows as $r ) {
		$cell = $r[ $key ] ?? '';
		$none = (string) $cell === __( 'not measured', 'signal-and-noise-tools' );
		if ( is_array( $cell ) || ( ! $none && 1 !== preg_match( '/^\d[\d,.]*( \/ \d[\d,.]*)?$/', (string) $cell ) ) ) {
			return false;
		}
		$seen = $seen || ! $none;
	}
	return $seen;
}

/** A strip cell for snt_ev_table(): the strip, with its words for search and copy. */
function snt_ev_strip_cell( $split, $size = 'cell' ) {
	return array(
		'html' => snt_evidence_strip( $split, array( 'size' => $size ) ),
		'text' => is_array( $split ) ? number_format_i18n( (int) ( $split['verified'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $split['claimed'] ?? 0 ) ) : __( 'not measured', 'signal-and-noise-tools' ),
	);
}
