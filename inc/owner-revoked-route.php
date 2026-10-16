<?php
/**
 * Signal & Noise Tools: GET /wp-json/signal-noise/v1/owner/revoked (SN Traffic
 * Lanes, Phase 1). The analytics Worker reads the revoked device ids on its
 * cron and caches them in KV; a listed id's marker counts as not valid.
 *
 * Auth: `Authorization: Bearer <SN_SRV_TOKEN>`, the server token the Worker
 * already holds, compared in constant time (sn_owner_bearer_ok). Fails closed:
 * 503 when the token is unset, 403 when it does not match. Answers ids and
 * the time the list last changed; never a label, a marker or a key.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether an Authorization header carries the secret. '' secret refuses all.
 *
 * @param string $header Raw header.
 * @param string $secret Secret.
 * @return bool
 */
function sn_owner_bearer_ok( $header, $secret ) {
	$header = (string) $header;
	return '' !== (string) $secret && 0 === strncmp( $header, 'Bearer ', 7 ) && hash_equals( (string) $secret, substr( $header, 7 ) );
}

/**
 * @param WP_REST_Request $request Request.
 * @return true|WP_Error
 */
function sn_owner_revoked_permission( $request ) {
	$secret = (string) apply_filters( 'sn_server_token', defined( 'SN_SRV_TOKEN' ) ? (string) SN_SRV_TOKEN : '' );
	if ( '' === $secret ) {
		return new WP_Error( 'sn_owner_revoked_unconfigured', 'The analytics server token is not set: set it in Connections › Credentials (Analytics server token).', array( 'status' => 503 ) );
	}
	if ( ! sn_owner_bearer_ok( (string) $request->get_header( 'authorization' ), $secret ) ) {
		return new WP_Error( 'sn_owner_revoked_forbidden', 'Invalid server token.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * @return WP_REST_Response
 */
function sn_owner_revoked_response() {
	$r = sn_owner_revoked();
	// The Worker refuses the whole list over one malformed id, so only the
	// contract shape (16 lowercase hex) ever leaves here.
	$ids      = array_values( array_filter( $r['ids'], static function ( $id ) { return 1 === preg_match( '/^[0-9a-f]{16}$/', (string) $id ); } ) );
	$response = new WP_REST_Response( array( 'revoked' => $ids, 'at' => $r['at'] ), 200 );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'signal-noise/v1',
			'/owner/revoked',
			array(
				'methods'             => 'GET',
				'callback'            => 'sn_owner_revoked_response',
				'permission_callback' => 'sn_owner_revoked_permission',
			)
		);
	}
);
