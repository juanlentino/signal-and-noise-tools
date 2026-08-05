<?php
/**
 * Signal & Noise Tools — Cloudways API cache/Varnish purge.
 *
 * The purge chain's Varnish leg (`do_action('breeze_clear_varnish')` in the theme)
 * silently no-ops on Cloudways — Breeze's PURGE is non-blocking + unconfirmable, so
 * stale Varnish objects survive and re-seed Cloudflare within seconds (curl-proven
 * 2026-07-03). This module rides that same action and clears the app's cache (incl.
 * Varnish) through the Cloudways API — the programmatic form of the panel's "Purge
 * Varnish" button — with a real `{status, operation_id}` confirmation.
 *
 * Config (wp-config.php constants; the same four values as the CLOUDWAYS_* deploy
 * secrets, but the WP runtime can't read those so it needs its own copies):
 *   define( 'SN_CLOUDWAYS_EMAIL',     '…' );
 *   define( 'SN_CLOUDWAYS_API_KEY',   '…' );  // account-wide key — keep in wp-config only
 *   define( 'SN_CLOUDWAYS_SERVER_ID', '…' );
 *   define( 'SN_CLOUDWAYS_APP_ID',    '…' );
 * Any missing constant ⇒ silent no-op (fail-safe, like inc/cloudflare-purge.php).
 *
 * Security: the key + token appear ONLY in urlencoded traffic to api.cloudways.com —
 * never echoed, logged, or stored. The last-purge option records only ok/http/op-id.
 *
 * @package SignalNoiseTools
 * @since 8.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_CW_API_BASE       = 'https://api.cloudways.com/api/v1';
const SNT_CW_LAST_PURGE_OPT = 'sn_cloudways_last_purge';

/**
 * Short-lived OAuth token cache (v10.52.4).
 *
 * Before this, every purge did a fresh token exchange. Two purges seconds apart
 * meant two exchanges, Cloudways rate-limits that endpoint, and the second one
 * failed at `stage: auth` before it ever reached /app/cache/purge — a
 * self-inflicted failure that reads exactly like a bad credential. Observed
 * live 2026-08-05.
 *
 * The TTL is deliberately capped well below the token's real lifetime. The
 * account-wide API key lives in wp-config (never the database); a bearer minted
 * from it grants the same powers, so persisting one widens the blast radius of a
 * DB dump. A short cap keeps the burst protection while bounding that window —
 * the goal is to survive a burst, not to eliminate every exchange.
 */
const SNT_CW_TOKEN_TRANSIENT   = 'sn_cloudways_token';
const SNT_CW_TOKEN_MAX_TTL     = 600; // Hard cap, regardless of what the API says.
const SNT_CW_TOKEN_MIN_TTL     = 60;  // Below this, caching buys nothing.
const SNT_CW_TOKEN_MARGIN      = 60;  // Refresh before expiry, never at it.
const SNT_CW_TOKEN_DEFAULT_TTL = 300; // When the API omits expires_in.

/**
 * Read a Cloudways config constant.
 *
 * @param string $name Constant name.
 * @return string Value, or '' when undefined/empty.
 */
function sn_cloudways_cfg( $name ) {
	return ( defined( $name ) && constant( $name ) ) ? (string) constant( $name ) : '';
}

/**
 * True only when all four credentials are present.
 *
 * @return bool
 */
function sn_cloudways_is_configured() {
	return '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_EMAIL' )
		&& '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_API_KEY' )
		&& '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_SERVER_ID' )
		&& '' !== sn_cloudways_cfg( 'SN_CLOUDWAYS_APP_ID' );
}

/**
 * Drop any cached token, in-request and persisted.
 *
 * Called when the API rejects a token we believed was good (401/403), so a
 * revoked or rotated credential costs one retry rather than failing every purge
 * until the TTL runs out. A cache that cannot be invalidated by the thing it
 * caches for is a cache that reports healthy while everything fails.
 *
 * @since 10.52.4
 *
 * @return void
 */
function sn_cloudways_forget_token() {
	unset( $GLOBALS['sn_cloudways_token_memo'] );
	if ( function_exists( 'delete_transient' ) ) {
		delete_transient( SNT_CW_TOKEN_TRANSIENT );
	}
}

