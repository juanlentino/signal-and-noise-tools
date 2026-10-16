<?php
/**
 * S&N Dashboard — Connections › Credentials: the ledger and one form (15.2.1).
 *
 * Left, one table per group (Credential · Source · Value · Verified), so all
 * sixteen rows read in one screen and the refused one stands out; a refused
 * or errored verdict is also a notice at the top, with its sentence. Right,
 * the rail: one "Set a credential" form (a select of the rows a value can be
 * set on, one value field; `clear` removes, `site` derives), Verify all with
 * its time, and the four worker commands. Same row model as the classic
 * leaf (inc/keyring-admin.php): fields `key_id` + `key_value`, actions
 * `keyring_save` + `keyring_verify`.
 *
 * The first cut (15.2.0) was sixteen stacked inputs in one column; the owner
 * called it, rightly, a wall.
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 * @since 15.2.1 A ledger and one form, not sixteen fields.
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_connections_keyring( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	if ( ! function_exists( 'sn_keyring_models' ) ) {
		return \snt_kit_empty( __( 'The keyring module is not loaded.', 'signal-and-noise-tools' ) );
	}
	$groups  = sn_keyring_groups();
	$columns = array(
		array( 'key' => 'credential', 'label' => __( 'Credential', 'signal-and-noise-tools' ) ),
		array( 'key' => 'source', 'label' => __( 'Source', 'signal-and-noise-tools' ) ),
		array( 'key' => 'value', 'label' => __( 'Value', 'signal-and-noise-tools' ) ),
		array( 'key' => 'verified', 'label' => __( 'Verified', 'signal-and-noise-tools' ) ),
	);

	// ── Left: the refusals, then the ledger.
	$left = '';
	foreach ( sn_keyring_refusals() as $r ) {
		$left .= \snt_kit_notice( 'warning', '<b>' . \snt_kit_esc( $r['label'] ) . '</b> ' . \snt_kit_esc( $r['detail'] ) );
	}
	foreach ( sn_keyring_models() as $group => $rows ) {
		$g     = $groups[ $group ] ?? array( 'label' => $group, 'about' => '' );
		$left .= \snt_kit_section( $g['label'], \snt_kit_table( $columns, sn_keyring_ledger_rows( $rows ), array( 'compact' => true ) ), $g['about'] );
	}

	// ── Right: one form, Verify all, the worker commands.
	$fields = \snt_kit_field( 'select', 'key_id', __( 'Credential', 'signal-and-noise-tools' ), '', array( 'options' => sn_keyring_select_options(), 'placeholder' => __( 'Choose a credential', 'signal-and-noise-tools' ) ) )
		. \snt_kit_field( 'text', 'key_value', __( 'Value', 'signal-and-noise-tools' ), '', array(
			'placeholder' => __( 'Paste the value', 'signal-and-noise-tools' ),
			'hint'        => __( 'Paste to set. Type "clear" to remove. On a worker row, type "site" to derive it from the site secret. Rows locked in wp-config.php are not listed.', 'signal-and-noise-tools' ),
		) );
	$copy  = sn_keyring_copy();
	$right = \snt_kit_section(
		__( 'Set a credential', 'signal-and-noise-tools' ),
		\snt_kit_form( 'keyring_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) ),
		$copy['set']
	);
	// Owner key: pasted from openssl (owner request 2026-10-15) or generated.
	$right .= \snt_kit_section(
		__( 'Owner key', 'signal-and-noise-tools' ),
		\snt_kit_form(
			'keyring_generate',
			\snt_kit_field( 'select', 'key_id', __( 'Key', 'signal-and-noise-tools' ), '', array( 'options' => sn_keyring_generate_options(), 'placeholder' => __( 'Choose a key', 'signal-and-noise-tools' ) ) ),
			array( 'submit' => __( 'Generate', 'signal-and-noise-tools' ), 'confirm' => __( 'Generate a new key? Set it on the Workers next.', 'signal-and-noise-tools' ) )
		),
		$copy['owner'] . ' ' . $copy['generate']
	);
	$at     = sn_keyring_verified_at();
	$right .= \snt_kit_section(
		__( 'Verify', 'signal-and-noise-tools' ),
		'<footer>' . \snt_kit_action_button( __( 'Verify all', 'signal-and-noise-tools' ), 'keyring_verify' ) . '</footer>'
		. '<p class="snt-hint">' . \snt_kit_esc( $at > 0 ? sn_keyring_verified_text( $at ) : $copy['never'] ) . '</p>',
		$copy['verify']
	);
	$cmds = array();
	foreach ( sn_keyring_worker_commands() as $c ) {
		$cmds[] = array( 'label' => $c['label'], 'html' => true, 'value' => \snt_kit_code( $c['command'], false ) );
	}
	$right .= \snt_kit_section(
		__( 'Worker secrets', 'signal-and-noise-tools' ),
		\snt_kit_kv( $cmds ),
		$copy['workers']
	);

	return \snt_kit_grid( array( \snt_kit_stack( $left ), \snt_kit_stack( $right ) ), 290, 24 );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/credentials'] = __NAMESPACE__ . '\\paint_connections_keyring';
		return $painters;
	}
);
