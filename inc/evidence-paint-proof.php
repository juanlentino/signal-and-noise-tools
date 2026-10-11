<?php
/**
 * Signal & Noise Tools: how each count is known. Section painters for the
 * claim-against-proof readings, host-agnostic (data in, HTML out).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The split columns, the basis in each heading. */
function snt_ev_split_columns( $matched = false ) {
	$cols = array(
		'claimed'  => __( 'Claimed (user agent)', 'signal-and-noise-tools' ),
		'verified' => __( 'Verified (Cloudflare)', 'signal-and-noise-tools' ),
	);
	if ( $matched ) {
		$cols['matched'] = __( 'Verified for its purpose', 'signal-and-noise-tools' );
	}
	return $cols + array(
		'unverified'   => __( 'Unverified (claim only)', 'signal-and-noise-tools' ),
		'unverifiable' => __( 'Unverifiable (not measured)', 'signal-and-noise-tools' ),
		'strip'        => __( 'Evidence', 'signal-and-noise-tools' ),
	);
}

/** One split row: the label cell, the counts as text, the strip. */
function snt_ev_split_row( $key, $label, array $t, $matched = false ) {
	$row = array( $key => (string) $label, 'claimed' => number_format_i18n( (int) $t['claimed'] ), 'verified' => number_format_i18n( (int) $t['verified'] ) );
	if ( $matched ) {
		$row['matched'] = number_format_i18n( (int) $t['verified_matched'] );
	}
	return $row + array( 'unverified' => number_format_i18n( (int) $t['unverified'] ), 'unverifiable' => number_format_i18n( (int) $t['unverifiable'] ), 'strip' => snt_ev_strip_cell( $t ) );
}

/**
 * Claim against proof: two large tiles, the full-width strip, the legend
 * (once per leaf) and the since line.
 *
 * @param array       $t       snt_mr_claim_proof().
 * @param string|null $since   SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
 * @param string      $surface kit|classic.
 * @return string
 */
function snt_ev_paint_claim_proof( array $t, $since, $surface = 'kit' ) {
	$classic = 'classic' === $surface;
	$tiles   = snt_ev_tiles( $surface, array(
		snt_ev_stat( $surface, number_format_i18n( (int) $t['claimed'] ), __( 'Claimed', 'signal-and-noise-tools' ), __( 'reads credited to a vendor by their user agent', 'signal-and-noise-tools' ), snt_basis_chip( 'claimed', $classic ) ),
		/* translators: %s: verified reads that fit their claimed purpose. */
		snt_ev_stat( $surface, number_format_i18n( (int) $t['verified'] ), __( 'Verified by Cloudflare', 'signal-and-noise-tools' ), sprintf( __( '%s verified for the purpose they claimed', 'signal-and-noise-tools' ), number_format_i18n( (int) $t['verified_matched'] ) ), snt_basis_chip( 'verified', $classic ) ),
	) );
	$when = null === $since ? __( 'an unknown time', 'signal-and-noise-tools' ) : gmdate( 'Y-m-d H:i', (int) strtotime( (string) $since ) ) . ' UTC';
	/* translators: %s: when the sensor began recording Cloudflare's verified-bot category. */
	$caption = sprintf( __( 'Cloudflare’s verified-bot category, recorded since %s; reads before that are unverifiable.', 'signal-and-noise-tools' ), $when );
	return '<div class="snt-ev-hero">' . $tiles . snt_evidence_legend( $since ) . snt_evidence_strip( $t, array( 'size' => 'hero' ) ) . snt_ev_lead( $surface, $caption ) . '</div>';
}

/**
 * Two kinds of proof, one card each, and the line that tells them apart.
 *
 * @param array|null  $sig      snt_mr_identity_breakdown() (null: never measured).
 * @param array       $t        snt_mr_claim_proof().
 * @param string|null $since    SN_RIGHTS_EVIDENCE_VERIFIED_SINCE.
 * @param string      $surface  kit|classic.
 * @return string
 */
