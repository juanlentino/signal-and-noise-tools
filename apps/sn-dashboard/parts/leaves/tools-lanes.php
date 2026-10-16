<?php
/**
 * S&N Dashboard: Integrity → Lanes, painted from the kit.
 *
 * The classic leaf (`sn_admin_render_lanes_section()`, inc/analytics-lanes-render.php)
 * and this one paint the same view model (`sn_lanes_view()`), which reads only
 * the lanes table: the invalid-token line first, the notes, then per sensor the
 * lanes for 7, 30 and 90 days, the reason split and the last fourteen days.
 * Earned folds (owner rule 2026-10-12): the by-lane tables and the notes are
 * the answer and paint open; the reason split and the daily view (detail
 * behind a counted summary) and how a lane is decided (reference) fold, each
 * summary stating its answer closed.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The leaf's one fold: detail or reference, closed, its summary the answer.
 *
 * @param string $heading Heading.
 * @param string $summary What the closed fold says.
 * @param string $inner   Painted content.
 * @return string
 */
function lanes_fold( $heading, $summary, $inner ) {
	return \snt_kit_fold( $heading, $summary, $inner );
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
		$notes .= \snt_kit_tag( 'p', array( 'class' => 'snt-prose' ), \snt_kit_esc( (string) $note ) );
	}
	// One stacked block of margin-free prose: as bare paragraphs each line
	// took a full section gap plus its margins (live 2026-10-16, 160px for four lines).
	$items[] = \snt_kit_section( __( 'Where the lanes stand', 'signal-and-noise-tools' ), \snt_kit_stack( $notes, 6 ) );
	foreach ( $view['tables'] as $t ) {
		$table = \snt_kit_table( $t['columns'], $t['rows'], array( 'empty' => __( 'Nothing stored yet.', 'signal-and-noise-tools' ), 'class' => 'snt-table-full' ) );
		$items[] = '' === (string) ( $t['fold'] ?? '' )
			? \snt_kit_section( (string) $t['heading'], $table, (string) $t['description'] )
			: lanes_fold( (string) $t['heading'], (string) $t['fold'], \snt_kit_tag( 'p', array( 'class' => 'snt-hint' ), \snt_kit_esc( (string) $t['description'] ) ) . $table );
	}
	$ref = '';
	foreach ( $view['reference']['lines'] as $line ) {
		$ref .= \snt_kit_tag( 'p', array( 'class' => 'snt-prose' ), \snt_kit_esc( (string) $line ) );
	}
	$items[] = lanes_fold( __( 'How the lanes are decided', 'signal-and-noise-tools' ), (string) $view['reference']['summary'], $ref );
	return \snt_kit_stack( implode( '', $items ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['tools/lanes'] = __NAMESPACE__ . '\\paint_tools_lanes';
		return $painters;
	}
);
