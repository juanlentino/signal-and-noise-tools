<?php
/**
 * Signal & Noise — the systems grid.
 *
 * MONOCHROME FIRST (Few). Every check and every component holds a fixed cell,
 * always readable, and a healthy grid carries NO colour at all. v11.29.1 painted
 * a green dot on all seventeen rows, which made green mean nothing and left
 * amber competing with a field of colour on the day it mattered.
 *
 * Cells are separated by hairlines rather than drawn boxes: at seventeen cells
 * a border each is seventeen rectangles of non-data pixels arguing for the same
 * attention. Grouping is what the rules are for.
 *
 * @package SignalNoiseTools
 * @since 11.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render one labelled cell.
 *
 * @since 11.30.0
 * @param array<string,mixed> $card A glance card.
 * @return void
 */
function sn_dash_render_system_cell( array $card ) {
	$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : 'ok';

	// v11.16.0: cold is not broken. A probe that has not reported paints no
	// state — the same predicate the verdict and the zone state use, so the
	// grid cannot disagree with the headline above it.
	$state = ( 'ok' !== $kind && sn_admin_card_wants_attention( $card ) ) ? $kind : '';

	$href = (string) ( $card['href'] ?? '' );
	echo '<div class="sn-sys' . ( '' !== $state ? ' sn-sys--' . esc_attr( $state ) : '' ) . '">';
	echo '<span class="sn-sys__k">' . esc_html( (string) ( $card['label'] ?? '' ) ) . '</span>';
	if ( '' !== $href ) {
		echo '<a class="sn-sys__v" href="' . esc_url( $href ) . '">' . esc_html( (string) ( $card['value'] ?? '' ) ) . '</a>';
	} else {
		echo '<span class="sn-sys__v">' . esc_html( (string) ( $card['value'] ?? '' ) ) . '</span>';
	}
	echo '</div>';
}

/**
 * Render the grid: checks first, then the fleet, in one continuous field.
 *
 * Deliberately NOT two labelled sections. The reader learns positions, and a
 * heading between them would spend a row saying something the ordering already
 * says.
 *
 * @since 11.30.0
 * @param array<int,array<string,mixed>> $checks
 * @param array<int,array<string,mixed>> $components
 * @return void
 */
function sn_dash_render_systems( array $checks, array $components ) {
	$all = array_merge( array_values( $checks ), array_values( $components ) );
	if ( empty( $all ) ) {
		return;
	}
	echo '<div class="sn-scr__systems">';
	foreach ( $all as $card ) {
		if ( is_array( $card ) ) {
			sn_dash_render_system_cell( $card );
		}
	}
	echo '</div>';
}
