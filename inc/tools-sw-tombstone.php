<?php
/**
 * Signal & Noise Tools — a tombstone service worker for the removed /tools/ PWA.
 *
 * WHY THIS EXISTS (issue #1002). A service worker was registered from
 * `https://juanlentino.com/tools/sw.js` with scope `/tools/`. That deployment is
 * gone — the page and the script both 404 — but the REGISTRATION lives in every
 * browser that visited it while it was live, and a registration outlives its
 * server. Chrome is supposed to drop one whose script 404s, but that only fires
 * on an in-scope navigation, and nobody navigates to a page that no longer
 * exists. So it can sit there indefinitely.
 *
 * The remedy for an orphaned worker is not to delete the file harder. It is to
 * serve a LIVE script at the same URL whose only job is to remove itself: the
 * browser's own update check fetches it, the new worker activates, unregisters,
 * clears the caches its predecessor left, and reloads whatever it controls.
 * After that the registration is gone and this route serves nobody.
 *
 * `/tools/` is a WordPress-served path on this site (its 404 is the theme's own
 * template), so this plugin can answer it from a virtual route. Nothing in the
 * `sntools-web` repo is touched, and nothing needs to be redeployed there.
 *
 * SCOPE IS `/tools/`, NOT ROOT — established by reading the live registration
 * rather than inferring it:
 *
 *     [ { "scope": "https://juanlentino.com/tools/",
 *         "script": "https://juanlentino.com/tools/sw.js" } ]
 *
 * That matters twice. It is why no `Service-Worker-Allowed` header is sent — a
 * worker served at /tools/sw.js already gets /tools/ scope by default, and
 * widening it would be claiming ground this worker was never entitled to. And
 * it is why this route is NOT a fix for the 503s seen on /wp-admin/: a
 * /tools/-scoped worker cannot intercept a page outside its scope. Those are a
 * separate, still-open problem; do not read this file as closing them.
 *
 * @package SignalNoiseTools
 * @since 13.96.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The exact path the orphaned worker was registered from. */
const SNT_TOOLS_SW_PATH = '/tools/sw.js';

/**
 * Is this request for the tombstone?
 *
 * Compares the PATH only. A service worker update check may carry a cache-
 * busting query, and matching the raw REQUEST_URI would miss it — the update
 * request is the one case that must never be missed, because it is the only
 * moment the browser will accept a replacement.
 *
 * @param string $request_uri Raw REQUEST_URI.
 * @return bool
 */
function snt_tools_sw_is_request( $request_uri ) {
	$path = (string) wp_parse_url( (string) $request_uri, PHP_URL_PATH );
	if ( '' === $path ) {
		return false;
	}
	// Tolerate a trailing slash and a repo installed under a subdirectory.
	$path = '/' . ltrim( rtrim( $path, '/' ), '/' );

	return SNT_TOOLS_SW_PATH === $path;
}

/**
 * The worker body: install, activate, remove yourself, take the pages with you.
 *
 * `skipWaiting()` matters. Without it the replacement sits in `waiting` behind
 * the dead worker until every controlled client closes — and the whole problem
 * is that the client which would close is a page nobody opens any more.
 *
 * @return string JavaScript.
 */
function snt_tools_sw_body() {
	return <<<'JS'
/* Tombstone for the removed /tools/ PWA — signal-and-noise-tools #1002.
   This worker exists only to delete itself and its predecessor's caches. */
self.addEventListener( 'install', function () {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		self.registration.unregister()
			.then( function () { return caches.keys(); } )
			.then( function ( keys ) {
				return Promise.all( keys.map( function ( k ) { return caches.delete( k ); } ) );
			} )
			.then( function () { return self.clients.matchAll( { type: 'window' } ); } )
			.then( function ( clients ) {
				clients.forEach( function ( c ) {
					// Re-navigate so the page reloads uncontrolled. Guarded:
					// navigate() rejects on a client this worker cannot claim,
					// and an unhandled rejection here would abort the whole
					// activate step, leaving the registration in place.
					try { c.navigate( c.url ); } catch ( e ) {}
				} );
			} )
			.catch( function () {} )
	);
} );

/* No fetch handler, deliberately. A tombstone that intercepts requests is just
   a different broken worker; with none, the browser goes straight to network
   for the moments before this worker disappears. */
JS;
}

/**
 * Send the tombstone.
 *
 * `no-store` is load-bearing: the browser's update check is an HTTP fetch like
 * any other, and a cached copy of the OLD script is what has kept this
 * registration alive. Serving the replacement from a cache would reproduce the
 * bug with new bytes.
 */
function snt_tools_sw_send() {
	if ( function_exists( 'status_header' ) ) {
		status_header( 200 ); // A postless path 404s by default at template_redirect.
	}
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex' );
	}
	echo snt_tools_sw_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JavaScript body, not HTML; escaping would corrupt it.
}

/**
 * template_redirect handler, priority 0 — before WP resolves the 404.
 */
function snt_tools_sw_maybe_serve() {
	$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( snt_tools_sw_is_request( $req ) ) {
		snt_tools_sw_send();
		exit;
	}
}

if ( ! defined( 'SNT_TOOLS_SW_TEST' ) || ! SNT_TOOLS_SW_TEST ) {
	add_action( 'template_redirect', 'snt_tools_sw_maybe_serve', 0 );
}
