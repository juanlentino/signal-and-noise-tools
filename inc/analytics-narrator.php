<?php
/**
 * Signal & Noise — analytics narrator (diagnostic + prescriptive language).
 * Consumes (summary, Signal[]) → a short narrative. AI path wraps the WP AI
 * Client; a deterministic template is the guaranteed floor. Spec §5.2.
 * @package SignalNoiseTools @since 9.30.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Deterministic narrative from signals' plain_labels. Always available (the floor). */
function sn_analytics_narrate_fallback( $summary, $signals ) {
	if ( empty( $signals ) ) {
		return '<p class="sn-an-note">No standout signals in this window — nothing needs attention right now.</p>';
	}
	$items = array();
	foreach ( array_slice( $signals, 0, 4 ) as $s ) {
		$label = trim( (string) ( $s['plain_label'] ?? '' ) );
		if ( '' !== $label ) { $items[] = '<li>' . esc_html( $label ) . '</li>'; }
	}
	return '<ul class="sn-an-digest-list">' . implode( '', $items ) . '</ul>';
}
