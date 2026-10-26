<?php
/**
 * AI > MCP Clients: the read door's optional credential binding, the native
 * twin of inc/admin-forms/mcp-connect-read-binding.php (same state, words,
 * field `sn_mcp_read_uuid` and action `bind_mcp_read_credential`).
 *
 * @package SignalNoise
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The read binding section.
 *
 * @return string
 */
function mcp_connect_read_binding_html() {
	if ( ! function_exists( 'sn_admin_mcp_read_binding_state' ) ) {
		return '';
	}
	$s      = sn_admin_mcp_read_binding_state();
	$title  = __( 'Read-door credential (optional)', 'signal-and-noise-tools' );
	$lede   = __( 'Which Application Password, if any, is limited to the read door', 'signal-and-noise-tools' );
	$status = '';
	if ( $s['off'] ) {
		$status .= \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'The read door is off.', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( __( 'Binding a credential does not open it while it is off.', 'signal-and-noise-tools' ) ) );
	}
	$line    = \snt_kit_esc( sn_admin_mcp_read_binding_status( $s ) );
	$status .= ( '' !== $s['bound'] && null === $s['bound_password'] ) ? \snt_kit_notice( 'warn', $line ) : '<p class="snt-prose">' . $line . '</p>';

	if ( empty( $s['passwords'] ) ) {
		$profile = function_exists( 'get_edit_profile_url' ) ? get_edit_profile_url() . '#application-passwords-section' : '';
		return \snt_kit_section( $title, $status . '<p class="snt-prose">' . \snt_kit_esc( __( 'You have no other Application Password to bind: create one under your profile first.', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_door( __( 'Your profile', 'signal-and-noise-tools' ), $profile ) . '</p>', $lede );
	}

	$field  = \snt_kit_field(
		'select',
		'sn_mcp_read_uuid',
		__( 'Application Password', 'signal-and-noise-tools' ),
		null !== $s['bound_password'] ? $s['bound'] : '',
		array(
			'options' => sn_admin_mcp_rw_options( $s['passwords'], $s['bound'], null !== $s['bound_password'] ),
			'hint'    => __( 'Your profile\'s Application Passwords, minus the write door\'s: each door needs its own.', 'signal-and-noise-tools' ),
		)
	);
	$button = static fn( $label, $variant ) => \snt_kit_tag( 'os-button', array( 'variant' => $variant, 'data-snt-form-submit' => true ), \snt_kit_esc( $label ) );
	$form   = \snt_kit_form( 'bind_mcp_read_credential', $field . \snt_kit_tag( 'os-cluster', array( 'class' => 'snt-save-row', 'gap' => '8' ), $button( __( 'Save read-door credential', 'signal-and-noise-tools' ), 'primary' ) ), array( 'submit' => __( 'Save read-door credential', 'signal-and-noise-tools' ), 'class' => 'snt-form--saves-inline' ) );
	if ( '' !== $s['bound'] ) {
		$unbind = \snt_kit_form(
			'bind_mcp_read_credential',
			$button( __( 'Unbind', 'signal-and-noise-tools' ), 'danger' ),
			array(
				'submit'  => __( 'Unbind', 'signal-and-noise-tools' ),
				'class'   => 'snt-form--saves-inline',
				'hidden'  => array( 'sn_mcp_read_uuid' => '', 'sn_mcp_read_unbind' => '1' ),
				'confirm' => __( 'Unbind the read-door credential? That Application Password stops being limited to the read door.', 'signal-and-noise-tools' ),
			)
		);
		$form   = \snt_kit_tag( 'div', array( 'class' => 'snt-bind-row' ), $form . $unbind );
	}
	return \snt_kit_section( $title, $status . $form, $lede );
}
