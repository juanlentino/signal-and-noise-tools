<?php
/**
 * Signal & Noise Tools — the keyring's row model and its classic leaf
 * (Connections › Credentials). The native leaf paints the same model
 * (apps/sn-dashboard/parts/leaves/connections-keyring.php), so the two
 * agree by construction: one field name per row (`key_<id>`), two actions
 * (`keyring_save`, `keyring_verify`).
 *
 * @package SignalNoiseTools
 * @since 15.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The groups, in paint order.
 *
 * @return array<string,array{label:string,about:string}>
 */
function sn_keyring_groups() {
	return array(
		'site'       => array( 'label' => __( 'Site secret and the workers', 'signal-and-noise-tools' ), 'about' => __( 'Shared passwords between this plugin and its workers. Each row can derive from the site secret; type "site" in a row to switch it, then run the command it prints so the worker carries the same value.', 'signal-and-noise-tools' ) ),
		'cloudflare' => array( 'label' => __( 'Cloudflare', 'signal-and-noise-tools' ), 'about' => __( 'One token, one zone, one account. Everything Cloudflare reads with these.', 'signal-and-noise-tools' ) ),
		'issued'     => array( 'label' => __( 'Issued by others', 'signal-and-noise-tools' ), 'about' => __( 'Tokens other services mint. They cannot share a value; each is its own row.', 'signal-and-noise-tools' ) ),
	);
}

/**
 * One row, as both leaves paint it: source, obscured value, verdict, hint.
 *
 * @param string              $id  Row id.
 * @param array<string,mixed> $row Registry row.
 * @return array<string,mixed>
 */
function sn_keyring_row_model( $id, array $row ) {
	$source   = sn_keyring_source( $id );
	$value    = sn_credential( $id );
	$verdicts = function_exists( 'sn_keyring_verdicts' ) ? sn_keyring_verdicts() : array();
	$verdict  = isset( $verdicts[ $id ] ) && is_array( $verdicts[ $id ] ) ? $verdicts[ $id ] : null;
	$shown    = 'public' === ( $row['kind'] ?? '' ) || 'id' === ( $row['kind'] ?? '' ) ? $value : ( function_exists( 'sn_mask_secret' ) ? sn_mask_secret( $value ) : ( '' === $value ? '' : '••••' ) );
	$sources  = array(
		'constant' => sprintf( /* translators: %s: constant name. */ __( 'Locked by %s in wp-config.php.', 'signal-and-noise-tools' ), (string) ( $row['constant'] ?? '' ) ),
		'site'     => __( 'From the site secret.', 'signal-and-noise-tools' ),
		'option'   => __( 'Saved here.', 'signal-and-noise-tools' ),
		''         => __( 'Not set.', 'signal-and-noise-tools' ),
	);
	$hint = (string) ( $row['about'] ?? '' ) . ' ' . sprintf( /* translators: %s: what the credential feeds. */ __( 'Feeds: %s.', 'signal-and-noise-tools' ), (string) ( $row['feeds'] ?? '' ) );
	if ( 'site' === ( $row['derive'] ?? '' ) && 'site' !== $source && 'constant' !== $source ) {
		$hint .= ' ' . __( 'Type "site" to derive it from the site secret.', 'signal-and-noise-tools' );
	}
	return array(
		'id'        => $id,
		'name'      => 'key_' . $id,
		'label'     => (string) $row['label'],
		'group'     => (string) $row['group'],
		'source'    => $source,
		'source_text' => $sources[ $source ] ?? '',
		'shown'     => $shown,
		// A row with no option and no setting is wp-config only: never a field.
		'locked'    => 'constant' === $source || ( ! isset( $row['option'] ) && ! isset( $row['setting'] ) ),
		'hint'      => trim( $hint ),
		'command'   => sn_keyring_other_half_command( $row ),
		'verdict'   => $verdict,
		'has_probe' => isset( $row['probe'] ),
	);
}

