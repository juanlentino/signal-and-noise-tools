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
 * The legend: a swatch for each of the four parts the strip paints, once
 * per view, above the first strip.
 *
 * @param string|null $since SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
 * @return string
 */
function snt_evidence_legend( $since ) {
	$when  = null === $since ? __( 'an unknown date', 'signal-and-noise-tools' ) : gmdate( 'Y-m-d', (int) strtotime( (string) $since ) );
	$items = array(
		'verified_matched' => __( 'Verified by Cloudflare for its claimed purpose', 'signal-and-noise-tools' ),
		'verified'         => __( 'Verified, not for its claimed purpose', 'signal-and-noise-tools' ),
		'unverified'       => __( 'Claimed by user agent only', 'signal-and-noise-tools' ),
		/* translators: %s: the date the sensor began recording Cloudflare's verified-bot category. */
		'unverifiable'     => sprintf( __( 'Before verification was recorded (since %s)', 'signal-and-noise-tools' ), $when ),
	);
	$out = '';
	foreach ( $items as $part => $words ) {
		$out .= '<span class="snt-evidence-legend__item"><span class="snt-evidence__seg snt-evidence-legend__swatch" data-part="' . esc_attr( $part ) . '" aria-hidden="true"></span>' . esc_html( $words ) . '</span>';
	}
	return '<p class="snt-evidence-legend">' . $out . '</p>';
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
		return '<span class="sn-mr-chip"' . ( '' !== $tone ? ' data-tone="' . esc_attr( $tone ) . '"' : '' ) . '>' . esc_html( $word ) . '</span>';
	}
	return snt_kit_chip( $word, $tone );
}
