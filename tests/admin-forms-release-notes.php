<?php
/**
 * Standalone fixture tests for inc/admin-forms/release-notes.php.
 *
 * Covers the v6.39.2 availability gate: the "Draft release notes" button must
 * be disabled (and an explanatory notice shown) when no AI provider is
 * configured, mirroring how the Insights "Run Analysis" button is gated — so a
 * click can't POST a request that just fails with an unavailable error.
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

if ( ! function_exists( 'esc_html' ) )     { function esc_html( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_attr' ) )     { function esc_attr( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url' ) )      { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) )    { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = '', $b = '', $c = true, $d = true ) { return ''; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 7; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return true; } }
if ( ! function_exists( 'snt_ai_is_available' ) ) {
	function snt_ai_is_available() { return ! empty( $GLOBALS['__rn_ai_available'] ); }
}

require_once __DIR__ . '/../inc/admin-forms/release-notes.php';

$pass = 0; $fail = 0;
function rn_true( $c, $msg ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; } }

function rn_render() {
	ob_start();
	sn_admin_render_release_notes_section();
	return ob_get_clean();
}

echo "admin-forms/release-notes suite — plugin v6.39.2\n";

echo "\nTest: button gated when AI unavailable\n";
$GLOBALS['__rn_ai_available'] = false;
$html = rn_render();
rn_true( false !== strpos( $html, 'Draft release notes' ), 'the drafter button is rendered' );
rn_true( false !== strpos( $html, 'disabled' ), 'button is disabled when AI unavailable' );
rn_true( false !== strpos( $html, 'AI client not available' ), 'an explanatory notice is shown when unavailable' );

echo "\nTest: button enabled when AI available\n";
$GLOBALS['__rn_ai_available'] = true;
$html = rn_render();
rn_true( false !== strpos( $html, 'Draft release notes' ), 'the drafter button is rendered' );
rn_true( false === strpos( $html, 'disabled' ), 'button is NOT disabled when AI available' );
rn_true( false === strpos( $html, 'AI client not available' ), 'no unavailable notice when AI is ready' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
