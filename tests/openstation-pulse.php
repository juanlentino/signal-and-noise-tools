<?php
/**
 * Standalone fixture tests for inc/openstation-pulse.php: Signal & Noise
 * feeding OpenStation 1.1.14's Pulse Monitor (Experimental server feed).
 *
 * Run: php tests/openstation-pulse.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

/* WP stubs. */
$GLOBALS['__filters']    = array();
$GLOBALS['__actions']    = array();
$GLOBALS['__transients'] = array();
function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][] = array( 'cb' => $cb, 'p' => $p, 'a' => $a ); }
function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = array( 'cb' => $cb, 'p' => $p, 'a' => $a ); }
function fire( $hook ) { foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $e ) { call_user_func( $e['cb'] ); } }
function hooked( $hook, $cb ) { foreach ( $GLOBALS['__filters'][ $hook ] ?? array() as $e ) { if ( $e['cb'] === $cb ) { return $e; } } foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $e ) { if ( $e['cb'] === $cb ) { return $e; } } return null; }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
class WP_Error {}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : ''; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }

define( 'SN_UPTIME_STATUS_TRANSIENT', 'sn_uptime_status_snapshot' );

require_once __DIR__ . '/../inc/openstation-pulse.php';

echo "openstation-pulse: S&N feeding the OpenStation Pulse Monitor\n\n";

/* 1. Registration waits for the upstream feed. */
fire( 'plugins_loaded' );
ok( null === hooked( 'openstation_pulse_request_kind', 'snt_pulse_request_kind' ), 'no Pulse feed upstream: nothing is hooked' );

$GLOBALS['__recorded'] = array();
$GLOBALS['__counts']   = false;
// Conditionally declared: parse-time hoisting would falsify the check above.
if ( ! function_exists( 'openstation_pulse_record' ) ) {
	function openstation_pulse_record( $kind, $args = array() ) { $GLOBALS['__recorded'][] = array( $kind, ! empty( $args['error'] ) ); return true; }
	function openstation_pulse_counts_visitors() { return $GLOBALS['__counts']; }
	function openstation_pulse_is_bot( $ua ) { return snt_pulse_is_bot( false, $ua ); }
}

fire( 'plugins_loaded' );
ok( null !== hooked( 'openstation_pulse_request_kind', 'snt_pulse_request_kind' ), 'request kind filter hooked once the feed exists' );
$is_bot = hooked( 'openstation_pulse_is_bot', 'snt_pulse_is_bot' );
ok( null !== $is_bot && 2 === $is_bot['a'], 'is_bot filter hooked with both arguments' );
ok( null !== hooked( 'openstation_pulse_health', 'snt_pulse_health' ), 'health filter hooked' );
$http = hooked( 'http_api_debug', 'snt_pulse_on_http' );
ok( null !== $http && 5 === $http['a'], 'http_api_debug watched with all five arguments' );

/* 2. Request kinds: only logged-out 'api' hits to our two public routes move. */
ok( 'visitors' === snt_pulse_kind_for( 'api', '/signal-noise/v1/feed-open', true, false ), 'feed-open pixel is a visitor when page views count' );
ok( 'bots' === snt_pulse_kind_for( 'api', '/signal-noise/v1/feed-open', true, true ), 'feed-open from a bot UA is a bot' );
ok( '' === snt_pulse_kind_for( 'api', '/signal-noise/v1/feed-open', false, false ), 'feed-open stays off the trace while page views are off (the opt-in holds)' );
ok( '' === snt_pulse_kind_for( 'api', '/signal-noise/v1/live', true, false ), 'the live-now poll (an edge-cache miss, not a reader) is dropped' );
ok( '' === snt_pulse_kind_for( 'api', '/signal-noise/v1/live', false, false ), 'and dropped with page views off too' );
ok( 'api' === snt_pulse_kind_for( 'api', '/signal-noise/v1/citations', true, false ), 'any other anonymous REST call stays api' );
ok( 'team' === snt_pulse_kind_for( 'team', '/signal-noise/v1/live', true, false ), 'a logged-in hit keeps its team lane' );
ok( 'cron' === snt_pulse_kind_for( 'cron', '', true, false ), 'cron is untouched' );

$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/signal-noise/v1/feed-open/' ) );
$_SERVER['HTTP_USER_AGENT'] = 'NetNewsWire (RSS Reader; https://netnewswire.com/)';
$GLOBALS['__counts'] = true;
ok( 'visitors' === snt_pulse_request_kind( 'api' ), 'the wrapper reads the REST route (trailing slash tolerated) and the UA' );
$_SERVER['HTTP_USER_AGENT'] = 'Mastodon/4.3 (http.rb/5.2; +https://example.social/)';
ok( 'bots' === snt_pulse_request_kind( 'api' ), 'a fediverse fetcher on the pixel is a bot via the S&N classifier' );
$GLOBALS['__counts'] = false;
ok( '' === snt_pulse_request_kind( 'api' ), 'the wrapper honors the visitors opt-in' );
unset( $GLOBALS['wp'] );
ok( 'api' === snt_pulse_request_kind( 'api' ), 'no REST route known: the kind passes through' );

