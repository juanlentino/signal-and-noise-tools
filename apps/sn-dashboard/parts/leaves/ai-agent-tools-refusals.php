<?php
/**
 * S&N Dashboard: AI › Agent tools, the refusals, counted. Every write a door
 * declined, door × rule × count over 7 and 30 days, busiest first. The words
 * are the classic block's (inc/admin-forms/door-refusals-block.php).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The refusals section, or the sentence that says why there is none.
 *
 * @param array|null $read From sn_door_refusals_read(); null reads it here.
 * @return string
 */
function agent_tools_refusals_html( $read = null ) {
	$heading = __( 'Refusals at the write doors', 'signal-and-noise-tools' );
	$about   = __( 'Every write a door declined, set against the rule that declined it.', 'signal-and-noise-tools' );
	if ( ! function_exists( 'sn_door_refusals_read' ) || ! function_exists( 'sn_admin_door_refusals_rows' ) ) {
		return \snt_kit_section( $heading, '<p class="snt-hint">' . \snt_kit_esc( __( 'Refusals are not counted here: the module is not loaded on this site.', 'signal-and-noise-tools' ) ) . '</p>', $about );
	}
	$read    = null === $read ? \sn_door_refusals_read() : $read;
	$warn    = \sn_admin_door_refusals_unreadable( $read );
	$rows    = \sn_admin_door_refusals_rows( $read );
	$labels  = \sn_admin_door_refusals_columns();
	$columns = array(
		array( 'key' => 'door', 'label' => $labels['door'] ),
		array( 'key' => 'rule', 'label' => $labels['rule'] ),
		array( 'key' => 'd7', 'label' => $labels['d7'], 'align' => 'end' ),
		array( 'key' => 'd30', 'label' => $labels['d30'], 'align' => 'end' ),
	);
	$inner = ( '' !== $warn ? \snt_kit_notice( 'warning', \snt_kit_esc( $warn ) ) : '' )
		. '<p class="snt-prose">' . \snt_kit_esc( \sn_admin_door_refusals_finding( $read ) ) . '</p>'
		. ( $rows ? \snt_kit_table( $columns, $rows ) : '' )
		. '<p class="snt-hint">' . \snt_kit_esc( \sn_admin_door_refusals_sources() ) . '</p>';
	return \snt_kit_section( $heading, $inner, $about );
}
