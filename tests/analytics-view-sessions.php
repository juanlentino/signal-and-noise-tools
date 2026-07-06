<?php
/**
 * Render smoke test for the Visits view. Run: php tests/analytics-view-sessions.php
 *
 * The view file requires inc/analytics-panels.php (the real panel primitives),
 * which declares snt_an_panel_open/snt_an_panel_close/snt_an_note_empty/
 * snt_an_flush_empty_fold unconditionally — so those must NOT be stubbed here
 * (stubbing them would cause a "Cannot redeclare function" fatal). Only the
 * two render helpers that live in analytics-admin-render.php (NOT loaded by
 * the view) are safe to stub, plus the leaf WP functions the real panel code
 * and the view call at runtime.
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
define( 'SN_ANALYTICS_DATASET', 'sn_pageviews' );

// Leaf WP function stubs used by the real analytics-panels.php + the view.
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = '' ) { return $s; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = '' ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); } }

// Render-helper stubs — these live in analytics-admin-render.php, which is NOT
// loaded by requiring the view, so stubbing them here is safe.
if ( ! function_exists( 'snt_analytics_render_distribution' ) ) { function snt_analytics_render_distribution( $t, $r, $e = '', $w = false ) { echo "[dist:$t:" . count( $r ) . ']'; } }
if ( ! function_exists( 'snt_analytics_render_dim_table' ) ) { function snt_analytics_render_dim_table( $t, $r, $e = '', $s = array(), $d = '', $v = 5 ) { echo "[dim:$t:" . count( $r ) . ']'; } }

require __DIR__ . '/../inc/analytics-sessions.php';
require __DIR__ . '/../inc/analytics-view-sessions.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

// Empty summaries render without fatal and emit the summary panel (real markup).
ob_start();
snt_analytics_render_summary_panels(
	array( 'visits' => 0, 'bounce_rate' => 0.0, 'pages_per_visit' => 0.0, 'median_duration' => 0, 'engaged_visits' => 0, 'engaged_rate' => 0.0 ),
	array(),
	array(),
	false
);
$out = ob_get_clean();
ok( is_string( $out ), 'render produced output without fatal' );
ok( false !== strpos( $out, 'postbox' ), 'emitted a real .postbox panel' );
ok( false !== strpos( $out, 'Visit quality' ), 'emitted the Visit quality panel title' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
