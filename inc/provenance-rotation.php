<?php
/**
 * Signal & Noise — the key-rotation PRODUCER.
 *
 * Everything shipped before this was a DETECTOR: /verify resolving a record
 * against the key it NAMES, the integrity sweep's signing_key_unpublished leg,
 * the DID naming retired keys. All three describe a rotation that nothing could
 * perform — `sn_prov_key_history` and `sn_prov_next_key_commitment` were read
 * and never written. This is the writer, and it is deliberately the last piece:
 * the detectors make a botched rotation fail LOUD, which is what makes an
 * automated one safe to offer at all.
 *
 * THE BOUNDARY, stated once and enforced by construction: nothing in this file
 * ever touches a PRIVATE key. The signing key lives in a Cloudflare Worker
 * secret and is never readable from WordPress. Every value handled here is
 * public — published key bytes, published hashes, published dates. A rotation
 * therefore has exactly one step this file cannot do, and must not: generating
 * and taking custody of the next private key.
 *
 * WHY A COMMITMENT AT ALL. Publishing sha256(next public key) BEFORE that key
 * is used converts a rotation from an announcement into something checkable: a
 * reader who saw the commitment can verify the key that later appears is the
 * key that was promised, so a rotation cannot be used to quietly substitute an
 * attacker's key. That property only holds if the commitment is made while the
 * next key is still unused, which is why sn_prov_commit_next_key() refuses to
 * commit to any key already in play.
 *
 * @package SignalNoiseTools
 * @since 13.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Can a rotation actually take effect right now?
 *
 * Every blocker here describes a rotation that would APPEAR to succeed and
 * change nothing, which is strictly worse than refusing: it writes a retired-key
 * row for a handover that never happened, so the published history disagrees
 * with the published key.
 *
 * @return array{ready:bool,blockers:string[]}
 */
function sn_prov_rotation_preflight() {
	$blockers = array();

	// A wp-config CONSTANT beats the option (sn_prov_config() returns a defined
	// constant even when blank and never reads the option). So if the active key
	// — or the id, or the date — is pinned by a constant, the options this file
	// writes are inert and the rotation is theatre. Reuses the same resolver the
	// admin System panel reports with, so the page and this check cannot drift.
	foreach ( array(
		'active-key-is-a-constant'    => array( 'SN_PROV_PUBKEY_B64', 'sn_prov_pubkey_b64' ),
		'key-id-is-a-constant'        => array( 'SN_PROV_PUBKEY_ID', 'sn_prov_pubkey_id' ),
		'introduced-at-is-a-constant' => array( 'SN_PROV_KEY_INTRODUCED_AT', 'sn_prov_key_introduced_at' ),
	) as $code => $pair ) {
		$source = sn_prov_key_config_source( $pair[0], $pair[1] );
		if ( 'constant' === $source || 'blank-constant' === $source ) {
			$blockers[] = $code;
		}
	}

	if ( null === sn_prov_next_key_commitment() ) {
		$blockers[] = 'no-commitment';
	}

	return array( 'ready' => empty( $blockers ), 'blockers' => $blockers );
}

/**
 * Publish a commitment to the NEXT signing key.
 *
 * Takes the next PUBLIC KEY and hashes it here. It deliberately does NOT accept
 * a ready-made digest: a mistyped hash commits the site, publicly and
 * permanently, to a key nobody holds — unfulfillable, and indistinguishable
 * from a correct commitment until the rotation that can never happen. Handing
 * over a key we can validate (32 bytes, decodes, not already in use) is the
 * only input for which "wrong" is detectable at the time it is offered.
 *
 * @param string $next_public_key_b64 The successor key's PUBLIC bytes, base64.
 * @param string $committed_at        ISO date the commitment is made.
 * @return array{ok:bool,code:string,commitment:string}
 */
function sn_prov_commit_next_key( $next_public_key_b64, $committed_at ) {
	$fail = static function ( $code ) {
		return array( 'ok' => false, 'code' => $code, 'commitment' => '' );
	};

	$b64 = trim( (string) $next_public_key_b64 );
	$raw = '' === $b64 ? false : base64_decode( $b64, true );
	// A sha256 hex digest is 64 chars of [0-9a-f] — every one a valid base64
	// character — so it decodes cleanly to 48 bytes and is caught HERE, by
	// length, rather than being mistaken for a key.
	if ( false === $raw || 32 !== strlen( $raw ) ) {
		return $fail( 'not-a-public-key' );
	}

	// Committing to a key that is already the active one, or already retired,
	// commits to nothing: the point of the commitment is that the key is unused
	// and unseen when the promise is made.
	$in_play = array( trim( (string) sn_prov_pubkey_b64() ) );
	foreach ( sn_prov_key_history_raw() as $row ) {
		$in_play[] = trim( (string) ( $row['public_key_base64'] ?? '' ) );
	}
	foreach ( $in_play as $used ) {
		if ( '' !== $used && hash_equals( $used, $b64 ) ) {
			return $fail( 'commitment-not-safe' );
		}
	}

	$commitment = hash( 'sha256', $raw );
	// Belt and braces: the shared safety predicate also rejects the hex of any
	// key we can see, so a future caller cannot smuggle key bytes in as a hash.
	if ( ! sn_prov_commitment_is_safe( $commitment ) ) {
		return $fail( 'commitment-not-safe' );
	}

	update_option( 'sn_prov_next_key_commitment', array(
		'value'        => $commitment,
		'committed_at' => (string) $committed_at,
	) );

	return array( 'ok' => true, 'code' => '', 'commitment' => $commitment );
}

