<?php
/**
 * Standalone fixture tests for inc/health-checks-admin.php.
 *
 * Covers the v6.39.2 "Suggest all" cost cap: the batch button's label must show
 * min(count, SNT_AI_SUGGEST_ALL_MAX) and carry the cap as a data attribute the
 * JS reads, so one click can fire at most SNT_AI_SUGGEST_ALL_MAX AI calls.
 *
 * @since plugin v6.39.2
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }

require_once __DIR__ . '/../inc/health-checks-admin.php';

$pass = 0; $fail = 0;
function hca_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }
function hca_eq( $e, $a, $msg ) { global $pass, $fail; if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual: " . var_export( $a, true ) . "\n"; } }

echo "health-checks-admin suite — plugin v6.39.2\n";

echo "\nTest: SNT_AI_SUGGEST_ALL_MAX defined\n";
hca_true( defined( 'SNT_AI_SUGGEST_ALL_MAX' ), 'cap constant exists' );
hca_eq( 50, defined( 'SNT_AI_SUGGEST_ALL_MAX' ) ? SNT_AI_SUGGEST_ALL_MAX : null, 'cap is 50' );

echo "\nTest: snt_health_suggest_all_button_html caps the label + emits the cap\n";

// Below the cap — label shows the true count.
$html = snt_health_suggest_all_button_html( 10 );
hca_true( false !== strpos( $html, 'Suggest all 10' ), 'count 10 → "Suggest all 10"' );
hca_true( false !== strpos( $html, 'data-snt-suggest-all="1"' ), 'carries the suggest-all hook attribute' );
hca_true( false !== strpos( $html, 'data-snt-suggest-all-max="50"' ), 'carries the cap as a data attribute' );

// At the cap.
$html = snt_health_suggest_all_button_html( 50 );
hca_true( false !== strpos( $html, 'Suggest all 50' ), 'count 50 → "Suggest all 50"' );

// Above the cap — label is clamped to the cap, never the raw count.
$html = snt_health_suggest_all_button_html( 73 );
hca_true( false !== strpos( $html, 'Suggest all 50' ), 'count 73 → label clamped to "Suggest all 50"' );
hca_true( false === strpos( $html, 'Suggest all 73' ), 'raw over-cap count 73 is NOT shown' );

// A single finding.
$html = snt_health_suggest_all_button_html( 1 );
hca_true( false !== strpos( $html, 'Suggest all 1' ), 'count 1 → "Suggest all 1"' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
