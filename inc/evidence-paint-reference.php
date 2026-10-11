<?php
/**
 * Signal & Noise Tools: the Machine readers reference, as spec-sheet bands
 * (heading left, items two across, the /colophon pattern) instead of folds.
 * Host-agnostic: rows in, HTML out, either surface.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Agent and purpose pairs the band shows before its "+N more" line. */
const SN_EV_PAIRS_ROWS = 20;

/** A spec-sheet band: the heading on the left, the items two across. */
function snt_ev_band( $heading, array $items ) {
	$items = array_filter( array_map( 'strval', $items ), 'strlen' );
	if ( array() === $items ) {
		return '';
	}
	return '<section class="snt-ev-band"><h3 class="snt-ev-band__h">' . esc_html( $heading ) . '</h3><div class="snt-ev-band__items">'
		. implode( '', array_map( static fn( $i ) => '<div class="snt-ev-band__item">' . $i . '</div>', $items ) ) . '</div></section>';
}

/**
 * The reference bands: observed vs declared, AI-training counted both ways,
 * agent and purpose pairs, surfaces.
 *
 * @param array  $rows    Aggregate rows.
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_reference( array $rows, $surface = 'kit' ) {
	$ai     = snt_mr_ai_training_families();
	$totals = array();
	$rights = array();
	$pairs  = array();
	foreach ( $rows as $r ) {
		if ( ! is_array( $r ) || snt_mr_is_impersonation( $r ) ) {
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
	$both    = snt_ev_table( $surface, array( 'definition' => __( 'Definition', 'signal-and-noise-tools' ), 'reads' => __( 'Reads (claimed)', 'signal-and-noise-tools' ) ), array(
		array( 'definition' => __( 'By declared purpose (ai_training_by_purpose)', 'signal-and-noise-tools' ), 'reads' => snt_mr_basis_count( $by_p ) ),
		array( 'definition' => __( 'By crawler family, LEGACY (ai_training)', 'signal-and-noise-tools' ), 'reads' => number_format_i18n( $by_f ) ),
	), 0, __( 'AI-training reads, counted both ways', 'signal-and-noise-tools' ) );
	return snt_ev_band( __( 'Observed vs declared', 'signal-and-noise-tools' ), array(
		snt_ev_table( $surface, array( 'family' => __( 'Family', 'signal-and-noise-tools' ), 'observed' => __( 'Observed reads', 'signal-and-noise-tools' ), 'rights' => __( 'Rights reads', 'signal-and-noise-tools' ) ), $compliance, 0, __( 'Observed vs declared', 'signal-and-noise-tools' ) ),
		snt_ev_lead( $surface, __( 'Read counts are what the edge observed; the AI-training class comes from public declarations. User agents are self-reported, so this is observation, not proof of identity.', 'signal-and-noise-tools' ) ),
	) )
		. snt_ev_band( __( 'AI-training, counted both ways', 'signal-and-noise-tools' ), array( $both, snt_ev_lead( $surface, $gap ) ) )
		. snt_ev_band( __( 'Agent and purpose pairs', 'signal-and-noise-tools' ), array(
			snt_ev_table( $surface, array( 'vendor' => __( 'Vendor', 'signal-and-noise-tools' ), 'agent' => __( 'Agent', 'signal-and-noise-tools' ), 'purpose' => __( 'Purpose', 'signal-and-noise-tools' ), 'reads' => __( 'Reads', 'signal-and-noise-tools' ) ), $pair_rows, max( 0, count( $pairs ) - SN_EV_PAIRS_ROWS ), __( 'Agent and purpose pairs', 'signal-and-noise-tools' ) ),
		) )
		. snt_ev_band( __( 'Surfaces', 'signal-and-noise-tools' ), array(
			snt_ev_table( $surface, array( 'surface' => __( 'Surface', 'signal-and-noise-tools' ), 'reads' => __( 'Reads', 'signal-and-noise-tools' ) ), $surfaces, 0, __( 'Surfaces', 'signal-and-noise-tools' ) ),
		) );
}