function snt_ev_paint_proofs( $sig, array $t, $since, $surface = 'kit' ) {
	$classic = 'classic' === $surface;
	$signed  = null === $sig
		? snt_ev_lead( $surface, __( 'Not measured: no read in this window carried a signature state.', 'signal-and-noise-tools' ) )
		: snt_ev_lead( $surface, sprintf(
			/* translators: 1: valid signatures, 2: reads carrying a signature state, 3: invalid, 4: unknown key, 5: top signers. */
			__( '%1$s of %2$s measured reads carried a valid Web Bot Auth signature; %3$s invalid, %4$s unknown key. Top signers: %5$s.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) $sig['valid'] ),
			number_format_i18n( (int) $sig['measured'] ),
			number_format_i18n( (int) $sig['invalid'] ),
			number_format_i18n( (int) $sig['unknown_key'] ),
			array() === $sig['by_agent'] ? __( 'none', 'signal-and-noise-tools' ) : implode( ', ', array_map( static fn( $a ) => $a['agent'] . ' ' . number_format_i18n( (int) $a['hits'] ), array_slice( $sig['by_agent'], 0, 3 ) ) )
		) );
	$verified = snt_ev_lead( $surface, sprintf(
		/* translators: 1: verified reads, 2: since. */
		__( '%1$s reads carried Cloudflare’s verified-bot category (source: cloudflare verified bot category, recorded since %2$s).', 'signal-and-noise-tools' ),
		number_format_i18n( (int) $t['verified'] ),
		null === $since ? __( 'an unknown time', 'signal-and-noise-tools' ) : gmdate( 'Y-m-d H:i', (int) strtotime( (string) $since ) ) . ' UTC'
	) );
	return snt_ev_pair(
		$surface,
		snt_ev_box( $surface, __( 'Signed', 'signal-and-noise-tools' ), snt_basis_chip( 'signed', $classic ) . $signed ),
		snt_ev_box( $surface, __( 'Verified', 'signal-and-noise-tools' ), snt_basis_chip( 'verified', $classic ) . $verified )
	) . snt_ev_lead( $surface, __( 'Signed is not verified: a signature proves who sent a request; Cloudflare’s category says it recognized the client.', 'signal-and-noise-tools' ) );
}

/**
 * By family: the split per family, ordered by claimed, led by the exceptions.
 *
 * @param array  $who     snt_mr_who_reads().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_family_split( array $who, $surface = 'kit' ) {
	$rows = array_map( static fn( $f ) => snt_ev_split_row( 'family', $f['family'], $f['split'] ), $who['rows'] );
	return snt_ev_box( $surface, __( 'By family', 'signal-and-noise-tools' ), snt_ev_lead( $surface, snt_mr_family_exceptions( $who ) ) . snt_ev_table( $surface, array( 'family' => __( 'Family', 'signal-and-noise-tools' ) ) + snt_ev_split_columns(), $rows, 0, __( 'By family', 'signal-and-noise-tools' ) ) );
}

/**
 * By purpose: the split per purpose (unknown reads unmapped), led by the
 * training line.
 *
 * @param array|null $purposes snt_mr_basis_display()['purposes'].
 * @param string     $surface  kit|classic.
 * @return string
 */
function snt_ev_paint_purpose_split( $purposes, $surface = 'kit' ) {
	$table = null === $purposes ? '' : snt_ev_table( $surface, array( 'purpose' => __( 'Purpose', 'signal-and-noise-tools' ) ) + snt_ev_split_columns( true ), array_map( static fn( $p ) => snt_ev_split_row( 'purpose', $p['label'], $p, true ), $purposes ), 0, __( 'By purpose', 'signal-and-noise-tools' ) );
	return snt_ev_box( $surface, __( 'By purpose', 'signal-and-noise-tools' ), snt_ev_lead( $surface, snt_mr_train_line( $purposes ) ) . $table );
}
