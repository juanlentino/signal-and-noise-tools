<?php
/**
 * S&N Dashboard — Connections › Credentials: every credential the plugin
 * holds, one form, one Verify all (15.2.0).
 *
 * Paints the row model from inc/keyring-admin.php, the same one the classic
 * leaf paints: one field per row (`key_<id>`), the source, the obscured
 * value, the last verdict in words, and for a shared secret the command that
 * sets its other half. Two actions: `keyring_save`, `keyring_verify`.
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One row: field (or locked field), the source and hint, the verdict, the command.
 *
 * @param array<string,mixed> $m Row model.
 * @return string
 */
function keyring_row_html( array $m ) {
	list( $kind, $text ) = sn_keyring_verdict_badge( $m['verdict'], $m['has_probe'] );
	$label = $m['label'];
	$hint  = $m['source_text'] . ' ' . $m['hint'];
	$field = $m['locked']
		? \snt_kit_tag( 'os-field-row', array( 'label' => $label, 'hint' => $hint ), \snt_kit_tag( 'os-text-field', array( 'type' => 'text', 'value' => '' !== $m['shown'] ? $m['shown'] : '••••', 'disabled' => true ) ) )
		: \snt_kit_field( 'text', $m['name'], $label, $m['shown'], array( 'hint' => $hint, 'placeholder' => __( 'Paste to update; "clear" to remove', 'signal-and-noise-tools' ) ) );
	$rows = array( array( 'label' => __( 'Verified', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_badge( 'muted' === $kind ? '' : $kind, $text ) . ( is_array( $m['verdict'] ) && '' !== (string) ( $m['verdict']['detail'] ?? '' ) ? ' ' . \snt_kit_esc( (string) $m['verdict']['detail'] ) : '' ), 'tone' => 'warn' === $kind ? 'warn' : '' ) );
	if ( '' !== $m['command'] ) {
		$rows[] = array( 'label' => __( 'Other half', 'signal-and-noise-tools' ), 'html' => true, 'value' => \snt_kit_code( $m['command'], false ) );
	}
	return $field . \snt_kit_kv( $rows );
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
	$verdicts = function_exists( 'sn_keyring_verdicts' ) ? sn_keyring_verdicts() : array();
	$at       = 0;
	foreach ( $verdicts as $v ) {
		$at = max( $at, (int) ( $v['at'] ?? 0 ) );
	}
	$inner = '<p class="snt-prose">' . \snt_kit_esc( __( 'Every credential this plugin holds, in one place. Paste a value to update, type "clear" to remove, leave an obscured value alone to keep it. A rotation is: paste here, run the command the row prints, press Verify all.', 'signal-and-noise-tools' ) ) . '</p>';
	$fields = '';
	foreach ( sn_keyring_models() as $group => $rows ) {
		$g       = sn_keyring_groups()[ $group ] ?? array( 'label' => $group, 'about' => '' );
		$fields .= '<h4 class="snt-h">' . \snt_kit_esc( $g['label'] ) . '</h4><p class="snt-hint">' . \snt_kit_esc( $g['about'] ) . '</p>';
		foreach ( $rows as $m ) {
			$fields .= keyring_row_html( $m );
		}
	}
	$inner .= \snt_kit_form( 'keyring_save', $fields, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
	$inner .= '<footer>' . \snt_kit_action_button( __( 'Verify all', 'signal-and-noise-tools' ), 'keyring_verify' ) . '</footer>';
	if ( $at > 0 ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( /* translators: %s: time. */ __( 'Last verified %s.', 'signal-and-noise-tools' ), function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i T', $at ) : gmdate( 'Y-m-d H:i', $at ) . ' UTC' ) ) . '</p>';
	}
	return \snt_kit_section( __( 'Credentials', 'signal-and-noise-tools' ), $inner, __( 'One place for every key; every row says where it comes from, what it feeds, and its last verdict.', 'signal-and-noise-tools' ), array( 'stack' => true ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['connections/credentials'] = __NAMESPACE__ . '\\paint_connections_keyring';
		return $painters;
	}
);
