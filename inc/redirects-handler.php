<?php
/**
 * Signal & Noise Tools — front-end redirect + 404-capture hooks (v8.10.0).
 *
 * Two template_redirect handlers, ordered so they never fight:
 *
 *   priority 8  — sn_redirect_maybe(): if the request path matches an owner-authored
 *                 redirect (inc/redirects-store.php), 30x to its target and exit.
 *                 Runs before WP's canonical redirect and the priority-9 tag handler.
 *
 *   priority 99 — sn_redirect_capture_404(): if the request is a genuine front-end
 *                 404, record it (inc/redirects-404-log.php). Deliberately LAST:
 *                 every redirect handler exits before this runs, so a path that was
 *                 about to 30x can never be mis-logged as a phantom 404. (Same-
 *                 priority hooks fire in registration order, which is why capture
 *                 sits at 99 rather than the redirect handler's neighbour, 9.)
 *
 * @package SignalNoiseTools
 * @since 8.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 30x the current request if it matches a configured redirect.
 *
 * External targets are allowed through wp_safe_redirect() by whitelisting the
 * destination host for this one request — the redirect list is owner-authored
 * (manage_options), so an off-site target is intentional, not an open redirect.
 *
 * @return void
 */
function sn_redirect_maybe() {
	if ( is_admin() ) {
		return;
	}
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$target = sn_redirect_target( $uri );
	if ( empty( $target ) ) {
		return;
	}

	$host = (string) wp_parse_url( $target['to'], PHP_URL_HOST );
	if ( '' !== $host ) {
		add_filter(
			'allowed_redirect_hosts',
			function ( $hosts ) use ( $host ) {
				$hosts[] = $host;
				return $hosts;
			}
		);
	}
	wp_safe_redirect( $target['to'], (int) $target['status'] );
	exit;
}
add_action( 'template_redirect', 'sn_redirect_maybe', 8 );

/**
 * Record a genuine front-end 404. GET requests only (a bot POSTing junk isn't a
 * broken link worth tracking); the store's junk filter drops probe noise.
 *
 * @return void
 */
function sn_redirect_capture_404() {
	if ( ! is_404() ) {
		return;
	}
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'GET' !== $method ) {
		return;
	}
	$uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$referer = wp_get_referer();
	sn_404_log_record( $uri, is_string( $referer ) ? $referer : '' );
}
add_action( 'template_redirect', 'sn_redirect_capture_404', 99 );
