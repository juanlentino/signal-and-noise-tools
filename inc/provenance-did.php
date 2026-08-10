<?php
/**
 * Signal & Noise — verifiable provenance (D1): the did:web DID document. Publishes
 * the provenance Ed25519 public key at /.well-known/did.json so a verifier can
 * check the signature carried by each Note's Verifiable Credential. Serves nothing
 * secret. Flush-free virtual route (template_redirect pri 0), same mechanism as the
 * theme's /.well-known/gpc.json.
 *
 * @package SignalNoiseTools
 * @since 9.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base64url (RFC 4648 §5): + → -, / → _, no padding.
 *
 * @param string $bin
 * @return string
 */
function sn_prov_base64url( $bin ) {
	return rtrim( strtr( base64_encode( (string) $bin ), '+/', '-_' ), '=' );
}

/**
 * The did:web domain = the site host (no port on this site).
 *
 * @return string
 */
function sn_prov_did_domain() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return (string) $host;
}

/** @return string did:web:<host> */
function sn_prov_did_id() {
	return 'did:web:' . sn_prov_did_domain();
}

/** @return string the single verificationMethod id */
function sn_prov_did_verification_method_id() {
	return sn_prov_did_id() . '#prov-key-1';
}

/**
 * Build the DID document, or null if no (valid 32-byte Ed25519) public key is
 * configured — in which case the whole VC surface is moot and did.json 404s.
 *
 * @return array<string,mixed>|null
 */
function sn_prov_did_document() {
	$b64 = function_exists( 'sn_prov_pubkey_b64' ) ? (string) sn_prov_pubkey_b64() : '';
	if ( '' === $b64 ) {
		return null;
	}
	$raw = base64_decode( $b64, true );
	if ( false === $raw || 32 !== strlen( $raw ) ) {
		return null; // an Ed25519 public key is exactly 32 bytes.
	}
	$did = sn_prov_did_id();
	return array(
		'@context'           => array( 'https://www.w3.org/ns/did/v1', 'https://w3id.org/security/suites/jws-2020/v1' ),
		'id'                 => $did,
		'verificationMethod' => array(
			array(
				'id'           => $did . '#prov-key-1',
				'type'         => 'JsonWebKey2020',
				'controller'   => $did,
				'publicKeyJwk' => array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => sn_prov_base64url( $raw ) ),
			),
		),
		'assertionMethod'    => array( $did . '#prov-key-1' ),
	);
}

/** @return string Stable public identifier for the active signing key. */
function sn_prov_key_id() {
	return (string) apply_filters( 'sn_prov_pubkey_id', 'sn-ed25519-2026-07' );
}

/** @return string The date the active key came into use. */
function sn_prov_key_introduced_at() {
	return (string) apply_filters( 'sn_prov_key_introduced_at', '2026-07-09' );
}

/**
 * Is $value shaped like a commitment (a hash) rather than a key?
 *
 * WHAT THIS CAN AND CANNOT DO, because the difference matters:
 *
 *   CAN reject a base64 public key (wrong alphabet), anything not in canonical
 *   lowercase sha256 form, and the hex encoding of a key we can actually SEE —
 *   the active key or any configured historical one. That last case is the
 *   realistic mistake: pasting a key you already hold into the commitment field.
 *
 *   CANNOT tell a digest from the raw bytes of a key it has never seen. A
 *   sha256 digest and an Ed25519 public key are both exactly 32 bytes, so
 *   hex-encoded both are 64 characters. There is no predicate here.
 *
 * What actually makes the commitment binding is not this function but
 * sn_prov_rotation_reveal_matches(), which compares a reveal THROUGH sha256: a
 * commitment holding key bytes can never validate its own rotation, so the
 * mistake is caught at the moment it would matter.
 *
 * @param string $value Candidate commitment.
 * @return bool
 */
