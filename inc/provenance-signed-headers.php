<?php
/**
 * The headers on every POST to the provenance Worker: one place builds them.
 *
 * v2 envelope (Worker 1.26.0, src/webhook-auth.mjs):
 *   X-SN-Sig-Ts:    unix seconds
 *   X-SN-Sig-Nonce: fresh per request (32 hex chars from random_bytes)
 *   X-SN-Sig:       hex HMAC-SHA256 over `v2|<ts>|<nonce>|<hex sha256(body)>`
 * The Worker refuses a ts more than 300s off and a nonce it has seen in 600s.
 *
 * The v1 body-only X-SN-Signature still rides along for the transition: a
 * Worker that sees any v2 header verifies v2 only, so v1 matters only to an
 * older deploy. Drop it once SN_PROV_ACCEPT_V1 is "0" on the Worker.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'sn_prov_signed_headers' ) ) {
	/**
	 * @param string      $body   The exact bytes sent as the request body.
	 * @param string      $secret The shared webhook secret.
	 * @param int|null    $ts     Unix seconds; now when null (tests pin it).
	 * @param string|null $nonce  Fresh random when null (tests pin it).
	 * @return array<string,string>
	 */
	function sn_prov_signed_headers( $body, $secret, $ts = null, $nonce = null ) {
		$body   = (string) $body;
		$secret = (string) $secret;
		$ts     = (string) ( null === $ts ? time() : (int) $ts );
		$nonce  = null === $nonce ? bin2hex( random_bytes( 16 ) ) : (string) $nonce;
		$signed = 'v2|' . $ts . '|' . $nonce . '|' . hash( 'sha256', $body );
		return array(
			'Content-Type'   => 'application/json',
			'X-SN-Sig-Ts'    => $ts,
			'X-SN-Sig-Nonce' => $nonce,
			'X-SN-Sig'       => hash_hmac( 'sha256', $signed, $secret ),
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		);
	}
}
