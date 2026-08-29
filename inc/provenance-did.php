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
	$did     = sn_prov_did_id();
	$methods = array(
		array(
			'id'           => $did . '#prov-key-1',
			'type'         => 'JsonWebKey2020',
			'controller'   => $did,
			'publicKeyJwk' => array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => sn_prov_base64url( $raw ) ),
		),
	);

	// RETIRED KEYS ARE NAMED HERE, AND ONLY HERE (v13.37.0).
	//
	// The two lists below are not redundant, and the split is the entire
	// mechanism: `verificationMethod` is the key MATERIAL this DID vouches
	// for; `assertionMethod` is the subset authorised to assert RIGHT NOW.
	// A retired key belongs in the first and must never enter the second —
	// listing it as an assertion method would publish, as fact, that a key
	// we deliberately rotated away from may still sign for us today.
	//
	// Why it has to exist at all: every credential names its signer in
	// proof.pubkey_id, but did.json carried ONE method under a fixed
	// '#prov-key-1' fragment. A third party doing ordinary did:web
	// resolution could resolve only the active key, so at the first
	// rotation every correctly-signed historical Note would read as
	// unverifiable to anyone trusting the DID rather than our keys mirror.
	// did:web has no versionTime, so there is no "resolve the document as
	// of 2025" to fall back on — the current document is the only document.
	//
	// STRICTLY ADDITIVE: '#prov-key-1' keeps its position and its meaning,
	// and assertionMethod is untouched, so no credential already issued
	// changes meaning. With no history configured this emits precisely the
	// document it always did (pinned).
	//
	// Fed by sn_prov_key_history(), the SAME validated producer the keys
	// mirror consumes — a malformed row is dropped in one place, not two.
	// Note this path is exercised by fixtures only: nothing yet WRITES
	// sn_prov_key_history (see docs/ops/key-rotation-runbook.md), so its
	// first live use will be the first real rotation.
	foreach ( sn_prov_key_history() as $retired ) {
		$bytes = base64_decode( (string) $retired['public_key_base64'], true );
		if ( false === $bytes || 32 !== strlen( $bytes ) ) {
			continue; // belt and braces; sn_prov_key_history() already dropped these.
		}
		$methods[] = array(
			'id'           => $did . '#' . $retired['id'],
			'type'         => 'JsonWebKey2020',
			'controller'   => $did,
			'publicKeyJwk' => array( 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => sn_prov_base64url( $bytes ) ),
		);
	}

	return array(
		'@context'           => array( 'https://www.w3.org/ns/did/v1', 'https://w3id.org/security/suites/jws-2020/v1' ),
		'id'                 => $did,
		'verificationMethod' => $methods,
		'assertionMethod'    => array( $did . '#prov-key-1' ),
	);
}

/**
 * Stable public identifier for the active signing key.
 *
 * Resolves the same way the public key beside it already does — constant, else
 * option, else the shipped default. Before v13.37.0 this was a filter default
 * hardcoded here, which put a PLUGIN RELEASE on the critical path of a key
 * rotation: changing the id meant editing PHP and shipping. The key bytes were
 * already config (`SN_PROV_PUBKEY_B64`), so the id being code was an asymmetry,
 * not a decision.
 *
 * A BLANK config value falls through to the default rather than winning. An
 * active key entry published with `id: ""` is worse than one published with a
 * stale id: the id is what every record names in `pubkey_id`, and a verifier
 * resolving by name would find nothing at all.
 *
 * The filter is kept and still wins, so existing extension points are unchanged.
 *
 * @return string
 */
/**
 * WHERE a provenance config value is coming from — 'constant', 'option',
 * 'blank-constant' or 'default'. Read-only diagnosis; changes nothing.
 *
 * This exists because sn_prov_config() returns the CONSTANT the moment it is
 * DEFINED, blank included, and never falls through to the option. So a
 * wp-config.php constant that is present but empty (a typo, a half-finished
 * edit) shadows a perfectly correct option AND lands on the shipped default —
 * three states that look identical from the served value alone.
 *
 * 'blank-constant' is deliberately NOT folded into 'default'. The served value
 * is the same, but the cause and the fix are not: one means "nothing is
 * configured", the other means "something IS configured and is being ignored,
 * and the file to edit is wp-config.php". During a rotation that is the
 * difference between a two-minute fix and hunting the wrong layer.
 *
 * Mirrors sn_prov_key_id()'s own precedence, including treating a whitespace
 * value as unset, so the reported source cannot disagree with the value served.
 *
 * @param string $const  Constant name.
 * @param string $option Option name.
 * @return string one of: constant|blank-constant|option|default
 */
function sn_prov_key_config_source( $const, $option ) {
	if ( defined( $const ) ) {
		return '' !== trim( (string) constant( $const ) ) ? 'constant' : 'blank-constant';
	}
	return '' !== trim( (string) get_option( $option, '' ) ) ? 'option' : 'default';
}

function sn_prov_key_id() {
	$configured = trim( (string) sn_prov_config( 'SN_PROV_PUBKEY_ID', 'sn_prov_pubkey_id' ) );
	return (string) apply_filters( 'sn_prov_pubkey_id', '' !== $configured ? $configured : 'sn-ed25519-2026-07' );
}

/**
 * The date the active key came into use. Config-resolved for the same reason as
 * the id above: it changes at a rotation, and a rotation should not need a
 * release. Blank falls through to the default.
 *
 * @return string
 */
function sn_prov_key_introduced_at() {
	$configured = trim( (string) sn_prov_config( 'SN_PROV_KEY_INTRODUCED_AT', 'sn_prov_key_introduced_at' ) );
	return (string) apply_filters( 'sn_prov_key_introduced_at', '' !== $configured ? $configured : '2026-07-09' );
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
