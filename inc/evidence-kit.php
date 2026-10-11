<?php
/**
 * Signal & Noise Tools: the evidence strip, its legend, and the basis chips.
 *
 * One visual grammar for "how is this count known", shared by the native
 * leaves (inside the kit) and their classic twins: proof fills, claims
 * outline. The strip is decorative (aria-hidden): the numbers always sit
 * beside it as text. CSS: apps/sn-analytics/sn-analytics.css (native) and
 * assets/analytics/analytics-admin.css (classic), both under `.snt-evidence`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strip's segments, in paint order: verified for its purpose (solid),
 * verified otherwise (accent outline over a half fill), claimed only
 * (hatched), before verification was recorded (dashed outline).
 *
 * @param array $split {claimed, verified, verified_matched, unverified, unverifiable}.
 * @return array<string,int> part => hits, zero parts dropped.
 */
function snt_evidence_parts( array $split ) {
	$parts = array(
		'verified_matched' => (int) ( $split['verified_matched'] ?? 0 ),
		'verified'         => max( 0, (int) ( $split['verified'] ?? 0 ) - (int) ( $split['verified_matched'] ?? 0 ) ),
		'unverified'       => (int) ( $split['unverified'] ?? 0 ),
		'unverifiable'     => (int) ( $split['unverifiable'] ?? 0 ),
	);
	return array_filter( $parts, static fn( $n ) => $n > 0 );
}

/**
 * The strip. A null split (not measured) paints no strip, only the words;
 * a split with nothing claimed paints an empty track.
 *
 * @param array|null $split A verification split.
 * @param array      $opts  size: cell (8px, default), hero (12px) or mini (6px).
 * @return string
 */
function snt_evidence_strip( $split, array $opts = array() ) {
	if ( ! is_array( $split ) ) {
		return '<span class="snt-evidence-none">' . esc_html__( 'not measured', 'signal-and-noise-tools' ) . '</span>';
	}
	$size   = in_array( $opts['size'] ?? '', array( 'hero', 'mini' ), true ) ? $opts['size'] : 'cell';
	$parts  = snt_evidence_parts( $split );
	$whole  = max( 1, array_sum( $parts ) );
	$inner  = '';
	foreach ( $parts as $part => $n ) {
		$inner .= '<span class="snt-evidence__seg" data-part="' . esc_attr( $part ) . '" style="--w:' . esc_attr( rtrim( rtrim( number_format( 100 * $n / $whole, 3, '.', '' ), '0' ), '.' ) ) . '%"></span>';
	}
	return '<span class="snt-evidence" data-size="' . esc_attr( $size ) . '" aria-hidden="true">' . $inner . '</span>';
}

/**
 * A chart's figures as a table only a screen reader reads (2026-10-11 review:
 * a line or a bar carries no numbers, and the old per-day tables did).
 *
 * @param string                $caption The chart's title.
 * @param array<int,string>     $days    Y-m-d, oldest first.
 * @param array<string,array>   $series  Column label => values, one per day.
 * @return string
 */
function snt_ev_sr_days_table( $caption, array $days, array $series ) {
	$head = '<th scope="col">' . esc_html__( 'Day', 'signal-and-noise-tools' ) . '</th>';
	foreach ( array_keys( $series ) as $label ) {
		$head .= '<th scope="col">' . esc_html( (string) $label ) . '</th>';
	}
	$rows = '';
	foreach ( array_values( $days ) as $i => $day ) {
		$rows .= '<tr><th scope="row">' . esc_html( (string) $day ) . '</th>';
		foreach ( $series as $values ) {
			$rows .= '<td>' . esc_html( number_format_i18n( (int) ( array_values( $values )[ $i ] ?? 0 ) ) ) . '</td>';
		}
		$rows .= '</tr>';
	}
	return '<table class="screen-reader-text"><caption>' . esc_html( (string) $caption ) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>';
}

/**
 * A basis chip: the basis word beside the figure it qualifies. No warning or
 * danger tone: a basis is not a finding.
 *
 * @param string $basis   One of SN_MR_BASIS_VALUES.
 * @param bool   $classic True for the classic twin's span; false for os-chip.
 * @return string
 */
function snt_basis_chip( $basis, $classic = false ) {
	$tones = array( 'signed' => 'success', 'verified' => 'info' );
	$basis = (string) $basis;
	$word  = 'unmeasured' === $basis ? __( 'not measured', 'signal-and-noise-tools' ) : $basis;
	$tone  = $tones[ $basis ] ?? '';
	if ( $classic ) {
		// Classic and S&N Analytics name the basis in the label instead (2026-10-11:
		// the light chip read as a pale box in the dark window).
		return '';
	}
	return snt_kit_chip( $word, $tone );
}
