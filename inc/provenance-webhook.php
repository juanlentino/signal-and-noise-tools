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

/**
 * Prefer a wp-config constant; fall back to an option. FOR SECRETS.
 *
 * wp-config.php is harder to tamper with than the database, so a secret should
 * take the constant first. That argument does NOT hold for values we publish —
 * see sn_prov_public_config() below.
 */
function sn_prov_config( $const, $option ) {
	if ( defined( $const ) ) {
		return (string) constant( $const );
	}
	return (string) get_option( $option, '' );
}

/**
 * Prefer a USABLE option; fall back to the wp-config constant. FOR PUBLIC
 * VALUES — the signing key's public half, its id, its introduction date.
 *
 * WHY THE PRECEDENCE IS INVERTED HERE. Constant-first made these unwritable by
 * the plugin, so a rotation could not take effect until a human edited
 * wp-config.php — and the obvious instruction, "delete the line", resolves the
 * key to '' and 404s the site's published identity (fixed in v13.41.0, after
 * the panel had been giving exactly that advice). Inverting removes the edit,
 * and with it the footgun.
 *
 * The security trade is deliberate and narrow. Constants beat options because
 * the database is easier to tamper with; that protects SECRETS. These are not
 * secrets — they are served at /.well-known/ for anyone to read — and a
 * substituted public key fails LOUDLY rather than silently: every existing
 * signature stops verifying and the ledger's key-pin check reds because the two
 * independent sources disagree. Compare a secret, where tampering is silent.
 *
 * THE CONSTANT REMAINS THE FLOOR. An option only supersedes it when the option
 * is itself usable, which the optional $is_usable callback decides. A blank or
 * corrupt row must never take a published value to nothing — that is the very
 * outage this inversion exists to make impossible.
 *
 * @param string        $const     Constant name.
 * @param string        $option    Option name.
 * @param callable|null $is_usable Optional predicate the option must satisfy to win.
 * @return string
 */
function sn_prov_public_config( $const, $option, $is_usable = null ) {
	$value = trim( (string) get_option( $option, '' ) );
	if ( '' !== $value && ( null === $is_usable || call_user_func( $is_usable, $value ) ) ) {
		return $value;
	}
	return defined( $const ) ? (string) constant( $const ) : '';
}

/**
 * Is this base64 a real 32-byte Ed25519 public key?
 *
 * The gate on whether a stored key may supersede the configured one. Length is
 * checked, not merely decodability: a 31-byte value decodes cleanly and would
 * be published as a key that can never verify anything.
 *
 * @param string $b64
 * @return bool
 */
function sn_prov_is_ed25519_public_key( $b64 ) {
	$raw = base64_decode( trim( (string) $b64 ), true );
	return false !== $raw && 32 === strlen( $raw );
}