/**
 * Every row model, grouped.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function sn_keyring_models() {
	$out = array();
	foreach ( sn_keyring() as $id => $row ) {
		$out[ (string) $row['group'] ][] = sn_keyring_row_model( $id, $row );
	}
	return $out;
}

/**
 * The verdict's badge kind and text.
 *
 * @param array<string,mixed>|null $verdict Stored verdict.
 * @param bool                     $has_probe Whether the row has a probe.
 * @return array{0:string,1:string}
 */
function sn_keyring_verdict_badge( $verdict, $has_probe ) {
	if ( ! $has_probe ) {
		return array( 'muted', __( 'no probe', 'signal-and-noise-tools' ) );
	}
	if ( ! is_array( $verdict ) ) {
		return array( 'muted', __( 'not verified', 'signal-and-noise-tools' ) );
	}
	$map = array( 'ok' => 'ok', 'refused' => 'warn', 'error' => 'warn', 'unset' => 'muted', 'none' => 'muted' );
	return array( $map[ (string) ( $verdict['status'] ?? '' ) ] ?? 'muted', (string) ( $verdict['status'] ?? '' ) );
}

/** Connections → Credentials, classic (on the wrapper's action). */
function sn_admin_keyring_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$verdicts = function_exists( 'sn_keyring_verdicts' ) ? sn_keyring_verdicts() : array();
	$at       = 0;
	foreach ( $verdicts as $v ) {
		$at = max( $at, (int) ( $v['at'] ?? 0 ) );
	}
	echo '<form method="post" class="sn-card">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<p class="sn-helper">' . esc_html__( 'Every credential this plugin holds, in one place. Paste a value to update, type "clear" to remove, leave an obscured value alone to keep it.', 'signal-and-noise-tools' ) . '</p>';
	foreach ( sn_keyring_models() as $group => $rows ) {
		$g = sn_keyring_groups()[ $group ] ?? array( 'label' => $group, 'about' => '' );
		echo '<h3 class="sn-fieldset-h">' . esc_html( $g['label'] ) . '</h3><p class="sn-helper">' . esc_html( $g['about'] ) . '</p>';
		foreach ( $rows as $m ) {
			list( $kind, $text ) = sn_keyring_verdict_badge( $m['verdict'], $m['has_probe'] );
			echo '<div class="sn-field sn-field-w-lg"><label class="sn-field-label" for="' . esc_attr( $m['name'] ) . '">' . esc_html( $m['label'] ) . ' <span class="sn-pill sn-pill--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span></label>';
			if ( $m['locked'] ) {
				echo '<input type="text" id="' . esc_attr( $m['name'] ) . '" value="' . esc_attr( '' !== $m['shown'] ? $m['shown'] : '••••' ) . '" disabled class="sn-mono">';
			} else {
				echo '<input type="text" id="' . esc_attr( $m['name'] ) . '" name="' . esc_attr( $m['name'] ) . '" value="' . esc_attr( $m['shown'] ) . '" class="sn-mono" autocomplete="off">';
			}
			echo '<p class="sn-field-helper"><strong>' . esc_html( $m['source_text'] ) . '</strong> ' . esc_html( $m['hint'] ) . '</p>';
			if ( is_array( $m['verdict'] ) && '' !== (string) ( $m['verdict']['detail'] ?? '' ) ) {
				echo '<p class="sn-field-helper">' . esc_html( (string) $m['verdict']['detail'] ) . '</p>';
			}
			if ( '' !== $m['command'] ) {
				echo '<p class="sn-field-helper">' . esc_html__( 'Other half:', 'signal-and-noise-tools' ) . ' <code>' . esc_html( $m['command'] ) . '</code></p>';
			}
			echo '</div>';
		}
	}
	echo '<button type="submit" name="sn_action" value="keyring_save" class="button button-primary">Save</button> ';
	echo '<button type="submit" name="sn_action" value="keyring_verify" class="button">Verify all</button>';
	if ( $at > 0 ) {
		echo '<p class="sn-field-helper">' . esc_html( sprintf( /* translators: %s: time. */ __( 'Last verified %s.', 'signal-and-noise-tools' ), wp_date( 'Y-m-d H:i T', $at ) ) ) . '</p>';
	}
	echo '</form>';
}
add_action( 'sn_admin_credentials_tab', 'sn_admin_keyring_render' );
