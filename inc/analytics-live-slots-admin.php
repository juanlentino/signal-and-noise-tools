<?php
/**
 * Signal & Noise Tools: the past 24 hours under the admin Right now block.
 *
 * The stored snapshots (inc/analytics-live-slots.php) as a grid, one row per
 * hour and one cell per 5-minute slot, each stored cell a link that names
 * its slot (`sn_slot`). Following one paints that slot's class split, pages
 * and sources below the grid. No script: on the classic page the link is a
 * page load; in the native window the host rewrites an own-page link into a
 * `go`, and `sn_slot` is window state (apps/sn-analytics/parts/state.php).
 * The grid is painted when the page is, so it is as fresh as the page; the
 * live bars above it keep updating on their own.
 *
 * Everything is escaped here; the block that prints it is declared an
 * escaping function in phpcs.xml.dist.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The view params a slot link carries, so following it changes nothing else. */
const SN_ANALYTICS_LIVE_SLOTS_CARRY = array( 'sn_view', 'sn_range', 'sn_from', 'sn_to', 'sn_class', 'sn_compare', 'sn_drill', 'sn_event_prop', 'sn_lg_range' );

/**
 * A slot start from a raw param: a whole number on a 5-minute boundary, or 0. PURE.
 *
 * @param mixed $raw The `sn_slot` value.
 * @return int
 */
function sn_analytics_live_slot_param( $raw ) {
	$raw = is_scalar( $raw ) ? (string) $raw : '';
	return ( 1 === preg_match( '/^\d{9,11}$/', $raw ) && 0 === (int) $raw % SN_ANALYTICS_LIVE_HOUR_SLOT ) ? (int) $raw : 0;
}

/**
 * A slot's clock time on the site's clock. PURE given wp_date().
 *
 * @param int $t Unix time.
 * @return string
 */
function sn_analytics_live_slot_clock( $t ) {
	return function_exists( 'wp_date' ) ? (string) wp_date( 'H:i', (int) $t ) : gmdate( 'H:i', (int) $t );
}

/**
 * The link for one slot, carrying the view params it was painted under.
 *
 * @param array<string,mixed> $get The request's view params.
 * @param int                 $t   Slot start.
 * @return string URL.
 */
function sn_analytics_live_slot_url( array $get, $t ) {
	$args = array();
	foreach ( SN_ANALYTICS_LIVE_SLOTS_CARRY as $key ) {
		if ( isset( $get[ $key ] ) && is_scalar( $get[ $key ] ) && '' !== (string) $get[ $key ] ) {
			$args[ $key ] = rawurlencode( (string) $get[ $key ] );
		}
	}
	$args['sn_slot'] = (string) (int) $t;
	return function_exists( 'snt_analytics_page_url' ) ? (string) snt_analytics_page_url( $args ) : '?' . http_build_query( $args );
}

/**
 * One server-filled list in the live block's own markup.
 *
 * @param string $heading Column heading.
 * @param array  $items   { label, url?, readers }.
 * @param string $empty   Text for an empty list.
 * @return string
 */
function sn_analytics_live_slot_list_html( $heading, array $items, $empty ) {
	$li = '';
	foreach ( $items as $p ) {
		$name = isset( $p['url'] ) && '' !== (string) $p['url'] ? '<a href="' . esc_url( (string) $p['url'] ) . '">' . esc_html( (string) $p['label'] ) . '</a>' : '<span>' . esc_html( (string) $p['label'] ) . '</span>';
		$li  .= '<li>' . $name . '<span class="sn-live-admin__n">' . esc_html( number_format_i18n( (int) $p['readers'] ) ) . '</span></li>';
	}
	return '<div class="sn-live-admin__col"><h4 class="sn-live-admin__h">' . esc_html( $heading ) . '</h4><ol class="sn-live-admin__list">'
		. ( '' !== $li ? $li : '<li class="sn-live-admin__empty">' . esc_html( $empty ) . '</li>' ) . '</ol></div>';
}

/**
 * The past 24 hours: the grid and the chosen slot.
 *
 * @param array<string,mixed>|null $get The view params (null reads $_GET).
 * @return string Escaped HTML.
 */
