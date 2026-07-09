<?php
/**
 * Signal & Noise Tools — Notes provenance: WordPress ↔ Worker glue.
 *
 * Dispatches a signed webhook per commit, receives ed25519-verified
 * confirmations, and reconciles missed callbacks. No signing key lives here;
 * WordPress only verifies (public key) and shares the outbound HMAC secret.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_PROV_CONFIRM_HOOK = 'sn_prov_reconcile';

/** Prefer a wp-config constant; fall back to an option. */
function sn_prov_config( $const, $option ) {
	if ( defined( $const ) ) {
		return (string) constant( $const );
	}
	return (string) get_option( $option, '' );
}

function sn_prov_worker_url() {
	return sn_prov_config( 'SN_PROV_WORKER_URL', 'sn_prov_worker_url' );
}
function sn_prov_hmac_secret() {
	return sn_prov_config( 'SN_PROV_HMAC_SECRET', 'sn_prov_hmac_secret' );
}
function sn_prov_pubkey_b64() {
	return sn_prov_config( 'SN_PROV_PUBKEY_B64', 'sn_prov_pubkey_b64' );
}

/**
 * POST an HMAC-signed webhook to the Worker for a new commit, then record the
 * returned signature + pending status on the chain entry. Silent on transport
 * failure — the reconcile cron (Task 5) catches up.
 *
 * @param int    $post_id
 * @param array  $commit
 * @param string $canonical The exact bytes that were hashed.
 */
function sn_prov_dispatch( $post_id, $commit, $canonical ) {
	$url    = sn_prov_worker_url();
	$secret = sn_prov_hmac_secret();
	if ( '' === $url || '' === $secret ) {
		return;
	}
	$body = wp_json_encode( array(
		'canonical'    => $canonical,
		'content_hash' => $commit['content_hash'],
		'note_uid'     => sn_prov_note_uid( $post_id ),
		'version'      => (int) $commit['version'],
	) );
	$response = wp_remote_post( $url, array(
		'timeout' => 15,
		'headers' => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'    => $body,
	) );
	if ( is_wp_error( $response ) ) {
		return;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return;
	}
	$out = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $out ) ) {
		return;
	}
	sn_prov_update_commit( (int) $post_id, (int) $commit['version'], array(
		'status'      => isset( $out['ots_status'] ) ? (string) $out['ots_status'] : 'pending',
		'signature'   => (string) ( $out['signature'] ?? '' ),
		'pubkey_id'   => (string) ( $out['pubkey_id'] ?? '' ),
		'ledger_path' => (string) ( $out['ledger_path'] ?? '' ),
	) );
}
add_action( 'sn_prov_committed', 'sn_prov_dispatch', 10, 3 );

/**
 * Merge fields into the chain entry matching $version. Returns true if found.
 *
 * @param int   $post_id
 * @param int   $version
 * @param array $fields
 * @return bool
 */
function sn_prov_update_commit( $post_id, $version, array $fields ) {
	$chain = sn_prov_get_chain( $post_id );
	foreach ( $chain as $i => $entry ) {
		if ( (int) ( $entry['version'] ?? 0 ) === (int) $version ) {
			$chain[ $i ] = array_merge( $entry, $fields );
			update_post_meta( (int) $post_id, SN_PROV_CHAIN_META, $chain );
			return true;
		}
	}
	return false;
}
