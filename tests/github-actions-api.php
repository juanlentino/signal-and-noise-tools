<?php
/**
 * Standalone test: GitHub Actions runs client — outbound hardening.
 * Run: php tests/github-actions-api.php
 *
 * Focused suite: pins the v8.7.1 outbound-hardening convention
 * (redirection => 0) on the workflow-runs fetch, which carries the
 * SNT_GITHUB_TOKEN bearer when configured. Behavioural coverage of
 * caching/ETag/parsing is out of scope here.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( $c = '', $m = '' ) {} } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }

$GLOBALS['__site_trans'] = array();
function get_site_transient( $k ) { return $GLOBALS['__site_trans'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__site_trans'][ $k ] = $v; return true; }

$GLOBALS['__last_args'] = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__last_args'] = $args;
	return array(
		'response' => array( 'code' => 200 ),
		'headers'  => array( 'etag' => '"abc"' ),
		'body'     => json_encode( array( 'workflow_runs' => array() ) ),
	);
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $r, $h ) { return is_array( $r ) ? (string) ( $r['headers'][ $h ] ?? '' ) : ''; }

require_once __DIR__ . '/../inc/github-actions-api.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "GitHub Actions runs client — outbound hardening\n\n";

$GLOBALS['__site_trans'] = array(); // force a cache miss → live fetch
$GLOBALS['__last_args']  = array();
snt_gh_recent_runs( 'juanlentino/signal-and-noise-tools', 5 );
ok( ! empty( $GLOBALS['__last_args'] ), 'runs: a live fetch was issued (cache miss)' );
// Outbound-hardening convention (v8.7.1): the runs fetch carries the GitHub
// bearer token when SNT_GITHUB_TOKEN is set — forbid redirects so a 3xx can't
// forward it to an attacker-controlled host.
ok( 0 === ( $GLOBALS['__last_args']['redirection'] ?? -1 ), 'runs: request disables redirects (no GitHub token forward on a 3xx)' );
// Timeout hardening (v9.46.1): 5min transient + ETag conditional — 5s bounds
// the cold miss on the dashboard/deploy surfaces.
ok( 5 === ( $GLOBALS['__last_args']['timeout'] ?? -1 ), 'runs: timeout capped at 5s' );

// ── #1223a: the cache key must include $count, or a 1-count poll (the
// desktop widget) and a 10-count poll (the dashboard) share one entry and
// whichever polled last wins for both.
$GLOBALS['__site_trans'] = array();
snt_gh_recent_runs( 'juanlentino/signal-and-noise-tools', 1 );
snt_gh_recent_runs( 'juanlentino/signal-and-noise-tools', 10 );
$data_keys = array_filter( array_keys( $GLOBALS['__site_trans'] ), function ( $k ) {
	return false !== strpos( $k, 'sn_gh_recent_runs_' ) && false === strpos( $k, 'etag' );
} );
ok( 2 === count( $data_keys ), 'a count=1 poll and a count=10 poll for the same repo land in TWO cache entries, not one: ' . implode( ',', $data_keys ) );

// ── #1223b: the etag must survive past the data transient's own TTL, or
// If-None-Match is never sent on the request that follows an expiry — the
// 304 branch above never runs, and every poll pays full quota.
$GLOBALS['__site_trans'] = array();
$GLOBALS['__last_args']  = array();
snt_gh_recent_runs( 'juanlentino/signal-and-noise-tools', 5 ); // first fetch: stores the etag
// Simulate the data transient expiring (its normal 5-minute TTL) WITHOUT
// touching the etag transient — that separation is the entire fix.
foreach ( array_keys( $GLOBALS['__site_trans'] ) as $k ) {
	if ( false !== strpos( $k, 'sn_gh_recent_runs_' ) && false === strpos( $k, 'etag' ) ) {
		unset( $GLOBALS['__site_trans'][ $k ] );
	}
}
$GLOBALS['__last_args'] = array();
snt_gh_recent_runs( 'juanlentino/signal-and-noise-tools', 5 ); // second fetch: data cache expired, etag should not have
ok( '"abc"' === ( $GLOBALS['__last_args']['headers']['If-None-Match'] ?? '' ), 'the etag from the first fetch survives the data cache expiring and is sent as If-None-Match on the next request' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
