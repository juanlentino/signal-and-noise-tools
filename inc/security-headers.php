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
 *   2. Locks down /wp-json/wp/v2/users for anonymous requests. Returns
 *      401 instead of leaking the username/slug list — confirmed leaking
 *      pre-fix on production. Authenticated callers (block editor, REST
 *      clients, Plausible widget proxy etc.) keep working.
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
 *   - sn_security_lock_rest_users    (default true)
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
 * Block /?author=N → /author/{username}/ enumeration. Anonymous users
 * hitting /?author=N (any value) get redirected home; logged-in users
 * keep the standard behaviour so the admin author-archive view still
 * works from inside the dashboard.
 */
add_action( 'template_redirect', function() {
	if ( ! apply_filters( 'sn_security_block_author_enum', true ) ) {
		return;
	}
	if ( is_user_logged_in() ) {
		return;
	}
	if ( isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

/**
 * Lock down /wp-json/wp/v2/users for unauthenticated requests. The
 * default WP REST API exposes username + display name + slug to anyone,
 * which is a free pass for brute-force attackers. Authenticated requests
 * (admins from the editor, etc.) still work.
 *
 * Implemented via `rest_authentication_errors` rather than removing the
 * route, so authenticated callers from the block editor or REST clients
 * keep working while anonymous callers get 401.
 */
add_filter( 'rest_authentication_errors', function( $result ) {
	if ( ! apply_filters( 'sn_security_lock_rest_users', true ) ) {
		return $result;
	}
	// Already an error — let it through unchanged.
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( is_user_logged_in() ) {
		return $result;
	}
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	// Match both /wp-json/wp/v2/users and the rest-route query form.
	if ( false !== strpos( $path, '/wp/v2/users' ) || false !== strpos( $path, 'rest_route=/wp/v2/users' ) ) {
		return new WP_Error(
			'rest_user_enum_blocked',
			'Authentication required.',
			array( 'status' => 401 )
		);
	}
	return $result;
}, 99 );

/**
 * Disable XML-RPC entirely.
 *
 *   - `xmlrpc_enabled` filter — turns the endpoint off at the WP layer.
 *   - `xmlrpc_methods` filter — empties the method map so any code path
 *     that bypasses the enabled-flag check still gets nothing.
 *   - `pings_open` / `pingback_url` — kill pingbacks at the source.
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
	// Strip the X-Pingback header WP sends by default. Already handled
	// by xmlrpc_enabled=false on most paths, but this ensures the
	// header never appears even if a plugin re-enables xmlrpc.
	header_remove( 'X-Pingback' );
} );
