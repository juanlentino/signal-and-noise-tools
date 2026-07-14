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
// D2-T3 review: the badge tooltip's fallback basis label is i18n-wrapped now.
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return (string) $s; } }
function number_format_i18n( $n ) { return (string) (int) $n; }
// snt_analytics_fmt_time lives in the render file; snt_analytics_render_delta_badge is a thin
// delegator whose body is snt_an_delta_badge in inc/analytics-panels.php (required by the render
// file) — no stubs needed, and NEVER stub either name (redeclare fatal).

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

echo "\nGroup: inline sparkline — smooth SVG mini-area\n";
$sp = snt_analytics_sparkline( array(
	array( 'day' => '2026-06-09', 'views' => 3 ),
	array( 'day' => '2026-06-10', 'views' => 9 ),
	array( 'day' => '2026-06-11', 'views' => 5 ),
) );
ok( strpos( $sp, 'sn-an-spark' ) !== false, 'sparkline: keeps the .sn-an-spark wrapper class' );
ok( strpos( $sp, '<svg' ) !== false && strpos( $sp, '<path' ) !== false, 'sparkline: renders an SVG path (not bars)' );
ok( strpos( $sp, 'class="b"' ) === false, 'sparkline: old grey tick bars gone' );
ok( preg_match( '/d="M [\d.]+,[\d.]+ C /', $sp ) === 1, 'sparkline: smooth bézier line' );
$se = snt_analytics_sparkline( array() );
ok( strpos( $se, 'sn-an-spark--empty' ) !== false, 'sparkline: empty-state class preserved' );
ok( strpos( $se, '<svg' ) === false, 'sparkline: empty-state has no SVG' );
// Many sparklines render per page (one per dim-table row × several tables) — the
// gradient id MUST be unique per call or duplicate ids break fill resolution.
$sa = snt_analytics_sparkline( array( array( 'day' => 'd', 'views' => 1 ), array( 'day' => 'e', 'views' => 2 ) ) );
$sb = snt_analytics_sparkline( array( array( 'day' => 'd', 'views' => 1 ), array( 'day' => 'e', 'views' => 2 ) ) );
preg_match( '/id="(sn-spark-fill-\d+)"/', $sa, $ma );
preg_match( '/id="(sn-spark-fill-\d+)"/', $sb, $mb );
ok( ! empty( $ma[1] ) && ! empty( $mb[1] ) && $ma[1] !== $mb[1], 'sparkline: gradient id is unique per call (no dup-id collision)' );
// A single-bucket dimension (one data point) must still show a visible mark — the old
// bar sparkline drew one full-height bar, so a bare-moveto (invisible) SVG would regress.
$s1 = snt_analytics_sparkline( array( array( 'day' => 'd', 'views' => 5 ) ) );
ok( strpos( $s1, '<svg' ) !== false && strpos( $s1, ' C ' ) !== false, 'sparkline: single point renders a visible flat line (not an invisible bare moveto)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
