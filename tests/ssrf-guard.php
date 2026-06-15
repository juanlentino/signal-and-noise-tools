<?php
/**
 * Standalone tests for the shared SSRF host-guard (inc/ssrf-guard.php, v6.13.1).
 *
 * sn_ssrf_host_blocked() is the single audited guard that webhooks +
 * external-link-rot (and any future outbound module) call to decide whether a
 * user/option/content-supplied host may be reached. It RESOLVES the host first —
 * gethostbyname() normalises numeric-encoded hosts (decimal/hex/octal), the
 * bypass a literal "169.254." string match misses — then range-checks the
 * resolved IP and fails CLOSED on an unresolvable host.
 *
 * This suite exercises the REAL resolver (NO seam). The encoded forms of the
 * cloud-metadata IP 169.254.169.254 are parsed LOCALLY by the C resolver
 * (inet_aton semantics — no DNS), so the bypass cases are deterministic AND
 * offline, and a guard that still used the old `^169\.254\.` preg_match would
 * FAIL them. A stubbed resolver here would be a false-green, so we do not stub
 * it. The hostname-lookup paths (which DO need DNS) are exercised offline via
 * the resolver seam in tests/webhooks.php + tests/health-external-links.php.
 *
 * Run: php tests/ssrf-guard.php
 *
 * @since plugin v6.13.1
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

require __DIR__ . '/../inc/ssrf-guard.php';

// ── Definitions ───────────────────────────────────────────────────────────
ok( function_exists( 'sn_ssrf_resolve_host' ), 'sn_ssrf_resolve_host() defined' );
ok( function_exists( 'sn_ssrf_host_blocked' ), 'sn_ssrf_host_blocked() defined' );

// ── Resolver: literal IPs pass through; numeric-encoded forms normalise ─────
ok( '93.184.216.34' === sn_ssrf_resolve_host( '93.184.216.34' ), 'resolver: literal public IP passes through unchanged' );
ok( '169.254.169.254' === sn_ssrf_resolve_host( '169.254.169.254' ), 'resolver: literal link-local IP passes through' );
ok( '169.254.169.254' === sn_ssrf_resolve_host( '2852039166' ), 'resolver: decimal 2852039166 normalises to 169.254.169.254 (LOCAL, no DNS)' );

// ── The bypass class: EVERY encoded form of 169.254.169.254 must be BLOCKED ─
// A literal "169.254." string match misses all but the first of these.
ok( sn_ssrf_host_blocked( '169.254.169.254' ), 'literal 169.254.169.254 (cloud metadata) blocked' );
ok( sn_ssrf_host_blocked( '2852039166' ), 'decimal-encoded 169.254.169.254 blocked (beats the ^169\.254\. bypass)' );
ok( sn_ssrf_host_blocked( '0xA9.0xFE.0xA9.0xFE' ), 'dotted-hex-encoded 169.254.169.254 blocked' );
ok( sn_ssrf_host_blocked( '0251.0376.0251.0376' ), 'dotted-octal-encoded 169.254.169.254 blocked' );
ok( sn_ssrf_host_blocked( '0xA9FEA9FE' ), 'flat-hex-encoded 169.254.169.254 blocked' );
ok( sn_ssrf_host_blocked( '025177524776' ), 'flat-octal-encoded 169.254.169.254 blocked' );

// ── Reserved / private / loopback / IPv6 are all blocked ────────────────────
ok( sn_ssrf_host_blocked( '127.0.0.1' ), 'loopback 127.0.0.1 blocked' );
ok( sn_ssrf_host_blocked( '10.0.0.5' ), 'RFC-1918 10/8 blocked' );
ok( sn_ssrf_host_blocked( '192.168.1.1' ), 'RFC-1918 192.168/16 blocked' );
ok( sn_ssrf_host_blocked( '172.16.0.1' ), 'RFC-1918 172.16/12 blocked' );
ok( sn_ssrf_host_blocked( '0.0.0.0' ), 'unspecified 0.0.0.0 (0/8 reserved) blocked' );
ok( sn_ssrf_host_blocked( '::1' ), 'IPv6 loopback ::1 blocked' );
ok( sn_ssrf_host_blocked( 'fe80::1' ), 'IPv6 link-local fe80::/10 blocked' );
ok( sn_ssrf_host_blocked( 'fc00::1' ), 'IPv6 ULA fc00::/7 blocked' );

// ── CGNAT 100.64.0.0/10 — the gap PHP's filter flags omit (explicit regex) ──
ok( sn_ssrf_host_blocked( '100.64.0.1' ), 'CGNAT 100.64/10 lower bound blocked' );
ok( sn_ssrf_host_blocked( '100.127.255.255' ), 'CGNAT 100.127/x upper bound blocked' );
// Boundary: 100.63 and 100.128 are PUBLIC and must NOT be over-blocked.
ok( ! sn_ssrf_host_blocked( '100.63.255.255' ), 'public 100.63/x (just below CGNAT) NOT blocked' );
ok( ! sn_ssrf_host_blocked( '100.128.0.1' ), 'public 100.128/x (just above CGNAT) NOT blocked' );

// ── Public hosts pass (NON-BREAKING for legitimate URLs) ────────────────────
ok( ! sn_ssrf_host_blocked( '93.184.216.34' ), 'public IP 93.184.216.34 NOT blocked (non-breaking)' );
ok( ! sn_ssrf_host_blocked( '1.1.1.1' ), 'public IP 1.1.1.1 NOT blocked' );

// ── Fail closed on an empty host ────────────────────────────────────────────
ok( sn_ssrf_host_blocked( '' ), 'empty host blocked (fail closed)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
