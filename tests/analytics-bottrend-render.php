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
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n ) { return (string) (int) $n; }

require_once __DIR__ . '/../inc/analytics-panels.php'; // v8.5.0: renderers emit chrome via the panel primitive
require __DIR__ . '/../inc/analytics-admin-render.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

echo "\nGroup: bot_trend render — smooth SVG chart (red, peak-labelled)\n";

$rows = array(
	array( 'day' => '2026-06-10', 'bot_pct' => 10, 'total' => 50 ),
	array( 'day' => '2026-06-11', 'bot_pct' => 30, 'total' => 80 ),
);
ob_start(); snt_analytics_render_bot_trend( $rows ); $h = ob_get_clean();
ok( strpos( $h, 'Bot share over time' ) !== false, 'heading present' );
ok( strpos( $h, 'sn-an-bot-trend' ) !== false, 'wrapper class present' );
ok( strpos( $h, '<svg' ) !== false && strpos( $h, '<path' ) !== false, 'smooth SVG path (not bars)' );
ok( strpos( $h, 'class="bar"' ) === false, 'old chunky bars gone' );
ok( strpos( $h, '#d63638' ) !== false, 'red accent (matches the bot quality-bar segment)' );
ok( strpos( $h, 'peak 30% bot' ) !== false, 'peak labelled with the absolute max bot %' );
ok( preg_match( '/d="M [\d.]+,[\d.]+ C /', $h ) === 1, 'smooth bézier line (scaled to peak)' );

unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_analytics_render_bot_trend( array() ); $e = ob_get_clean();
ok( '' === $e, 'empty input → panel folds instead of rendering inline (D4 §4)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Bot share over time' === $noted[0]['title'] && false !== stripos( $noted[0]['why'], 'No traffic recorded' ), 'empty state copy carried as the fold why' );

// A single day of data must still draw a visible (flat) line, not an invisible bare moveto.
ob_start(); snt_analytics_render_bot_trend( array( array( 'day' => '2026-06-11', 'bot_pct' => 12, 'total' => 40 ) ) ); $h1 = ob_get_clean();
ok( strpos( $h1, ' C ' ) !== false, 'single day → visible flat line' );
ok( strpos( $h1, 'peak 12% bot' ) !== false, 'single day → peak labelled (i18n-wrapped)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
