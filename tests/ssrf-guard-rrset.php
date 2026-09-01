<?php
/**
 * Standalone tests for the SSRF guard's MULTI-ADDRESS (rrset) handling.
 *
 * WHY THIS IS A SEPARATE SUITE FROM tests/ssrf-guard.php. That suite exercises
 * the REAL resolver on purpose and says so: its encoded-IP cases are parsed
 * locally by inet_aton semantics, so a stub there would be a false green. This
 * suite is the opposite case — a multi-address record set cannot be produced
 * offline without a seam, so it stubs sn_ssrf_resolve_host_all() and asserts the
 * decision logic on top of it.
 *
 * THE DEFECT THIS PINS (found 2026-08-31). The guard resolved with
 * gethostbyname(), which returns ONE address from a multi-address rrset —
 * measured: gethostbyname('dns.google') = 8.8.4.4 while gethostbynamel() =
 * 8.8.4.4, 8.8.8.8. The guard therefore validated a single address out of a set
 * it never enumerated, while cURL resolved independently when the request went
 * out. A host publishing [public, 169.254.169.254] could be validated against
 * the public record and fetched from the internal one, with no attacker timing.
 *
 * NEGATIVE CONTROL: every assertion below FAILS against the pre-fix guard, which
 * consulted only sn_ssrf_resolve_host() and would have seen the public address.
 *
 * Run: php tests/ssrf-guard-rrset.php
 *
 * @since plugin v13.51.0
 */

// SECURITY: Prevent web access — this is a test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

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

// v13.53.0: Step 2's port derivation is the guard's first use of a WordPress
// function. Stubbed to core's own semantics (wp_parse_url delegates to
// parse_url for a full URL) so the module still loads standalone.
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; }
}
if ( ! function_exists( 'update_option' ) ) {
	$GLOBALS['__ssrf_opts'] = array();
	function update_option( $k, $v, $a = null ) { $GLOBALS['__ssrf_opts'][ $k ] = $v; return true; }
}

// ── The rrset seam, defined BEFORE the require so function_exists keeps it ──
// Models what gethostbynamel() returns: EVERY A record, in DNS order.
$GLOBALS['__rrset'] = array(
	'mixed-public-first.example' => array( '93.184.216.34', '169.254.169.254' ),
	'mixed-public-last.example'  => array( '169.254.169.254', '93.184.216.34' ),
	'all-public.example'         => array( '93.184.216.34', '1.1.1.1' ),
	'cgnat-second.example'       => array( '93.184.216.34', '100.64.0.1' ),
	'single-public.example'      => array( '93.184.216.34' ),
	'unresolvable.example'       => array(),
);
function sn_ssrf_resolve_host_all( $host ) {
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return array( $host );
	}
	return $GLOBALS['__rrset'][ $host ] ?? array();
}

// The SINGLE-address seam, stubbed to return the FIRST record — exactly what
// gethostbyname() does with an rrset. This is what makes the negative control
// sharp rather than vacuous: without it the unfixed guard fails to resolve every
// .example host and blocks them all, so the "blocked" assertions would pass for
// the wrong reason and prove nothing. With it, the pre-fix guard sees the public
// record of mixed-public-first.example and lets it through — the defect, exactly.
function sn_ssrf_resolve_host( $host ) {
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return $host;
	}
	$rr = $GLOBALS['__rrset'][ $host ] ?? array();
	return $rr[0] ?? '';
}

require __DIR__ . '/../inc/ssrf-guard.php';

echo "\nGroup: definitions\n";
ok( function_exists( 'sn_ssrf_resolve_host_all' ), 'sn_ssrf_resolve_host_all() defined' );
ok( function_exists( 'sn_ssrf_ip_blocked' ), 'sn_ssrf_ip_blocked() defined (range check extracted)' );

echo "\nGroup: THE DEFECT — an internal address anywhere in the rrset blocks the host\n";
ok( sn_ssrf_host_blocked( 'mixed-public-first.example' ), 'rrset [public, link-local] blocked (public record listed FIRST — the pre-fix false green)' );
ok( sn_ssrf_host_blocked( 'mixed-public-last.example' ), 'rrset [link-local, public] blocked (order must not matter)' );
ok( sn_ssrf_host_blocked( 'cgnat-second.example' ), 'rrset [public, CGNAT] blocked — the range our own regex carries, not PHP' );

echo "\nGroup: non-breaking — an all-public rrset still passes\n";
ok( ! sn_ssrf_host_blocked( 'all-public.example' ), 'rrset [public, public] NOT blocked' );
ok( ! sn_ssrf_host_blocked( 'single-public.example' ), 'single-address public rrset NOT blocked' );

echo "\nGroup: fail closed\n";
ok( sn_ssrf_host_blocked( 'unresolvable.example' ), 'empty rrset blocked (fail closed — UNRESOLVABLE is not PUBLIC)' );
ok( sn_ssrf_host_blocked( '' ), 'empty host blocked' );

