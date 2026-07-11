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
 * Shared outbound gate for every provenance probe + dispatcher (CMA LOW-1).
 *
 * Mirrors the sibling worker-version probe (inc/worker-version.php): an outbound
 * URL must be https, pass core URL validation, and resolve to a PUBLIC host — the
 * shared resolve-then-range-check catches the encoded-IP metadata bypasses a
 * literal string match misses. Fails CLOSED on an empty/malformed/internal URL.
 * Callers still pass 'redirection' => 0 on the request itself: this gate only
 * sees the first hop, so redirection=0 is what stops a validated host bouncing to
 * an internal one. sn_ssrf_host_blocked() is function_exists-guarded because
 * ssrf-guard.php loads after this module — but at call time (a save or a cron
 * event, long after every require) it is always present.
 *
 * @since 9.21.1
 * @param string $url Outbound URL (Worker endpoint or ledger raw URL).
 * @return bool True when the URL is safe to request.
 */
function sn_prov_url_allowed( $url ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return false;
	}
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! wp_http_validate_url( $url )
		|| 'https' !== wp_parse_url( $url, PHP_URL_SCHEME )
		|| ( function_exists( 'sn_ssrf_host_blocked' ) && sn_ssrf_host_blocked( $host ) )
	) {
		return false;
	}
	return true;
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
	if ( ! sn_prov_url_allowed( $url ) ) {
		return; // outbound gate — never POST to a non-https / internal host.
	}
	$body = wp_json_encode( array(
		'canonical'    => $canonical,
		'content_hash' => $commit['content_hash'],
		'note_uid'     => sn_prov_note_uid( $post_id ),
		'version'      => (int) $commit['version'],
	) );
	$response = wp_remote_post( $url, array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'        => $body,
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

const SN_PROV_DISPATCH_ASYNC_HOOK = 'sn_prov_dispatch_async';

/**
 * On a fresh commit, enqueue the Worker dispatch instead of POSTing synchronously
 * inside the editor save (CMA INFO-1). sn_prov_record() has already persisted the
 * commit as 'unanchored' before firing sn_prov_committed, so a near-term
 * single-event cron re-dispatches it through the SAME reconcile path the hourly
 * sweep uses — keeping a slow/unreachable Worker (a 15s POST timeout) off the
 * save's critical path. The hourly sn_prov_reconcile is the backstop; dedup by
 * post id coalesces rapid re-saves (reconcile catches every unanchored commit for
 * the post anyway). Registered for one arg — the async event re-reads the chain,
 * so it needs only the post id, not the commit/canonical.
 *
 * @since 9.21.1
 * @param int $post_id
 */
function sn_prov_enqueue_dispatch( $post_id ) {
	$post_id = (int) $post_id;
	if ( ! wp_next_scheduled( SN_PROV_DISPATCH_ASYNC_HOOK, array( $post_id ) ) ) {
		wp_schedule_single_event( time(), SN_PROV_DISPATCH_ASYNC_HOOK, array( $post_id ) );
	}
}
add_action( 'sn_prov_committed', 'sn_prov_enqueue_dispatch', 10, 1 );
add_action( SN_PROV_DISPATCH_ASYNC_HOOK, 'sn_prov_reconcile_post', 10, 1 );

/**
 * Trigger the Worker's on-demand upgrade sweep (POST /sweep) — the hourly cron's
 * work, run now. HMAC-signed with the SAME secret as sn_prov_dispatch() (the body
 * is signed, so no new secret), letting the admin flip Bitcoin-confirmed proofs
 * immediately from the Provenance panel instead of waiting for the top of the
 * hour. Returns a normalized result: [ ok, checked, upgraded, still_pending ] on
 * success, or [ ok => false, error ] on failure.
 *
 * @return array
 */
function sn_prov_run_sweep() {
	$url    = sn_prov_worker_url();
	$secret = sn_prov_hmac_secret();
	if ( '' === $url || '' === $secret ) {
		return array( 'ok' => false, 'error' => 'unconfigured' );
	}
	$endpoint = untrailingslashit( $url ) . '/sweep';
	if ( ! sn_prov_url_allowed( $endpoint ) ) {
		return array( 'ok' => false, 'error' => 'blocked' );
	}
	$body     = wp_json_encode( array( 'action' => 'sweep' ) );
	$response = wp_remote_post( $endpoint, array(
		'timeout'     => 20,
		'redirection' => 0,
		'headers'     => array(
			'Content-Type'   => 'application/json',
			'X-SN-Signature' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
		),
		'body'        => $body,
	) );
	if ( is_wp_error( $response ) ) {
		return array( 'ok' => false, 'error' => 'network' );
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$out  = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $out ) || empty( $out['ok'] ) ) {
		return array( 'ok' => false, 'error' => 'worker', 'code' => $code );
	}
	return array(
		'ok'            => true,
		'checked'       => (int) ( $out['checked'] ?? 0 ),
		'upgraded'      => (int) ( $out['upgraded'] ?? 0 ),
		'still_pending' => (int) ( $out['stillPending'] ?? 0 ),
	);
}

