<?php
/**
 * Tests for the engaged-rate stat card in snt_analytics_render_cards().
 * Run: php tests/analytics-cards-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_html__( $s, $d = null ) { return (string) $s; }
function esc_attr__( $s, $d = null ) { return (string) $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
// snt_analytics_fmt_time and snt_analytics_render_delta_badge are defined in the render file itself — no stubs needed.

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $cond, $msg ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok: $msg\n"; }
	else         { $fail++; echo "  FAIL: $msg\n"; }
}

echo "\nGroup: engaged-rate card render\n";

$totals = array( 'views' => 100, 'visits' => 120, 'scroll_avg' => 40, 'time_avg' => 5000 );
ob_start(); snt_analytics_render_cards( 3, $totals, array(), array( 'current' => 42, 'pct' => 5, 'dir' => 'up' ) ); $h = ob_get_clean();
ok( strpos( $h, 'Engaged' ) !== false, 'engaged card label present' );
ok( strpos( $h, '42%' ) !== false, 'engaged value rendered as percent' );

ob_start(); snt_analytics_render_cards( 3, $totals, array(), null ); $h2 = ob_get_clean();
ok( strpos( $h2, 'Engaged' ) === false, 'no engaged card when null' );

ob_start(); snt_analytics_render_cards( 3, $totals, array(), array( 'current' => null ) ); $h3 = ob_get_clean();
ok( strpos( $h3, 'Engaged' ) === false, "array('current'=>null) (all-range, no timed data) hides the card" );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
