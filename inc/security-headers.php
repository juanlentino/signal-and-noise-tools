<?php
/**
 * Signal & Noise — WordPress hardening at the plugin layer.
 *
 * Closes the gaps Cloudflare's edge config doesn't already cover for
 * juanlentino.com, per WordPress's hardening documentation
 * (https://wordpress.org/documentation/article/hardening-wordpress/).
 *
 * Empirically scoped (headers verified 2026-05-08 via `curl -I`):
 *
 *   ✓ Already emitted by Cloudflare → not duplicated here:
 *     X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 *     Strict-Transport-Security, Content-Security-Policy. Sending
 *     them from PHP would be redundant — CF proxies all traffic, so
 *     edge values reach the browser regardless of origin headers.
 *
 *   ✓ Already closed at the edge → no plugin work needed:
 *     /?author=N (returns 404, no author archive registered).
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
 *   3. XML-RPC, in TWO independent layers (see the method-filter block):
 *      full-disable (default on) empties the method map entirely; a
 *      scoped strip (default on, INDEPENDENT of full-disable) removes
 *      system.multicall and pingback even when the endpoint is left on
 *      for a client that needs it. 2026-08-14: XML-RPC is currently ON
 *      (a monitoring tool required it), so the scoped strip is the live
 *      protection, with a Cloudflare WAF rule allowlisting the client's
 *      IPs and blocking the rest of /xmlrpc.php as the outer layer.
 *
 *   4. Redirects ?author=N to home in case author archives are ever
 *      enabled — a no-op against the current edge 404 but it survives
 *      edge config drift.
 *
 * Filterable so individual hardenings can be reverted without editing
 * this file:
 *   - sn_security_permissions_policy       (default true)
 *   - sn_security_lock_rest_users          (default true)
 *   - sn_security_block_author_enum        (default true)
 *   - sn_security_disable_xmlrpc           (default true)
 *   - sn_security_strip_xmlrpc_dangerous   (default true)
 *
 * @package SignalNoiseTools
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
	$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
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
 * XML-RPC hardening, in two independent layers so the dangerous methods stay
 * closed even when the endpoint itself is deliberately left on.
 *
 *   - `xmlrpc_enabled` — turns the endpoint off at the WP layer. Gated by
 *     sn_security_disable_xmlrpc (default true). Turn this off for a client
 *     that genuinely needs XML-RPC.
 *   - `xmlrpc_methods` — the method-map filter below. Empties the map under
 *     full-disable, and strips system.multicall + pingback UNDER ITS OWN
 *     switch even when full-disable is off. This is the layer that survives
 *     the endpoint being left on: see sn_security_xmlrpc_methods_filter().
 *   - `pings_open` — kills the pingback advertisement at the source. Belt to
 *     the method strip's suspenders (pingback.ping is removed regardless).
 *
 * Outer layer, not plugin-owned: a Cloudflare WAF custom rule allowlists the
 * needing client's IP ranges on /xmlrpc.php and blocks the rest, so brute
 * force never reaches the origin. This module is what holds if that rule is
 * ever removed or the client's IPs drift — the same edge-config-drift posture
 * as the author-enum guard below.
 */
add_filter( 'xmlrpc_enabled', function( $enabled ) {
	return apply_filters( 'sn_security_disable_xmlrpc', true ) ? false : $enabled;
} );

/**
 * The XML-RPC methods that must never be reachable, whether or not the
 * endpoint as a whole is disabled.
 *
 *   - system.multicall — the brute-force AMPLIFIER. One HTTP request carries
 *     hundreds of wrapped calls, so a single POST attempts hundreds of logins.
 *     It authenticates through a path wp-login.php's rate limiting and the
 *     login-guard worker never see, which is exactly why it is the method
 *     credential-stuffing tools reach for.
 *   - pingback.ping / pingback.extensions.getPingbacks — the SSRF vector.
 *     pingback.ping makes the server fetch an attacker-chosen URL.
 *
 * A function, not a const array, so a test can read it and a future edit
 * cannot silently shrink it.
 *
 * @return string[]
 */
function sn_security_xmlrpc_dangerous_methods() {
	return array(
		'system.multicall',
		'pingback.ping',
		'pingback.extensions.getPingbacks',
	);
}

/**
 * Filter the XML-RPC method map through TWO independent hardenings, each with
 * its own switch, so the dangerous methods stay closed even when the endpoint
 * is deliberately left open.
 *
 *   1. sn_security_disable_xmlrpc (default true) — empty the whole map. The
 *      endpoint still answers but exposes nothing. Correct when nothing
 *      legitimate uses XML-RPC.
 *   2. sn_security_strip_xmlrpc_dangerous (default true) — remove ONLY
 *      sn_security_xmlrpc_dangerous_methods(), leaving the rest. This is what
 *      survives switch 1 being turned off: a client that genuinely needs
 *      XML-RPC (Jetpack et al.) never needs system.multicall or pingback, so
 *      the brute-force amplifier stays shut without breaking that client.
 *
 * Order is deliberate: full-disable wins, because an empty map has nothing to
 * strip. Both switches off is the only path that leaves multicall reachable,
 * and it takes two explicit opt-outs to get there.
 *
 * Registered at priority 99 so it runs AFTER anything that populates the map —
 * Jetpack adds its methods on this same filter, and a strip that ran first
 * would find nothing to remove.
 *
 * @param mixed $methods The XML-RPC method map (name => callable).
 * @return array
 */
function sn_security_xmlrpc_methods_filter( $methods ) {
	if ( ! is_array( $methods ) ) {
		$methods = array();
	}
	if ( apply_filters( 'sn_security_disable_xmlrpc', true ) ) {
		return array();
	}
	if ( apply_filters( 'sn_security_strip_xmlrpc_dangerous', true ) ) {
		foreach ( sn_security_xmlrpc_dangerous_methods() as $method ) {
			unset( $methods[ $method ] );
		}
	}
	return $methods;
}
add_filter( 'xmlrpc_methods', 'sn_security_xmlrpc_methods_filter', 99 );

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
