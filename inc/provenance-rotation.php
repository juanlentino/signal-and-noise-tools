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