function sn_prov_commitment_is_safe( $value ) {
	$value = (string) $value;
	if ( ! preg_match( '/^[0-9a-f]{64}$/', $value ) ) {
		return false;
	}
	// Reject the hex of any key we can see.
	$known = array();
	if ( function_exists( 'sn_prov_pubkey_b64' ) ) {
		$known[] = trim( (string) sn_prov_pubkey_b64() );
	}
	foreach ( sn_prov_key_history_raw() as $row ) {
		$known[] = trim( (string) ( $row['public_key_base64'] ?? '' ) );
	}
	foreach ( $known as $b64 ) {
		if ( '' === $b64 ) {
			continue;
		}
		$raw = base64_decode( $b64, true );
		if ( false !== $raw && hash_equals( bin2hex( $raw ), $value ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Does a revealed public key match the commitment made before it was used?
 *
 * This is the whole enforcement. A rotation is only legitimate if the key now
 * being revealed hashes to the value published while it was still secret.
 *
 * @param string $revealed_b64 The newly revealed public key, base64.
 * @param string $commitment   The previously published sha256 hex commitment.
 * @return bool
 */
function sn_prov_rotation_reveal_matches( $revealed_b64, $commitment ) {
	$commitment = (string) $commitment;
	if ( ! preg_match( '/^[0-9a-f]{64}$/', $commitment ) ) {
		return false;
	}
	$raw = base64_decode( trim( (string) $revealed_b64 ), true );
	if ( false === $raw || 32 !== strlen( $raw ) ) {
		return false;
	}
	return hash_equals( $commitment, hash( 'sha256', $raw ) );
}

/**
 * Configured historical keys, unvalidated. Internal — callers want
 * sn_prov_key_history().
 *
 * @return array<int,array<string,mixed>>
 */
function sn_prov_key_history_raw() {
	$rows = get_option( 'sn_prov_key_history', array() );
	$rows = apply_filters( 'sn_prov_key_history', $rows );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Retired keys, validated and normalised.
 *
 * A retired key is NOT dropped on rotation: anchors signed under it must keep
 * verifying, which they cannot do if the verifier can no longer find its bytes.
 * That is what the validity window is for — it says "this key was legitimate
 * between these dates", not "this key is gone".
 *
 * Rows without a real 32-byte Ed25519 public key are dropped rather than
 * emitted half-formed; a malformed row published as fact is worse than absent.
 *
 * @return array<int,array<string,mixed>>
 */
function sn_prov_key_history() {
	$out = array();
	foreach ( sn_prov_key_history_raw() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$b64 = trim( (string) ( $row['public_key_base64'] ?? '' ) );
		$raw = '' === $b64 ? false : base64_decode( $b64, true );
		if ( false === $raw || 32 !== strlen( $raw ) ) {
			continue;
		}
		$id = trim( (string) ( $row['id'] ?? '' ) );
		if ( '' === $id ) {
			continue;
		}
		$out[] = array(
			'id'                 => $id,
			'algorithm'          => 'Ed25519',
			'public_key_base64'  => $b64,
			'sha256_fingerprint' => hash( 'sha256', $raw ),
			'status'             => 'retired',
			'valid_from'         => (string) ( $row['valid_from'] ?? '' ),
			'valid_until'        => (string) ( $row['valid_until'] ?? '' ),
		);
	}
	return $out;
}

/**
 * The published commitment to the next key, or null when none is configured or
 * the configured one fails the shape check.
 *
 * @return array<string,mixed>|null
 */
function sn_prov_next_key_commitment() {
	$conf = get_option( 'sn_prov_next_key_commitment', array() );
	$conf = apply_filters( 'sn_prov_next_key_commitment', $conf );
	if ( ! is_array( $conf ) ) {
		return null;
	}
	$value = trim( (string) ( $conf['value'] ?? '' ) );
	if ( '' === $value || ! sn_prov_commitment_is_safe( $value ) ) {
		return null; // absent beats publishing something that is not a commitment.
	}
	return array(
		'algorithm'    => 'sha256',
		'value'        => $value,
		'committed_at' => (string) ( $conf['committed_at'] ?? '' ),
	);
}

/**
 * Off-ledger HTTPS mirror of the provenance keys: the active key, every retired
 * key with its validity window, and a commitment to the successor.
 *
 * ORDERING IS LOAD-BEARING. assets/js/prov-verify-core.js reads
 * `keys[0].public_key_base64`, so the ACTIVE key stays at index 0 and history is
 * appended after it. (That JS also selects by status as of v10.77.0, so this is
 * belt and braces rather than the only thing holding it up — but a consumer we
 * do not control may still be reading index 0.)
 *
 * @return array<string,mixed>|null
 */
function sn_prov_key_document() {
	$b64 = function_exists( 'sn_prov_pubkey_b64' ) ? trim( (string) sn_prov_pubkey_b64() ) : '';
	$raw = '' === $b64 ? false : base64_decode( $b64, true );
	if ( false === $raw || 32 !== strlen( $raw ) ) {
		return null;
	}

	$keys = array(
		array(
			'id'                 => sn_prov_key_id(),
			'algorithm'          => 'Ed25519',
			'public_key_base64'  => $b64,
			'sha256_fingerprint' => hash( 'sha256', $raw ),
			'status'             => 'active',
			'introduced_at'      => sn_prov_key_introduced_at(),
			'valid_from'         => sn_prov_key_introduced_at(),
			'valid_until'        => null, // open window: still in use.
		),
	);
	foreach ( sn_prov_key_history() as $retired ) {
		$keys[] = $retired;
	}

	$doc = array(
		'schema' => 'sn-provenance-keys-v2',
		'domain' => sn_prov_did_domain(),
		'keys'   => $keys,
	);

	$commitment = sn_prov_next_key_commitment();
	if ( null !== $commitment ) {
		$doc['next_key_commitment'] = $commitment;
	}
	return $doc;
}

/**
 * Is this request for /.well-known/did.json? Pure (takes the path).
 *
 * @param string $uri
 * @return bool
 */
function sn_prov_did_is_request( $uri ) {
	$path = strtok( (string) $uri, '?' );
	$path = '/' . trim( (string) $path, '/' );
	return ( '/.well-known/did.json' === $path );
}

/** @param string $uri @return bool */
function sn_prov_keys_is_request( $uri ) {
	$path = strtok( (string) $uri, '?' );
	$path = '/' . trim( (string) $path, '/' );
	return ( '/.well-known/provenance-keys.json' === $path );
}

/**
 * Emit the DID document (200 + application/did+json), or 404 if no key.
 * status_header is REQUIRED (postless path → 404 by template_redirect).
 */
function sn_prov_did_send() {
	$doc = sn_prov_did_document();
	if ( null === $doc ) {
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}
		return;
	}
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	header( 'Content-Type: application/did+json; charset=utf-8' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- application/did+json from wp_json_encode; HTML escaping would corrupt the JSON.
	echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES );
}

/** Emit the off-ledger key mirror, or a truthful 404 without a valid key. */
function sn_prov_keys_send() {
	$doc = sn_prov_key_document();
	if ( null === $doc ) {
		if ( function_exists( 'status_header' ) ) {
			status_header( 404 );
		}
		return;
	}
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 );
	}
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: public, max-age=300' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON endpoint; HTML escaping would corrupt the key.
	echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES );
}

/**
 * template_redirect handler.
 */
function sn_prov_did_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( sn_prov_did_is_request( $req ) ) {
		sn_prov_did_send();
		exit;
	}
	if ( sn_prov_keys_is_request( $req ) ) {
		sn_prov_keys_send();
		exit;
	}
}

if ( ! defined( 'SN_PROV_DID_TEST' ) || ! SN_PROV_DID_TEST ) {
	add_action( 'template_redirect', 'sn_prov_did_maybe_serve', 0 );
}
