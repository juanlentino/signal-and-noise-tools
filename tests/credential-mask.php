<?php
/**
 * Unit tests for sn_mask_secret() — the shared length-aware credential mask
 * (v4.14.2) used by the Music / Cloudflare / Plausible / Webhooks admin fields.
 *
 * Regression guard for the back-audit finding: the old mask
 * `'••••' . substr( $v, -4 )` returned the WHOLE value for secrets of 4 chars
 * or fewer, rendering a short/mis-pasted credential in cleartext.
 *
 * Run: php tests/credential-mask.php
 *
 * @since 4.14.2
 */

// SECURITY: CLI / WP-CLI only — a test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
// settings.php is constants + pure function defs; stub the hook fns in case a
// file-level registration is ever added so the require can't fatal.
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }

require __DIR__ . '/../inc/settings.php';

$pass = 0;
$fail = 0;
function m_eq( $expected, $actual, $msg ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    expected " . var_export( $expected, true ) . "\n    actual   " . var_export( $actual, true ) . "\n";
	}
}
function m_true( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n";
	}
}

echo "sn_mask_secret()\n";

// Empty → empty (no field value).
m_eq( '', sn_mask_secret( '' ), 'empty secret → empty string' );

// Short secrets (<= 8 chars) must NOT round-trip in cleartext, and must not
// leak their exact length — fixed 8-bullet placeholder.
foreach ( array( 'a', 'ab', 'abcd', 'abcde', 'eightch!' ) as $short ) {
	$masked = sn_mask_secret( $short );
	m_true( false === strpos( $masked, $short ), "short secret '$short' is NOT present in the mask (no cleartext reveal)" );
	m_eq( '••••••••', $masked, "short secret '$short' → fixed 8-bullet placeholder (no length leak)" );
}

// Long secrets: last 4 shown (recognition affordance), leading bullets kept so
// the masked-save guard (leading-bullet check) still treats the field as untouched.
$long   = 'sk_live_0123456789abcdef';
$masked = sn_mask_secret( $long );
m_eq( '••••cdef', $masked, 'long secret → bullets + last 4 chars' );
m_true( 0 === strpos( $masked, '••••' ), 'masked value keeps the leading bullets (masked-save guard relies on it)' );
m_true( false === strpos( $masked, '0123456789' ), 'long secret body is NOT revealed' );

// Boundary: 8 chars is the last "short" length; 9 is the first to show last-4.
m_eq( '••••••••', sn_mask_secret( '12345678' ), '8-char secret → placeholder (boundary, still short)' );
m_eq( '••••6789', sn_mask_secret( '123456789' ), '9-char secret → last-4 shown (crosses the >8 threshold)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
