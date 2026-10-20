<?php
/**
 * Signal & Noise Tools: the X-SN-CSP: lax marker (2026-10-20).
 *
 * A Cloudflare Response Header Transform Rule enforces the strict
 * Content-Security-Policy on every response that does NOT carry
 * `X-SN-CSP: lax`. This module marks the responses whose inline scripts
 * change per request, so no hash can pin them:
 *
 *   - any front-end request from a signed-in user (the admin bar and core
 *     print per-request inline scripts);
 *   - wp-admin, outside admin-ajax (the dashboard is all inline scripts);
 *   - every login screen: login_init fires inside wp-login.php for the hidden
 *     door and for every action (lost password, reset, logout, 2FA, passkey);
 *   - a page that renders the AllTerrain Forms block or shortcode, and the
 *     /contact/ page by slug as a belt (the form prints a config script with
 *     a nonce, plus Turnstile).
 *
 * A missed page breaks under the strict policy; an extra mark only keeps that
 * page loose, so the rule errs toward marking. Ordinary public pages for a
 * stranger are never marked; the daily CSP check (inc/csp-hash-check.php)
 * reads them without cookies and raises the csp_hashes watch if one is.
 * REST and admin-ajax are not marked: they are not HTML, and REST never
 * reaches template_redirect.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_CSP_LAX_HEADER = 'X-SN-CSP: lax';

/**
 * Why the current front-end request keeps the loose policy, or '' when it
 * takes the strict one.
 *
 * @return string 'logged-in', 'form', 'contact' or ''.
 */
function sn_csp_lax_reason() {
	if ( is_user_logged_in() ) {
		return 'logged-in';
	}
	if ( is_singular() ) {
		$post    = get_queried_object();
		$content = is_object( $post ) && isset( $post->post_content ) ? (string) $post->post_content : '';
		if ( has_block( 'allterrain-forms/form', $content ) || has_shortcode( $content, 'allterrain_form' ) ) {
			return 'form';
		}
	}
	if ( is_page( 'contact' ) ) {
		return 'contact';
	}
	return '';
}

/**
 * Send the marker. header()'s replace flag keeps it to one header however
 * many hooks call this.
 *
 * @return bool Whether the marker is set for this response.
 */
function sn_csp_mark_lax() {
	static $marked = false;
	if ( ! $marked && ! headers_sent() ) {
		header( SN_CSP_LAX_HEADER, true );
		$marked = true;
	}
	return $marked;
}

/** Front end: decide once the query is known, before any output. */
function sn_csp_lax_front() {
	if ( '' !== sn_csp_lax_reason() ) {
		sn_csp_mark_lax();
	}
}

/** wp-admin, outside admin-ajax. */
function sn_csp_lax_admin() {
	if ( ! wp_doing_ajax() ) {
		sn_csp_mark_lax();
	}
}

if ( ! defined( 'SN_CSP_LAX_TEST' ) || ! SN_CSP_LAX_TEST ) {
	add_action( 'template_redirect', 'sn_csp_lax_front', 1 );
	add_action( 'login_init', 'sn_csp_mark_lax', 1 );
	add_action( 'admin_init', 'sn_csp_lax_admin', 1 );
}
