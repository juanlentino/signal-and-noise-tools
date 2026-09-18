<?php
/**
 * Signal & Noise Tools -- Zenodo client (15.11.0).
 *
 * The documented deposit API (developers.zenodo.org): create a deposition,
 * PUT each file into the record's bucket, PUT the metadata, POST publish.
 * Zenodo runs on InvenioRDM; this deposit API is its maintained compatibility
 * layer and the one the docs describe, which is the documented way.
 *
 * Two environments, two tokens, one switch: `sandbox.zenodo.org` mints
 * 10.5072 DOIs that resolve nowhere and never flow into public surfaces;
 * production mints 10.5281. Every call is a bearer request with a 15 s cap,
 * no redirects followed, and every answer comes back as {ok, code, body};
 * nothing here throws. See docs/zenodo-doi-design.md.
 *
 * @package SignalNoiseTools
 * @since 15.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sandbox switch: an option, so the leaf can flip it. */
const SN_ZENODO_ENV_OPT = 'sn_zenodo_env';

/** Per-post meta: the minted identifiers and the last error. */
const SN_ZENODO_DOI_META         = '_sn_zenodo_doi';
const SN_ZENODO_CONCEPT_DOI_META = '_sn_zenodo_concept_doi';
const SN_ZENODO_RECORD_META      = '_sn_zenodo_record_id';
const SN_ZENODO_ENV_META         = '_sn_zenodo_env';
const SN_ZENODO_AT_META          = '_sn_zenodo_deposited_at';
const SN_ZENODO_DRAFT_META       = '_sn_zenodo_draft_id';
const SN_ZENODO_ERROR_META       = '_sn_zenodo_last_error';

/**
 * Which environment the site deposits to: 'sandbox' (default) or 'production'.
 *
 * @since 15.11.0
 * @return string
 */
function sn_zenodo_env() {
	$env = (string) get_option( SN_ZENODO_ENV_OPT, 'sandbox' );
	return 'production' === $env ? 'production' : 'sandbox';
}

/**
 * The API base for an environment. PURE.
 *
 * @since 15.11.0
 * @param string $env 'sandbox' | 'production'.
 * @return string
 */
function sn_zenodo_api_base( $env ) {
	return 'production' === (string) $env ? 'https://zenodo.org/api' : 'https://sandbox.zenodo.org/api';
}

/**
 * The keyring id that holds the token for an environment. PURE.
 *
 * @since 15.11.0
 * @param string $env 'sandbox' | 'production'.
 * @return string
 */
function sn_zenodo_token_id( $env ) {
	return 'production' === (string) $env ? 'zenodo_token' : 'zenodo_sandbox_token';
}

/**
 * The token for an environment, from the keyring.
 *
 * @since 15.11.0
 * @param string|null $env Environment; null = the current one.
 * @return string
 */
function sn_zenodo_token( $env = null ) {
	$env = null === $env ? sn_zenodo_env() : (string) $env;
	return function_exists( 'sn_credential' ) ? (string) sn_credential( sn_zenodo_token_id( $env ) ) : '';
}

/**
 * Is a DOI a sandbox one? PURE. Sandbox DOIs carry the 10.5072 test prefix
 * and must never reach a public surface.
 *
 * @since 15.11.0
 * @param string $doi
 * @return bool
 */
function sn_zenodo_doi_is_sandbox( $doi ) {
	return 0 === strpos( (string) $doi, '10.5072/' );
}

/**
 * One bearer request. Returns {ok, code, body, error}; never throws.
 *
 * @since 15.11.0
 * @param string      $method  GET|POST|PUT|DELETE.
 * @param string      $url     Absolute URL.
 * @param mixed       $body    Array (JSON-encoded) or string (raw bytes) or null.
 * @param string|null $env     Environment; null = current.
 * @param array       $headers Extra headers.
 * @return array{ok:bool,code:int,body:mixed,error:string}
 */
