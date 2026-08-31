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

echo "\n-- $pass passed, $fail failed --\n";
exit( $fail > 0 ? 1 : 0 );
