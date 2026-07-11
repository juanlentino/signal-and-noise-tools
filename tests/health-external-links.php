<?php
/**
 * Tests for the external link-rot Site Health check (D1, plugin v6.13.0).
 *
 * inc/health-external-links.php adds a 7th check to the plugin's Content Health
 * scan: it extracts OFF-host cited links from published posts and HEAD-probes
 * them, flagging 4xx/5xx/network failures so rotted cited sources surface (the
 * internal broken-links check drops off-host links). Because it probes
 * third-party hosts, it must (a) apply the FULL SSRF guard the internal probe
 * skips — wp_http_validate_url + scheme + the explicit 169.254 link-local block
 * that wp_http_validate_url omits — (b) use redirection=0, and (c) cap network
 * probes per run.
 *
 * @since plugin v6.13.0
 */

// SECURITY: Prevent web access.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'SN_HEALTH_LINK_TIMEOUT' ) ) { define( 'SN_HEALTH_LINK_TIMEOUT', 5 ); }
if ( ! defined( 'SN_HEALTH_LINK_CACHE_TTL' ) ) { define( 'SN_HEALTH_LINK_CACHE_TTL', DAY_IN_SECONDS ); }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '6.13.0' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
// Small cap so the per-run bound is testable.
if ( ! defined( 'SN_HEALTH_EXTLINK_MAX_PROBES' ) ) { define( 'SN_HEALTH_EXTLINK_MAX_PROBES', 3 ); }

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }

// ─── Controllable state ───
$GLOBALS['__ext'] = array(
	'http'      => array(), // url => array('code'=>int) | 'ERR'
	'http_get'  => array(), // url => array('code'=>int) | 'ERR'  (GET fallback)
	'transient' => array(), // cache
	'rows'      => array(),  // $wpdb rows
	'head_args' => array(),  // captured args per url
);

class WP_Error_Stub {}
function is_wp_error( $x ) { return $x instanceof WP_Error_Stub; }

// Deterministic resolver seam for the SHARED guard (v6.13.1: inc/ssrf-guard.php
// guards sn_ssrf_resolve_host with function_exists). Literal IPs pass through
// filter_var; hostnames + encoded-IP forms resolve via this map. Encoded host
// 2852039166 → 169.254.169.254 mirrors real gethostbyname so the encoded-IP
// SSRF case is genuinely exercised through the shared guard the module now uses.
$GLOBALS['__resolve'] = array(
	'good.example'       => '93.184.216.34',
	'gone.example'       => '93.184.216.34',
	'noheadsite.example' => '93.184.216.34',
	'dead.example'       => '93.184.216.34',
	'cached.example'     => '93.184.216.34',
	'ok.example'         => '93.184.216.34',
	'rot.example'        => '93.184.216.34',
	'2852039166'         => '169.254.169.254',
);
function sn_ssrf_resolve_host( $host ) {
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) { return $host; }
	return $GLOBALS['__resolve'][ $host ] ?? '';
}
function home_url( $p = '' ) { return 'https://mysite.test' . $p; }
function admin_url( $p = '' ) { return 'https://mysite.test/wp-admin/' . $p; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }

