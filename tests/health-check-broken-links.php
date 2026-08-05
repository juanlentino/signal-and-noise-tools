<?php
/**
 * Tests: the broken-internal-link prober only ever probes the site's OWN host.
 *
 * Why this suite exists: the CMA post-ship audit of v10.48.0 (2026-08-05) filed INFO-3 —
 * inc/health-external-links.php runs every candidate through sn_ssrf_host_blocked() while
 * this module relied entirely on its extractor being same-host. Two defenses held it up
 * (the extractor's host filter + redirection => 0 on both probes), but neither lives at
 * the prober, so an extractor regression would silently turn sn_health_link_status() into
 * an arbitrary-host fetcher.
 *
 * Deliberate divergence from the sibling: this does NOT call sn_ssrf_host_blocked(). That
 * guard blocks private/CGNAT ranges, and this prober's only legitimate target IS the
 * site's own host — on a local or internal-staging install home_url() resolves into
 * exactly those ranges, so the literal parity fix would make the whole check inert
 * wherever it is most likely to be run by hand. Same-host enforcement is the invariant
 * that actually matters here, and it is strictly stronger for this call site: it admits
 * one host, where the SSRF guard admits every public one.
 *
 * (plugin v10.48.1)
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok  - $label\n"; }
	else { $fail++; echo "  FAIL - $label\n"; }
}

const SN_HEALTH_LINK_TIMEOUT   = 5;
const SN_HEALTH_LINK_CACHE_TTL = 86400;
define( 'SNT_VERSION', '10.48.1-test' );

$GLOBALS['__requested']  = array();   // every URL that reached the HTTP layer
$GLOBALS['__transients'] = array();
$GLOBALS['__next_code']  = 200;

function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_transient( $k ) { return $GLOBALS['__transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function sn_health_probe_cache_key( $prefix, $url ) { return $prefix . md5( $url ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_remote_head( $url, $args = array() ) {
	$GLOBALS['__requested'][] = $url;
	return array( 'code' => $GLOBALS['__next_code'], 'headers' => array() );
}
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__requested'][] = $url;
	return array( 'code' => $GLOBALS['__next_code'], 'headers' => array() );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 0; }
function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? array(); }
function sn_health_is_bot_challenge( $code, $headers ) { return false; }
function sn_health_is_edge_gated( $code, $headers ) { return false; }
function sn_health_is_nonstandard_status( $code ) { return false; }
function sn_health_pack_check( $label, $findings ) { return array( $label, $findings ); }

class WP_Error {}

require __DIR__ . '/../inc/health-check-broken-links.php';

echo "Group: the extractor's same-host contract (the first line of defense)\n";
$html = '<a href="https://example.test/good/">in</a>'
	. '<a href="/root-relative/">rel</a>'
	. '<a href="https://evil.test/out/">out</a>'
	. '<a href="mailto:a@b.test">mail</a>'
	. '<a href="javascript:alert(1)">js</a>'
	. '<a href="//evil.test/proto-rel">protocol-relative</a>';
$links = sn_health_extract_internal_links( $html, 'example.test' );
ok( in_array( 'https://example.test/good/', $links, true ), 'keeps a same-host absolute link' );
ok( in_array( 'https://example.test/root-relative/', $links, true ), 'resolves a root-relative link against home_url()' );
ok( ! in_array( 'https://evil.test/out/', $links, true ), 'drops an off-host link' );
ok( ! in_array( '//evil.test/proto-rel', $links, true ), 'drops a protocol-relative off-host link' );
foreach ( $links as $l ) {
	ok( 'example.test' === wp_parse_url( $l, PHP_URL_HOST ), "extracted link is same-host: $l" );
}

echo "\nGroup: the prober enforces same-host itself (defense-in-depth at the boundary)\n";
$GLOBALS['__requested'] = array();
$res = sn_health_link_status( 'https://evil.test/anything' );
ok( array() === $GLOBALS['__requested'], 'an off-host URL never reaches the HTTP layer' );
ok( ! empty( $res['skipped'] ), 'an off-host URL is reported skipped (unverifiable), not broken' );
ok( true === ( $res['ok'] ?? null ), 'a skipped URL is not counted as a finding' );
ok( 'off_host' === ( $res['reason'] ?? '' ), 'the skip carries an explicit reason' );

echo "\nGroup: cloud-metadata and internal hosts are refused for the same reason\n";
foreach ( array( 'http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1/wp-admin/', 'http://localhost:8080/' ) as $u ) {
	$GLOBALS['__requested'] = array();
	$r = sn_health_link_status( $u );
	ok( array() === $GLOBALS['__requested'] && ! empty( $r['skipped'] ), "refused without a request: $u" );
}

echo "\nGroup: the legitimate same-host probe still works (the fix must not make the check inert)\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__requested']  = array();
$GLOBALS['__next_code']  = 200;
$res = sn_health_link_status( 'https://example.test/live/' );
ok( array( 'https://example.test/live/' ) === $GLOBALS['__requested'], 'a same-host URL is probed exactly once' );
ok( true === $res['ok'] && 200 === $res['code'], 'a 200 same-host link reads ok' );

$GLOBALS['__transients'] = array();
$GLOBALS['__requested']  = array();
$GLOBALS['__next_code']  = 404;
$res = sn_health_link_status( 'https://example.test/gone/' );
ok( false === $res['ok'] && 404 === $res['code'], 'a 404 same-host link is still flagged broken' );

echo "\nGroup: a private-network install still probes its own host (why sn_ssrf_host_blocked is NOT used here)\n";
$GLOBALS['__transients'] = array();
$GLOBALS['__requested']  = array();
$GLOBALS['__next_code']  = 200;
// home_url() is example.test in this harness; the point is that the gate keys on the
// SITE host, not on the address it resolves to, so a LAN/staging install is unaffected.
$res = sn_health_link_status( 'https://example.test/on-a-private-lan/' );
ok( 1 === count( $GLOBALS['__requested'] ), 'the site host is probed regardless of what it resolves to' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