/**
 * The deployed Worker's semver, read from its public GET /_sn/version endpoint
 * and cached ~10 min (the version only changes on deploy, and this is an
 * admin-panel readout — no need to hit the Worker per page load). Returns '' when
 * the Worker URL is unset or the endpoint is unreachable.
 *
 * Deliberately NOT folded into sn_prov_admin_system_status(): that view-model is
 * also read by the dashboard glance card, which must stay query-only (no outbound
 * HTTP on every admin page load). This is called only from the Provenance panel.
 *
 * @return string
 */
function sn_prov_worker_version() {
	$url = sn_prov_worker_url();
	if ( '' === $url ) {
		return '';
	}
	$cached = get_transient( 'sn_prov_worker_version' );
	if ( false !== $cached ) {
		return (string) $cached;
	}
	$version  = '';
	$endpoint = untrailingslashit( $url ) . '/_sn/version';
	if ( sn_prov_url_allowed( $endpoint ) ) {
		$response = wp_remote_get( $endpoint, array(
			'timeout'     => 5,
			'redirection' => 0,
		) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['version'] ) ) {
				$version = (string) $data['version'];
			}
		}
	}
	set_transient( 'sn_prov_worker_version', $version, 10 * MINUTE_IN_SECONDS );
	return $version;
}

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

	// Integrity belt: a confirm must never flip an entry whose hash doesn't
	// match what's already on the chain — resolve + compare before mutating.
	foreach ( sn_prov_get_chain( $post_id ) as $entry ) {
		if ( (int) ( $entry['version'] ?? 0 ) !== (int) $version ) {
			continue;
		}
		if ( isset( $data['content_hash'] ) && ( $entry['content_hash'] ?? null ) !== $data['content_hash'] ) {
			return false;
		}
		break;
	}

	// Whitelist status: never store an arbitrary caller-supplied string.
	$allowed_statuses = array( 'pending', 'confirmed', 'unanchored', 'genesis' );
	$status           = (string) ( $data['status'] ?? 'confirmed' );
	$fields           = array(
		'status' => in_array( $status, $allowed_statuses, true ) ? $status : 'confirmed',
	);
	if ( isset( $data['bitcoin_block'] ) ) {
		$fields['bitcoin_block'] = (int) $data['bitcoin_block'];
	}
	if ( isset( $data['block_time'] ) ) {
		$fields['block_time'] = (string) $data['block_time'];
	}
	// Pending-anchor progress: the Worker reports the in-flight Bitcoin tx id and
	// its confirmation count (0..6) so a still-pending Note links to mempool.space
	// and shows a live N/6 count. Validate the txid shape; never store junk.
	if ( isset( $data['bitcoin_txid'] ) && preg_match( '/^[0-9a-f]{64}$/i', (string) $data['bitcoin_txid'] ) ) {
		$fields['bitcoin_txid'] = strtolower( (string) $data['bitcoin_txid'] );
	}
	if ( isset( $data['confirmations'] ) ) {
		$fields['confirmations'] = max( 0, (int) $data['confirmations'] );
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
	// Genesis is a sentinel, not a Note post — apply its confirmation to the
	// persisted genesis option directly. Routing it through
	// sn_prov_apply_confirmation() (which resolves a post by uid) would drop it,
	// leaving the option stuck 'pending' forever.
	if ( 'genesis' === (string) $data['note_uid'] && function_exists( 'sn_prov_apply_genesis_confirmation' ) ) {
		$ok = sn_prov_apply_genesis_confirmation( $data );
		return new WP_REST_Response( array( 'ok' => $ok ), $ok ? 200 : 404 );
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
