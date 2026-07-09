<?php
/**
 * Tests for inc/analytics-annotations.php — the rules-only panel-annotation
 * resolvers. Pure functions, so a plain CLI harness with inline stubs suffices.
 *
 * @since plugin v9.4.0
 */

// SECURITY: test fixture, not a runtime module.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n ) { return number_format( (float) $n ); }
}

require_once __DIR__ . '/../inc/analytics-annotations.php';

$pass = 0;
$fail = 0;
function an_eq( $e, $a, $m ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $m\n"; }
	else { $fail++; echo "  FAIL: $m\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function an_true( $c, $m ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; }
}

echo "analytics-annotations resolver suite (v9.4.0)\n";

// ── read blocks are appended below by Tasks 3-6 ──

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
