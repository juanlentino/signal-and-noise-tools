<?php
/**
 * Signal & Noise Tools: the words for impostor reads, shared by every surface
 * that names them (the Reader evidence lead on both twins, the AI-training
 * finding, /stats). The desktop widget mirrors the same labels in JS
 * (assets/desktop-mode-widget-anchors.js). PURE.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What one impostor reason means, as a short clause after a count.
 *
 * @param string $reason cf_directory|vendor_ranges|off_home_network|rotator_network.
 * @return string
 */
function snt_mr_impostor_reason_label( $reason ) {
	switch ( (string) $reason ) {
		case 'cf_directory':
			return __( 'failed Cloudflare’s check', 'signal-and-noise-tools' );
		case 'vendor_ranges':
			return __( 'came from outside the vendor’s published IP ranges and network', 'signal-and-noise-tools' );
		case 'off_home_network':
			return __( 'came from outside the vendor’s own network', 'signal-and-noise-tools' );
		case 'rotator_network':
			return __( 'came from a network posing as many crawlers', 'signal-and-noise-tools' );
	}
	return __( 'failed a check', 'signal-and-noise-tools' );
}

/**
 * The per-reason breakdown as one clause: "1,378 failed Cloudflare’s check;
 * 461 came from outside the vendor’s own network". '' when there is none.
 *
 * @param array|null $reasons Reason => hits (snt_mr_impostor_reasons()).
 * @return string
 */
function snt_mr_impostor_breakdown( $reasons ) {
	$parts = array();
	foreach ( (array) $reasons as $reason => $hits ) {
		if ( (int) $hits > 0 ) {
			$parts[] = number_format_i18n( (int) $hits ) . ' ' . snt_mr_impostor_reason_label( (string) $reason );
		}
	}
	return implode( '; ', $parts );
}

/**
 * The impostor line: the total, then the breakdown when there is one.
 *
 * @param int        $total   Impostor reads.
 * @param array|null $reasons Reason => hits; null or [] for no breakdown (an older snapshot).
 * @param string     $credit  The closing clause ("counted in the total and credited to no vendor").
 * @return string
 */
function snt_mr_impostor_line( $total, $reasons, $credit ) {
	$why = snt_mr_impostor_breakdown( $reasons );
	/* translators: 1: impostor reads, 2: how they are counted. */
	$line = sprintf( __( 'Claimed a known crawler but failed a check: %1$s reads, %2$s', 'signal-and-noise-tools' ), number_format_i18n( (int) $total ), $credit );
	/* translators: %s: reads per reason. */
	return $line . ( '' === $why ? '.' : ' ' . sprintf( __( '(%s).', 'signal-and-noise-tools' ), $why ) );
}