/**
 * Exchange email + api_key for a short-lived OAuth access token, cached.
 *
 * Two layers: an in-request memo (the case that actually bit us — two purges in
 * one process) and a capped transient (adjacent requests, e.g. a save-triggered
 * purge landing seconds after a deploy-triggered one).
 *
 * A FAILED exchange is never cached. Caching '' would convert one rate-limited
 * moment into a TTL-long outage, which is the opposite of the point.
 *
 * @since 10.52.4 Cached; `$force_fresh` added.
 *
 * @param bool $force_fresh Skip both cache layers and mint a new token.
 * @return string Access token, or '' on failure.
 */
function sn_cloudways_get_token( $force_fresh = false ) {
	if ( ! $force_fresh ) {
		if ( ! empty( $GLOBALS['sn_cloudways_token_memo'] ) ) {
			return (string) $GLOBALS['sn_cloudways_token_memo'];
		}
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( SNT_CW_TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				$GLOBALS['sn_cloudways_token_memo'] = $cached;
				return $cached;
			}
		}
	}

	$res = wp_remote_post( SNT_CW_API_BASE . '/oauth/access_token', array(
		// (render hardening FIX 3a): 15s → 5s. This request rides the
		// theme's breeze_clear_varnish action, which itself fires inline on
		// admin/save paths — a slow or hanging Cloudways API blocked that
		// request for up to 15s. 5s is generous for an OAuth token exchange.
		'timeout' => 5,
		'headers' => array( 'Accept' => 'application/json' ),
		'body'    => array(
			'email'   => sn_cloudways_cfg( 'SN_CLOUDWAYS_EMAIL' ),
			'api_key' => sn_cloudways_cfg( 'SN_CLOUDWAYS_API_KEY' ),
		),
		// v8.7.1 (CMA audit INFO-1): the account-wide api_key rides the POST body,
		// so a 307/308 would re-send it — forbid following any 3xx from the API host.
		'redirection' => 0,
	) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}
	$data  = json_decode( wp_remote_retrieve_body( $res ), true );
	$token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
	if ( '' === $token ) {
		return '';
	}

	// Honour expires_in when the API sends it, minus a margin so we never hand
	// out a token that expires mid-flight — then clamp. The cap is the security
	// decision (see the constant block); the floor is because a cache measured in
	// seconds is not worth a database row.
	$ttl = SNT_CW_TOKEN_DEFAULT_TTL;
	if ( is_array( $data ) && isset( $data['expires_in'] ) && is_numeric( $data['expires_in'] ) ) {
		$ttl = (int) $data['expires_in'] - SNT_CW_TOKEN_MARGIN;
	}
	$ttl = min( SNT_CW_TOKEN_MAX_TTL, $ttl );

	$GLOBALS['sn_cloudways_token_memo'] = $token;
	if ( $ttl >= SNT_CW_TOKEN_MIN_TTL && function_exists( 'set_transient' ) ) {
		set_transient( SNT_CW_TOKEN_TRANSIENT, $token, $ttl );
	}

	return $token;
}

/**
 * Purge the app's Cloudways cache (incl. Varnish). Once per request. Hooked to the
 * theme's breeze_clear_varnish action (the Varnish step of sn_purge_all_caches), so it
 * runs at the right point in the inner→outer order, before the Cloudflare purge — and
 * respects the origin_html flag (that gates the do_action). The guard is a GLOBAL (not
 * a static) so the standalone tests can reset it between scenarios.
 *
 * @return bool Whether Cloudways accepted the purge (HTTP 200 + status:true).
 */