// FAITHFUL wp_http_validate_url: blocks loopback + RFC-1918 (WP default) but NOT
// 169.254 link-local — copying the real gap so the explicit guard is what must
// reject 169.254. A stub that also blocked 169.254 would be a false-green.
function wp_http_validate_url( $url ) {
	$host   = parse_url( $url, PHP_URL_HOST );
	$scheme = parse_url( $url, PHP_URL_SCHEME );
	if ( ! $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) { return false; }
	if ( preg_match( '#^(127\.|10\.|192\.168\.|0\.|localhost$)#', $host ) ) { return false; }
	if ( preg_match( '#^172\.(1[6-9]|2[0-9]|3[01])\.#', $host ) ) { return false; }
	return $url;
}
function wp_safe_remote_head( $url, $args = array() ) {
	$GLOBALS['__ext']['head_args'][ $url ] = $args;
	$r = $GLOBALS['__ext']['http'][ $url ] ?? array( 'code' => 200 );
	return 'ERR' === $r ? new WP_Error_Stub() : array( 'response' => $r );
}
function wp_safe_remote_get( $url, $args = array() ) {
	$r = $GLOBALS['__ext']['http_get'][ $url ] ?? array( 'code' => 200 );
	return 'ERR' === $r ? new WP_Error_Stub() : array( 'response' => $r );
}
function wp_remote_retrieve_response_code( $resp ) { return is_array( $resp ) ? (int) ( $resp['response']['code'] ?? 0 ) : 0; }
// Headers ride inside the stubbed response array (http[url]['headers']); mirrors
// wp_remote_retrieve_headers() returning the response's header bag.
function wp_remote_retrieve_headers( $resp ) { return is_array( $resp ) ? ( $resp['response']['headers'] ?? array() ) : array(); }
function get_transient( $k ) { return $GLOBALS['__ext']['transient'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__ext']['transient'][ $k ] = $v; return true; }
function sn_health_pack_check( $label, $findings, $fix_hint = '' ) {
	return array( 'count' => count( $findings ), 'findings' => $findings, 'label' => $label, 'fix_hint' => $fix_hint );
}

// $wpdb stub
$GLOBALS['wpdb'] = new class {
	public $posts = 'wp_posts';
	public function get_results( $sql, $output = null ) { return $GLOBALS['__ext']['rows']; }
};

require __DIR__ . '/../inc/ssrf-guard.php'; // shared SSRF guard (sn_ssrf_host_blocked) — resolver seam above overrides its lookup
require __DIR__ . '/../inc/health-probe-classify.php'; // shared bot-challenge classifier (sn_health_is_bot_challenge) — unit-tested in tests/health-probe-classify.php
require __DIR__ . '/../inc/health-external-links.php';

// ─── 1. Extractor: keep off-host http/https, drop same-host/relative/non-http ───
ok( function_exists( 'sn_health_extract_external_links' ), 'sn_health_extract_external_links() defined' );
$content = '<a href="https://example.com/src">x</a> <a href="https://mysite.test/own">int</a> '
	. '<a href="/relative">rel</a> <a href="#frag">f</a> <a href="mailto:a@b.c">m</a> '
	. '<a href="http://other.org/page">o</a> <a href="https://example.com/src">dup</a>';
$ext = sn_health_extract_external_links( $content, 'mysite.test' );
sort( $ext );
ok( in_array( 'https://example.com/src', $ext, true ), 'keeps off-host https link' );
ok( in_array( 'http://other.org/page', $ext, true ), 'keeps off-host http link' );
ok( ! in_array( 'https://mysite.test/own', $ext, true ), 'drops same-host link' );
ok( count( array_filter( $ext, fn( $u ) => strpos( $u, 'relative' ) !== false || strpos( $u, 'frag' ) !== false || strpos( $u, 'mailto' ) !== false ) ) === 0, 'drops relative/anchor/mailto' );
ok( count( $ext ) === 2, 'dedupes (example.com appears once)' );

// ─── 2. Probe SSRF guard ───
ok( function_exists( 'sn_health_external_link_status' ), 'sn_health_external_link_status() defined' );

// 2a. RFC-1918 private IP → skipped (wp_http_validate_url rejects it).
$s = sn_health_external_link_status( 'http://10.0.0.5/x' );
ok( ! empty( $s['skipped'] ), 'private 10.x URL is SKIPPED (not probed)' );

// 2b. 169.254 link-local → MUST be skipped by the EXPLICIT guard (wp_http_validate_url does NOT reject it).
$s = sn_health_external_link_status( 'http://169.254.169.254/latest/meta-data/' );
ok( ! empty( $s['skipped'] ), '169.254 link-local is SKIPPED by the explicit guard (the false-green trap)' );

// 2b-enc. Encoded-IP bypass: decimal 2852039166 == 169.254.169.254. A literal
// "169.254." string match MISSES it; resolving the host before range-checking
// is what blocks it. This is the real SSRF bug the adversarial review caught —
// it would FAIL against the old preg_match guard.
$s = sn_health_external_link_status( 'http://2852039166/latest/meta-data/' );
ok( ! empty( $s['skipped'] ), 'decimal-encoded 169.254.169.254 (2852039166) is SKIPPED (resolve-then-range-check beats encoding bypass)' );

// 2c. Good 200 → ok.
$GLOBALS['__ext']['http']['https://good.example/a'] = array( 'code' => 200 );
$s = sn_health_external_link_status( 'https://good.example/a' );
ok( true === $s['ok'] && 200 === $s['code'], '200 → ok' );
ok( ( $GLOBALS['__ext']['head_args']['https://good.example/a']['redirection'] ?? null ) === 0, 'HEAD probe uses redirection=0 (no SSRF redirect-follow)' );

// 2d. 404 → not ok.
$GLOBALS['__ext']['http']['https://gone.example/b'] = array( 'code' => 404 );
$s = sn_health_external_link_status( 'https://gone.example/b' );
ok( false === $s['ok'] && 404 === $s['code'], '404 → not ok (rotted)' );

// 2e. HEAD 405 → GET fallback.
$GLOBALS['__ext']['http']['https://noheadsite.example/c']     = array( 'code' => 405 );
$GLOBALS['__ext']['http_get']['https://noheadsite.example/c'] = array( 'code' => 200 );
$s = sn_health_external_link_status( 'https://noheadsite.example/c' );
ok( true === $s['ok'] && 200 === $s['code'], 'HEAD 405 → GET fallback resolves to 200' );

// 2f. Network error → code 0, not ok.
$GLOBALS['__ext']['http']['https://dead.example/d'] = 'ERR';
$s = sn_health_external_link_status( 'https://dead.example/d' );
ok( false === $s['ok'] && 0 === $s['code'], 'network error → code 0, not ok' );

// 2g. Cache hit → cached flag, no new probe.
$GLOBALS['__ext']['transient']['sn_extlink_' . md5( 'https://cached.example/e' )] = array( 'ok' => false, 'code' => 503 );
$s = sn_health_external_link_status( 'https://cached.example/e' );
ok( false === $s['ok'] && 503 === $s['code'] && ! empty( $s['cached'] ), 'cache hit returns cached result with cached=true' );

// 2h. Separate cache-key prefix (must NOT collide with the internal probe's 'sn_health_link_').
ok( isset( $GLOBALS['__ext']['transient']['sn_extlink_' . md5( 'https://good.example/a' )] ), 'external probe caches under the sn_extlink_ prefix (not sn_health_link_)' );

// The bot-challenge CLASSIFIER unit tests (sn_health_is_bot_challenge, incl. the
// ArrayAccess/CaseInsensitiveDictionary production path) live in their own suite,
// tests/health-probe-classify.php. Here we test only this module's INTEGRATION
// with it: that a challenged probe is folded into the skip bucket.

// ─── 2j. Status function folds a CF challenge into the SSRF-skip bucket ───
$GLOBALS['__resolve']['ssrn.example'] = '93.184.216.34';
$GLOBALS['__ext']['transient'] = array(); // ensure uncached
$GLOBALS['__ext']['http']['https://ssrn.example/paper'] = array( 'code' => 403, 'headers' => array( 'cf-mitigated' => 'challenge', 'server' => 'cloudflare' ) );
$s = sn_health_external_link_status( 'https://ssrn.example/paper' );
ok( ! empty( $s['skipped'] ), 'CF-challenged 403 is SKIPPED, not rotted' );
ok( ( $s['reason'] ?? '' ) === 'bot_challenge', 'skip reason is bot_challenge' );
ok( true === $s['ok'], 'CF-challenged link is treated as ok (produces no finding)' );
ok( 403 === $s['code'], 'challenge code is preserved' );
ok( ! empty( $s['probed'] ), 'the discovery probe is a real network call (counts toward the budget)' );
$s2 = sn_health_external_link_status( 'https://ssrn.example/paper' );
ok( ! empty( $s2['cached'] ) && ! empty( $s2['skipped'] ), 'bot-challenge result is cached (one probe per TTL, not re-probed every scan)' );
ok( empty( $s2['probed'] ), 'a cache hit is NOT counted as a new network probe' );

// A plain 403 with NO Cloudflare challenge header must STILL rot (guards against
// the lazy "just ignore every 403" fix).
$GLOBALS['__resolve']['forbidden.example'] = '93.184.216.34';
$GLOBALS['__ext']['http']['https://forbidden.example/x'] = array( 'code' => 403, 'headers' => array() );
$s = sn_health_external_link_status( 'https://forbidden.example/x' );
ok( false === $s['ok'] && empty( $s['skipped'] ) && 403 === $s['code'], 'plain 403 (no cf-mitigated) is STILL flagged as rot' );

// A Cloudflare BLOCK (403 + cf-ray, no cf-mitigated challenge) is a LIVE page the
// edge gates for bots — the real-world false positive on CF-protected citations.
// It must be SKIPPED (edge_gated), not rotted, WITHOUT loosening the plain-403 guard.
$GLOBALS['__resolve']['cf-blocked.example'] = '93.184.216.34';
$GLOBALS['__ext']['http']['https://cf-blocked.example/y'] = array( 'code' => 403, 'headers' => array( 'cf-ray' => '8ab1234567890', 'server' => 'cloudflare' ) );
$s = sn_health_external_link_status( 'https://cf-blocked.example/y' );
ok( ! empty( $s['skipped'] ) && true === $s['ok'], 'CF block (403 + cf-ray) is SKIPPED as edge-gated, not rotted' );
ok( 'edge_gated' === ( $s['reason'] ?? '' ), 'CF block carries the edge_gated reason' );

// A CF-fronted 429 (rate limit) is likewise a live page, not rot.
$GLOBALS['__resolve']['cf-limited.example'] = '93.184.216.34';
$GLOBALS['__ext']['http']['https://cf-limited.example/z'] = array( 'code' => 429, 'headers' => array( 'cf-ray' => '8ab9999' ) );
$s = sn_health_external_link_status( 'https://cf-limited.example/z' );
ok( ! empty( $s['skipped'] ) && true === $s['ok'], 'CF rate-limit (429 + cf-ray) is SKIPPED, not rotted' );

// A CF-fronted 404 is genuinely GONE — edge-gating must NOT mask real rot.
$GLOBALS['__resolve']['cf-gone.example'] = '93.184.216.34';
$GLOBALS['__ext']['http']['https://cf-gone.example/g'] = array( 'code' => 404, 'headers' => array( 'cf-ray' => '8ab404' ) );
$s = sn_health_external_link_status( 'https://cf-gone.example/g' );
ok( false === $s['ok'] && empty( $s['skipped'] ) && 404 === $s['code'], 'CF-fronted 404 still rots (edge-gating is 403/429 only, never masks GONE)' );

// ─── 2m. LinkedIn's HTTP 999 "Request denied" — a LIVE profile the server refuses
// to answer for a non-browser probe. 999 is OUTSIDE the valid HTTP range (100–599),
// so it is neither a CF challenge nor a CF block (LinkedIn is not behind Cloudflare)
// and must fold into the skip bucket as nonstandard_status, NOT rot. This is the
// exact false positive the user reported on https://www.linkedin.com/in/… ───
$GLOBALS['__resolve']['linkedin.example'] = '93.184.216.34';
$GLOBALS['__ext']['transient'] = array(); // uncached
$GLOBALS['__ext']['http']['https://linkedin.example/in/someone'] = array( 'code' => 999, 'headers' => array( 'server' => 'Play' ) );
$s = sn_health_external_link_status( 'https://linkedin.example/in/someone' );
ok( ! empty( $s['skipped'] ), 'HTTP 999 (LinkedIn anti-bot) is SKIPPED, not rotted' );
ok( 'nonstandard_status' === ( $s['reason'] ?? '' ), 'skip reason is nonstandard_status' );
ok( true === $s['ok'], 'a 999 link is treated as ok (produces no finding)' );
ok( 999 === $s['code'], '999 code is preserved' );

// ─── 3. The check: report rotted external links, skip good/SSRF-unsafe ───
ok( function_exists( 'sn_health_check_external_links' ), 'sn_health_check_external_links() defined' );
$GLOBALS['__ext']['transient'] = array(); // clear cache
$GLOBALS['__ext']['http'] = array(
	'https://ok.example/keep'   => array( 'code' => 200 ),
	'https://rot.example/dead'  => array( 'code' => 404 ),
);
$GLOBALS['__ext']['rows'] = array(
	array( 'ID' => 11, 'post_title' => 'Essay One', 'post_content' => '<a href="https://ok.example/keep">a</a> <a href="https://rot.example/dead">b</a> <a href="https://mysite.test/own">int</a>' ),
);
$res = sn_health_check_external_links();
ok( isset( $res['findings'], $res['label'], $res['count'] ), 'returns the pack_check envelope shape' );
ok( 1 === $res['count'], 'exactly one rotted external link flagged' );
ok( strpos( $res['findings'][0]['subject_url'], 'rot.example' ) !== false, 'the flagged URL is the 404 one' );
ok( strpos( $res['findings'][0]['note'], '404' ) !== false, 'finding note carries the HTTP code' );

// 3b. Per-run network-probe cap: > CAP uncached off-host URLs → only CAP probed.
$GLOBALS['__ext']['transient'] = array();
$GLOBALS['__ext']['head_args'] = array();
$GLOBALS['__ext']['http']      = array(); // all default to 200
$cap_content = '';
for ( $i = 1; $i <= SN_HEALTH_EXTLINK_MAX_PROBES + 3; $i++ ) {
	$GLOBALS['__resolve'][ "cap$i.example" ] = '93.184.216.34';
	$cap_content .= '<a href="https://cap' . $i . '.example/x">a</a> ';
}
$GLOBALS['__ext']['rows'] = array( array( 'ID' => 20, 'post_title' => 'Caps', 'post_content' => $cap_content ) );
sn_health_check_external_links();
ok( count( $GLOBALS['__ext']['head_args'] ) === SN_HEALTH_EXTLINK_MAX_PROBES, 'per-run cap bounds network probes to SN_HEALTH_EXTLINK_MAX_PROBES (' . SN_HEALTH_EXTLINK_MAX_PROBES . ')' );

// ─── 3c. Regression for the SSRN false-positive (v6.48.3): a post citing a
// Cloudflare-challenged source (403 + cf-mitigated:challenge) plus a genuinely
// dead link (404). Only the dead link is flagged; the challenged citation is
// not. This is the exact Health-tab scenario the user reported. ───
$GLOBALS['__ext']['transient'] = array();
$GLOBALS['__ext']['head_args'] = array();
$GLOBALS['__resolve']['papers.example'] = '93.184.216.34';
$GLOBALS['__ext']['http'] = array(
	'https://papers.example/cf'  => array( 'code' => 403, 'headers' => array( 'cf-mitigated' => 'challenge', 'server' => 'cloudflare' ) ),
	'https://rot.example/dead'   => array( 'code' => 404 ),
);
$GLOBALS['__ext']['rows'] = array(
	array( 'ID' => 31, 'post_title' => 'On Provenance', 'post_content' => '<a href="https://papers.example/cf">cited source</a> <a href="https://rot.example/dead">dead source</a>' ),
);
$res = sn_health_check_external_links();
ok( 1 === $res['count'], 'CF-challenged citation is NOT flagged; only the genuinely dead 404 is (reproduces the SSRN false positive)' );
ok( 1 === count( $res['findings'] ) && strpos( $res['findings'][0]['subject_url'], 'rot.example' ) !== false, 'the single finding is the dead link, not the bot-protected one' );

// ─── 3d. Regression for the LinkedIn HTTP 999 false positive: a post citing a
// LinkedIn profile (answers a bot probe with 999) plus a genuinely dead link (404).
// Only the dead link is flagged; the live-but-gated LinkedIn URL is not. This is
// the exact Health-tab scenario the user reported. ───
$GLOBALS['__ext']['transient'] = array();
$GLOBALS['__ext']['head_args'] = array();
$GLOBALS['__resolve']['linkedin.example'] = '93.184.216.34';
$GLOBALS['__ext']['http'] = array(
	'https://linkedin.example/in/juanlentino' => array( 'code' => 999 ),
	'https://rot.example/dead'                => array( 'code' => 404 ),
);
$GLOBALS['__ext']['rows'] = array(
	array( 'ID' => 41, 'post_title' => 'Personal', 'post_content' => '<a href="https://linkedin.example/in/juanlentino">LinkedIn</a> <a href="https://rot.example/dead">dead source</a>' ),
);
$res = sn_health_check_external_links();
ok( 1 === $res['count'], 'LinkedIn 999 profile is NOT flagged; only the genuinely dead 404 is (reproduces the reported false positive)' );
ok( 1 === count( $res['findings'] ) && strpos( $res['findings'][0]['subject_url'], 'rot.example' ) !== false, 'the single finding is the dead link, not the LinkedIn profile' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
