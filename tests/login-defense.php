<?php
/**
 * CLI fixture for the Login defense panel query builders + helpers.
 * Mirrors tests/ssrf-guard.php: standalone, no WP bootstrap, global-stub style.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
define( 'ABSPATH', '/' );

$fails  = 0;
$passes = 0;
function ok( $cond, $msg ) {
	global $fails, $passes;
	if ( $cond ) { echo "PASS: $msg\n"; $passes++; } else { echo "FAIL: $msg\n"; $fails++; }
}

// Minimal WP stubs the helpers touch (defined unconditionally; only used in CLI).
$GLOBALS['__home'] = 'https://juanlentino.com';
function home_url( $p = '' ) { return rtrim( $GLOBALS['__home'], '/' ) . $p; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

require __DIR__ . '/../inc/login-defense.php';

// --- AE query builders -------------------------------------------------------
$sql = sn_login_defense_decisions_sql( 7 );
ok( strpos( $sql, 'sn_login_guard' ) !== false, 'decisions SQL targets sn_login_guard dataset' );
ok( strpos( $sql, 'sum(_sample_interval)' ) !== false, 'decisions SQL uses sum(_sample_interval) for de-sampled totals' );
ok( strpos( $sql, "INTERVAL '7'" ) !== false, 'decisions SQL honors the day window' );
ok( strpos( $sql, 'blob2 AS decision' ) !== false, 'decisions SQL aliases blob2 as decision' );

$top = sn_login_defense_top_asn_sql();
ok( strpos( $top, "blob2 = 'block'" ) !== false, 'top-ASN SQL filters to blocked decisions' );
ok( strpos( $top, 'blob4 AS asorg' ) !== false, 'top-ASN SQL aliases blob4 as asorg' );
ok( strpos( $top, 'LIMIT' ) !== false, 'top-ASN SQL is bounded by LIMIT' );

// --- status URL derivation ---------------------------------------------------
ok(
	sn_login_defense_status_url() === 'https://juanlentino.com/_sn/login-guard/status',
	'status URL derives the origin and points at /_sn/login-guard/status'
);

// --- attribution -------------------------------------------------------------
$attr = sn_login_defense_attribution();
ok(
	stripos( $attr, 'FireHOL' ) !== false && stripos( $attr, 'Spamhaus' ) !== false,
	'attribution credits both FireHOL and Spamhaus (license requirement)'
);

echo "\n$passes passed, $fails failed\n";
exit( $fails === 0 ? 0 : 1 );
