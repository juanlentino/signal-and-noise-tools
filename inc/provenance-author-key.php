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
 * pick "the active key" or resolve a key by name refuse it by role.
 *
 * Only the ACTIVE author key is published. Retired author keys stay in the
 * ledger's own key history, which is what verifies old countersignatures; the
 * ledger's pin check covers only the active one, so the site needs no history.
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
	if ( ! preg_match( '/^sn-author-ed25519-\d{4}-\d{2}$/', $id ) || false === $raw || 32 !== strlen( $raw ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $ymd ) || ! checkdate( (int) $ymd[2], (int) $ymd[3], (int) $ymd[1] ) ) {
		return null;
	}
	// The author key must never be a publisher key, compared as key BYTES so a
	// second base64 spelling cannot slip past: that would put both signatures
	// in one hand, which is what the ledger refuses too. Nor may it reuse a
	// publisher id, or a reader resolving by id could land on either row.
	$publishers = array( array( 'id' => function_exists( 'sn_prov_key_id' ) ? sn_prov_key_id() : '', 'public_key_base64' => function_exists( 'sn_prov_pubkey_b64' ) ? (string) sn_prov_pubkey_b64() : '' ) );
	foreach ( function_exists( 'sn_prov_key_history' ) ? sn_prov_key_history() : array() as $row ) {
		$publishers[] = $row;
	}
	foreach ( $publishers as $row ) {
		$bytes = base64_decode( trim( (string) ( $row['public_key_base64'] ?? '' ) ), true );
		if ( ( false !== $bytes && hash_equals( $bytes, $raw ) ) || $id === (string) ( $row['id'] ?? '' ) ) {
			return null;
		}
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