function sn_analytics_live_slots_html( $get = null ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view param, validated by sn_analytics_live_slot_param().
	$get   = is_array( $get ) ? $get : (array) wp_unslash( $_GET );
	$slots = function_exists( 'sn_analytics_live_slots' ) ? sn_analytics_live_slots( 24 ) : array();
	$pick  = sn_analytics_live_slot_param( $get['sn_slot'] ?? '' );
	$out   = '<div class="sn-live-admin__past"><h3 class="sn-live-admin__h">' . esc_html__( 'The last 24 hours', 'signal-and-noise-tools' ) . '</h3>'
		. '<p class="sn-live-admin__sub">' . esc_html__( 'human readers per 5 minutes; choose a slot to see its pages, sources and traffic classes', 'signal-and-noise-tools' ) . '</p>';
	if ( array() === $slots ) {
		return $out . '<p class="sn-live-admin__empty">' . esc_html__( 'No slots stored yet. A slot is kept for 24 hours once a refresh has seen it complete.', 'signal-and-noise-tools' ) . '</p></div>';
	}
	$by = array();
	foreach ( $slots as $s ) {
		$by[ intdiv( (int) $s['t'], 3600 ) * 3600 ][ (int) ( ( (int) $s['t'] % 3600 ) / SN_ANALYTICS_LIVE_HOUR_SLOT ) ] = $s;
	}
	krsort( $by );
	$grid   = '';
	$chosen = null;
	foreach ( $by as $hour => $cells ) {
		$grid .= '<div class="sn-live-admin__row"><span class="sn-live-admin__hr">' . esc_html( sn_analytics_live_slot_clock( $hour ) ) . '</span>';
		for ( $i = 0; $i < 12; $i++ ) {
			$s = $cells[ $i ] ?? null;
			if ( null === $s ) {
				$grid .= '<span class="sn-live-admin__cell is-missing" aria-hidden="true"></span>';
				continue;
			}
			$on     = $pick === (int) $s['t'];
			$chosen = $on ? $s : $chosen;
			/* translators: 1: slot start time, 2: human readers. */
			$label  = sprintf( __( '%1$s, %2$s human readers', 'signal-and-noise-tools' ), sn_analytics_live_slot_clock( (int) $s['t'] ), number_format_i18n( (int) $s['readers'] ) );
			$grid  .= '<a class="sn-live-admin__cell' . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( sn_analytics_live_slot_url( $get, (int) $s['t'] ) ) . '" aria-label="' . esc_attr( $label ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '>' . esc_html( number_format_i18n( (int) $s['readers'] ) ) . '</a>';
		}
		$grid .= '</div>';
	}
	$out .= '<div class="sn-live-admin__grid">' . $grid . '</div>';
	return $out . sn_analytics_live_slot_detail_html( $chosen, $pick ) . '</div>';
}

/**
 * The chosen slot: its class split, pages and sources.
 *
 * @param array|null $s    The stored slot, or null.
 * @param int        $pick The slot asked for (0: none).
 * @return string
 */
function sn_analytics_live_slot_detail_html( $s, $pick ) {
	if ( ! is_array( $s ) ) {
		return 0 === $pick ? '' : '<p class="sn-live-admin__empty">' . esc_html__( 'That slot is not stored: slots are kept for 24 hours.', 'signal-and-noise-tools' ) . '</p>';
	}
	$c = (array) ( $s['classes'] ?? array() );
	/* translators: 1: slot start, 2: slot end. */
	$head = sprintf( __( '%1$s to %2$s', 'signal-and-noise-tools' ), sn_analytics_live_slot_clock( (int) $s['t'] ), sn_analytics_live_slot_clock( (int) $s['t'] + SN_ANALYTICS_LIVE_HOUR_SLOT ) );
	/* translators: 1: human, 2: suspect, 3: bot readers. */
	$split = sprintf( __( 'Human %1$s · Suspect %2$s · Bot %3$s', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $c['human'] ?? 0 ) ), number_format_i18n( (int) ( $c['suspect'] ?? 0 ) ), number_format_i18n( (int) ( $c['bot'] ?? 0 ) ) );
	return '<div class="sn-live-admin__slot"><h4 class="sn-live-admin__h">' . esc_html( $head ) . '</h4><p class="sn-live-admin__sub">' . esc_html( $split ) . '</p>'
		. '<div class="sn-live-admin__cols">'
		. sn_analytics_live_slot_list_html( __( 'Being read', 'signal-and-noise-tools' ), (array) ( $s['pages'] ?? array() ), __( 'Nobody on a published page.', 'signal-and-noise-tools' ) )
		. sn_analytics_live_slot_list_html( __( 'Arrived from', 'signal-and-noise-tools' ), (array) ( $s['sources'] ?? array() ), __( 'No arrivals.', 'signal-and-noise-tools' ) )
		. '</div></div>';
}
