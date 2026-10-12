<?php
/**
 * S&N Dashboard, Integrity > Provenance: the three status boxes (System, Key
 * rotation, Genesis anchor), split out of tools-provenance.php (line cap) on
 * 2026-10-12. Twins of `sn_prov_admin_render_system_fieldset()`,
 * `sn_prov_admin_render_rotation_fieldset()` and
 * `sn_prov_admin_render_genesis_fieldset()`, from the same readers.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One config-presence readout: the setting label with a configured/not-set
 * tone. Presence ONLY: the value (e.g. the HMAC secret) is never rendered,
 * exactly as the classic ✓/✗ pill.
 *
 * @param bool $present Whether the constant/option is configured.
 * @return string
 */
function provenance_presence_text( $present ) {
	return $present
		? __( '✓ Configured', 'signal-and-noise-tools' )
		: __( '✗ Not set', 'signal-and-noise-tools' );
}

/**
 * One signing-key readout: the resolved value plus where it came from, public
 * either way (published in did.json and the keys mirror).
 *
 * @param string $value  Resolved value.
 * @param string $source constant|blank-constant|option|default.
 * @return string
 */
function provenance_key_row_html( $value, $source ) {
	$sources = array(
		'constant'       => array( __( 'wp-config.php constant', 'signal-and-noise-tools' ), 'ok' ),
		'option'         => array( __( 'site option', 'signal-and-noise-tools' ), 'ok' ),
		'default'        => array( __( 'shipped default', 'signal-and-noise-tools' ), '' ),
		'blank-constant' => array( __( 'shipped default: a BLANK wp-config constant is shadowing the option', 'signal-and-noise-tools' ), 'warn' ),
	);
	$known = $sources[ $source ] ?? array( (string) $source, '' );
	return \snt_kit_code( (string) $value, false ) . ' ' . \snt_kit_badge( $known[1], $known[0] );
}

/**
 * System box: config presence, Worker version, the public key, the signing
 * key's identity and source, and the public-ledger link.
 *
 * @param array $sys sn_prov_admin_system_status() view-model.
 * @return string
 */
function provenance_system_html( array $sys ) {
	$config = isset( $sys['config'] ) && is_array( $sys['config'] ) ? $sys['config'] : array();
	$inner  = \snt_kit_kv(
		array(
			array( 'label' => __( 'Worker URL', 'signal-and-noise-tools' ), 'value' => provenance_presence_text( ! empty( $config['worker_url'] ) ), 'tone' => \snt_kit_tone( ! empty( $config['worker_url'] ) ? 'ok' : 'warn' ) ),
			array( 'label' => __( 'HMAC secret', 'signal-and-noise-tools' ), 'value' => provenance_presence_text( ! empty( $config['hmac'] ) ), 'tone' => \snt_kit_tone( ! empty( $config['hmac'] ) ? 'ok' : 'warn' ) ),
			array( 'label' => __( 'Public key', 'signal-and-noise-tools' ), 'value' => provenance_presence_text( ! empty( $config['pubkey'] ) ), 'tone' => \snt_kit_tone( ! empty( $config['pubkey'] ) ? 'ok' : 'warn' ) ),
		)
	);

	if ( ! empty( $config['worker_url'] ) ) {
		$wver   = function_exists( 'sn_prov_worker_version' ) ? (string) sn_prov_worker_version() : '';
		$inner .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Worker version', 'signal-and-noise-tools' ) ) . ' '
			. ( '' !== $wver ? \snt_kit_code( $wver, false ) : '<span class="snt-hint">' . \snt_kit_esc( __( 'unknown', 'signal-and-noise-tools' ) ) . '</span>' )
			. '</p>';
	}

	$pubkey = (string) ( $sys['pubkey'] ?? '' );
	if ( '' !== $pubkey ) {
		$inner .= '<p class="snt-provenance-key"><span class="snt-hint">' . \snt_kit_esc( __( 'Public signing key', 'signal-and-noise-tools' ) ) . '</span><br>' . \snt_kit_code( $pubkey, false ) . '</p>';
	}

	$sk = isset( $sys['signing_key'] ) && is_array( $sys['signing_key'] ) ? $sys['signing_key'] : array();
	if ( '' !== (string) ( $sk['id'] ?? '' ) ) {
		$inner .= \snt_kit_kv(
			array(
				array( 'label' => __( 'Signing key id', 'signal-and-noise-tools' ), 'value' => provenance_key_row_html( (string) $sk['id'], (string) ( $sk['id_source'] ?? '' ) ), 'html' => true ),
				array( 'label' => __( 'In use since', 'signal-and-noise-tools' ), 'value' => provenance_key_row_html( (string) ( $sk['introduced_at'] ?? '' ), (string) ( $sk['introduced_at_source'] ?? '' ) ), 'html' => true ),
			)
		);
	}

	$ledger_url = (string) ( $sys['ledger_url'] ?? '' );
	if ( '' !== $ledger_url ) {
		$inner .= '<p>' . \snt_kit_link( __( 'Public ledger', 'signal-and-noise-tools' ) . ' →', $ledger_url ) . '</p>';
	}

	return \snt_kit_section( __( 'System', 'signal-and-noise-tools' ), $inner );
}