/**
 * Perform the rotation: retire the active key and promote the committed one.
 *
 * ORDER IS LOAD-BEARING, and it is the runbook's publish-before-signing rule
 * applied in-process: the outgoing key is written into history BEFORE the new
 * key becomes active. If anything were to fail between the two, the recoverable
 * state is "a retired key is published that is still active" — harmless, and
 * self-correcting on retry. The reverse order fails to "the active key changed
 * and the key that signed every historical Note is published nowhere", which is
 * precisely the breakage the whole detector layer exists to shout about.
 *
 * @param string $revealed_public_key_b64 The successor key, now revealed.
 * @param string $new_key_id              Its published id.
 * @param string $rotated_at              ISO date of the handover.
 * @return array{ok:bool,code:string}
 */
function sn_prov_rotate_to( $revealed_public_key_b64, $new_key_id, $rotated_at ) {
	$fail = static function ( $code ) {
		return array( 'ok' => false, 'code' => $code );
	};

	// Refuse anything that could not take effect, BEFORE writing a single row.
	$pre = sn_prov_rotation_preflight();
	foreach ( $pre['blockers'] as $blocker ) {
		if ( 'no-commitment' !== $blocker ) {
			return $fail( $blocker ); // an inert write is worse than no write.
		}
	}

	$commitment = sn_prov_next_key_commitment();
	if ( null === $commitment ) {
		return $fail( 'no-commitment' );
	}

	// THE enforcement. A reveal that does not hash to the published commitment
	// is not the key we promised, whatever else it may be.
	if ( ! sn_prov_rotation_reveal_matches( $revealed_public_key_b64, $commitment['value'] ) ) {
		return $fail( 'reveal-mismatch' );
	}

	$key_id = trim( (string) $new_key_id );
	if ( ! preg_match( '/^[a-z0-9-]{1,80}$/', $key_id ) ) {
		return $fail( 'bad-key-id' );
	}
	$when = trim( (string) $rotated_at );
	if ( '' === $when ) {
		return $fail( 'bad-date' );
	}

	$outgoing_b64 = trim( (string) sn_prov_pubkey_b64() );
	$outgoing_id  = trim( (string) sn_prov_key_id() );
	if ( '' === $outgoing_b64 || '' === $outgoing_id ) {
		return $fail( 'no-active-key' );
	}

	// 1. Retire the outgoing key FIRST, under the id it was published as, with a
	//    closed window. Appended, never replacing history.
	$history   = sn_prov_key_history_raw();
	$history[] = array(
		'id'                => $outgoing_id,
		'public_key_base64' => $outgoing_b64,
		'valid_from'        => trim( (string) sn_prov_key_introduced_at() ),
		'valid_until'       => $when,
	);
	update_option( 'sn_prov_key_history', $history );

	// 2. Only now promote the successor.
	update_option( 'sn_prov_pubkey_b64', trim( (string) $revealed_public_key_b64 ) );
	update_option( 'sn_prov_pubkey_id', $key_id );
	update_option( 'sn_prov_key_introduced_at', $when );

	// 3. Consume the commitment. It described THIS handover; leaving it standing
	//    would let the same promise authorise a second, different rotation.
	delete_option( 'sn_prov_next_key_commitment' );

	return array( 'ok' => true, 'code' => '' );
}

/**
 * The id a key rotated on this date should be published under.
 *
 * DERIVED, never typed. The id is what every record carries in `pubkey_id` and
 * what a verifier resolves by name, so a hand-typed one is an extra thing that
 * can disagree with the key it names. An unparseable date yields '' — no id
 * beats a wrong one, and sn_prov_rotate_to() refuses '' on its own.
 *
 * @param string $date ISO date.
 * @return string
 */
function sn_prov_next_key_id_for( $date ) {
	if ( ! preg_match( '/^(\d{4})-(\d{2})(?:-\d{2})?$/', trim( (string) $date ), $m ) ) {
		return '';
	}
	return 'sn-ed25519-' . $m[1] . '-' . $m[2];
}

