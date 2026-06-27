<?php
/**
 * Standalone test: snt_insights_render_usage_section() render contract.
 *
 * Locks the plugin-scoped "AI usage & spend" readout introduced in v6.41.0:
 * cost rendering from recorded tokens, the per-feature table, the empty state,
 * the unpriced-model disclosure, and — critically — the pointer to WordPress's
 * native AI Request Logs (the complement-not-duplicate contract: SN surfaces
 * the cost of its OWN features, WordPress owns the full per-request log).
 *
 * Standalone — no PHPUnit. Run: php tests/insights-admin.php
 *
 * @package SignalNoiseTools
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

$pass = 0;
$fail = 0;

// ─── WP stubs (load-time + render-time) ──────────────────────────────
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); } // pass the inc-file direct-access guards
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $v ) { return false; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, (int) $ts ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can() { return true; } }

$GLOBALS['__opts'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $n, $d = false ) { return $GLOBALS['__opts'][ $n ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $n, $v, $a = null ) { $GLOBALS['__opts'][ $n ] = $v; return true; } }

require_once __DIR__ . '/../inc/ai-bootstrap.php';
require_once __DIR__ . '/../inc/insights-admin.php';

function hc_contains( $haystack, $needle, $msg ) {
	global $pass, $fail;
	if ( false !== strpos( $haystack, $needle ) ) {
		$pass++;
		echo "  PASS: $msg\n";
	} else {
		$fail++;
		echo "  FAIL: $msg\n    Missing: $needle\n";
	}
}

// ─── Test A: populated log — tokens, cost, per-feature, native pointer ─
echo "Test A: usage section renders cost + native AI Request Logs pointer\n";
$now = time();
update_option(
	'sn_ai_usage_log',
	array(
		array( 'ts' => $now - 100, 'feature' => 'insights', 'model' => 'claude-sonnet-4-6', 'served_model' => 'claude-sonnet-4-6', 'prompt' => 1000, 'completion' => 500, 'total' => 1500 ),
		array( 'ts' => $now - 50, 'feature' => 'meta', 'model' => 'claude-haiku-4-5', 'served_model' => 'claude-haiku-4-5', 'prompt' => 2000, 'completion' => 1000, 'total' => 3000 ),
	)
);
ob_start();
snt_insights_render_usage_section();
$html = ob_get_clean();
hc_contains( $html, 'AI usage', 'renders the section header' );
hc_contains( $html, 'page=ai-wp-admin', 'links to native Settings → AI (complement, not duplicate)' );
hc_contains( $html, 'Request', 'names the native AI Request Logs' );
hc_contains( $html, '$0.01', 'renders an estimated dollar cost' );
hc_contains( $html, 'insights', 'per-feature row for insights' );
hc_contains( $html, 'List-price estimate', 'discloses the estimate basis' );
hc_contains( $html, '4,500', 'renders total tokens for the window (1500+3000)' );

// ─── Test B: unknown model → unpriced disclosure ─────────────────────
echo "\nTest B: unpriced-model disclosure\n";
update_option(
	'sn_ai_usage_log',
	array(
		array( 'ts' => $now - 10, 'feature' => 'meta', 'model' => 'mystery', 'served_model' => 'mystery', 'prompt' => 100, 'completion' => 50, 'total' => 150 ),
	)
);
ob_start();
snt_insights_render_usage_section();
$html = ob_get_clean();
hc_contains( $html, 'no list price on file', 'discloses unpriced calls excluded from the dollar figure' );

// ─── Test C: empty log → graceful empty state ────────────────────────
echo "\nTest C: empty state\n";
$GLOBALS['__opts'] = array();
ob_start();
snt_insights_render_usage_section();
$html = ob_get_clean();
hc_contains( $html, 'No AI calls recorded', 'graceful empty state' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
