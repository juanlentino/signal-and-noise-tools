<?php
/**
 * S&N Dashboard: Settings > Accounts and connections, painted from the kit.
 * The fifth pane of the Settings tab, the native twin of
 * inc/admin-forms/settings-accounts.php.
 *
 * This file WAS the Connections > Credentials leaf (connections-keyring.php)
 * until 2026-11. The leaf is the list of accounts with their state (the
 * ledger, the one form that sets a row, Verify all, the owner key, the worker
 * commands), which is what an Accounts pane holds, so it moved whole:
 * accounts_credentials_html() is that leaf's body, unchanged. Left, one table
 * per group (Credential · Source · Value · Verified); a refused or errored
 * verdict is also a notice at the top, with its sentence. Right, the rail:
 * one "Set a credential" form (a select of the rows a value can be set on,
 * one value field; `clear` removes, `site` derives), the owner key, Verify
 * all with its time, and the worker commands. Same row model as the classic
 * twin (inc/keyring-admin.php): fields `key_id` + `key_value`, actions
 * `keyring_save`, `keyring_generate` and `keyring_verify`.
 *
 * Under it, one box per account for the settings three Connections leaves
 * gave up (settings-accounts-parts.php): Zenodo, IndexNow, Muso.AI.
 *
 * A save is answered on the tab and leaf it was posted from
 * (sn-dashboard.os.php, the `post` action), and no form here carries a hidden
 * tab or sub, so it lands back on this pane.
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 * @since 15.2.1 A ledger and one form, not sixteen fields.
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/settings-accounts-parts.php'; // the three boxes under Credentials.

/**
 * The Credentials box: the ledger and its rail, as the Connections >
 * Credentials leaf painted them.
 *
 * @return string
 */
function accounts_credentials_html() {
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

/**
 * The pane: the Credentials box, then one row of boxes, one per account. A
 * box paints only when its module is loaded. Every leaf these came from
 * gated on manage_options; so does the pane.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_accounts( array $ctx ) {
	unset( $ctx );
	if ( ! current_user_can( 'manage_options' ) ) {
		return \snt_kit_empty( __( 'This account cannot manage options.', 'signal-and-noise-tools' ) );
	}
	$out = function_exists( 'sn_keyring_models' )
		? '<h3 class="snt-h">' . \snt_kit_esc( __( 'Credentials', 'signal-and-noise-tools' ) ) . '</h3>' . accounts_credentials_html()
		: \snt_kit_empty( __( 'The keyring module is not loaded.', 'signal-and-noise-tools' ) );
	return $out . \snt_kit_grid( array_values( array_filter( array( accounts_zenodo_html(), accounts_indexnow_html(), accounts_muso_html() ) ) ), 290 );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/accounts'] = __NAMESPACE__ . '\\paint_settings_accounts';
		return $painters;
	}
);