function sn_prov_worker_url() {
	return sn_prov_config( 'SN_PROV_WORKER_URL', 'sn_prov_worker_url' );
}
function sn_prov_hmac_secret() {
	return sn_prov_config( 'SN_PROV_HMAC_SECRET', 'sn_prov_hmac_secret' );
}
function sn_prov_pubkey_b64() {
	return sn_prov_public_config( 'SN_PROV_PUBKEY_B64', 'sn_prov_pubkey_b64', 'sn_prov_is_ed25519_public_key' );
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
	// v10.84.0: the ledger path is built from `kind` by the Worker (>= v1.10.0).
	// Absent means 'note' THERE too, so an older Worker keeps working — but a
	// worker older than 1.10.0 REFUSES an unknown key, which is why this only
	// ships after the deployed /_sn/version was confirmed.
	//
	// v12.6.5: '' IS NOT 'note'. sn_prov_subject_kind() returns '' for "this is
	// not a provenance subject" — an unopted page, a post outside the notes
	// category, a $post that would not load. That is the one honest non-answer,
	// and the previous code coerced it into a confident directory choice.
	//
	// The ledger is append-only and Bitcoin-anchored: a record filed under the
	// wrong root cannot be moved back. The Worker says so itself — it REFUSES an
	// unrecognised kind rather than defaulting, because "silently filing a page
	// under notes/ is the exact irreversible mistake this field exists to
	// prevent". Coercing '' here defeated that refusal from the caller's side.
	//
	// It really happened: the About PAGE's v2 was filed to notes/ on 2026-08-19
	// and the public ledger's coverage check has been red ever since, because a
	// page can never earn a row in the notes index. The old reasoning — "dispatch
	// is only ever reached for a real subject" — is false: sn_prov_reconcile_post()
	// re-dispatches stored unanchored commits WITHOUT re-resolving the subject,
	// so a page whose opt-in is not readable in that context resolved to ''.
	//
	// So: refuse. A missed dispatch is recoverable (the sweep retries, and this
	// commit stays unanchored); a misfiled anchored record is not.
	$kind = function_exists( 'sn_prov_subject_kind' )
		? (string) sn_prov_subject_kind( get_post( $post_id ) )
		: '';
	if ( '' === $kind ) {
		// Recorded, not silent. A refusal that leaves no trace is indistinguishable
		// from a dispatch that never had a reason to run, and the reconcile sweep
		// would retry it forever with nothing to read.
		sn_prov_update_commit( (int) $post_id, (int) $commit['version'], array(
			'dispatch_refused'        => time(),
			'dispatch_refused_reason' => 'subject-kind-unresolved',
		) );
		return;
	}
	$body = wp_json_encode( array(
		'canonical'    => $canonical,
		'content_hash' => $commit['content_hash'],
		'note_uid'     => sn_prov_note_uid( $post_id ),
		'version'      => (int) $commit['version'],
		'kind'         => $kind,
	) );
	// v11.10.0: mark BEFORE the POST, not after. A request whose response is
	// lost still reached the Worker, which may already have signed and published
	// this version — so a later save must never supersede it. Set afterwards, a
	// dropped response would let the next save rewrite a version the ledger had
	// already published under the same number with a different hash. Marking
	// first costs at most a spare version; marking last risks contradicting a
	// public record.
	sn_prov_update_commit( (int) $post_id, (int) $commit['version'], array(
		'dispatch_attempted' => time(),
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
	$args    = array( $post_id );

	// v11.10.0: DEBOUNCE, not dedupe. The old guard only skipped scheduling when
	// an event was already pending, and the event was scheduled for time() — a
	// window one page-load wide — so saves minutes apart each got their own
	// version. Measured 2026-08-15: three saves in ten minutes minted v1, v2 and
	// v3, all permanent and public.
	//
	// Now each save pushes the dispatch out again, so an editing pass signs once
	// when it goes quiet. A revision tomorrow is still a new version, which is
	// correct — the goal is to stop versions BLEEDING within one pass, not to
	// have fewer of them.
	$existing = wp_next_scheduled( SN_PROV_DISPATCH_ASYNC_HOOK, $args );
	if ( $existing ) {
		if ( ! function_exists( 'wp_unschedule_event' ) ) {
			return; // Cannot debounce; leave the pending dispatch exactly as before.
		}
		wp_unschedule_event( $existing, SN_PROV_DISPATCH_ASYNC_HOOK, $args );
	}
	// Guarded like sn_prov_record()'s call to the supersede gate: if the settle
	// module is somehow absent, degrade to the previous immediate dispatch
	// rather than fatalling inside a post save.
	$settle = function_exists( 'sn_prov_settle_seconds' ) ? sn_prov_settle_seconds() : 0;
	wp_schedule_single_event( time() + $settle, SN_PROV_DISPATCH_ASYNC_HOOK, $args );
}

/**
 * Is a settle-window dispatch still pending for this post? (v11.10.0)
 *
 * The supersede gate's "still private" signal. Lives here because the hook
 * constant does; sn_prov_record() reaches it through function_exists().
 *
 * @param int $post_id
 * @return bool
 */
function sn_prov_dispatch_pending( $post_id ) {
	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		return false;
	}
	return (bool) wp_next_scheduled( SN_PROV_DISPATCH_ASYNC_HOOK, array( (int) $post_id ) );
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
		// v10.84.0: ONE uuid namespace across subject types — the ledger path
		// carries the kind, so this resolver must span them all or a signed
		// page's confirm callback would find nothing to attach itself to.
		'post_type'   => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
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
		// v9.88.0: UNCONDITIONAL. The Worker signs ledger payloads and confirm
		// callbacks with one key and no domain separation, and a signed ledger
		// payload is PUBLISHED publicly — so a replayed one verifies here. A
		// genuine callback always carries content_hash (sweep.mjs), so requiring
		// it costs nothing and makes the published payload non-replayable.
		if ( ! isset( $data['content_hash'] ) || ( $entry['content_hash'] ?? null ) !== $data['content_hash'] ) {
			return false;
		}
		break;
	}

	// Whitelist status: never store an arbitrary caller-supplied string.
	// v9.88.0: never DEFAULT to 'confirmed' — an absent status used to promote a
	// pending commit to verified. Genuine callbacks always send one.
	$allowed_statuses = array( 'pending', 'confirmed', 'unanchored', 'genesis' );
	$status           = (string) ( $data['status'] ?? '' );
	if ( ! in_array( $status, $allowed_statuses, true ) ) {
		return false;
	}
	$fields = array( 'status' => $status );
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
	// v9.88.0: mirror the recording gate — an already-recorded commit for a
	// now-protected post must not be pushed later by the hourly sweep.
	if ( function_exists( 'get_post' ) ) {
		$sn_rp = get_post( $post_id );
		if ( is_object( $sn_rp ) && '' !== (string) ( $sn_rp->post_password ?? '' ) ) {
			return false;
		}
	}

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
	$page       = 1;
	$batch_size = 50;
	do {
		$ids = get_posts( array(
			// v10.84.0: same widening as the UID resolver — a sweep that walked
			// only posts would leave a signed page unanchored forever.
			'post_type'      => function_exists( 'sn_prov_subject_post_types' ) ? sn_prov_subject_post_types() : 'post',
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_key'       => SN_PROV_UID_META,
		) );
		foreach ( $ids as $id ) {
			sn_prov_reconcile_post( (int) $id );
		}
		++$page;
	} while ( count( $ids ) === $batch_size );
}
add_action( SN_PROV_CONFIRM_HOOK, 'sn_prov_reconcile_sweep' );

add_action( 'init', 'sn_prov_schedule_reconcile' );
function sn_prov_schedule_reconcile() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( SN_PROV_CONFIRM_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', SN_PROV_CONFIRM_HOOK );
	}
}
