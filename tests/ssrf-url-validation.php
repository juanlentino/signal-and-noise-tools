<?php
/**
 * Standalone tests for the outbound-URL SSRF guard (v4.14.1).
 *
 * An outbound module consumed an admin/option-set host and dispatched a
 * wp_remote_* request WITHOUT the wp_http_validate_url() guard that the
 * codebase's own inc/webhooks.php (x4) + inc/uptime-heartbeat.php (x2) already
 * apply. This locks it to the same pattern: https-only + wp_http_validate_url()
 * (which rejects reserved/internal IPs), so:
 *   - sn_rss_tracker_send_plausible(): an internal / non-https endpoint is skipped
 *     instead of POSTed to (this fires on UNauthenticated public feed hits and
 *     forwards the requester's UA + X-Forwarded-For).
 * Valid public https hosts are unaffected (non-breaking).
 *
 * v6.0.0: the Plausible Stats-API half (sn_plausible_config) was removed with
 * inc/plausible-api.php. Only the RSS-tracker SSRF guard remains.
 *
 * Run: php tests/ssrf-url-validation.php
 *
 * @since plugin v4.14.1
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SNT_VERSION', '4.14.1' );

$pass = 0;
$fail = 0;
function ok( $c, $m ) {
	global $pass, $fail;
	if ( $c ) {
		++$pass;
		echo "PASS: $m\n";
	} else {
		++$fail;
		echo "FAIL: $m\n";
	}
}

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); } }

// Option store (sn_plausible_config reads get_option twice).
$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}

// wp_http_validate_url — mirror WP core FAITHFULLY: require http/https + host,
// and reject the RFC-1918 / loopback ranges WP rejects — but NOT 169.254.0.0/16
// (WP core genuinely omits the link-local range). The 169.254 block is the
// plugin's OWN explicit check, so the stub must not fake it, or the metadata
// test would give false assurance (per the adversarial-review finding).
if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( $u ) {
		if ( ! is_string( $u ) || '' === $u ) { return false; }
		$p = parse_url( $u );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) { return false; }
		if ( ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) ) { return false; }
		if ( ! empty( $p['user'] ) || ! empty( $p['pass'] ) ) { return false; } // userinfo
		$host = strtolower( $p['host'] );
		foreach ( array( '127.', '10.', '192.168.', '172.16.', '0.', 'localhost' ) as $bad ) {
			if ( $host === $bad || 0 === strpos( $host, $bad ) ) { return false; }
		}
		return $u; // NOTE: 169.254.x is intentionally NOT rejected here — WP core doesn't.
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

// Capture outbound POSTs (rss tracker). wp_remote_get not exercised here.
$GLOBALS['__post_calls'] = array();
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__post_calls'][] = array( 'url' => $url, 'args' => $args );
		return array();
	}
}
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $e = 0 ) { return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }

require __DIR__ . '/../inc/rss-plausible-tracker.php';

// ── sn_rss_tracker_send_plausible() SSRF guard on plausible_url ──────────
function rss_send( $url ) {
	$GLOBALS['__post_calls'] = array();
	sn_rss_tracker_send_plausible(
		array( 'plausible_url' => $url, 'event_name' => 'pageview', 'plausible_domain' => 'example.com' ),
		'https://example.com/notes/feed/', 'curl/8', 'abc123', '203.0.113.7'
	);
	return count( $GLOBALS['__post_calls'] );
}

ok( rss_send( 'https://127.0.0.1/api/event' ) === 0, 'rss: loopback https endpoint → POST skipped (no SSRF on public feed hit)' );
ok( rss_send( 'https://169.254.169.254/api/event' ) === 0, 'rss: cloud-metadata 169.254 endpoint → POST skipped (explicit link-local guard)' );
ok( rss_send( 'http://plausible.example.com/api/event' ) === 0, 'rss: non-https endpoint → POST skipped' );
ok( rss_send( '' ) === 0, 'rss: empty endpoint → POST skipped' );
$n = rss_send( 'https://plausible.example.com/api/event' );
ok( $n === 1, 'rss: valid https endpoint → POST sent (NON-BREAKING)' );
ok( $n === 1 && $GLOBALS['__post_calls'][0]['url'] === 'https://plausible.example.com/api/event', 'rss: POST dispatched to the validated endpoint' );
ok( $n === 1 && ( $GLOBALS['__post_calls'][0]['args']['redirection'] ?? null ) === 0, 'rss: POST sets redirection=0 (no redirect-to-internal bypass)' );

echo "Result: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