function sn_zenodo_request( $method, $url, $body = null, $env = null, array $headers = array() ) {
	$token = sn_zenodo_token( $env );
	if ( '' === $token ) {
		return array( 'ok' => false, 'code' => 0, 'body' => null, 'error' => 'no-token' );
	}
	$args = array(
		'method'      => strtoupper( (string) $method ),
		'timeout'     => 15,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => array_merge(
			array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'User-Agent'    => 'signal-and-noise-tools',
			),
			$headers
		),
	);
	if ( is_array( $body ) ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	} elseif ( is_string( $body ) ) {
		$args['headers']['Content-Type'] = isset( $headers['Content-Type'] ) ? $headers['Content-Type'] : 'application/octet-stream';
		$args['body']                    = $body;
	}
	$resp = wp_remote_request( $url, $args );
	if ( is_wp_error( $resp ) ) {
		return array( 'ok' => false, 'code' => 0, 'body' => null, 'error' => $resp->get_error_message() );
	}
	$code    = (int) wp_remote_retrieve_response_code( $resp );
	$raw     = (string) wp_remote_retrieve_body( $resp );
	$decoded = json_decode( $raw, true );
	$ok      = $code >= 200 && $code < 300;
	return array(
		'ok'    => $ok,
		'code'  => $code,
		'body'  => null === $decoded ? $raw : $decoded,
		'error' => $ok ? '' : ( is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : 'http-' . $code ),
	);
}

/**
 * Create an empty deposition. Returns the deposition (id, links.bucket, ...).
 *
 * @since 15.11.0
 * @return array{ok:bool,code:int,body:mixed,error:string}
 */
function sn_zenodo_create_deposition( $env = null ) {
	return sn_zenodo_request( 'POST', sn_zenodo_api_base( null === $env ? sn_zenodo_env() : $env ) . '/deposit/depositions', array(), $env );
}

/**
 * Read a deposition (the resume path after an interrupted flow).
 *
 * @since 15.11.0
 */
function sn_zenodo_get_deposition( $id, $env = null ) {
	return sn_zenodo_request( 'GET', sn_zenodo_api_base( null === $env ? sn_zenodo_env() : $env ) . '/deposit/depositions/' . rawurlencode( (string) $id ), null, $env );
}

/**
 * PUT one file into the record's bucket (the "new files API" the docs point at).
 *
 * @since 15.11.0
 * @param string $bucket_url links.bucket from the deposition.
 * @param string $filename   Name inside the record.
 * @param string $bytes      File contents.
 */
function sn_zenodo_upload_file( $bucket_url, $filename, $bytes, $env = null, $content_type = 'application/octet-stream' ) {
	return sn_zenodo_request( 'PUT', rtrim( (string) $bucket_url, '/' ) . '/' . rawurlencode( (string) $filename ), (string) $bytes, $env, array( 'Content-Type' => $content_type ) );
}

/**
 * PUT the metadata onto a deposition.
 *
 * @since 15.11.0
 * @param array $metadata The `metadata` object (see sn_zenodo_metadata_for()).
 */
function sn_zenodo_set_metadata( $id, array $metadata, $env = null ) {
	return sn_zenodo_request( 'PUT', sn_zenodo_api_base( null === $env ? sn_zenodo_env() : $env ) . '/deposit/depositions/' . rawurlencode( (string) $id ), array( 'metadata' => $metadata ), $env );
}

/**
 * Publish a deposition: this mints the DOI.
 *
 * @since 15.11.0
 */
function sn_zenodo_publish( $id, $env = null ) {
	return sn_zenodo_request( 'POST', sn_zenodo_api_base( null === $env ? sn_zenodo_env() : $env ) . '/deposit/depositions/' . rawurlencode( (string) $id ) . '/actions/publish', null, $env );
}

/**
 * Open a new version of a published record; the answer's links.latest_draft
 * is the deposition to fill.
 *
 * @since 15.11.0
 */
function sn_zenodo_new_version( $id, $env = null ) {
	return sn_zenodo_request( 'POST', sn_zenodo_api_base( null === $env ? sn_zenodo_env() : $env ) . '/deposit/depositions/' . rawurlencode( (string) $id ) . '/actions/newversion', null, $env );
}
