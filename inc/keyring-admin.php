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
	// 15.2.1: table-cell length; the constant's name is the whole point of the cell.
	$sources  = array(
		'constant' => sprintf( /* translators: %s: constant name. */ __( 'wp-config (%s)', 'signal-and-noise-tools' ), (string) ( $row['constant'] ?? '' ) ),
		'site'     => __( 'Site secret', 'signal-and-noise-tools' ),
		'option'   => __( 'Saved', 'signal-and-noise-tools' ),
		''         => __( 'Not set', 'signal-and-noise-tools' ),
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

/**
 * The ledger's table rows for one group, as both leaves paint them:
 * Credential · Source · Value · Verified. Plain text only.
 *
 * @param array<int,array<string,mixed>> $models Row models.
 * @return array<int,array{credential:string,source:string,value:string,verified:string}>
 */
function sn_keyring_ledger_rows( array $models ) {
	$out = array();
	foreach ( $models as $m ) {
		list( , $text ) = sn_keyring_verdict_badge( $m['verdict'], $m['has_probe'] );
		$out[] = array(
			'credential' => (string) $m['label'],
			'source'     => (string) $m['source_text'],
			'value'      => '' !== (string) $m['shown'] ? (string) $m['shown'] : '—',
			'verified'   => (string) $text,
		);
	}
	return $out;
}

/**
 * The verdicts that need saying: refused and error rows, with their sentence.
 *
 * @return array<int,array{label:string,detail:string}>
 */
function sn_keyring_refusals() {
	$out = array();
	foreach ( sn_keyring_models() as $rows ) {
		foreach ( $rows as $m ) {
			if ( is_array( $m['verdict'] ) && in_array( (string) ( $m['verdict']['status'] ?? '' ), array( 'refused', 'error' ), true ) ) {
				$out[] = array( 'label' => (string) $m['label'], 'detail' => (string) ( $m['verdict']['detail'] ?? '' ) );
			}
		}
	}
	return $out;
}

/**
 * The select's options: every row a value can be set on (not constant-locked,
 * not wp-config-only), labelled by group.
 *
 * @return array<string,string> id => label.
 */
function sn_keyring_select_options() {
	$out    = array();
	$groups = sn_keyring_groups();
	foreach ( sn_keyring_models() as $group => $rows ) {
		foreach ( $rows as $m ) {
			if ( ! $m['locked'] ) {
				$out[ (string) $m['id'] ] = (string) ( $groups[ $group ]['label'] ?? $group ) . ' · ' . (string) $m['label'];
			}
		}
	}
	return $out;
}

/**
 * The worker secrets and the command that sets each, for the rail.
 *
 * @return array<int,array{label:string,command:string}>
 */
function sn_keyring_worker_commands() {
	$out = array();
	foreach ( sn_keyring() as $row ) {
		$cmd = sn_keyring_other_half_command( $row );
		if ( '' !== $cmd ) {
			$out[] = array( 'label' => (string) $row['label'], 'command' => $cmd );
		}
	}
	return $out;
}

/** The time of the last Verify all, 0 when never. */
function sn_keyring_verified_at() {
	$at = 0;
	foreach ( function_exists( 'sn_keyring_verdicts' ) ? sn_keyring_verdicts() : array() as $v ) {
		$at = max( $at, (int) ( $v['at'] ?? 0 ) );
	}
	return $at;
}

/** Connections → Credentials, classic (on the wrapper's action). 15.2.1: a ledger and one form. */
function sn_admin_keyring_render() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	foreach ( sn_keyring_refusals() as $r ) {
		wp_admin_notice( '<strong>' . esc_html( $r['label'] ) . '</strong> ' . esc_html( $r['detail'] ), array( 'type' => 'warning', 'additional_classes' => array( 'inline' ) ) );
	}
	sn_admin_shell_open();
	$groups = sn_keyring_groups();
	foreach ( sn_keyring_models() as $group => $rows ) {
		$g = $groups[ $group ] ?? array( 'label' => $group, 'about' => '' );
		echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html( $g['label'] ) . '</h2><p class="sn-field-helper">' . esc_html( $g['about'] ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>Credential</th><th>Source</th><th>Value</th><th>Verified</th></tr></thead><tbody>';
		foreach ( sn_keyring_ledger_rows( $rows ) as $r ) {
			echo '<tr><td>' . esc_html( $r['credential'] ) . '</td><td>' . esc_html( $r['source'] ) . '</td><td class="sn-mono">' . esc_html( $r['value'] ) . '</td><td>' . esc_html( $r['verified'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
	sn_admin_shell_rail( 'Set a credential' );
	echo '<form method="post" action="' . esc_url( sn_admin_post_url( 'keyring_save' ) ) . '" class="sn-card">';
	echo '<div class="sn-field"><label class="sn-field-label" for="key_id">Credential</label><select id="key_id" name="key_id">';
	foreach ( sn_keyring_select_options() as $id => $label ) {
		echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</option>';
	}
	echo '</select></div>';
	echo '<div class="sn-field"><label class="sn-field-label" for="key_value">Value</label><input type="text" id="key_value" name="key_value" class="sn-mono" autocomplete="off" placeholder="Paste the value">';
	echo '<p class="sn-field-helper">Paste to set. Type <code>clear</code> to remove. On a worker row, type <code>site</code> to derive it from the site secret. Rows locked in wp-config.php are not listed.</p></div>';
	echo '<button type="submit"' . sn_admin_post_button( 'keyring_save' ) . ' class="button button-primary">Save</button> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped inside sn_admin_post_button().
	echo '<button type="submit"' . sn_admin_post_button( 'keyring_verify' ) . ' class="button">Verify all</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute is escaped inside sn_admin_post_button().
	$at = sn_keyring_verified_at();
	if ( $at > 0 ) {
		echo '<p class="sn-field-helper">' . esc_html( sprintf( 'Last verified %s.', wp_date( 'Y-m-d H:i T', $at ) ) ) . '</p>';
	}
	echo '</form>';
	echo '<div class="sn-card"><strong>Worker secrets</strong><p class="sn-field-helper">After changing a worker row here, set the same value on the worker:</p>';
	foreach ( sn_keyring_worker_commands() as $c ) {
		echo '<p class="sn-field-helper">' . esc_html( $c['label'] ) . '<br><code>' . esc_html( $c['command'] ) . '</code></p>';
	}
	echo '</div>';
	sn_admin_shell_close();
}
add_action( 'sn_admin_credentials_tab', 'sn_admin_keyring_render' );