/**
 * Ask the Worker for the STAGED successor key's public half.
 *
 * The Worker holds the private key; this asks it to derive and return the
 * public one, so no human copies key material between systems. Signed with the
 * same HMAC as every other Worker call.
 *
 * @return array{ok:bool,code:string,configured:bool,public_key_base64:string,sha256_commitment:string}
 */
function sn_prov_fetch_next_key() {
	$fail = static function ( $code ) {
		return array( 'ok' => false, 'code' => $code, 'configured' => false, 'public_key_base64' => '', 'sha256_commitment' => '' );
	};
	$base   = trim( (string) sn_prov_worker_url() );
	$secret = (string) sn_prov_hmac_secret();
	if ( '' === $base || '' === $secret ) {
		return $fail( 'worker-not-configured' );
	}
	$body     = wp_json_encode( array() );
	$response = wp_remote_post( rtrim( $base, '/' ) . '/next-key', array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'        => $body,
	) );
	if ( is_wp_error( $response ) ) {
		return $fail( 'worker-unreachable' );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return $fail( 'worker-unreachable' );
	}
	$out = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $out ) ) {
		return $fail( 'worker-unreadable' );
	}
	if ( empty( $out['configured'] ) ) {
		return $fail( 'no-successor-staged' );
	}
	return array(
		'ok'                => true,
		'code'              => '',
		'configured'        => true,
		'public_key_base64' => trim( (string) ( $out['public_key_base64'] ?? '' ) ),
		'sha256_commitment' => trim( (string) ( $out['sha256_commitment'] ?? '' ) ),
	);
}

/**
 * Publish a commitment to whatever successor the Worker has staged.
 *
 * The Worker also returns its own sha256, and we do NOT use it. The commitment
 * is recomputed here from the KEY BYTES, because a commitment is a promise this
 * site makes: taking the digest on trust would mean publishing, permanently, a
 * promise about bytes we never checked — and a corrupted or dishonest response
 * would commit us to something nothing can fulfil. Their hash is corroboration,
 * reported as such, never the source.
 *
 * @param string $when ISO date.
 * @return array{ok:bool,code:string,commitment:string,corroborated:bool}
 */
function sn_prov_stage_commitment( $when ) {
	$fetched = sn_prov_fetch_next_key();
	if ( ! $fetched['ok'] ) {
		return array( 'ok' => false, 'code' => $fetched['code'], 'commitment' => '', 'corroborated' => false );
	}
	$result = sn_prov_commit_next_key( $fetched['public_key_base64'], $when );
	$result['corroborated'] = ( '' !== $fetched['sha256_commitment'] && hash_equals( $result['commitment'], $fetched['sha256_commitment'] ) );
	return $result;
}

/**
 * Rotate to the staged successor: fetch it, prove it against the commitment,
 * retire the outgoing key, promote it. Nothing is typed by hand.
 *
 * @param string $when ISO date of the handover.
 * @return array{ok:bool,code:string}
 */
function sn_prov_perform_rotation( $when ) {
	$fetched = sn_prov_fetch_next_key();
	if ( ! $fetched['ok'] ) {
		return array( 'ok' => false, 'code' => $fetched['code'] );
	}
	$key_id = sn_prov_next_key_id_for( $when );
	if ( '' === $key_id ) {
		return array( 'ok' => false, 'code' => 'bad-date' );
	}
	return sn_prov_rotate_to( $fetched['public_key_base64'], $key_id, $when );
}

/**
 * admin_post_sn_prov_stage_key handler: nonce + manage_options gated.
 * Publishes a commitment to whatever successor the Worker has staged.
 */
function sn_prov_admin_stage_key_handler() {
	check_admin_referer( 'sn_prov_stage_key' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	$r = sn_prov_stage_commitment( gmdate( 'Y-m-d' ) );
	wp_safe_redirect( sn_prov_admin_rotation_url( $r['ok'] ? 'staged' : ( 'e-' . $r['code'] ) ) );
	exit;
}

/**
 * admin_post_sn_prov_rotate_key handler: nonce + manage_options gated.
 *
 * Deliberately a SECOND button rather than a confirmation dialog. A rotation is
 * only possible once a commitment has been published by the button above, so
 * the ceremony is already two separate, deliberate acts — and the gap between
 * them is exactly the property the commitment exists to create.
 */
function sn_prov_admin_rotate_key_handler() {
	check_admin_referer( 'sn_prov_rotate_key' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'signal-and-noise-tools' ), '', array( 'response' => 403 ) );
	}
	$r = sn_prov_perform_rotation( gmdate( 'Y-m-d' ) );
	wp_safe_redirect( sn_prov_admin_rotation_url( $r['ok'] ? 'rotated' : ( 'e-' . $r['code'] ) ) );
	exit;
}

/**
 * Redirect target back to Tools → Provenance carrying a rotation result flag.
 *
 * @param string $result
 * @return string
 */