/**
 * Key rotation box: what the last button did (sn_prov_rotation_notice(), the
 * classic notice's words), the published commitment or its absence, and
 * exactly one of the two ceremony buttons, never both.
 *
 * @param array|null $commitment sn_prov_next_key_commitment() result.
 * @param string     $flag       The `sn_prov_rotate` param, '' for none.
 * @return string
 */
function provenance_rotation_html( $commitment, $flag = '' ) {
	$notice = function_exists( 'sn_prov_rotation_notice' ) ? sn_prov_rotation_notice( (string) $flag ) : null;
	$inner  = null === $notice ? '' : \snt_kit_notice( $notice['kind'], '<b>' . \snt_kit_esc( $notice['title'] ) . '</b> ' . \snt_kit_esc( $notice['body'] ) );
	$inner .= \snt_kit_kv(
		array(
			array(
				'label' => __( 'Commitment to the next key', 'signal-and-noise-tools' ),
				'value' => null === $commitment
					? \snt_kit_badge( '', __( 'none published', 'signal-and-noise-tools' ) )
					: \snt_kit_code( substr( (string) ( $commitment['value'] ?? '' ), 0, 16 ) . '…', false ) . ' '
						. \snt_kit_badge( 'ok', sprintf( /* translators: %s: the date, in the site's format */ __( 'committed %s', 'signal-and-noise-tools' ), function_exists( 'sn_prov_admin_format_ts' ) ? sn_prov_admin_format_ts( (string) ( $commitment['committed_at'] ?? '' ) ) : (string) ( $commitment['committed_at'] ?? '' ) ) ),
				'html'  => true,
			),
		)
	);
	$step   = null === $commitment ? 'stage' : 'rotate';
	$hint   = function_exists( 'sn_prov_rotation_hint' ) ? sn_prov_rotation_hint( $step ) : '';
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( $hint ) . '</p>'
		. ( 'stage' === $step
			? provenance_post_action( 'sn_prov_stage_key', __( 'Publish a commitment to the staged key', 'signal-and-noise-tools' ) )
			: provenance_post_action( 'sn_prov_rotate_key', __( 'Rotate to the committed key', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section( __( 'Key rotation', 'signal-and-noise-tools' ), $inner );
}

/**
 * The re-anchor flash: dispatched|failed, config-aware on failure, mirroring
 * `sn_prov_admin_render_reanchor_notice()`.
 *
 * @param string $flag 'ok'|'fail'|other.
 * @param array  $sys  sn_prov_admin_system_status() view-model.
 * @return string
 */
function provenance_reanchor_notice_html( $flag, array $sys ) {
	if ( 'ok' === $flag ) {
		$kind  = 'ok';
		$title = __( 'Re-anchor dispatched', 'signal-and-noise-tools' );
		$body  = __( 'The genesis root was re-submitted to the Worker for anchoring.', 'signal-and-noise-tools' );
	} elseif ( 'fail' === $flag ) {
		$kind   = 'err';
		$title  = __( 'Re-anchor failed', 'signal-and-noise-tools' );
		$config = isset( $sys['config'] ) && is_array( $sys['config'] ) ? $sys['config'] : array();
		$body   = ( ! empty( $config['worker_url'] ) && ! empty( $config['hmac'] ) && ! empty( $config['pubkey'] ) )
			? __( 'The Worker rejected the dispatch. Check the Worker is deployed and reachable.', 'signal-and-noise-tools' )
			: __( 'Set the SN_PROV_* constants in wp-config first.', 'signal-and-noise-tools' );
	} else {
		return '';
	}
	return \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_esc( $body ) );
}

/**
 * Genesis anchor box: the re-anchor flash, the status badge, the truncated
 * root, and the re-anchor button, withheld once already anchored.
 *
 * @param array  $sys           sn_prov_admin_system_status() view-model.
 * @param string $reanchor_flag 'ok'|'fail'|''.
 * @return string
 */
function provenance_genesis_html( array $sys, $reanchor_flag ) {
	$genesis = is_array( $sys['genesis'] ?? null ) ? $sys['genesis'] : array();
	$status  = (string) ( $genesis['status'] ?? '' );
	$root    = (string) ( $genesis['root'] ?? '' );

	$inner = '' !== (string) $reanchor_flag ? provenance_reanchor_notice_html( (string) $reanchor_flag, $sys ) : '';

	$colors = array( 'confirmed' => 'ok', 'pending' => 'warn', 'unsent' => 'err' );
	$label  = isset( $colors[ $status ] )
		? ( function_exists( 'sn_prov_admin_status_label' ) ? sn_prov_admin_status_label( $status ) : ucfirst( $status ) )
		: __( 'Not anchored', 'signal-and-noise-tools' );
	$inner .= '<p>' . \snt_kit_badge( $colors[ $status ] ?? '', $label ) . '</p>';

	if ( '' !== $root ) {
		$truncated = function_exists( 'sn_prov_admin_truncate' ) ? sn_prov_admin_truncate( $root ) : $root;
		$inner    .= \snt_kit_code( $truncated, false );
	}

	if ( 'pending' === $status || 'confirmed' === $status ) {
		// Classic still marks up the form and only disables its submit. A
		// window paints no disabled write button, so the button is withheld:
		// same click blocked, and the suite pins the omission.
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Already anchored: nothing to re-anchor.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$inner .= provenance_post_action( 'sn_prov_reanchor', __( 'Re-anchor genesis', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_section( __( 'Genesis anchor', 'signal-and-noise-tools' ), $inner );
}
