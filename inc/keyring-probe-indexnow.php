<?php
/**
 * Signal & Noise Tools — the keyring's IndexNow probe. The key is public by
 * design, so its other half is the file a search engine reads: /<key>.txt at
 * the site root (inc/indexnow.php serves it). The probe fetches that URL
 * through the public edge, the way an engine does, and passes only when the
 * body is this key. Neither the key nor the body ever enters a verdict.
 *
 * No cache-buster: a rotated key is a new path, so the edge holds no stale
 * copy of it, and the plain URL is the one the engines are given.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return array{status:string,detail:string,at:int} */
function sn_keyring_probe_indexnow() {
	$key  = sn_credential( 'indexnow_key' );
	$resp = wp_remote_get( home_url( '/' . rawurlencode( $key ) . '.txt' ), array( 'timeout' => 6, 'redirection' => 0, 'sslverify' => true ) );
	if ( is_wp_error( $resp ) ) {
		return sn_keyring_verdict( 'error', $resp->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( 200 === $code ) {
		return hash_equals( $key, trim( (string) wp_remote_retrieve_body( $resp ) ) )
			? sn_keyring_verdict( 'ok', __( 'The key file answers this key at its public URL.', 'signal-and-noise-tools' ) )
			: sn_keyring_verdict( 'refused', __( 'The key URL answers, but not with this key: search engines would reject the pings.', 'signal-and-noise-tools' ) );
	}
	/* translators: %d: HTTP status. */
	$said = sprintf( __( 'The key URL answered HTTP %d.', 'signal-and-noise-tools' ), $code );
	if ( $code >= 400 && $code < 500 ) {
		return sn_keyring_verdict( 'refused', $said . ' ' . __( 'The key file is served only while IndexNow is on; search engines would reject the pings.', 'signal-and-noise-tools' ) );
	}
	return sn_keyring_verdict( 'error', $said );
}
