<?php
/**
 * S&N Home, Operations, the Systems section: one finding that names the
 * systems that need a look, their cards, and every other system as a chip in
 * a band (Not measured, then Holding). Unknown is never zero: a system with
 * no reading is not measured, never holding (2026-10-11, the fold it replaced
 * said only "N of M systems need a look").
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Which band a system card belongs to.
 *
 * @param array<string,mixed> $card A check or fleet card.
 * @return string look|unmeasured|holding
 */
function system_state( array $card ) {
	// A fleet card that reported no version, or whose probe is still warming
	// (the fleet zone's own placeholder value), has no reading yet.
	if ( false === ( $card['measured'] ?? true ) || __( 'warming…', 'signal-and-noise-tools' ) === (string) ( $card['value'] ?? '' ) ) {
		return 'unmeasured';
	}
	return ( 'ok' !== (string) ( $card['pill']['kind'] ?? 'ok' ) && \sn_admin_card_wants_attention( $card ) ) ? 'look' : 'holding';
}

/**
 * The Systems section: the finding, the cards that need a look, and the band
 * of every other system as chips (Not measured, then Holding).
 *
 * @param array<int,array<string,mixed>> $checks     Checks.
 * @param array<int,array<string,mixed>> $components Fleet cards.
 * @param string                         $tab        The painting tab.
 * @return string
 */
function systems_html( array $checks, array $components, $tab ) {
	$by = array( 'look' => array(), 'unmeasured' => array(), 'holding' => array() );
	foreach ( array_merge( array_values( $checks ), array_values( $components ) ) as $card ) {
		if ( is_array( $card ) ) {
			$by[ system_state( $card ) ][] = $card;
		}
	}
	$band = array();
	if ( $by['unmeasured'] ) {
		/* translators: %s: systems with no reading. */
		$band[] = array( 'label' => sprintf( __( 'Not measured (%s)', 'signal-and-noise-tools' ), number_format_i18n( count( $by['unmeasured'] ) ) ), 'value' => system_chips( $by['unmeasured'], false ), 'html' => true );
	}
	if ( $by['holding'] ) {
		/* translators: %s: systems holding. */
		$band[] = array( 'label' => sprintf( __( 'Holding (%s)', 'signal-and-noise-tools' ), number_format_i18n( count( $by['holding'] ) ) ), 'value' => system_chips( $by['holding'], true ), 'html' => true );
	}
	$parts = array();
	if ( ! empty( $checks ) ) {
		/* translators: %d health checks on the wall */
		$parts[] = sprintf( _n( '%d check', '%d checks', count( $checks ), 'signal-and-noise-tools' ), count( $checks ) );
	}
	if ( ! empty( $components ) ) {
		/* translators: %d fleet components on the wall */
		$parts[] = sprintf( _n( '%d component', '%d components', count( $components ), 'signal-and-noise-tools' ), count( $components ) );
	}
	return \snt_kit_section(
		__( 'Systems', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . systems_finding( $by ) . '</p>'
		. \snt_kit_grid( array_map( static fn( $c ) => system_cell( $c, $tab ), $by['look'] ), 190, 12 )
		. ( $band ? \snt_kit_kv( $band ) : '' ),
		implode( ' · ', $parts ),
		array( 'class' => 'snt-home__systems' )
	);
}

/**
 * The Systems finding, built from the three bands, escaped.
 *
 * @param array<string,array> $by Cards by state.
 * @return string
 */
function systems_finding( array $by ) {
	$total = count( $by['look'] ) + count( $by['unmeasured'] ) + count( $by['holding'] );
	$names = static fn( array $cards ) => and_list( array_map( static fn( $c ) => \snt_kit_esc( (string) ( $c['label'] ?? '' ) ), $cards ) );
	if ( 0 === $total ) {
		return \snt_kit_esc( __( 'No system has reported in, so none is measured.', 'signal-and-noise-tools' ) );
	}
	if ( $by['look'] ) {
		/* translators: 1: systems that need a look, 2: all systems, 3: their names. */
		$lead = sprintf( \snt_kit_esc( _n( '%1$s of %2$s systems needs a look: %3$s.', '%1$s of %2$s systems need a look: %3$s.', count( $by['look'] ), 'signal-and-noise-tools' ) ), number_format_i18n( count( $by['look'] ) ), number_format_i18n( $total ), $names( $by['look'] ) );
	} elseif ( $by['unmeasured'] ) {
		/* translators: 1: systems holding, 2: all systems. */
		$lead = sprintf( \snt_kit_esc( _n( '%1$s of %2$s systems is holding.', '%1$s of %2$s systems are holding.', count( $by['holding'] ), 'signal-and-noise-tools' ) ), number_format_i18n( count( $by['holding'] ) ), number_format_i18n( $total ) );
	} else {
		/* translators: %s: all systems. */
		$lead = sprintf( \snt_kit_esc( _n( '%s system is holding.', 'All %s systems are holding.', $total, 'signal-and-noise-tools' ) ), number_format_i18n( $total ) );
	}
	if ( ! $by['unmeasured'] ) {
		return $lead;
	}
	/* translators: %s: names of the systems with no reading. */
	return $lead . ' ' . sprintf( \snt_kit_esc( _n( '%s is not measured: no version reported yet.', '%s are not measured: no version reported yet.', count( $by['unmeasured'] ), 'signal-and-noise-tools' ) ), $names( $by['unmeasured'] ) );
}

/**
 * A cluster of chips, one per system: the label alone, or with its figures
 * (value and meta line) when the band shows readings.
 *
 * @param array<int,array<string,mixed>> $cards   Cards.
 * @param bool                           $figures Whether to carry the value and meta.
 * @return string
 */
function system_chips( array $cards, $figures ) {
	$chips = '';
	foreach ( $cards as $c ) {
		$text   = array( (string) ( $c['label'] ?? '' ) );
		$text[] = $figures ? (string) ( $c['value'] ?? '' ) : '';
		$text[] = $figures ? trim( html_entity_decode( strip_tags( (string) ( $c['meta_html'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) ) : '';
		$chips .= \snt_kit_chip( implode( ' · ', array_filter( $text, 'strlen' ) ) );
	}
	return \snt_kit_tag( 'os-cluster', array( 'gap' => '6' ), $chips );
}

/**
 * One system card: its label, its value (a go link to its leaf when it has
 * one), its pill and its meta line.
 *
 * @param array<string,mixed> $card A check or fleet card that needs a look.
 * @param string              $tab  The painting tab.
 * @return string
 */
function system_cell( array $card, $tab ) {
	$kind  = (string) ( $card['pill']['kind'] ?? 'ok' );
	$value = (string) ( $card['value'] ?? '' );
	$go    = go_target( (string) ( $card['href'] ?? '' ) );
	$pill  = (string) ( $card['pill']['text'] ?? '' );
	return '<div class="snt-sys snt-sys--' . \snt_kit_esc( $kind ) . '" data-tone="' . \snt_kit_tone( $kind ) . '">'
		. '<span class="snt-sys__k">' . \snt_kit_esc( (string) ( $card['label'] ?? '' ) ) . '</span>'
		. ( null !== $go ? \snt_kit_go( $value, $go + array( 'current' => $tab ), array( 'class' => 'snt-sys__v' ) ) : '<span class="snt-sys__v">' . \snt_kit_esc( $value ) . '</span>' )
		. ( '' !== $pill ? \snt_kit_badge( $kind, $pill ) : '' )
		. ( '' !== (string) ( $card['meta_html'] ?? '' ) ? '<span class="snt-sys__meta">' . (string) $card['meta_html'] . '</span>' : '' )
		. '</div>';
}
