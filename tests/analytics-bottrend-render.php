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

echo "\nGroup: bot_trend render: smooth SVG chart (red, peak-labelled)\n";

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
// D5 §3: routes through the shared trend-SVG primitive (inc/analytics-panels.php)
// with id_suffix 'Bot' — proves the primitive is doing the drawing AND guards the
// anti-collision fix (the Overview header trend's 'snSparkFill' co-renders on this
// same Quality-tab page; a bare/default id here would silently steal that gradient).
ok( strpos( $h, 'id="snSparkFillBot"' ) !== false && strpos( $h, 'url(#snSparkFillBot)' ) !== false, 'trend: routes through the shared primitive with a de-collided gradient id' );
ok( strpos( $h, 'snBotTrendFill' ) === false, 'trend: the old standalone gradient id is gone' );

unset( $GLOBALS['sn_an_empty_panels'] );
ob_start(); snt_analytics_render_bot_trend( array() ); $e = ob_get_clean();
ok( '' === $e, 'empty input → panel folds instead of rendering inline (D4 §4)' );
$noted = (array) ( $GLOBALS['sn_an_empty_panels'] ?? array() );
ok( 1 === count( $noted ) && 'Bot share over time' === $noted[0]['title'] && false !== stripos( $noted[0]['why'], 'No traffic recorded' ), 'empty state copy carried as the fold why' );

// A single day of data must still draw a visible (flat) line, not an invisible bare moveto.
ob_start(); snt_analytics_render_bot_trend( array( array( 'day' => '2026-06-11', 'bot_pct' => 12, 'total' => 40 ) ) ); $h1 = ob_get_clean();
ok( strpos( $h1, ' C ' ) !== false, 'single day → visible flat line' );
ok( strpos( $h1, 'peak 12% bot' ) !== false, 'single day → peak labelled (i18n-wrapped)' );

echo "\nGroup: v9.68.1: bot breakdown: a NULL networks list (failed dims read) says so, never a quiet omission\n";
$bb_fail = array(
	'totals'           => array( 'human' => 100, 'suspect' => 10, 'bot' => 30, 'total' => 140 ),
	'top_bot_networks' => null, // sn_analytics_bot_breakdown carries the accessor's failed-read verdict through
);
ob_start(); snt_analytics_render_bot_breakdown( $bb_fail ); $hbf = ob_get_clean();
ok( strpos( $hbf, 'Traffic quality' ) !== false, 'breakdown: the class totals (their own table) still render' );
ok( strpos( $hbf, 'could not be read (read failure: not an empty window).' ) !== false,
	'breakdown: the failed networks read renders the shared read-failure line' );
ok( strpos( $hbf, 'Top bot networks' ) === false, 'breakdown: no half-table drawn from a failed read' );
$bb_empty = array(
	'totals'           => array( 'human' => 100, 'suspect' => 10, 'bot' => 30, 'total' => 140 ),
	'top_bot_networks' => array(),
);
ob_start(); snt_analytics_render_bot_breakdown( $bb_empty ); $hbe = ob_get_clean();
ok( strpos( $hbe, 'could not be read' ) === false, 'breakdown: an EMPTY networks list stays a quiet omission (the pre-existing honest shape)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
