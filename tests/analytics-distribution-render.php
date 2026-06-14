<?php
/**
 * Tests for snt_analytics_render_distribution()'s optional custom empty-state.
 * The bot-confidence panel needs a bespoke "needs Cloudflare Bot Management"
 * message instead of the generic "No <title> data" copy.
 * Run: php tests/analytics-distribution-render.php
 * @since plugin v6.6.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', '/' );
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  ok: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

echo "\nGroup: distribution custom empty-state\n";
// Custom empty message when no data.
ob_start(); snt_analytics_render_distribution( 'Bot confidence', array(), 'Needs Cloudflare Bot Management enabled.' ); $e = ob_get_clean();
ok( strpos( $e, 'Needs Cloudflare Bot Management enabled.' ) !== false, 'custom empty message shown when no data' );
// Default empty message preserved (2-arg back-compat).
ob_start(); snt_analytics_render_distribution( 'Scroll depth', array() ); $d = ob_get_clean();
ok( strpos( $d, 'No scroll depth data in this range yet.' ) !== false, 'default empty message unchanged (2-arg callers)' );
ok( strpos( $d, 'sn-an-empty--panel' ) !== false, 'empty-state uses the extracted padding class (refinement audit D2)' );
ok( strpos( $d, 'style="padding' ) === false, 'no inline padding style remains on the empty-state' );
// Bars render when data present (custom msg ignored).
ob_start(); snt_analytics_render_distribution( 'Bot confidence', array( array( 'label' => '61–99', 'views' => 9 ) ), 'x' ); $h = ob_get_clean();
ok( strpos( $h, '61–99' ) !== false && strpos( $h, 'sn-an-dist-bar' ) !== false, 'renders bands when data present' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
