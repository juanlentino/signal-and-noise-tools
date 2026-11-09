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
	// The labels the classic twin uses; the kit has no date control, so its
	// text fields say the format.
	$fields = \snt_kit_field( 'text', 'from', __( 'Starts', 'signal-and-noise-tools' ), $f['from'], array( 'required' => true, 'placeholder' => 'YYYY-MM-DD', 'hint' => 'YYYY-MM-DD' ) )
		. \snt_kit_field( 'text', 'to', __( 'Ends (empty: runs on)', 'signal-and-noise-tools' ), $f['to'], array( 'placeholder' => 'YYYY-MM-DD', 'hint' => 'YYYY-MM-DD' ) );
	$boxes  = '';
	foreach ( \SN_CONTENT_QUEUE_WEEKDAY_NAMES as $n => $name ) {
		$boxes .= \snt_kit_field( 'checkbox', 'wd_' . $n, $name, in_array( $n, $f['days'], true ), array( 'value' => '1' ) );
	}
	// One named group, as the classic fieldset's legend (review: seven bare
	// boxes were announced with no group).
	$weekdays = __( 'Weekdays', 'signal-and-noise-tools' );
	$fields  .= \snt_kit_tag( 'os-field-row', array( 'label' => $weekdays ), \snt_kit_tag( 'os-cluster', array( 'gap' => '12', 'role' => 'group', 'aria-label' => $weekdays ), $boxes ) );
	$inner .= \snt_kit_form( 'queue_weekday_save', $fields, array( 'submit' => __( 'Save rule', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Weekday rules', 'signal-and-noise-tools' ), $inner, \sn_content_queue_weekday_help() );
}
