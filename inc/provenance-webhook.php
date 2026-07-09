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

add_action( 'rest_api_init', 'sn_prov_register_confirm_route' );

function sn_prov_register_confirm_route() {
	register_rest_route( 'sn-prov/v1', '/confirm', array(
		'methods'             => 'POST',
		'callback'            => 'sn_prov_confirm_handler',
		'permission_callback' => 'sn_prov_confirm_permission',
	) );
}

/**
 * Verify the Worker's Ed25519 signature (header X-SN-Ed25519, base64) over the
 * raw body, using the published public key. WP holds no signing secret.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function sn_prov_confirm_permission( $request ) {
	$pub_b64 = sn_prov_pubkey_b64();
	if ( '' === $pub_b64 ) {
		return new WP_Error( 'sn_prov_no_key', 'No public key configured.', array( 'status' => 500 ) );
	}
	$pk  = base64_decode( $pub_b64, true );
	$sig = base64_decode( (string) $request->get_header( 'x_sn_ed25519' ), true );
	$msg = $request->get_body();

	if ( false === $pk || false === $sig
		|| SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pk )
		|| SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
		return new WP_Error( 'sn_prov_bad_sig', 'Malformed signature.', array( 'status' => 401 ) );
	}
	if ( ! sodium_crypto_sign_verify_detached( $sig, $msg, $pk ) ) {
		return new WP_Error( 'sn_prov_bad_sig', 'Invalid signature.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Resolve a note_uid to its post ID via the provenance UID meta.
 *
 * @param string $uid
 * @return int 0 if not found.
 */
function sn_prov_post_by_uid( $uid ) {
	$ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
		'meta_value'  => (string) $uid,
	) );
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Apply a confirmation payload to the matching commit. Returns false if the
 * note or version is unknown.
 *
 * @param string $uid
 * @param int    $version
 * @param array  $data  {status, bitcoin_block?, block_time?}
 * @return bool
 */
function sn_prov_apply_confirmation( $uid, $version, array $data ) {
	$post_id = sn_prov_post_by_uid( $uid );
	if ( ! $post_id ) {
		return false;
	}
	$fields = array( 'status' => (string) ( $data['status'] ?? 'confirmed' ) );
	if ( isset( $data['bitcoin_block'] ) ) {
		$fields['bitcoin_block'] = (int) $data['bitcoin_block'];
	}
	if ( isset( $data['block_time'] ) ) {
		$fields['block_time'] = (string) $data['block_time'];
	}
	return sn_prov_update_commit( $post_id, (int) $version, $fields );
}

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function sn_prov_confirm_handler( $request ) {
	$data = json_decode( $request->get_body(), true );
	if ( ! is_array( $data ) || empty( $data['note_uid'] ) ) {
		return new WP_Error( 'sn_prov_bad_payload', 'Malformed payload.', array( 'status' => 400 ) );
	}
	$ok = sn_prov_apply_confirmation( (string) $data['note_uid'], (int) ( $data['version'] ?? 0 ), $data );
	return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 404 );
}

/**
 * Re-dispatch any 'unanchored' commits for a post (dropped webhook recovery).
 * Uses the stored canonical payload so re-dispatch is byte-identical.
 *
 * @param int $post_id
 */
function sn_prov_reconcile_post( $post_id ) {
	foreach ( sn_prov_get_chain( $post_id ) as $commit ) {
		if ( 'unanchored' !== ( $commit['status'] ?? '' ) ) {
			continue;
		}
		$canonical = isset( $commit['payload'] ) ? sn_prov_canonical_json( (array) $commit['payload'] ) : '';
		sn_prov_dispatch( $post_id, $commit, $canonical );
	}
}

/**
 * Cron sweep: reconcile every Note that still has an unanchored commit.
 */
function sn_prov_reconcile_sweep() {
	$ids = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 50,
		'fields'      => 'ids',
		'meta_key'    => SN_PROV_UID_META,
	) );
	foreach ( $ids as $id ) {
		sn_prov_reconcile_post( (int) $id );
	}
}
add_action( SN_PROV_CONFIRM_HOOK, 'sn_prov_reconcile_sweep' );

add_action( 'init', 'sn_prov_schedule_reconcile' );
function sn_prov_schedule_reconcile() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_PROV_CONFIRM_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SN_PROV_CONFIRM_HOOK );
	}
}
