<?php
/**
 * S&N Dashboard: Integrity → Lanes, painted from the kit.
 *
 * The classic leaf (`sn_admin_render_lanes_section()`, inc/analytics-lanes-render.php)
 * and this one paint the same view model (`sn_lanes_view()`), which reads only
 * the lanes table: the invalid-token line first, the notes, then per sensor the
 * lanes for 7, 30 and 90 days, the reason split and the last fourteen days.
 * No folds: every table is the answer, not reference.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os; `lanes_view` is the test seam.
 * @return string
 */
function paint_tools_lanes( array $ctx ) {
	$view = is_array( $ctx['lanes_view'] ?? null ) ? $ctx['lanes_view'] : ( function_exists( 'sn_lanes_view' ) ? \sn_lanes_view( \sn_lanes_report_data() ) : null );
	if ( ! is_array( $view ) ) {
		return \snt_kit_empty( __( 'Lanes are not loaded', 'signal-and-noise-tools' ) );
	}
	$items = array( \snt_kit_notice( 'warning' === $view['alert']['kind'] ? 'warning' : 'info', '<strong>' . \snt_kit_esc( (string) $view['alert']['text'] ) . '</strong>' ) );
	$notes = '';
	foreach ( $view['notes'] as $note ) {
		$notes .= \snt_kit_tag( 'p', array(), \snt_kit_esc( (string) $note ) );
	}
	$items[] = \snt_kit_section( __( 'How to read the lanes', 'signal-and-noise-tools' ), $notes );
	foreach ( $view['tables'] as $t ) {
		$items[] = \snt_kit_section( (string) $t['heading'], \snt_kit_table( $t['columns'], $t['rows'], array( 'empty' => __( 'Nothing stored yet.', 'signal-and-noise-tools' ), 'class' => 'snt-table-full' ) ), (string) $t['description'] );
	}
	return \snt_kit_stack( implode( '', $items ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/lanes'] = __NAMESPACE__ . '\\paint_tools_lanes';
		return $painters;
	}
);
