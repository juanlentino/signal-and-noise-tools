<?php
/**
 * Tests for snt_analytics_render_bot_trend() in inc/analytics-admin-render.php.
 * Run: php tests/analytics-bottrend-render.php
 * @since plugin v6.1.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );

function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }

require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "\nGroup: bot_trend render\n";

$rows = array(
	array( 'day' => '2026-06-10', 'bot_pct' => 10, 'total' => 50 ),
	array( 'day' => '2026-06-11', 'bot_pct' => 30, 'total' => 80 ),
);
ob_start(); snt_analytics_render_bot_trend( $rows ); $h = ob_get_clean();
ok( strpos( $h, 'Bot share over time' ) !== false, 'heading present' );
ok( strpos( $h, 'height:30%' ) !== false, 'bar height reflects bot_pct' );

ob_start(); snt_analytics_render_bot_trend( array() ); $e = ob_get_clean();
ok( strpos( $e, 'sn-an-empty' ) !== false || $e === '', 'empty input → empty state or nothing' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
