<?php
/**
 * S&N Dashboard: Connections > Scheduled, the Weekday rules box (2026-11-09,
 * queue calendar phase 2, item 4). The classic twin says the same
 * (sn_admin_render_queue_weekdays()) and posts to the same handler
 * (sn_handle_queue_weekday_save()).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The stored rules in words, then the form, open on the latest-starting rule.
 *
 * @param string[] $rules Stored rules (sn_content_queue_weekday_rules()).
 * @return string
 */
function scheduled_content_weekdays_html( array $rules ) {
	$lines = \sn_content_queue_weekday_lines( $rules );
	$inner = array() === $lines
		? '<p class="snt-prose">' . \snt_kit_esc( \sn_content_queue_weekday_none() ) . '</p>'
		: \snt_kit_list( array_map( static fn( $l ) => array( 'label' => $l ), $lines ) );

	$f      = \sn_content_queue_weekday_form_rule( $rules );
	$fields = \snt_kit_field( 'text', 'from', __( 'Starts', 'signal-and-noise-tools' ), $f['from'], array( 'required' => true, 'placeholder' => '2027-01-04', 'hint' => __( 'A day, as YYYY-MM-DD.', 'signal-and-noise-tools' ) ) )
		. \snt_kit_field( 'text', 'to', __( 'Ends', 'signal-and-noise-tools' ), $f['to'], array( 'placeholder' => 'YYYY-MM-DD', 'hint' => __( 'Empty: the rule runs on.', 'signal-and-noise-tools' ) ) );
	foreach ( \SN_CONTENT_QUEUE_WEEKDAY_NAMES as $n => $name ) {
		$fields .= \snt_kit_field( 'checkbox', 'wd_' . $n, $name, in_array( $n, $f['days'], true ), array( 'value' => '1' ) );
	}
	$inner .= \snt_kit_form( 'queue_weekday_save', $fields, array( 'submit' => __( 'Save rule', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Weekday rules', 'signal-and-noise-tools' ), $inner, \sn_content_queue_weekday_help() );
}
