<?php
/**
 * Signal & Noise Tools: the Machine readers reference as analytics panels
 * (2026-10-11 redesign: the spec-sheet bands read as plain tables on a wide
 * grid). The composer pairs them by height. Rows in, HTML out.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Agent and purpose pairs the panel shows before its "+N more" line. */
const SN_EV_PAIRS_ROWS = 15;

/**
 * The reference panels: observed against declared, surfaces, AI training
 * counted both ways (two tiles), agent and purpose pairs; with the row counts
 * the composer pairs them by.
 *
 * @param array  $rows    Aggregate rows.
 * @param string $surface kit|classic.
 * @return array{observed:string,surfaces:string,both:string,pairs:string,rows:array}
 */
function snt_ev_paint_reference( array $rows, $surface = 'kit' ) {
	$ai     = snt_mr_ai_training_families();
	$totals = array();
	$rights = array();
	$pairs  = array();
	foreach ( $rows as $r ) {
		if ( ! snt_mr_is_credited( $r ) ) {
			continue;
		}
		$fam  = (string) ( $r['family'] ?? '' );
		$hits = max( 0, (int) ( $r['hits'] ?? 0 ) );
		if ( in_array( $fam, $ai, true ) ) {
			$totals[ $fam ] = ( $totals[ $fam ] ?? 0 ) + $hits;
			$rights[ $fam ] = ( $rights[ $fam ] ?? 0 ) + ( 'rights' === (string) ( $r['surface'] ?? '' ) ? $hits : 0 );
		}
		if ( '' !== (string) ( $r['vendor'] ?? '' ) && empty( $r['first_party'] ) ) {
			$k           = $r['vendor'] . '|' . (string) ( $r['agent'] ?? '' ) . '|' . snt_mr_purpose_label( (string) ( $r['purpose'] ?? 'unknown' ) );
			$pairs[ $k ] = ( $pairs[ $k ] ?? 0 ) + $hits;
		}
	}
	arsort( $totals );
	arsort( $pairs );
	$compliance = array();
	foreach ( $totals as $f => $n ) {
		$compliance[] = array( 'family' => (string) $f, 'observed' => number_format_i18n( $n ), 'rights' => number_format_i18n( (int) $rights[ $f ] ) );
	}
	$pair_rows = array();
	foreach ( array_slice( $pairs, 0, SN_EV_PAIRS_ROWS, true ) as $k => $n ) {
		list( $vendor, $agent, $purpose ) = explode( '|', (string) $k, 3 );
		$pair_rows[] = array( 'vendor' => $vendor, 'agent' => '' !== $agent ? $agent : __( 'none', 'signal-and-noise-tools' ), 'purpose' => $purpose, 'reads' => number_format_i18n( $n ) );
	}
	$surfaces = array();
	foreach ( snt_mr_sum_hits_by( $rows, 'surface' ) as $s => $n ) {
		$surfaces[] = array( 'surface' => (string) $s, 'reads' => number_format_i18n( (int) $n ) );
	}
	$d       = snt_mr_basis_display( $rows );
	$by_p    = $d['ai'][0]['claimed'];
	$by_f    = (int) $d['ai'][1]['claimed'];
	$delta   = null === $by_p ? 0 : $by_f - (int) $by_p;
	$gap     = 0 === $delta ? '' : ( $delta > 0
		/* translators: %s: how much higher the family count is. */
		? sprintf( __( 'The family count is higher by %s: the frozen families also hold search, user-directed and unmapped agents of the same vendors. A published figure depends on the family field, so it is kept; cite the purpose count.', 'signal-and-noise-tools' ), number_format_i18n( $delta ) )
		/* translators: %s: how much higher the purpose count is. */
		: sprintf( __( 'The purpose count is higher by %s. Cite the purpose count.', 'signal-and-noise-tools' ), number_format_i18n( -$delta ) ) );
	$both = snt_ev_tiles( $surface, array(
		snt_ev_stat( $surface, snt_mr_basis_count( $by_p ), __( 'By declared purpose', 'signal-and-noise-tools' ), 'ai_training_by_purpose' ),
		snt_ev_stat( $surface, number_format_i18n( $by_f ), __( 'By crawler family, legacy', 'signal-and-noise-tools' ), 'ai_training' ),
	) );
	$cols_pairs = array( 'vendor' => __( 'Vendor', 'signal-and-noise-tools' ), 'agent' => __( 'Agent', 'signal-and-noise-tools' ), 'purpose' => __( 'Purpose', 'signal-and-noise-tools' ), 'reads' => __( 'Reads', 'signal-and-noise-tools' ) );
	return array(
		'observed' => snt_ev_box( $surface, __( 'Observed against declared', 'signal-and-noise-tools' ), snt_ev_lead( $surface, __( 'Read counts are what the edge observed; the AI-training class comes from public declarations. User agents are self-reported, so this is observation, not proof of identity.', 'signal-and-noise-tools' ) )
			. snt_ev_table( $surface, array( 'family' => __( 'Family', 'signal-and-noise-tools' ), 'observed' => __( 'Observed reads', 'signal-and-noise-tools' ), 'rights' => __( 'Rights reads', 'signal-and-noise-tools' ) ), $compliance, 0, __( 'Observed against declared', 'signal-and-noise-tools' ) ) ),
		'surfaces' => snt_ev_box( $surface, __( 'Surfaces', 'signal-and-noise-tools' ), snt_ev_table( $surface, array( 'surface' => __( 'Surface', 'signal-and-noise-tools' ), 'reads' => __( 'Reads', 'signal-and-noise-tools' ) ), $surfaces, 0, __( 'Surfaces', 'signal-and-noise-tools' ) ) ),
		'both'     => snt_ev_box( $surface, __( 'AI training, counted both ways', 'signal-and-noise-tools' ), $both . snt_ev_lead( $surface, $gap ) ),
		'pairs'    => snt_ev_box( $surface, __( 'Agent and purpose pairs', 'signal-and-noise-tools' ), snt_ev_table( $surface, $cols_pairs, $pair_rows, max( 0, count( $pairs ) - SN_EV_PAIRS_ROWS ), __( 'Agent and purpose pairs', 'signal-and-noise-tools' ) ) ),
		'rows'     => array( 'observed' => count( $compliance ) + 2, 'surfaces' => count( $surfaces ) ),
	);
}
