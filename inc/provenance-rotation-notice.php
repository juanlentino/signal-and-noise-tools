<?php
/**
 * Signal & Noise Tools: what a key-rotation button did, said once for both
 * surfaces (the classic Key rotation fieldset and the S&N Home leaf).
 *
 * The two rotation handlers redirect with `sn_prov_rotate=staged|rotated|
 * e-<code>` and nothing read it before the 2026-10-12 audit: a successful rotation
 * consumes its commitment, so the box went back to "none published" with the
 * publish button, exactly as if nothing had happened, and a refusal left the
 * same button with no reason. The no-confirm design (the handler docblock)
 * only holds up if the result is painted.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The result of the last rotation button, or null for no (or an empty) flag.
 *
 * @param string $flag The `sn_prov_rotate` value: staged, rotated or e-<code>.
 * @return array{kind:string,title:string,body:string}|null
 */
function sn_prov_rotation_notice( $flag ) {
	$flag = (string) $flag;
	if ( 'staged' === $flag ) {
		return array(
			'kind'  => 'ok',
			'title' => __( 'Commitment published', 'signal-and-noise-tools' ),
			'body'  => __( 'The hash of the key the Worker has staged is now public. Rotating is refused unless the key the Worker returns then matches it.', 'signal-and-noise-tools' ),
		);
	}
	if ( 'rotated' === $flag ) {
		$id = function_exists( 'sn_prov_key_id' ) ? trim( (string) sn_prov_key_id() ) : '';
		return array(
			'kind'  => 'ok',
			'title' => '' !== $id
				/* translators: %s: the new signing key id. */
				? sprintf( __( 'Rotated to key %s', 'signal-and-noise-tools' ), $id )
				: __( 'Rotated to the committed key', 'signal-and-noise-tools' ),
			'body'  => __( 'The previous key is in the published history with its closing date, and the commitment it fulfilled is spent.', 'signal-and-noise-tools' ),
		);
	}
	if ( 0 !== strpos( $flag, 'e-' ) || 2 === strlen( $flag ) ) {
		return null;
	}
	$code    = substr( $flag, 2 );
	$reasons = array(
		'worker-not-configured' => __( 'The Worker URL or HMAC secret is not set, so the Worker could not be asked for its staged key.', 'signal-and-noise-tools' ),
		'worker-unreachable'    => __( 'The Worker did not answer, or answered with an error.', 'signal-and-noise-tools' ),
		'worker-unreadable'     => __( 'The Worker answered with something this site could not read.', 'signal-and-noise-tools' ),
		'no-successor-staged'   => __( 'The Worker holds no staged successor key. Stage one on the Worker first, as the key-rotation runbook says.', 'signal-and-noise-tools' ),
		'not-a-public-key'      => __( 'The key the Worker returned is not a 32-byte public key.', 'signal-and-noise-tools' ),
		'commitment-not-safe'   => __( 'The key the Worker returned is already active or retired, so a commitment to it would promise nothing.', 'signal-and-noise-tools' ),
		'no-commitment'         => __( 'No commitment is published, so there is nothing to rotate to. Publish one first.', 'signal-and-noise-tools' ),
		'reveal-mismatch'       => __( 'The key the Worker returned does not hash to the published commitment.', 'signal-and-noise-tools' ),
		'bad-key-id'            => __( 'Today\'s date did not make a valid key id.', 'signal-and-noise-tools' ),
		'bad-date'              => __( 'Today\'s date did not make a valid key id.', 'signal-and-noise-tools' ),
		'no-active-key'         => __( 'No active signing key is configured, so there is nothing to retire.', 'signal-and-noise-tools' ),
		'ledger-write-failed'   => __( 'The public ledger did not record the rotation, so this site kept the current key. Run it again.', 'signal-and-noise-tools' ),
	);
	return array(
		'kind'  => 'err',
		'title' => __( 'Nothing changed', 'signal-and-noise-tools' ),
		'body'  => $reasons[ $code ] ?? sprintf(
			/* translators: %s: the refusal code the handler returned. */
			__( 'The rotation was refused with the code "%s".', 'signal-and-noise-tools' ),
			$code
		),
	);
}

/**
 * What each rotation button does, one sentence for both surfaces.
 *
 * @param string $step 'stage' or 'rotate'.
 * @return string
 */
function sn_prov_rotation_hint( $step ) {
	return 'rotate' === $step
		? __( 'Retires the current key into the published history with a closed validity window, then promotes the committed successor. Refused unless the key the Worker returns hashes to the published commitment.', 'signal-and-noise-tools' )
		: __( 'Asks the Worker for the successor key it holds, hashes it here, and publishes that hash, so the key that later appears can be checked against the one promised.', 'signal-and-noise-tools' );
}

/**
 * The classic twin: the status box inside the Key rotation fieldset.
 *
 * @return void
 */
function sn_prov_admin_render_rotation_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a whitelisted read-only flash flag.
	$flag   = isset( $_GET['sn_prov_rotate'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_prov_rotate'] ) ) : '';
	$notice = sn_prov_rotation_notice( $flag );
	if ( null === $notice ) {
		return;
	}
	echo '<div class="sn-status-box' . ( 'err' === $notice['kind'] ? ' sn-status-box--err' : '' ) . '"><div>'
		. '<p class="sn-status-box-title">' . esc_html( $notice['title'] ) . '</p>'
		. '<p class="sn-status-box-body">' . esc_html( $notice['body'] ) . '</p>'
		. '</div></div>';
}