function sn_prov_admin_rotation_url( $result ) {
	return add_query_arg(
		array(
			'page'           => 'sn-theme-options',
			'tab'            => 'tools',
			'sub'            => 'provenance',
			'sn_prov_rotate' => $result,
		),
		admin_url( 'admin.php' )
	);
}

if ( ! defined( 'SN_PROV_DID_TEST' ) || ! SN_PROV_DID_TEST ) {
	add_action( 'admin_post_sn_prov_stage_key', 'sn_prov_admin_stage_key_handler' );
	add_action( 'admin_post_sn_prov_rotate_key', 'sn_prov_admin_rotate_key_handler' );
}

/**
 * The "Key rotation" fieldset: what is staged, what blocks, and the two buttons.
 *
 * READ-ONLY except for the two nonce-gated actions. There is no field here for
 * the key, the id or the date: the key comes from the Worker, the id is derived
 * from the rotation month, and the date is today. A rotation is a ceremony with
 * a mandatory order, and a button that performs the whole ordered ceremony is
 * the only shape of control that cannot be used in the wrong order — which is
 * exactly why this is buttons and not settings.
 */
function sn_prov_admin_render_rotation_fieldset() {
	$pre        = sn_prov_rotation_preflight();
	$commitment = sn_prov_next_key_commitment();

	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Key rotation', 'signal-and-noise-tools' ) . '</h2>';

	echo '<table class="widefat striped sn-prov-config"><tbody><tr><td>'
		. esc_html__( 'Commitment to the next key', 'signal-and-noise-tools' ) . '</td><td>';
	if ( null === $commitment ) {
		echo '<span class="sn-pill sn-pill--muted">' . esc_html__( 'none published', 'signal-and-noise-tools' ) . '</span>';
	} else {
		echo '<code>' . esc_html( substr( $commitment['value'], 0, 16 ) ) . '…</code> '
			. '<span class="sn-pill sn-pill--ok">' . esc_html( sprintf(
				/* translators: %s: ISO date. */
				__( 'committed %s', 'signal-and-noise-tools' ),
				$commitment['committed_at']
			) ) . '</span>';
	}
	echo '</td></tr></tbody></table>';

	// Blockers are shown even when no rotation is staged: a constant-pinned key
	// makes every button here inert, and the operator should learn that BEFORE
	// staging a commitment they cannot then act on.
	$explain = array(
		'active-key-is-a-constant'    => __( 'SN_PROV_PUBKEY_B64 is set in wp-config.php, so a rotation could not change the active key. Remove the constant first.', 'signal-and-noise-tools' ),
		'key-id-is-a-constant'        => __( 'SN_PROV_PUBKEY_ID is set in wp-config.php, so the new key id could not take effect. Remove the constant first.', 'signal-and-noise-tools' ),
		'introduced-at-is-a-constant' => __( 'SN_PROV_KEY_INTRODUCED_AT is set in wp-config.php, so the new date could not take effect. Remove the constant first.', 'signal-and-noise-tools' ),
	);
	foreach ( $pre['blockers'] as $blocker ) {
		if ( isset( $explain[ $blocker ] ) ) {
			echo '<p><span class="sn-pill sn-pill--warn">' . esc_html__( 'blocked', 'signal-and-noise-tools' ) . '</span> '
				. esc_html( $explain[ $blocker ] ) . '</p>';
		}
	}

	$inert = (bool) array_intersect( $pre['blockers'], array_keys( $explain ) );

	if ( null === $commitment && ! $inert ) {
		sn_prov_admin_rotation_button(
			'sn_prov_stage_key',
			__( 'Publish a commitment to the staged key', 'signal-and-noise-tools' ),
			__( 'Asks the Worker for the successor key it holds, hashes it here, and publishes that hash — so the key that later appears can be checked against the one promised.', 'signal-and-noise-tools' )
		);
	} elseif ( null !== $commitment && ! $inert ) {
		sn_prov_admin_rotation_button(
			'sn_prov_rotate_key',
			__( 'Rotate to the committed key', 'signal-and-noise-tools' ),
			__( 'Retires the current key into the published history with a closed validity window, then promotes the committed successor. Refused unless the key the Worker returns hashes to the commitment above.', 'signal-and-noise-tools' )
		);
	}
	echo '</div>';
}

/**
 * One nonce-gated rotation button with its consequence stated beside it.
 *
 * @param string $action admin_post action name (also the nonce name).
 * @param string $label  Button label.
 * @param string $what   What pressing it does.
 */
function sn_prov_admin_rotation_button( $action, $label, $what ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( $action );
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	echo '<p class="sn-prov-muted">' . esc_html( $what ) . '</p>';
	echo '<p><button type="submit" class="button">' . esc_html( $label ) . '</button></p>';
	echo '</form>';
}
