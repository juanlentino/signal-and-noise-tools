<?php
/**
 * Signal & Noise Tools: the cells, color steps and legend of the past-24-hours
 * heat grid (inc/analytics-live-slots-admin.php paints the grid around them).
 *
 * A stored slot is a link to its own detail with no number inside; its color
 * step is a class, scaled to the window's busiest slot. A slot never recorded
 * and a slot still to come are inert and are never a zero.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A count's color step against the window's max: 0 is empty, then up to three
 * steps, never more steps than the max has readers. PURE.
 *
 * @param int $n   Human readers in the slot.
 * @param int $max The window's busiest slot.
 * @return int 0 to 3.
 */
function sn_analytics_live_heat_step( $n, $max ) {
	$n   = (int) $n;
	$max = max( 1, (int) $max );
	return $n <= 0 ? 0 : min( 3, (int) ceil( min( 3, $max ) * min( $n, $max ) / $max ) );
}

/**
 * The real steps under a max, for the legend: { step, label }. PURE.
 *
 * @param int $max The window's busiest slot.
 * @return array<int,array{step:int,label:string}>
 */
function sn_analytics_live_heat_steps( $max ) {
	$max = max( 1, (int) $max );
	$k   = min( 3, $max );
	$out = array();
	for ( $l = 1; $l <= $k; $l++ ) {
		$lo    = intdiv( ( $l - 1 ) * $max, $k ) + 1;
		$hi    = intdiv( $l * $max, $k );
		$out[] = array(
			'step'  => $l,
			'label' => $lo === $hi ? (string) $lo : $lo . '-' . $hi,
		);
	}
	return $out;
}

/**
 * One 5-minute cell: a link for a stored slot, an inert mark otherwise.
 *
 * @param array|null          $s    The stored slot, or null.
 * @param int                 $t    Slot start.
 * @param bool                $soon The slot has not finished yet.
 * @param int                 $max  The window's busiest slot.
 * @param int                 $pick The chosen slot.
 * @param array<string,mixed> $get  The view params.
 * @return string
 */
function sn_analytics_live_heat_cell( $s, $t, $soon, $max, $pick, array $get ) {
	if ( $soon ) {
		return '<span class="sn-live-admin__cell is-future" aria-hidden="true"></span>';
	}
	/* translators: 1: slot start, 2: slot end. */
	$range = sprintf( __( '%1$s to %2$s', 'signal-and-noise-tools' ), sn_analytics_live_slot_clock( $t ), sn_analytics_live_slot_clock( $t + SN_ANALYTICS_LIVE_HOUR_SLOT ) );
	if ( null === $s ) {
		/* translators: %s: slot time range. */
		return '<span class="sn-live-admin__cell is-missing" aria-hidden="true" data-hint="' . esc_attr( sprintf( __( '%s, not recorded', 'signal-and-noise-tools' ), $range ) ) . '"></span>';
	}
	$n     = (int) $s['readers'];
	$on    = $pick === $t;
	/* translators: 1: slot time range, 2: human readers. */
	$label = sprintf( _n( '%1$s, %2$s human reader', '%1$s, %2$s human readers', $n, 'signal-and-noise-tools' ), $range, number_format_i18n( $n ) );
	return '<a class="sn-live-admin__cell is-step-' . sn_analytics_live_heat_step( $n, $max ) . ( $on ? ' is-on' : '' ) . '" href="' . esc_url( sn_analytics_live_slot_url( $get, $t ) ) . '" aria-label="' . esc_attr( $label ) . '" data-hint="' . esc_attr( $label ) . '"' . ( $on ? ' aria-current="true"' : '' ) . '></a>';
}

/**
 * The legend: empty, the real steps, not recorded.
 *
 * @param int $max The window's busiest slot.
 * @return string
 */
function sn_analytics_live_heat_legend( $max ) {
	$key = '<span class="sn-live-admin__key"><i class="sn-live-admin__swatch is-step-0"></i>0</span>';
	foreach ( ( $max > 0 ? sn_analytics_live_heat_steps( $max ) : array() ) as $step ) {
		$key .= '<span class="sn-live-admin__key"><i class="sn-live-admin__swatch is-step-' . (int) $step['step'] . '"></i>' . esc_html( $step['label'] ) . '</span>';
	}
	$key .= '<span class="sn-live-admin__key"><i class="sn-live-admin__swatch is-missing"></i>' . esc_html__( 'not recorded', 'signal-and-noise-tools' ) . '</span>';
	return '<p class="sn-live-admin__legend" aria-hidden="true">' . $key . '</p>';
}
