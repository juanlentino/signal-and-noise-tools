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
	// Some cards are ASYNC: snt_freshness_card() renders a neutral "Checking…"
	// and assets/freshness-dot.js finds it BY ID to fill in the live edge
	// result. v11.30.0 dropped the id here, so Caches read "Checking…" forever.
	// A card that carries an id is a card something else is going to write to.
	$id = (string) ( $card['id'] ?? '' );
	echo '<div class="sn-sys' . ( '' !== $state ? ' sn-sys--' . esc_attr( $state ) : '' ) . '"'
		. ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
	echo '<span class="sn-sys__k">' . esc_html( (string) ( $card['label'] ?? '' ) ) . '</span>';
	// An async card's value is REPLACED in place by its filler. freshness-dot.js
	// looks for `.sn-glance-card__value` inside the card it found by id — so
	// carrying the id without this class left "Checking…" on screen forever
	// while the JS appended its real verdict underneath. Declared only on cards
	// that actually have a filler, so the coupling is visible rather than
	// sprayed across every cell.
	$vclass = 'sn-sys__v' . ( '' !== $id ? ' sn-glance-card__value' : '' );
	if ( '' !== $href ) {
		echo '<a class="' . esc_attr( $vclass ) . '" href="' . esc_url( $href ) . '">' . esc_html( (string) ( $card['value'] ?? '' ) ) . '</a>';
	} else {
		echo '<span class="' . esc_attr( $vclass ) . '">' . esc_html( (string) ( $card['value'] ?? '' ) ) . '</span>';
	}
	// The state WORD, not just a tint. A cold probe paints no colour by design
	// (v11.16.0), so without this the reader has no way to tell "warming" from
	// "current" — the honesty of not alarming cost the fact itself.
	$pill_text = (string) ( $card['pill']['text'] ?? '' );
	if ( '' !== $pill_text && 'ok' !== $kind ) {
		echo '<span class="sn-sys__state">' . esc_html( $pill_text ) . '</span>';
	}

	// meta_html is built and ESCAPED by its source — snt_freshness_report_meta()
	// composes the "last purge" line that way. Re-escaping here would print the
	// tags. Dropping it, as v11.30.0 did, threw away a fact already computed.
	$meta = (string) ( $card['meta_html'] ?? '' );
	if ( '' !== $meta ) {
		echo '<span class="sn-sys__meta">';
		echo $meta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at build by the card's source; see snt_freshness_report_meta().
		echo '</span>';
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
	echo '<section class="sn-scr__systems">';
	echo '<header class="sn-card__head">';
	echo '<span class="sn-card__eyebrow">' . esc_html__( 'Systems', 'signal-and-noise-tools' ) . '</span>';
	// NAME THE PARTS, do not print a total. On v11.31.1 this header read
	// "11 reporting" while the verdict subline read "7 components" — both true
	// (11 is the fleet plus the checks) and irreconcilable from the screen,
	// because an opaque total cannot show that one number contains the other.
	// Stating the composition makes the subline's 7 visibly a part of this
	// card, and costs nothing: both sets already arrive here separately.
	// A set that is empty is omitted rather than printed as a zero — on a wall
	// whose rule is that anything visible matters, "0 checks" is noise.
	$parts = array();
	if ( ! empty( $checks ) ) {
		/* translators: %d health checks on the wall */
		$parts[] = sprintf( _n( '%d check', '%d checks', count( $checks ), 'signal-and-noise-tools' ), count( $checks ) );
	}
	if ( ! empty( $components ) ) {
		/* translators: %d fleet components on the wall */
		$parts[] = sprintf( _n( '%d component', '%d components', count( $components ), 'signal-and-noise-tools' ), count( $components ) );
	}
	echo '<span class="sn-card__meta">' . esc_html( implode( ' · ', $parts ) ) . '</span>';
	echo '</header>';
	echo '<div class="sn-scr__grid">';
	foreach ( $all as $card ) {
		if ( is_array( $card ) ) {
			sn_dash_render_system_cell( $card );
		}
	}
	echo '</div>';
	echo '</section>';
}
