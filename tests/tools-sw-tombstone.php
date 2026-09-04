<?php
/**
 * Tests: the /tools/sw.js tombstone (issue #1002).
 *
 * The worker exists to remove an orphaned registration. Every property below is
 * one the worker needs in order to disappear; get one wrong and the route ships
 * a NEW permanent service worker instead of deleting an old one, which is
 * strictly worse than doing nothing.
 *
 * Run: php tests/tools-sw-tombstone.php
 * @since 13.96.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'SNT_TOOLS_SW_TEST', true ); // do not attach the template_redirect hook
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}

require_once __DIR__ . '/../inc/tools-sw-tombstone.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "tools-sw-tombstone — plugin v13.96.2\n\nGroup 1: the route answers the exact registered path\n";

// The path is not a guess. It was read off the live registration:
//   [ { scope: "https://juanlentino.com/tools/", script: ".../tools/sw.js" } ]
ok( '/tools/sw.js' === SNT_TOOLS_SW_PATH, 'the path is the one the registration names' );
ok( snt_tools_sw_is_request( '/tools/sw.js' ), 'the bare path matches' );

// THE load-bearing case: a service worker update check may carry a cache-buster.
// Matching the raw REQUEST_URI instead of the path would miss it, and the update
// check is the only moment a browser will accept a replacement worker.
ok( snt_tools_sw_is_request( '/tools/sw.js?ver=123' ), 'a query string still matches — the update check is the one request that must not be missed' );
ok( snt_tools_sw_is_request( '/tools/sw.js/' ), 'a trailing slash still matches' );

foreach ( array( '/tools/', '/tools/sw.json', '/sw.js', '/wp-admin/admin.php?page=openstation', '/tools/sw.js.map', '/' ) as $miss ) {
	ok( ! snt_tools_sw_is_request( $miss ), "does NOT claim $miss" );
}

echo "\nGroup 2: the body removes itself\n";
$js = snt_tools_sw_body();
ok( '' !== trim( $js ), 'the body is non-empty (an empty worker would install and sit there forever)' );
ok( false !== strpos( $js, 'skipWaiting' ),
	'skipWaiting() is called — without it the replacement waits behind the dead worker for a client that nobody opens' );
ok( false !== strpos( $js, 'registration.unregister' ), 'it unregisters itself' );
ok( false !== strpos( $js, 'caches.delete' ), 'it clears the caches its predecessor left' );
ok( false !== strpos( $js, 'clients.matchAll' ) && false !== strpos( $js, 'navigate' ),
	'it re-navigates the clients it controls, so they come back uncontrolled' );

// A tombstone that intercepts requests is just a different broken worker.
ok( false === strpos( $js, "addEventListener( 'fetch'" ) && false === strpos( $js, 'addEventListener("fetch"' ),
	'it registers NO fetch handler — a tombstone that intercepts traffic is a new bug, not a fix' );

// Control: the assertions above are substring checks, so prove they can fail.
ok( false === strpos( 'self.addEventListener( "install", function () {} );', 'registration.unregister' ),
	'CONTROL: a worker that does NOT unregister fails the unregister check' );

echo "\nGroup 3: it is served so the update check can actually reach it\n";
/**
 * Source with comments stripped.
 *
 * The first version of the Service-Worker-Allowed assertion below went red on
 * the DOCBLOCK that explains why the header is absent. Prose about a rule is not
 * the rule — the same confusion tests/inc-population-guard.php and
 * tests/health-contrast-usage.php both already document.
 */
function snt_tsw_code_only( $php ) {
	$out = '';
	foreach ( token_get_all( $php ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) { $out .= "\n"; continue; }
		$out .= is_array( $t ) ? $t[1] : $t;
	}
	return $out;
}
$src = snt_tsw_code_only( (string) file_get_contents( __DIR__ . '/../inc/tools-sw-tombstone.php' ) );
ok( false !== strpos( $src, 'status_header' ) && false === strpos( $src, 'WHY THIS EXISTS' ),
	'CONTROL: the comment stripper removes prose and keeps code' );
ok( false !== strpos( $src, 'application/javascript' ), 'served as JavaScript' );
ok( (bool) preg_match( '/Cache-Control:[^\']*no-store/i', $src ),
	'served no-store — a CACHED copy of the old script is what kept the registration alive; caching the replacement reproduces the bug with new bytes' );

// Scope discipline: /tools/sw.js gets /tools/ scope by default. Sending
// Service-Worker-Allowed would claim ground this worker never had.
ok( false === stripos( $src, 'Service-Worker-Allowed' ),
	'no Service-Worker-Allowed header — the registration is /tools/-scoped and the replacement must not widen it' );

echo "\nGroup 4: it is wired into the bootstrap\n";
$boot = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( false !== strpos( $boot, 'inc/tools-sw-tombstone.php' ), 'required from the plugin bootstrap — an unloaded route serves nothing' );
ok( false !== strpos( $src, "add_action( 'template_redirect', 'snt_tools_sw_maybe_serve', 0 )" ),
	'hooked at template_redirect priority 0, before WP resolves the 404 for this postless path' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
