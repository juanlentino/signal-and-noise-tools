<?php
/**
 * Signal & Noise — Content Health check: near-duplicate cousins (v10.20.0).
 *
 * A thin adapter over the v10.16.0 ML cousin scan (snt_ml_cousin_pairs at
 * its default threshold): each pair of posts whose bodies are cousins —
 * near-identical without being byte-identical — becomes one finding. Riding
 * the 24h health scan is what makes this affordable everywhere downstream:
 * the count flows into the Health tab, the desktop health widget, and the
 * attention badge from the CACHED scan, so no admin pageload ever tokenizes
 * the corpus (the badge's never-computes contract holds).
 *
 * Zero-vs-null discipline: zero pairs is a CLEAN check (an empty scan is an
 * answer); a malformed envelope or row yields no finding rather than a
 * fabricated one.
 *
 * @package SignalNoiseTools
 * @since 10.20.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the cousin scan and pack it as a health check.
 *
 * @return array The sn_health_pack_check envelope.
 */
function sn_health_check_ml_cousins() {
	$label    = __( 'Near-duplicate cousins', 'signal-and-noise-tools' );
	$fix_hint = __( 'Two posts whose bodies rank as near-identical without being byte-identical — usually a duplicated-then-edited seed. Rework or retire one of the pair; byte-exact duplicates live in the duplicate-body scan instead.', 'signal-and-noise-tools' );

	// Defensive only: the kernel module loads unconditionally with the plugin,
	// so this branch is unreachable in practice — but a missing scanner must
	// read as "no findings", never fatal the whole health scan.
	if ( ! function_exists( 'snt_ml_cousin_pairs' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint );
	}

	$scan     = snt_ml_cousin_pairs( 0.6 );
	$findings = array();
	foreach ( (array) ( is_array( $scan ) ? ( $scan['pairs'] ?? array() ) : array() ) as $pair ) {
		if ( ! is_array( $pair ) || ! isset( $pair['a']['post_id'], $pair['b']['post_id'] ) ) {
			continue; // Malformed row: skip, never fabricate.
		}
		$a          = $pair['a'];
		$b          = $pair['b'];
		$findings[] = array(
			'subject_label' => (string) ( $a['title'] ?? '' ) . ' ↔ ' . (string) ( $b['title'] ?? '' ),
			'note'          => sprintf(
				/* translators: 1: cosine similarity, 2: first post status, 3: second post status. */
				__( 'cosine %1$s · %2$s / %3$s', 'signal-and-noise-tools' ),
				number_format( (float) ( $pair['cosine'] ?? 0 ), 4, '.', '' ),
				(string) ( $a['status'] ?? '' ),
				(string) ( $b['status'] ?? '' )
			),
			'edit_url'      => (string) get_edit_post_link( (int) $a['post_id'], 'raw' ),
		);
	}

	return sn_health_pack_check( $label, $findings, $fix_hint );
}
