<?php
/**
 * Signal & Noise Tools — the login guard follows the login slug.
 *
 * The sn-login-guard worker guards the masked login door by Cloudflare route,
 * and a route is a literal pattern: it cannot read the slug. The routes used
 * to live in the worker's wrangler.toml, so a slug changed here went
 * unguarded until someone also edited that file (found 2026-10-18). The
 * worker now declares no routes, which makes wrangler leave them alone on
 * deploy, and this module owns the door routes through the Cloudflare API.
 *
 * Saving the Login URL (inc/admin-post-actions/system.php) runs, in order:
 *   1. sn_lg_guard_slug( $new ): add the apex and www door routes for the new
 *      slug, then re-read the routes and confirm both are there;
 *   2. store the slug, only if step 1 confirmed;
 *   3. sn_lg_prune_doors( $new ): remove the old door's routes.
 * So the door is never unguarded: a save the guard cannot confirm is refused.
 * Only this worker's door routes are ever touched; its status route and every
 * other worker's routes are left alone.
 *
 * Needs the central Cloudflare token (inc/cloudflare-credentials.php) with
 * Zone › Workers Routes › Edit.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LG_SCRIPT = 'sn-login-guard';

/**
 * The door's route patterns for a slug: the apex and www, each with a
 * trailing * so ?action=… and the password POSTs are covered too.
 *
 * @param string $slug Login slug.
 * @return string[]
 */
function sn_lg_door_patterns( $slug ) {
	$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$host = preg_replace( '/^www\./', '', $host );
	$slug = trim( (string) $slug, '/' );
	return array( $host . '/' . $slug . '*', 'www.' . $host . '/' . $slug . '*' );
}

/**
 * Whether a route is one of this worker's door routes (not its status route).
 *
 * @param array $route Route from the API.
 * @return bool
 */
function sn_lg_is_door_route( array $route ) {
	return SN_LG_SCRIPT === ( $route['script'] ?? '' ) && false === strpos( (string) ( $route['pattern'] ?? '' ), '/_sn/' );
}

if ( ! function_exists( 'sn_lg_api' ) ) {
	/**
	 * One call to the Cloudflare v4 API with the central token. A Bearer on a
	 * fixed host, so no redirect may re-send it.
	 *
	 * @param string     $method GET, POST or DELETE.
	 * @param string     $path   Path starting with '/'.
	 * @param array|null $body   JSON body for POST.
	 * @return array{http:int,body:array}
	 */
	function sn_lg_api( $method, $path, $body = null ) {
		$args = array(
			'method'      => $method,
			'headers'     => array(
				'Authorization' => 'Bearer ' . sn_cf_get_token(),
				'Content-Type'  => 'application/json',
			),
			'timeout'     => 8,
			'sslverify'   => true,
			'redirection' => 0,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( 'https://api.cloudflare.com/client/v4' . $path, $args );
		if ( is_wp_error( $res ) ) {
			return array( 'http' => 0, 'body' => array( 'errors' => array( array( 'message' => $res->get_error_message() ) ) ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return array( 'http' => (int) wp_remote_retrieve_response_code( $res ), 'body' => is_array( $data ) ? $data : array() );
	}
}

/**
 * Cloudflare's own error text from a response, or the HTTP status.
 *
 * @param array $res Result of sn_lg_api().
 * @return string
 */
function sn_lg_error( array $res ) {
	$msg = (string) ( $res['body']['errors'][0]['message'] ?? '' );
	return '' !== $msg ? $msg : 'HTTP ' . (int) $res['http'];
}

/**
 * The zone's Worker routes.
 *
 * @return array{ok:bool,routes:array,error:string}
 */
function sn_lg_list_routes() {
	$res = sn_lg_api( 'GET', '/zones/' . rawurlencode( sn_cf_get_zone() ) . '/workers/routes' );
	if ( 200 !== $res['http'] || empty( $res['body']['success'] ) ) {
		return array( 'ok' => false, 'routes' => array(), 'error' => sn_lg_error( $res ) );
	}
	return array( 'ok' => true, 'routes' => (array) ( $res['body']['result'] ?? array() ), 'error' => '' );
}

/**
 * Guard a slug: add any missing door route, then re-read and confirm both
 * patterns point at the guard. Never removes anything.
 *
 * @param string $slug Login slug about to be saved.
 * @return array{ok:bool,error:string}
 */
function sn_lg_guard_slug( $slug ) {
	if ( '' === sn_cf_get_token() || '' === sn_cf_get_zone() ) {
		return array( 'ok' => false, 'error' => __( 'No Cloudflare token or zone is configured (Connections › Cloudflare).', 'signal-and-noise-tools' ) );
	}
	$list = sn_lg_list_routes();
	if ( ! $list['ok'] ) {
		return array( 'ok' => false, 'error' => $list['error'] );
	}
	$have = array_column( array_filter( $list['routes'], 'sn_lg_is_door_route' ), 'pattern' );
	foreach ( sn_lg_door_patterns( $slug ) as $pattern ) {
		if ( in_array( $pattern, $have, true ) ) {
			continue;
		}
		$res = sn_lg_api( 'POST', '/zones/' . rawurlencode( sn_cf_get_zone() ) . '/workers/routes', array( 'pattern' => $pattern, 'script' => SN_LG_SCRIPT ) );
		if ( 200 !== $res['http'] || empty( $res['body']['success'] ) ) {
			return array( 'ok' => false, 'error' => sn_lg_error( $res ) );
		}
	}
	// Trust the re-read, not the POST's answer.
	$list = sn_lg_list_routes();
	if ( ! $list['ok'] ) {
		return array( 'ok' => false, 'error' => $list['error'] );
	}
	$have    = array_column( array_filter( $list['routes'], 'sn_lg_is_door_route' ), 'pattern' );
	$missing = array_diff( sn_lg_door_patterns( $slug ), $have );
	return $missing
		? array( 'ok' => false, 'error' => sprintf( __( 'Cloudflare accepted the route but it is not there on re-read: %s', 'signal-and-noise-tools' ), implode( ', ', $missing ) ) )
		: array( 'ok' => true, 'error' => '' );
}

/**
 * Remove the guard's door routes that do not belong to the current slug.
 * Runs after the slug is saved. The status route and other workers' routes
 * are never touched.
 *
 * @param string $slug The saved slug.
 * @return array{removed:int,error:string}
 */
function sn_lg_prune_doors( $slug ) {
	$list = sn_lg_list_routes();
	if ( ! $list['ok'] ) {
		return array( 'removed' => 0, 'error' => $list['error'] );
	}
	$keep    = sn_lg_door_patterns( $slug );
	$removed = 0;
	foreach ( $list['routes'] as $route ) {
		if ( ! sn_lg_is_door_route( $route ) || in_array( $route['pattern'] ?? '', $keep, true ) ) {
			continue;
		}
		$res = sn_lg_api( 'DELETE', '/zones/' . rawurlencode( sn_cf_get_zone() ) . '/workers/routes/' . rawurlencode( (string) ( $route['id'] ?? '' ) ) );
		if ( 200 === $res['http'] ) {
			++$removed;
		}
	}
	return array( 'removed' => $removed, 'error' => '' );
}
