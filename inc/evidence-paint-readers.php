<?php
/**
 * Signal & Noise Tools: who reads, and for what. Section painters for the
 * Machine Readers question, host-agnostic (data in, HTML out, either surface).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hero's tile row: reads, readers, AI-training verified of claimed (with its strip),
 * signatures, and the figures beside them. "not measured" for a null figure.
 *
 * @param array  $h       snt_mr_hero_figures().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_hero_tiles( array $h, $surface = 'kit' ) {
	$classic = 'classic' === $surface;
	$days    = number_format_i18n( (int) $h['days'] );
	$ai      = $h['ai'];
	$id      = $h['identity'];
	$tiles   = array(
		/* translators: %s: window in days. */
		snt_ev_stat( $surface, number_format_i18n( (int) $h['total'] ), sprintf( __( 'machine reads, %sd', 'signal-and-noise-tools' ), $days ) ),
		/* translators: %s: reads from this site's own tools. */
		snt_ev_stat( $surface, number_format_i18n( (int) $h['readers'] ), __( 'readers, not this site’s tools', 'signal-and-noise-tools' ), sprintf( __( '%s this site’s own tools, not readership', 'signal-and-noise-tools' ), number_format_i18n( (int) $h['first_party'] ) ) ),
		// 2026-10-18: the credited figure that a check vouched for leads; the
		// claimed figure and the verified part by source are its caption.
		snt_ev_stat(
			$surface,
			is_array( $ai ) ? number_format_i18n( (int) $ai['verified'] ) : __( 'not measured', 'signal-and-noise-tools' ),
			__( 'AI-training reads, verified', 'signal-and-noise-tools' ),
			/* translators: 1: claimed reads, 2: verified by source. */
			is_array( $ai ) ? sprintf( __( 'of %1$s claimed; %2$s', 'signal-and-noise-tools' ), number_format_i18n( (int) $ai['claimed'] ), snt_mr_verified_by( (int) $ai['verified'], (int) ( $ai['vendor_verified'] ?? 0 ), ! empty( $h['vendor_measured'] ) ) ) : '',
			snt_basis_chip( 'verified', $classic ),
			is_array( $ai ) ? snt_evidence_strip( $ai, array( 'size' => 'hero' ) ) : ''
		),
		snt_ev_stat(
			$surface,
			is_array( $id ) && $id['measured'] > 0 ? number_format_i18n( (int) $id['valid'] ) . ' / ' . number_format_i18n( (int) $id['measured'] ) : __( 'not measured', 'signal-and-noise-tools' ),
			__( 'proved identity (Web Bot Auth)', 'signal-and-noise-tools' ),
			/* translators: 1: invalid signatures, 2: unknown keys. */
			is_array( $id ) && ( $id['invalid'] > 0 || $id['unknown_key'] > 0 ) ? sprintf( __( '%1$s invalid, %2$s unknown key', 'signal-and-noise-tools' ), number_format_i18n( (int) $id['invalid'] ), number_format_i18n( (int) $id['unknown_key'] ) ) : '',
			snt_basis_chip( 'signed', $classic )
		),
	);
	if ( null !== $h['feed_total'] ) {
		/* translators: %s: window in days. */
		$tiles[] = snt_ev_stat( $surface, number_format_i18n( (int) $h['feed_total'] ), sprintf( __( 'feed fetches, %sd', 'signal-and-noise-tools' ), $days ) );
	}
	if ( null !== $h['tool_calls'] ) {
		/* translators: %s: window in days. */
		$tiles[] = snt_ev_stat( $surface, number_format_i18n( (int) $h['tool_calls'] ), sprintf( __( 'agent tool calls, %sd', 'signal-and-noise-tools' ), $days ) );
	}
	if ( is_array( $id ) ) {
		/* translators: %s: window in days. */
		$tiles[] = snt_ev_stat( $surface, number_format_i18n( (int) $id['markdown'] ), sprintf( __( 'asked for markdown, %sd', 'signal-and-noise-tools' ), $days ) );
	}
	return snt_ev_tiles( $surface, $tiles );
}

/**
 * Who reads: family, reads, verified, the strip, last seen; this site's own
 * tools as one muted closing row, never a family.
 *
 * @param array  $who     snt_mr_who_reads().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_who_reads( array $who, $surface = 'kit' ) {
	$rows = array();
	foreach ( $who['rows'] as $f ) {
		$rows[] = array( 'family' => $f['family'], 'reads' => number_format_i18n( $f['split']['claimed'] ), 'verified' => number_format_i18n( $f['split']['verified'] ), 'strip' => snt_ev_strip_cell( $f['split'] ), 'last' => $f['last_seen'] );
	}
	$cols = array( 'family' => __( 'Family', 'signal-and-noise-tools' ), 'reads' => __( 'Reads (claimed)', 'signal-and-noise-tools' ), 'verified' => __( 'Verified', 'signal-and-noise-tools' ), 'strip' => __( 'Evidence', 'signal-and-noise-tools' ), 'last' => __( 'Last seen', 'signal-and-noise-tools' ) );
	/* translators: %s: reads from this site's own tools. */
	$own = '<p class="snt-ev-muted">' . esc_html( sprintf( __( 'This site’s own tools (not readership): %s', 'signal-and-noise-tools' ), number_format_i18n( (int) $who['first_party'] ) ) ) . '</p>';
	return snt_ev_box( $surface, __( 'Who reads', 'signal-and-noise-tools' ), snt_ev_lead( $surface, $who['lead'] ) . snt_ev_table( $surface, $cols, $rows, 0, __( 'Who reads', 'signal-and-noise-tools' ) ) . $own );
}

/**
 * What for: purpose (unknown reads unmapped), reads, verified, the strip.
 *
 * @param array  $what    snt_mr_what_for().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_what_for( array $what, $surface = 'kit' ) {
	$table = '';
	if ( null !== $what['rows'] ) {
		$rows = array();
		foreach ( $what['rows'] as $p ) {
			$rows[] = array( 'purpose' => $p['label'], 'reads' => number_format_i18n( (int) $p['claimed'] ), 'verified' => number_format_i18n( (int) $p['verified'] ), 'strip' => snt_ev_strip_cell( $p ) );
		}
		$table = snt_ev_table( $surface, array( 'purpose' => __( 'Purpose', 'signal-and-noise-tools' ), 'reads' => __( 'Reads (claimed)', 'signal-and-noise-tools' ), 'verified' => __( 'Verified', 'signal-and-noise-tools' ), 'strip' => __( 'Evidence', 'signal-and-noise-tools' ) ), $rows, 0, __( 'What for', 'signal-and-noise-tools' ) );
	}
	return snt_ev_box( $surface, __( 'What for', 'signal-and-noise-tools' ), snt_ev_lead( $surface, $what['lead'] ) . $table );
}
