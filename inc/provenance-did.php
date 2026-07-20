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

/**
 * Off-ledger HTTPS mirror of the active provenance key.
 *
 * @return array<string,mixed>|null
 */
function sn_prov_key_document() {
	$b64 = function_exists( 'sn_prov_pubkey_b64' ) ? trim( (string) sn_prov_pubkey_b64() ) : '';
	$raw = base64_decode( $b64, true );
	if ( false === $raw || 32 !== strlen( $raw ) ) {
		return null;
	}
	return array(
		'schema' => 'sn-provenance-keys-v1',
		'domain' => sn_prov_did_domain(),
		'keys'   => array(
			array(
				'id'                 => sn_prov_key_id(),
				'algorithm'          => 'Ed25519',
				'public_key_base64'  => $b64,
				'sha256_fingerprint' => hash( 'sha256', $raw ),
				'status'             => 'active',
				'introduced_at'      => '2026-07-09',
			),
		),
	);
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
