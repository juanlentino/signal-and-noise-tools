<?php
/**
 * S&N Dashboard: the native twin of inc/admin-forms/mcp-door-switches.php.
 * Each door's switch, first in that door's box on Settings > AI; the
 * related-notes ranking, a field of the Reading box on Settings > Front end;
 * the Write-door alerts box on Settings > Notifications and reports. Same field
 * names, actions and words (snt_switches_text()), so the two twins cannot
 * drift.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One door's switch, its own form, for that door's box. '' when the switch
 * module is not loaded.
 *
 * @param string $door `read` or `rw`.
 * @return string
 */
function switches_door_html( $door ) {
	if ( ! function_exists( 'snt_switches_text' ) ) {
		return '';
	}
	$t      = \snt_switches_text();
	$locked = \snt_mcp_door_locked( $door );
	$field  = \snt_kit_field( 'checkbox', 'sn_' . $door . '_door_enabled', $t[ $door . '_label' ], ! $locked && \snt_mcp_door_switch_on( $door ), array( 'value' => '1', 'disabled' => $locked, 'hint' => $locked ? \snt_switches_locked_text( $door ) : $t[ $door . '_hint' ] ) );
	return \snt_kit_form( 'mcp_doors_save', $field, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ), 'hidden' => array( 'sn_door' => $door ) ) );
}

/**
 * The ranking select, a field of the Front end pane's save_theme form.
 *
 * @return string
 */
function switches_related_field_html() {
	if ( ! function_exists( 'snt_switches_text' ) ) {
		return '';
	}
	$t = \snt_switches_text();
	return \snt_kit_field( 'select', 'sn_related_source', $t['related_lbl'], (string) \sn_setting( 'ml.related_source', 'embeddings' ), array( 'options' => array( 'embeddings' => $t['emb'], 'tfidf' => $t['tfidf'] ), 'hint' => $t['related_hint'] ) );
}

/**
 * The Write-door alerts box.
 *
 * @return string
 */
function switches_notify_html() {
	$t     = \snt_switches_text();
	$field = \snt_kit_field( 'checkbox', 'sn_rw_notify', $t['notify_label'], (bool) get_option( 'sn_mcp_rw_notify', false ), array( 'value' => '1', 'hint' => $t['notify_hint'] ) );
	return \snt_kit_section( $t['notify_h'], \snt_kit_form( 'mcp_rw_notify_save', $field, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ) );
}
