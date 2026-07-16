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
// v9.54.1: the transient/durable failure TTLs are expressed in minutes.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
// WP_Error must actually CARRY its code+message. The old stub took both
// constructor args and threw them away, and had no accessors at all — so any
// test of a network-failure path would have fataled on get_error_message(), or
// silently asserted against a fiction. Model the real class.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'sprintf_placeholder' ) ) { /* no-op guard */ }

$GLOBALS['__site_trans'] = array();
function get_site_transient( $k ) { return $GLOBALS['__site_trans'][ $k ] ?? false; }
function set_site_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__site_trans'][ $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['__site_trans'][ $k ] ); return true; }

// Controllable HTTP stub. Default = the healthy 200 the original suite assumed;
// set $GLOBALS['__http'] to drive a failure path.
$GLOBALS['__last_args'] = array();
$GLOBALS['__http']      = null;
$GLOBALS['__calls']     = 0;
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__last_args'] = $args;
	$GLOBALS['__calls']     = ( $GLOBALS['__calls'] ?? 0 ) + 1;
	$healthy = array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array( array( 'name' => 'v9.0.0' ), array( 'name' => 'v8.8.4' ) ) ),
	);
	// Models the ACTUAL incident: GitHub failing ~35% of requests, so the same
	// URL 503s once and answers fine a moment later. A stub that always returns
	// one fixed response cannot express "flaky", and a retry test written
	// against it would pass without a retry ever happening.
	if ( 'flaky-503-then-200' === $GLOBALS['__http'] ) {
		return $GLOBALS['__calls'] < 2
			? array( 'response' => array( 'code' => 503 ), 'body' => 'Service Unavailable' )
			: $healthy;
	}
	if ( null !== $GLOBALS['__http'] ) {
		return $GLOBALS['__http'];
	}
	return $healthy;
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

echo "\n── v9.54.0: the checker must never fail silently ──\n";
// THE INCIDENT (2026-07-16): both cards showed a red "unknown" with no
// explanation, and nothing anywhere said why. A 401 (dead token), a 403 (rate
// limit), a 404 (repo gone) and a 5s timeout ALL collapsed to `return null`,
// so diagnosing it took reading the source, timing the endpoint from a laptop,
// and probing GitHub's 401 header behaviour — to conclude something the code
// knew the whole time and threw away.
//
// Worse, the dashboard's "GitHub API: 4,971/5,000" readout still looked
// healthy: the rate monitor only records a snapshot from responses that CARRY
// x-ratelimit-* headers, and GitHub's 401 for a bad credential carries none.
// A cache that only updates on success cannot report failure — it freezes at
// the last good value and poses as healthy exactly when it isn't.
//
// Same rule as the v9.47.2 janitor: never silent. Keep the reason.

// -- a dead token is NAMED, not swallowed --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"message":"Bad credentials"}' );
$tag = sn_gh_latest_plugin_tag( true );
ok( null === $tag, '401: still returns null (the update path is unchanged)' );
$why = sn_gh_latest_plugin_tag_error();
ok( is_string( $why ) && '' !== $why, '401: a reason is recorded at all (this is the whole fix)' );
ok( false !== stripos( (string) $why, '401' ), '401: the reason names the status code' );
ok( false !== stripos( (string) $why, 'token' ), '401: the reason names the TOKEN as the thing to check' );

// -- rate limiting is distinguishable from a dead token --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = array( 'response' => array( 'code' => 403 ), 'body' => '{"message":"rate limit exceeded"}' );
sn_gh_latest_plugin_tag( true );
$why403 = sn_gh_latest_plugin_tag_error();
ok( false !== stripos( (string) $why403, '403' ), '403: the reason names the status code' );
ok( $why403 !== $why, '403 and 401 read differently — the card can tell them apart' );

// -- a repo that vanished --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = array( 'response' => array( 'code' => 404 ), 'body' => '{}' );
sn_gh_latest_plugin_tag( true );
ok( false !== stripos( (string) sn_gh_latest_plugin_tag_error(), '404' ), '404: the reason names the status code' );

// -- the network-error path: no HTTP response at all --
// This is the case the frozen rate readout hides best: a WP_Error never fires
// the http_response filter, so the snapshot keeps showing the last good number.
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds' );
sn_gh_latest_plugin_tag( true );
$whyerr = sn_gh_latest_plugin_tag_error();
ok( false !== stripos( (string) $whyerr, 'timed out' ), 'WP_Error: the reason carries the real cURL message, not a generic string' );
ok( $whyerr !== $why403 && $whyerr !== $why, 'a network failure reads differently from any HTTP status' );

// -- a 200 with garbage --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = array( 'response' => array( 'code' => 200 ), 'body' => 'not json' );
sn_gh_latest_plugin_tag( true );
ok( '' !== (string) sn_gh_latest_plugin_tag_error(), 'a 200 with an unparseable body still records a reason' );

// -- no matching tags --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( array( 'name' => 'nightly' ) ) ) );
sn_gh_latest_plugin_tag( true );
ok( '' !== (string) sn_gh_latest_plugin_tag_error(), 'a 200 with zero semver tags records a reason (not the same as "no update")' );