function sn_cloudways_purge_app() {
	if ( ! empty( $GLOBALS['sn_cloudways_purge_done'] ) ) {
		return false;
	}
	$GLOBALS['sn_cloudways_purge_done'] = true;

	if ( ! sn_cloudways_is_configured() ) {
		return false;
	}

	$token = sn_cloudways_get_token();
	if ( '' === $token ) {
		update_option( SNT_CW_LAST_PURGE_OPT, array( 'time' => time(), 'ok' => false, 'stage' => 'auth' ), false );
		return false;
	}

	$dispatch = static function ( $bearer ) {
		return wp_remote_post( SNT_CW_API_BASE . '/app/cache/purge', array(
			// (render hardening FIX 3a): 15s → 5s, same rationale as the
			// token exchange above.
			'timeout' => 5,
			'headers' => array( 'Authorization' => 'Bearer ' . $bearer ),
			'body'    => array(
				'server_id' => sn_cloudways_cfg( 'SN_CLOUDWAYS_SERVER_ID' ),
				'app_id'    => sn_cloudways_cfg( 'SN_CLOUDWAYS_APP_ID' ),
			),
			'redirection' => 0, // v8.7.1 (CMA INFO-1): never re-send the Bearer on a 3xx.
		) );
	};

	$res = $dispatch( $token );

	// v10.52.4: a cached token can outlive its welcome — rotated key, revoked
	// session, a cap we guessed too generously. The API rejecting it is the only
	// authority on that, so treat a 401/403 as cache invalidation plus ONE retry
	// with a fresh token. Exactly one: a credential that is genuinely wrong must
	// fail, visibly, rather than loop.
	$reauthed = false;
	if ( ! is_wp_error( $res ) && in_array( (int) wp_remote_retrieve_response_code( $res ), array( 401, 403 ), true ) ) {
		sn_cloudways_forget_token();
		$fresh = sn_cloudways_get_token( true );
		if ( '' !== $fresh ) {
			$reauthed = true;
			$res      = $dispatch( $fresh );
		}
	}

	$body_raw = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
	$http     = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
	$data     = is_wp_error( $res ) ? array() : (array) json_decode( $body_raw, true );
	$ok       = ( 200 === $http ) && ! empty( $data['status'] );

	$operation_id = isset( $data['operation_id'] ) ? (int) $data['operation_id'] : 0;

	// v10.52.2: Cloudways SERIALIZES cache operations per server. A purge issued
	// while one is still open is rejected with 422 "An operation is already in
	// progress for this server." — and the envelope names the operation that
	// blocked it. When that operation is itself an in-flight purge_app_cache,
	// recording ✕ inverts the truth: the purge we asked for IS running, under an
	// id someone else's request opened. That is what made every leg on this site
	// read failed while all three routes verified fresh (live, 2026-08-05).
	//
	// So we coalesce onto the open operation and adopt its id. Deliberately NARROW
	// — a 422 blocked by any other operation type, an operation already completed
	// (nothing is purging, so there is nothing to ride), or a body we cannot parse
	// all stay failures. A broader reading would turn this row into a success-only
	// readout that reports healthy while the cache goes stale, which is the exact
	// failure this record exists to catch.
	$coalesced = false;
	if ( ! $ok && 422 === (int) $http ) {
		$op = isset( $data['operation'] ) && is_array( $data['operation'] ) ? $data['operation'] : array();
		// array_key_exists, not ??: an ABSENT is_completed is unknown state, not
		// "still running". Absent must not read as in-flight.
		$still_running = array_key_exists( 'is_completed', $op ) && '0' === (string) $op['is_completed'];
		if ( 'purge_app_cache' === (string) ( $op['type'] ?? '' ) && ! empty( $op['id'] ) && $still_running ) {
			$coalesced    = true;
			$ok           = true;
			$operation_id = (int) $op['id'];
		}
	}

	$record = array(
		'time'         => time(),
		'ok'           => $ok,
		'http'         => (int) $http,
		'operation_id' => $operation_id,
	);
	if ( $coalesced ) {
		// Keep the real http code above and mark the row, so a coalesced purge is
		// never silently indistinguishable from a fresh 200 dispatch.
		$record['coalesced'] = true;
	}
	if ( $reauthed ) {
		// Surfaced because a purge that only succeeds on the second attempt is a
		// signal about the credential, not a clean success.
		$record['reauthed'] = true;
	}

	if ( ! $ok ) {
		// (render hardening FIX 3b): on a non-2xx/non-{status:true} response, capture the
		// error envelope so it's actually visible (e.g. the live 422's field-
		// validation message) instead of a bare ok:false. Bounded to 300 chars
		// (a hostile/oversized error page can't bloat the option) and stripped
		// of tags (defence in depth — the body is Cloudways' own JSON/HTML error
		// envelope, never user input, but this stays safe if that ever changes).
		// The endpoint/payload themselves are UNCHANGED here — this capture is
		// what decides that fix, in a follow-up.
		$record['error'] = substr( wp_strip_all_tags( $body_raw ), 0, 300 );
	}

	update_option( SNT_CW_LAST_PURGE_OPT, $record, false );

	return $ok;
}

add_action( 'breeze_clear_varnish', 'sn_cloudways_purge_app' );