/* 3. Bots: S&N's classifier only ever ADDS detection. */
function sn_rss_tracker_is_bot( $ua ) { return (bool) preg_match( '/mastodon|http\.rb\//i', $ua ); }
ok( true === snt_pulse_is_bot( false, 'Mastodon/4.3 (http.rb/5.2)' ), 'a UA upstream misses but S&N knows is a bot' );
ok( true === snt_pulse_is_bot( true, 'HeadlessChrome/120' ), 'an upstream bot verdict is never overturned' );
ok( false === snt_pulse_is_bot( false, 'Mozilla/5.0 (Macintosh) Safari/605' ), 'a browser stays a visitor' );

/* 4. Health: a down Better Stack monitor is a failed request. */
$base = array( 'siteHealth' => null, 'requests' => 10, 'failures' => 1, 'cronLate' => 0 );
$rows = array(
	'fetched_at' => 1,
	'rows'       => array(
		array( 'kind' => 'monitor', 'status' => 'down' ),
		array( 'kind' => 'monitor', 'status' => 'up' ),
		array( 'kind' => 'monitor', 'status' => 'paused' ),
		array( 'kind' => 'heartbeat', 'status' => 'down' ),
	),
);
$out = snt_pulse_health_with_uptime( $base, $rows );
ok( 2 === $out['failures'] && 11 === $out['requests'], 'one down monitor adds one failure and one request (heartbeats and paused do not)' );
ok( 0 === $out['cronLate'] && null === $out['siteHealth'], 'the rest of the snapshot is untouched' );
ok( $base === snt_pulse_health_with_uptime( $base, false ), 'no cached uptime snapshot: unchanged (never a network call)' );
ok( $base === snt_pulse_health_with_uptime( $base, array( 'rows' => 'junk' ) ), 'a malformed snapshot changes nothing' );
$zero = snt_pulse_health_with_uptime( array( 'requests' => 0, 'failures' => 0 ), $rows );
ok( $zero['failures'] <= $zero['requests'], 'failures never exceed requests' );
$GLOBALS['__transients']['sn_uptime_status_snapshot'] = $rows;
ok( 2 === snt_pulse_health( $base )['failures'], 'the filter reads the uptime transient' );

/* 5. Beats. */
$GLOBALS['__recorded'] = array();
ok( true === snt_pulse_beat( 'cache-purge', false ), 'snt_pulse_beat() records through openstation_pulse_record()' );
ok( array( array( 'cache-purge', false ) ) === $GLOBALS['__recorded'], 'kind and error flag pass through' );

function sn_prov_worker_url() { return 'https://prov.example/hook'; }
$GLOBALS['__recorded'] = array();
snt_pulse_on_http( array( 'response' => array( 'code' => 200 ) ), 'response', 'Requests', array(), 'https://prov.example/hook' );
snt_pulse_on_http( array( 'response' => array( 'code' => 502 ) ), 'response', 'Requests', array(), 'https://prov.example/hook' );
snt_pulse_on_http( new WP_Error(), 'response', 'Requests', array(), 'https://prov.example/hook' );
snt_pulse_on_http( array( 'response' => array( 'code' => 200 ) ), 'response', 'Requests', array(), 'https://prov.example/hook/sweep' );
snt_pulse_on_http( array( 'response' => array( 'code' => 200 ) ), 'response', 'Requests', array(), 'https://other.example/' );
ok( array( array( 'provenance', false ), array( 'provenance', true ), array( 'provenance', true ) ) === $GLOBALS['__recorded'], 'a signing dispatch beats; a non-2xx or transport error is a failed beat; the sweep and other hosts do not beat' );

/* 6. The purge seam and privacy. */
$purge = (string) file_get_contents( __DIR__ . '/../inc/cloudflare-purge.php' );
ok( 1 === substr_count( $purge, "snt_pulse_beat( 'cache-purge'" ), 'sn_cf_purge_everything() beats once, guarded' );
// The error flag itself: refused is a failed beat; confirmed, and queued for a retry, are not.
ok( false === snt_pulse_purge_failed( true, '' ), 'a confirmed purge is a good beat' );
ok( false === snt_pulse_purge_failed( false, 'queued' ), 'a purge Cloudflare queued for retry is not a failure' );
ok( true === snt_pulse_purge_failed( false, 'refused' ), 'a refused purge is a failed beat' );
ok( 1 === substr_count( $purge, "snt_pulse_beat( 'cache-purge', snt_pulse_purge_failed( \$confirmed, sn_cf_send_last() ) );" ), 'the purge seam passes the confirmation and the send outcome, uninverted' );
$src = (string) file_get_contents( __DIR__ . '/../inc/openstation-pulse.php' );
ok( false === strpos( $src, 'wp_remote_' . 'get' ) && false === strpos( $src, 'sn_uptime_status_' . 'fetch' ), 'no network call anywhere in the module' );
ok( false === strpos( $src, 'REMOTE_' . 'ADDR' ), 'no IP is read' );
$boot = (string) file_get_contents( __DIR__ . '/../signal-and-noise-tools.php' );
ok( false !== strpos( $boot, "inc/openstation-pulse.php'" ), 'the loader requires the module' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