// -- SUCCESS MUST CLEAR IT --
// Otherwise the fix becomes the next bug: a stale error would sit on the card
// forever after the token was rotated, and the owner would rotate it again.
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = null; // healthy 200
$tag = sn_gh_latest_plugin_tag( true );
ok( 'v9.0.0' === $tag, 'recovery: a healthy fetch still returns the tag' );
ok( '' === (string) sn_gh_latest_plugin_tag_error(), 'recovery: success CLEARS the recorded reason (a fixed token must clear the card)' );

// -- the reason must never leak the credential --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__http'] = new WP_Error( 'http_request_failed', 'Bearer ghp_SUPERSECRETTOKENVALUE failed' );
sn_gh_latest_plugin_tag( true );
$leak = (string) sn_gh_latest_plugin_tag_error();
ok( false === strpos( $leak, 'ghp_SUPERSECRETTOKENVALUE' ), 'the reason NEVER echoes a token-shaped string back to the screen' );

echo "\n── v9.54.1: a GitHub blip must not cost an hour of blindness ──\n";
// THE INCIDENT (2026-07-16 22:51 UTC): GitHub declared "Degraded REST API
// Availability" — ~35% of REST requests failing, "not consistently reaching the
// application layer". The site's tags fetch got a 503 and the cards went red
// four minutes later.
//
// GitHub's fault. The 60-MINUTE blindness was OURS: one unlucky poll cached the
// empty sentinel for HOUR_IN_SECONDS, so a blip that lasted a second cost an
// hour — and kept costing it every hour the incident ran.
//
// The tell was sitting on the same dashboard the whole time. "Recent deploys"
// stayed live and accurate throughout, because its sibling fetch
// (github-actions-api.php) caches failures for FIVE MINUTES and self-heals.
// Same host, same token, same timeout — only the failure TTL differed. That
// asymmetry, not response size or timeouts, is why one panel died and the other
// rode out the incident.
//
// So: cache a failure in proportion to how likely it is to STILL be true later.
//   503 / 5xx / network  → transient → short TTL (GitHub recovers on its own)
//   401 / 404            → durable   → nothing changes in an hour; hold it
$trans_ttls = array();
$GLOBALS['__ttl_probe'] = true;

// -- transient: a 503 must expire FAST --
$GLOBALS['__site_trans'] = array(); $GLOBALS['__ttl_seen'] = array();
$GLOBALS['__http'] = array( 'response' => array( 'code' => 503 ), 'body' => 'Service Unavailable' );
sn_gh_latest_plugin_tag( true );
ok( false !== stripos( (string) sn_gh_latest_plugin_tag_error(), '503' ),
	'503: the reason still names the status (the card told us the truth in minutes)' );
ok( sn_gh_failure_is_transient( 503 ),
	'503 classifies as TRANSIENT — GitHub recovers on its own' );
ok( sn_gh_failure_is_transient( 502 ) && sn_gh_failure_is_transient( 504 ),
	'502/504 are transient too (the whole 5xx family)' );
ok( sn_gh_failure_is_transient( 0 ),
	'a network error / timeout (code 0) is transient — the box may just be busy' );

// -- durable: a dead token will still be dead in an hour --
ok( ! sn_gh_failure_is_transient( 401 ),
	'401 is DURABLE — an invalid token will not fix itself; re-polling every 5 min is pointless noise' );
ok( ! sn_gh_failure_is_transient( 404 ),
	'404 is DURABLE — a deleted/renamed repo will not fix itself' );

// -- the TTLs actually differ, and the transient one matches the sibling that
//    demonstrably survived this incident --
ok( sn_gh_failure_ttl( 503 ) < sn_gh_failure_ttl( 401 ),
	'a transient failure is cached for LESS time than a durable one (the whole point)' );
ok( sn_gh_failure_ttl( 503 ) <= 5 * 60,
	'a 503 blinds the cards for at most 5 minutes, matching github-actions-api.php' );
ok( sn_gh_failure_ttl( 401 ) >= 60 * 60,
	'a 401 still holds for an hour — no point hammering GitHub over a dead credential' );

// -- and a transient failure retries before giving up --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__calls'] = 0;
$GLOBALS['__http'] = array( 'response' => array( 'code' => 503 ), 'body' => '' );
sn_gh_latest_plugin_tag( true );
ok( $GLOBALS['__calls'] >= 2,
	'a 503 is RETRIED once before the failure is recorded (35% failure rate → one retry recovers most polls)' );

// -- but a durable failure must NOT retry: hammering a dead token is noise --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__calls'] = 0;
$GLOBALS['__http'] = array( 'response' => array( 'code' => 401 ), 'body' => '' );
sn_gh_latest_plugin_tag( true );
ok( 1 === $GLOBALS['__calls'],
	'a 401 is NOT retried — it will fail identically the second time' );

// -- the retry must actually recover, not just burn a request --
$GLOBALS['__site_trans'] = array();
$GLOBALS['__calls'] = 0;
$GLOBALS['__http'] = 'flaky-503-then-200'; // stub flips to healthy on call 2
$tag = sn_gh_latest_plugin_tag( true );
ok( 'v9.0.0' === $tag,
	'a 503 followed by a 200 RECOVERS in the same poll — exactly the 35%-failure case' );
ok( '' === (string) sn_gh_latest_plugin_tag_error(),
	'…and recovering leaves no error caption behind' );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
