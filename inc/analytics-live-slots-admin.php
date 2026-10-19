<?php
/**
 * Signal & Noise Tools: the past 24 hours under the admin Right now block.
 *
 * The stored snapshots (inc/analytics-live-slots.php) as a compact heat grid,
 * one column per hour and one cell per 5-minute slot, colored by its human
 * readers (no number in the cell), each stored cell a link that names
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
 * The past 24 hours: a heat grid (an hour per column, a 5-minute slot per
 * row, now at the right) and the chosen slot.
 *
 * @param array<string,mixed>|null $get The view params (null reads $_GET).
 * @param int|null                 $now Unix time (null: now).
 * @return string Escaped HTML.
 */
function sn_analytics_live_slots_html( $get = null, $now = null ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view param, validated by sn_analytics_live_slot_param().
	$get   = is_array( $get ) ? $get : (array) wp_unslash( $_GET );
	$now   = null === $now ? time() : (int) $now;
	$slots = function_exists( 'sn_analytics_live_slots' ) ? sn_analytics_live_slots( 24 ) : array();
	$pick  = sn_analytics_live_slot_param( $get['sn_slot'] ?? '' );
	$out   = '<div class="sn-live-admin__past"><h3 class="sn-live-admin__h" id="sn-live-heat-h">' . esc_html__( 'The last 24 hours', 'signal-and-noise-tools' ) . '</h3>'
		. '<p class="sn-live-admin__sub">' . esc_html__( 'human readers per 5 minutes; choose a slot to see its pages, sources and traffic classes', 'signal-and-noise-tools' ) . '</p>';
	if ( array() === $slots ) {
		return $out . '<p class="sn-live-admin__empty">' . esc_html__( 'No slots stored yet. A slot is kept for 24 hours once a refresh has seen it complete.', 'signal-and-noise-tools' ) . '</p></div>';
	}
	$by     = array();
	$max    = 0;
	$busy   = null;
	$filled = 0;
	$chosen = null;
	foreach ( $slots as $s ) {
		$t        = (int) $s['t'];
		$by[ $t ] = $s;
		$n        = (int) $s['readers'];
		$filled  += $n > 0 ? 1 : 0;
		$busy     = $n > $max ? $s : $busy;
		$max      = max( $max, $n );
		$chosen   = $pick === $t ? $s : $chosen;
	}
	$first = intdiv( $now, 3600 ) * 3600 - 23 * 3600;
	$grid  = '';
	$axis  = '';
	for ( $h = 0; $h < 24; $h++ ) {
		$hour = $first + $h * 3600;
		for ( $i = 0; $i < 12; $i++ ) {
			$t     = $hour + $i * SN_ANALYTICS_LIVE_HOUR_SLOT;
			$grid .= sn_analytics_live_heat_cell( $by[ $t ] ?? null, $t, $t + SN_ANALYTICS_LIVE_HOUR_SLOT > $now, $max, $pick, $get );
		}
		$text  = 23 === $h ? __( 'now', 'signal-and-noise-tools' ) : ( 0 === ( 23 - $h ) % 6 ? sn_analytics_live_slot_clock( $hour ) : '' );
		$axis .= '<span' . ( 23 === $h ? ' class="is-now"' : '' ) . '>' . esc_html( $text ) . '</span>';
	}
	$summary = null === $busy
		? __( 'No human readers in the stored slots of the last 24 hours.', 'signal-and-noise-tools' )
		/* translators: 1: busiest slot start, 2: its human readers, 3: slots with any. */
		: sprintf( _n( 'Busiest 5-minute slot: %1$s, %2$s human readers; %3$s non-empty slot in 24 hours', 'Busiest 5-minute slot: %1$s, %2$s human readers; %3$s non-empty slots in 24 hours', $filled, 'signal-and-noise-tools' ), sn_analytics_live_slot_clock( (int) $busy['t'] ), number_format_i18n( (int) $busy['readers'] ), number_format_i18n( $filled ) );
	$out .= '<div class="sn-live-admin__heatbox">'
		. '<div class="sn-live-admin__heat" role="group" aria-labelledby="sn-live-heat-h" aria-describedby="sn-live-heat-sum">' . $grid . '</div>'
		. '<div class="sn-live-admin__heatax" aria-hidden="true">' . $axis . '</div>'
		. '</div>'
		. sn_analytics_live_heat_legend( $max )
		. '<p class="sn-live-admin__sub" id="sn-live-heat-sum">' . esc_html( $summary ) . '</p>';
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
