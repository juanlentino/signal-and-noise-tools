<?php
/**
 * Signal & Noise — WordPress hardening at the theme layer.
 *
 * Closes the gaps Cloudflare's edge config doesn't already cover for
 * juanlentino.com, per WordPress's hardening documentation
 * (https://wordpress.org/documentation/article/hardening-wordpress/).
 *
 * Empirically scoped (verified 2026-05-08 via `curl -I`):
 *
 *   ✓ Already emitted by Cloudflare → not duplicated here:
 *     X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 *     Strict-Transport-Security, Content-Security-Policy. Sending
 *     them from PHP would be redundant — CF proxies all traffic, so
 *     edge values reach the browser regardless of origin headers.
 *
 *   ✓ Already closed at the edge → no theme work needed:
 *     /xmlrpc.php (CF returns 520 for POSTs, X-Pingback header
 *     stripped from homepage), /?author=N (returns 404, no author
 *     archive registered).
 *
 * What THIS module actually does:
 *
 *   1. Emits Permissions-Policy. CF isn't sending one currently; this
 *      locks features the site doesn't use (camera, mic, geolocation,
 *      payment, usb).
 *
 *   2. (Retired 2026-10-24.) The anonymous /wp/v2/users lock is
 *      inc/rest-hardening.php alone: it drops the routes from
 *      rest_endpoints, so core answers rest_no_route (404) on both URL
 *      spellings, any letter case, and inside _embed. The copy that lived
 *      here (a REQUEST_URI substring on rest_authentication_errors, 401)
 *      missed /wp-json/WP/V2/Users and only hid the real control.
 *
 *   3. Belt-and-suspenders: disables XML-RPC at the WP layer in case
 *      the edge rule is ever removed, and redirects ?author=N to home
 *      in case author archives are ever enabled. Both effectively
 *      no-op against the current edge config but cost nothing and
 *      survive an edge config drift.
 *
 * Filterable so individual hardenings can be reverted without editing
 * this file:
 *   - sn_security_permissions_policy (default true)
 *   - sn_security_block_author_enum  (default true)
 *   - sn_security_disable_xmlrpc     (default true)
 *
 * @package SignalNoise
 * @since 7.2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emit Permissions-Policy. Cloudflare already emits the four other
 * common security headers (X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy, HSTS) and a CSP at the edge — re-sending them from
 * PHP would be pure noise. Permissions-Policy is the one CF isn't
 * sending, so this fills exactly that gap.
 */
add_action( 'send_headers', function() {
	if ( ! apply_filters( 'sn_security_permissions_policy', true ) ) {
		return;
	}
	if ( headers_sent() ) {
		return;
	}
	// Lock features the site doesn't use. Expand this list if a future
	// feature legitimately needs one of them.
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()' );
} );

/**
 * Decision half of the author-enum guard (split from the redirect+exit
 * action so tests never cross exit). True = block the request.
 */
function sn_security_author_enum_should_redirect() {
	if ( ! apply_filters( 'sn_security_block_author_enum', true ) ) {
		return false;
	}
	if ( is_user_logged_in() ) {
		return false;
	}
	if ( ! isset( $_GET['author'] ) ) {
		return false;
	}
	return true;
}

/**
 * Block /?author=N → /author/{username}/ enumeration. Anonymous users
 * hitting /?author=N (any value) get redirected home; logged-in users
 * keep the standard behaviour so the admin author-archive view still
 * works from inside the dashboard.
 *
 * Hook order (v8.1.6, audit LOW-1): registered at priority 9. WP core
 * registers redirect_canonical on this same hook at priority 10 BEFORE
 * plugins load, so at the default priority core wins by registration
 * order and canonical-redirects a bare /?author=N (leaking the nicename
 * in Location) before this guard runs. Priority 9 puts the guard first.
 * Defense-in-depth on current config (the CF edge 404s /?author=N), but
 * the registered priority is the contract — tests/security-headers.php
 * asserts it.
 */
function sn_security_author_enum_guard() {
	if ( ! sn_security_author_enum_should_redirect() ) {
		return;
	}
	wp_safe_redirect( home_url( '/' ), 301 );
	exit;
}
add_action( 'template_redirect', 'sn_security_author_enum_guard', 9 );

/**
 * Author archives answer 404 to strangers, real author or not (2026-10-18).
 * Live, /notes/author/<real slug>/ answered 200 and a made-up slug 404, so a
 * guessed slug was confirmed, and the slug can be the login username. Nothing
 * on the site links an author archive. Signed-in users still see them; the
 * ?author= guard's filter switches both off.
 *
 * @return bool
 */
function sn_security_author_archive_should_404() {
	if ( ! apply_filters( 'sn_security_block_author_enum', true ) || is_user_logged_in() ) {
		return false;
	}
	return is_author();
}

/**
 * Turn the request into the theme's own 404 (no redirect, no exit): the
 * template loader then renders the same page any dead link gets.
 */
function sn_security_author_archive_guard() {
	if ( ! sn_security_author_archive_should_404() ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'sn_security_author_archive_guard', 9 );

/**
 * Disable XML-RPC and pingbacks.
 *
 *   - `xmlrpc_enabled` filter: per core's own docblock
 *     (wp_xmlrpc_server::set_is_enabled()) this switches off only the
 *     methods that require authentication. It does not control pingbacks.
 *   - `xmlrpc_methods` filter: removes every method in core's map,
 *     pingback.ping among them. That is what covers the methods
 *     `xmlrpc_enabled` leaves on.
 *   - `pings_open` filter: answers false for every post. This overrides
 *     Settings > Discussion and each post's own ping_status, and neither
 *     screen shows it. Trackbacks close too: wp-trackback.php asks
 *     pings_open(). No `pingback_url` filter is registered here.
 *
 * Belt + suspenders: the .htaccess / nginx layer should also block
 * /xmlrpc.php for total coverage, but those edits aren't theme-owned.
 */
add_filter( 'xmlrpc_enabled', function( $enabled ) {
	return apply_filters( 'sn_security_disable_xmlrpc', true ) ? false : $enabled;
} );

add_filter( 'xmlrpc_methods', function( $methods ) {
	return apply_filters( 'sn_security_disable_xmlrpc', true ) ? array() : $methods;
}, 99 );

add_filter( 'pings_open', function( $open ) {
	return apply_filters( 'sn_security_disable_xmlrpc', true ) ? false : $open;
}, 99 );

add_action( 'wp', function() {
	if ( ! apply_filters( 'sn_security_disable_xmlrpc', true ) ) {
		return;
	}
	// Core sets X-Pingback in WP::send_headers() only for a singular post
	// where pings_open() is true, so the `pings_open` filter above already
	// keeps it from being set. `xmlrpc_enabled` plays no part in that header.
	// This call is a fallback for a later filter that reopens pings.
	header_remove( 'X-Pingback' );
} );
