<?php
/**
 * Signal & Noise — unauthenticated REST API hardening: the wiring layer.
 *
 * Defense in depth after CVE-2026-63030 (WP2Shell), which chained the REST
 * batch endpoint to a WP_Query SQLi. Core is patched (7.0.2); this narrows the
 * surface that made the chain reachable. Second driver: the durability
 * assessment found /wp/v2/posts serving full rendered content as clean JSON,
 * routing around the TDMRep + Content Signals stack entirely.
 *
 * Three controls — route removal, rendered-field stripping, TDM headers — all
 * anonymous-only except the headers. Every route decision is delegated to
 * inc/rest-hardening-policy.php; nothing here hardcodes a route.
 *
 * @see docs/REST-HARDENING.md Rationale, hook order, verification matrix.
 *
 * @package SignalNoise
 * @since 9.83.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/rest-hardening-policy.php';

/**
 * Drop the policy's routes for anonymous callers. Fires inside
 * WP_REST_Server::dispatch(), after check_authentication(), so
 * is_user_logged_in() is authoritative here. Filtering the route table covers
 * /wp-json/… and /?rest_route=… identically — both converge on this dispatch.
 *
 * @param array $endpoints Route key => handlers.
 * @return array
 */
function snt_rest_hardening_endpoints( $endpoints ) {
	if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
		return $endpoints;
	}
	$policy = snt_rest_hardening_policy();
	foreach ( array_keys( $endpoints ) as $route ) {
		if ( snt_rest_hardening_should_remove( $route, $policy ) ) {
			unset( $endpoints[ $route ] );
		}
	}
	return $endpoints;
}
add_filter( 'rest_endpoints', 'snt_rest_hardening_endpoints' );

/**
 * Empty content.rendered / excerpt.rendered for anonymous callers, leaving
 * every other field intact. Emptied rather than unset so schema-validating
 * clients keep a well-formed response — the payload is the leak, not the shape.
 *
 * @param object $response Prepared REST response.
 * @return object
 */
function snt_rest_hardening_strip_rendered( $response ) {
	if ( is_user_logged_in() || ! is_object( $response ) ) {
		return $response;
	}
	if ( ! isset( $response->data ) || ! is_array( $response->data ) ) {
		return $response;
	}
	foreach ( array( 'content', 'excerpt' ) as $field ) {
		if ( isset( $response->data[ $field ]['rendered'] ) ) {
			$response->data[ $field ]['rendered'] = '';
		}
	}
	return $response;
}

/**
 * Bind rest_prepare_{$type} for each post type in the policy. Deferred to
 * rest_api_init, not require time: at require time nothing has had a chance to
 * register on `snt_rest_hardening_policy`, so an early bind would read a policy
 * nobody could influence — filterable in name only.
 *
 * @return void
 */
function snt_rest_hardening_bind_strip() {
	$policy = snt_rest_hardening_policy();
	$types  = isset( $policy['strip'] ) ? (array) $policy['strip'] : array();
	foreach ( $types as $type ) {
		add_filter( 'rest_prepare_' . $type, 'snt_rest_hardening_strip_rendered' );
	}
}
add_action( 'rest_api_init', 'snt_rest_hardening_bind_strip' );

/**
 * Attach the TDM reservation headers to every REST response. Measured
 * 2026-07-28: these reached /wp-json/… but NOT /?rest_route=…, so the
 * machine-readable surface advertised no reservation on the spelling a scraper
 * is likeliest to use. Emitting from dispatch makes it origin-owned on both.
 *
 * Duck-typed rather than `instanceof WP_REST_Response` so the standalone
 * harness drives the real branch instead of a stub that always misses.
 *
 * @param object $result Dispatched response.
 * @return object
 */
function snt_rest_hardening_tdm_headers( $result ) {
	if ( ! is_object( $result ) || ! method_exists( $result, 'header' ) ) {
		return $result;
	}
	$policy  = snt_rest_hardening_policy();
	$headers = isset( $policy['headers'] ) ? (array) $policy['headers'] : array();
	foreach ( $headers as $name => $value ) {
		$result->header( $name, $value, true );
	}
	return $result;
}
add_filter( 'rest_post_dispatch', 'snt_rest_hardening_tdm_headers' );
