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
 * Exchange email + api_key for a short-lived OAuth access token.
 *
 * @return string Access token, or '' on failure.
 */
function sn_cloudways_get_token() {
	$res = wp_remote_post( SNT_CW_API_BASE . '/oauth/access_token', array(
		'timeout' => 15,
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
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	return is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
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

	$res  = wp_remote_post( SNT_CW_API_BASE . '/app/cache/purge', array(
		'timeout' => 15,
		'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		'body'    => array(
			'server_id' => sn_cloudways_cfg( 'SN_CLOUDWAYS_SERVER_ID' ),
			'app_id'    => sn_cloudways_cfg( 'SN_CLOUDWAYS_APP_ID' ),
		),
		'redirection' => 0, // v8.7.1 (CMA INFO-1): never re-send the Bearer on a 3xx.
	) );
	$http = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );
	$data = is_wp_error( $res ) ? array() : (array) json_decode( wp_remote_retrieve_body( $res ), true );
	$ok   = ( 200 === $http ) && ! empty( $data['status'] );

	update_option( SNT_CW_LAST_PURGE_OPT, array(
		'time'         => time(),
		'ok'           => $ok,
		'http'         => (int) $http,
		'operation_id' => isset( $data['operation_id'] ) ? (int) $data['operation_id'] : 0,
	), false );

	return $ok;
}

add_action( 'breeze_clear_varnish', 'sn_cloudways_purge_app' );
