<?php
/**
 * Standalone test: IndexNow sub-tab two-column shell contract (v6.42.0).
 *
 * The enable toggle + maintenance actions stay in the main column; the
 * status box + status table (key-file URL, last submission) move to the rail.
 *
 * Run: php tests/indexnow-shell.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! defined( 'SN_INDEXNOW_RESULT_OPT' ) ) { define( 'SN_INDEXNOW_RESULT_OPT', 'sn_indexnow_result' ); }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1 ) { echo '<input type="hidden" name="_wpnonce">'; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { $r = ( $a == $b ) ? ' checked' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $a, $b = 0 ) { return '5 minutes'; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; } }
if ( ! function_exists( 'sn_indexnow_is_enabled' ) ) { function sn_indexnow_is_enabled() { return $GLOBALS['__enabled'] ?? true; } }
if ( ! function_exists( 'sn_indexnow_key_url' ) ) { function sn_indexnow_key_url() { return 'https://example.test/abc123.txt'; } }

$GLOBALS['__opts'] = array( 'sn_indexnow_result' => array( 'time' => time() - 60, 'code' => 200, 'count' => 4 ) );

require_once __DIR__ . '/../inc/admin-shell.php';
require_once __DIR__ . '/../inc/admin-forms/indexnow.php';

function in_assert( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Test: IndexNow renders main+rail shell (controls in main, status in rail)\n";
ob_start();
sn_admin_render_indexnow_section();
$html = ob_get_clean();
$rail_at = strpos( $html, '<aside class="sn-shell__rail"' );

in_assert( false !== strpos( $html, '<div class="sn-shell">' ), 'wrapped in the two-column shell' );
in_assert( false !== $rail_at, 'has a right rail' );

$enable_at = strpos( $html, 'Notify search engines' );
in_assert( false !== $enable_at && $enable_at < $rail_at, 'enable toggle sits in the main column' );

$keyfile_at = strpos( $html, 'Key file' );
$active_at  = strpos( $html, 'Active' );
// is_int( $rail_at ) gates these: strpos returns false when the rail is
// absent, and `$x > false` coerces to `$x > 0` (a spurious pass). The guard
// makes a missing rail genuinely fail the placement assert.
in_assert( is_int( $rail_at ) && false !== $keyfile_at && $keyfile_at > $rail_at, 'status table (key file) sits in the rail' );
in_assert( is_int( $rail_at ) && false !== $active_at && $active_at > $rail_at, 'status box sits in the rail' );

in_assert( 1 === substr_count( $html, '</aside>' ), 'rail aside closes exactly once' );

// ─── Scenario B: disabled — Off pill must still land in the rail ──────
echo "\nScenario B: disabled state keeps status in the rail, toggle in main\n";
$GLOBALS['__enabled'] = false;
ob_start();
sn_admin_render_indexnow_section();
$html_b   = ob_get_clean();
$rail_b   = strpos( $html_b, '<aside class="sn-shell__rail"' );
$off_at   = strpos( $html_b, 'Off' );
$toggle_b = strpos( $html_b, 'Notify search engines' );
in_assert( is_int( $rail_b ) && false !== $off_at && $off_at > $rail_b, 'Disabled (Off) status sits in the rail' );
in_assert( is_int( $rail_b ) && false !== $toggle_b && $toggle_b < $rail_b, 'enable toggle stays in the main column when disabled' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
