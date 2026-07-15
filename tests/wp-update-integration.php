<?php
/**
 * Standalone test: plugin self-updater tag fetch — outbound hardening.
 * Run: php tests/wp-update-integration.php
 *
 * Focused suite: pins the v8.7.1 outbound-hardening convention
 * (redirection => 0) on sn_gh_latest_plugin_tag()'s GitHub tags fetch,
 * which carries the SNT_GITHUB_TOKEN bearer when configured. Full
 * behavioural coverage (cache TTL, ETag, version selection) is tracked
 * separately as roadmap item C3 and can extend this file.
 *
 * @package SignalNoiseTools
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( $c = '', $m = '' ) {} } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }

$GLOBALS['__site_trans'] = array();
function get_site_transient( $k ) { return $GLOBALS['__site_trans'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__site_trans'][ $k ] = $v; return true; }

$GLOBALS['__last_args'] = array();
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__last_args'] = $args;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( array( 'name' => 'v9.0.0' ), array( 'name' => 'v8.8.4' ) ) ),
	);
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }

require_once __DIR__ . '/../inc/wp-update-integration.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

echo "Plugin self-updater tag fetch — outbound hardening\n\n";

$GLOBALS['__site_trans'] = array(); // force a cache miss → live fetch
$GLOBALS['__last_args']  = array();
$tag = sn_gh_latest_plugin_tag( true );
ok( 'v9.0.0' === $tag, 'tag: selects the highest valid semver tag (sanity)' );
ok( ! empty( $GLOBALS['__last_args'] ), 'tag: a live fetch was issued' );
// Outbound-hardening convention (v8.7.1): the tags fetch carries the GitHub
// bearer token when SNT_GITHUB_TOKEN is set — forbid redirects so a 3xx can't
// forward it. This fetch sits on the critical wp-admin Updates path.
ok( 0 === ( $GLOBALS['__last_args']['redirection'] ?? -1 ), 'tag: request disables redirects (no GitHub token forward on a 3xx)' );
// Timeout hardening (v9.46.1): 1h transient either way — 5s bounds the
// once-hourly cold miss that can land on any admin_init.
ok( 5 === ( $GLOBALS['__last_args']['timeout'] ?? -1 ), 'tag: timeout capped at 5s' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
