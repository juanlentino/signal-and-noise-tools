<?php
/**
 * Signal & Noise Tools: the author's own key, published beside the publisher's.
 *
 * The provenance Worker signs every note at publish with the publisher key.
 * The author also holds an Ed25519 SSH key and uses it to countersign ledger
 * records (signal-and-noise-provenance, countersign.mjs). The ledger accepts
 * that key only when it is pinned outside GitHub, as the publisher key is:
 * here, as a role "author" entry in /.well-known/provenance-keys.json and a
 * verification method in did.json, and as a DNS TXT record the owner sets.
 *
 * The key is public material, configured in the `sn_prov_author_key` option:
 * { id, public_key_base64, introduced_at }. Nothing is published until it is
 * set, so an unconfigured site serves exactly the documents it served before.
 *
 * It ONLY countersigns. It never enters did.json's assertionMethod, so no
 * credential reader can take it for a key that signs notes, and readers that
 * pick "the active key" skip it by role.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The configured author key, validated, or null. A malformed value publishes
 * nothing: a half-formed key served as fact is worse than an absent one.
 *
 * @return array{id:string,public_key_base64:string,sha256_fingerprint:string,introduced_at:string}|null
 */
function sn_prov_author_key() {
	$conf = apply_filters( 'sn_prov_author_key', get_option( 'sn_prov_author_key', array() ) );
	if ( ! is_array( $conf ) ) {
		return null;
	}
	$id   = trim( (string) ( $conf['id'] ?? '' ) );
	$b64  = trim( (string) ( $conf['public_key_base64'] ?? '' ) );
	$date = trim( (string) ( $conf['introduced_at'] ?? '' ) );
	$raw  = '' === $b64 ? false : base64_decode( $b64, true );
	if ( ! preg_match( '/^sn-author-ed25519-\d{4}-\d{2}$/', $id ) || false === $raw || 32 !== strlen( $raw ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
		return null;
	}
	// The author key must never be the publisher key: that would put both
	// signatures in one hand, which is what the ledger refuses too.
	$publisher = function_exists( 'sn_prov_pubkey_b64' ) ? trim( (string) sn_prov_pubkey_b64() ) : '';
	if ( '' !== $publisher && hash_equals( $publisher, $b64 ) ) {
		return null;
	}
	return array(
		'id'                 => $id,
		'public_key_base64'  => $b64,
		'sha256_fingerprint' => hash( 'sha256', $raw ),
		'introduced_at'      => $date,
	);
}

/**
 * The author key's row in provenance-keys.json, in the shape the ledger's
 * verify-key-pins.mjs compares field by field.
 *
 * @return array<string,mixed>|null
 */
function sn_prov_author_key_entry() {
	$key = sn_prov_author_key();
	if ( null === $key ) {
		return null;
	}
	return array(
		'id'                 => $key['id'],
		'algorithm'          => 'Ed25519',
		'role'               => 'author',
		'public_key_base64'  => $key['public_key_base64'],
		'sha256_fingerprint' => $key['sha256_fingerprint'],
		'status'             => 'active',
		'introduced_at'      => $key['introduced_at'],
		'valid_from'         => $key['introduced_at'],
		'valid_until'        => null,
	);
}

/**
 * The DNS TXT value the owner sets at _provenance-author.<domain>, in the same
 * format as the publisher's _provenance record. Shown, never set, by the site.
 *
 * @return string|null
 */
function sn_prov_author_key_txt() {
	$key = sn_prov_author_key();
	if ( null === $key ) {
		return null;
	}
	return sprintf( 'v=sn-prov1; id=%s; alg=Ed25519; key=%s; sha256=%s', $key['id'], $key['public_key_base64'], $key['sha256_fingerprint'] );
}