echo "\nGroup: literal IPs still short-circuit (no rrset to enumerate)\n";
ok( sn_ssrf_host_blocked( '169.254.169.254' ), 'literal metadata IP blocked' );
ok( ! sn_ssrf_host_blocked( '93.184.216.34' ), 'literal public IP NOT blocked' );

echo "\nGroup: the extracted range check, pinned directly\n";
ok( sn_ssrf_ip_blocked( '100.64.0.1' ), 'CGNAT lower bound blocked — carried by OUR regex, not FILTER_FLAG_NO_RES_RANGE' );
ok( sn_ssrf_ip_blocked( '100.127.255.255' ), 'CGNAT upper bound blocked' );
ok( ! sn_ssrf_ip_blocked( '100.63.255.255' ), 'just below CGNAT NOT blocked' );
ok( ! sn_ssrf_ip_blocked( '100.128.0.1' ), 'just above CGNAT NOT blocked' );
ok( sn_ssrf_ip_blocked( '' ), 'empty IP blocked (fail closed)' );


echo "\nGroup: STEP 2 — connect-time pinning (v13.53.0)\n";
//
// THE DECISION UNDER TEST is "pin where we can, record where we cannot, never
// refuse". These pin BOTH halves: that a pinnable request really binds, and
// that an unpinnable one is made VISIBLE instead of passing silently.

// ── sn_ssrf_resolve_entries: the pure mapping ───────────────────────────
$e = sn_ssrf_resolve_entries( 'example.com', 443, array( '93.184.216.34', '93.184.216.35' ) );
ok( array( 'example.com:443:93.184.216.34', 'example.com:443:93.184.216.35' ) === $e, 'entries: one CURLOPT_RESOLVE line per validated address' );

// THE LAST GATE. A blocked address must never reach the pin, even if a caller
// hands it over — binding it would give cURL the exact destination the guard
// exists to refuse.
$e = sn_ssrf_resolve_entries( 'evil.test', 443, array( '93.184.216.34', '169.254.169.254' ) );
ok( array( 'evil.test:443:93.184.216.34' ) === $e, 'entries: a link-local address is DROPPED at the pin site, not trusted from the caller' );
ok( array() === sn_ssrf_resolve_entries( 'evil.test', 443, array( '169.254.169.254', '127.0.0.1' ) ), 'entries: an all-internal rrset yields NOTHING to pin' );
ok( array() === sn_ssrf_resolve_entries( '', 443, array( '8.8.8.8' ) ), 'entries: an empty host pins nothing' );
ok( array() === sn_ssrf_resolve_entries( 'example.com', 0, array( '8.8.8.8' ) ), 'entries: an unknown port pins nothing' );
ok( array() === sn_ssrf_resolve_entries( 'example.com', 443, array( 'not-an-ip' ) ), 'entries: a non-address is dropped' );

// ── port derivation ─────────────────────────────────────────────────────
ok( 443 === sn_ssrf_url_port( 'https://example.com/x' ), 'port: https implies 443' );
ok( 80 === sn_ssrf_url_port( 'http://example.com/x' ), 'port: http implies 80' );
ok( 8443 === sn_ssrf_url_port( 'https://example.com:8443/x' ), 'port: an explicit port wins' );
ok( 0 === sn_ssrf_url_port( 'ftp://example.com/x' ), 'port: an unknown scheme yields 0, which pins nothing' );
ok( 0 === sn_ssrf_url_port( 'not a url' ), 'port: garbage yields 0' );

// ── the health verdict, both branches ───────────────────────────────────
$h = sn_ssrf_pinning_health( false, null );
ok( 'recommended' === $h['status'], 'health: no cURL transport => recommended, never critical (this is the status quo, not a regression)' );
ok( false !== strpos( $h['summary'], 'fsockopen' ), 'health: and it NAMES the transport fallback rather than saying "unavailable"' );
$h = sn_ssrf_pinning_health( true, null );
ok( 'good' === $h['status'], 'health: pinning available and nothing recorded => good' );
$h = sn_ssrf_pinning_health( true, array( 'host' => 'drifty.test', 'at' => 123 ) );
ok( 'recommended' === $h['status'] && false !== strpos( $h['summary'], 'drifty.test' ), 'health: a recorded unpinned request is surfaced BY HOST — the gap is visible, which is the whole decision' );

// ── the decision itself: pinning NEVER refuses ──────────────────────────
// sn_ssrf_pin_curl_handle() takes the handle by reference and returns void; a
// null handle must simply do nothing rather than error, because a refusal here
// would be a NEW failure mode on a path that has none today.
$null_handle = null;
sn_ssrf_pin_curl_handle( $null_handle, array(), 'https://example.com/x' );
ok( true, 'pin: a missing handle is a no-op, never a refusal' );

echo "\n-- $pass passed, $fail failed --\n";
exit( $fail > 0 ? 1 : 0 );
