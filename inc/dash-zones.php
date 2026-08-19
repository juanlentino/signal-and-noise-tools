<?php
/**
 * Signal & Noise — Dashboard zones: contract, state, renderer.
 *
 * A zone is a group of glance cards that answers one question. Its STATE decides
 * whether it takes space: `ok` collapses to a line, `attention` expands and leads,
 * `unknown` collapses but says it was never measured.
 *
 * `unknown` is derived FIRST on purpose. A zone holding an unmeasured probe and a
 * real warning reports unknown, because you cannot triage what you did not measure.
 *
 * @package SignalNoiseTools
 * @since 11.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The three zone states, most-urgent last-resort first. */
const SN_DASH_STATES = array( 'unknown', 'attention', 'ok' );

/**
 * Derive a zone's state from its cards. Pure.
 *
 * A card is UNMEASURED when it carries `'measured' => false`. That is the only
 * unknown signal — a card with value '0' and no `measured` key ran and returned
 * zero, which is measured. array_key_exists, never a falsy check.
 *
 * @param array<int,array<string,mixed>> $cards
 * @return string One of SN_DASH_STATES.
 */
function sn_dash_zone_state( array $cards ) {
	$unknown   = false;
	$attention = false;
	foreach ( $cards as $card ) {
		if ( ! is_array( $card ) ) {
			continue;
		}
		if ( array_key_exists( 'measured', $card ) && false === $card['measured'] ) {
			$unknown = true;
			continue;
		}
		$kind = isset( $card['pill']['kind'] ) ? (string) $card['pill']['kind'] : '';
		// Same opt-out the existing attention sort honours: a card may look amber
		// without asking to be promoted (a cold probe is unknown, not broken).
		// One rule, one copy — shared with the sort and the attention builder.
		$wants = sn_admin_card_wants_attention( $card );
		if ( $wants && ( 'err' === $kind || 'warn' === $kind ) ) {
			$attention = true;
		}
	}
	if ( $unknown ) {
		return 'unknown';
	}
	return $attention ? 'attention' : 'ok';
}

/**
 * Should this zone render expanded?
 *
 * An `attention` zone is ALWAYS open — a pin can force a zone open, never closed.
 * Pinning is a personal view preference and must not be able to hide a problem.
 * Pure.
 *
 * @param array<string,mixed> $zone
 * @param string[]            $pins Zone ids the current user has pinned open.
 * @return bool
 */
function sn_dash_zone_is_open( array $zone, array $pins ) {
	$state = isset( $zone['state'] ) ? (string) $zone['state'] : '';
	if ( 'attention' === $state ) {
		return true;
	}
	$id = isset( $zone['id'] ) ? (string) $zone['id'] : '';
	return '' !== $id && in_array( $id, $pins, true );
}

/**
 * Render one zone as a <details> block.
 *
 * The open state is server-rendered so the correct shape is present on first
 * paint with no flash. A collapsed zone does not call the grid helper at all —
 * there is no point building tiles nobody will see.
 *
 * @param array<string,mixed> $zone
 * @param string[]            $pins
 * @return void
 */
function sn_dash_render_zone( array $zone, array $pins = array() ) {
	$state   = isset( $zone['state'] ) ? (string) $zone['state'] : 'ok';
	$id      = isset( $zone['id'] ) ? (string) $zone['id'] : '';
	$summary = isset( $zone['summary'] ) ? (string) $zone['summary'] : '';
	$detail  = isset( $zone['detail'] ) ? (string) $zone['detail'] : '';
	$cards   = isset( $zone['cards'] ) && is_array( $zone['cards'] ) ? $zone['cards'] : array();
	$open    = sn_dash_zone_is_open( $zone, $pins );

	echo '<details class="sn-dash-zone sn-dash-zone--' . esc_attr( $state ) . '"'
		. ' data-zone="' . esc_attr( $id ) . '"' . ( $open ? ' open' : '' ) . '>';
	echo '<summary class="sn-dash-zone-summary">';
	echo '<span class="sn-dash-zone-label">' . esc_html( $summary ) . '</span>';
	if ( '' !== $detail ) {
		echo ' <span class="sn-dash-zone-detail">' . esc_html( $detail ) . '</span>';
	}
	echo '</summary>';
	// v11.28.0: a zone may fold pre-rendered markup in beside its cards — the
	// fleet zone carries the Recent deploys list this way. It is TRUSTED markup
	// built by the tab, never user input, so it is echoed unescaped; the zone's
	// own summary/detail/id are still escaped above.
	$body_html = isset( $zone['body_html'] ) ? (string) $zone['body_html'] : '';

	if ( $open && ( ! empty( $cards ) || '' !== $body_html ) ) {
		echo '<div class="sn-dash-zone-body">';
		if ( ! empty( $cards ) ) {
			sn_admin_glance_grid( sn_admin_glance_sort_by_attention( $cards ) );
		}
		if ( '' !== $body_html ) {
			echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tab-built markup, see above.
		}
		echo '</div>';
	}
	echo '</details>';
}
