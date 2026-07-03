<?php
/**
 * Standalone fixture tests for the v8.5.0 Analytics panel primitive
 * (inc/analytics-panels.php): the ONE place that emits panel chrome on the
 * Analytics page, plus the row-clamp wrapper.
 *
 * Run: php tests/analytics-panels.php
 * @since plugin v8.5.0
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ), '-' ); } }

require_once __DIR__ . '/../inc/analytics-panels.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "  PASS: $m\n"; } else { $fail++; echo "  FAIL: $m\n"; } }

function capture( $fn ) { ob_start(); $fn(); return (string) ob_get_clean(); }

echo "analytics-panels suite - plugin v8.5.0\n";

echo "\nTest: basic panel shell\n";
$html = capture( function () {
	snt_an_panel_open( 'Top pages' );
	echo 'BODY';
	snt_an_panel_close();
} );
ok( false !== strpos( $html, '<div class="postbox sn-an-postbox">' ), 'native .postbox shell + the sn-an-postbox marker (the token contract hook)' );
ok( false !== strpos( $html, '<h2 class="hndle"><span>Top pages</span></h2>' ), 'normal-case bold postbox header (owner: keep the postbox feel)' );
ok( false !== strpos( $html, '<div class="inside">BODY</div></div>' ), 'default inside class wraps the body' );
ok( 1 === substr_count( $html, 'postbox-header' ), 'exactly one header' );

echo "\nTest: args — inside_class, panel_class, header_meta\n";
$html = capture( function () {
	snt_an_panel_open( 'Uptime', array(
		'inside_class' => 'inside inside-flush',
		'panel_class'  => 'sn-an-rail-tile',
		'header_meta'  => 'vs prior period',
	) );
	snt_an_panel_close();
} );
ok( false !== strpos( $html, 'postbox sn-an-postbox sn-an-rail-tile' ), 'extra panel class appended' );
ok( false !== strpos( $html, '<div class="inside inside-flush">' ), 'inside class override' );
ok( false !== strpos( $html, '<span class="sn-an-head-meta">vs prior period</span>' ), 'header meta rendered right of the title' );

echo "\nTest: collapsible panel\n";
$html = capture( function () {
	snt_an_panel_open( 'Uptime detail', array( 'collapsible' => true, 'collapsed' => true ) );
	snt_an_panel_close();
} );
ok( false !== strpos( $html, 'data-sn-an-collapsible="uptime-detail"' ), 'collapsible marker carries the slug (localStorage key)' );
ok( false !== strpos( $html, 'sn-an-collapsed' ), 'collapsed class present when collapsed' );
ok( false !== strpos( $html, 'aria-expanded="false"' ), 'toggle button carries aria-expanded' );
ok( false !== strpos( $html, 'class="sn-an-toggle"' ), 'toggle button present' );
$html = capture( function () {
	snt_an_panel_open( 'Open one', array( 'collapsible' => true ) );
	snt_an_panel_close();
} );
ok( false !== strpos( $html, 'aria-expanded="true"' ) && false === strpos( $html, 'sn-an-collapsed' ), 'collapsible defaults to open' );

echo "\nTest: collapsed without collapsible is ignored\n";
$html = capture( function () {
	snt_an_panel_open( 'Static', array( 'collapsed' => true ) );
	snt_an_panel_close();
} );
ok( false === strpos( $html, 'sn-an-collapsed' ) && false === strpos( $html, 'sn-an-toggle' ), 'collapsed only applies to collapsible panels' );

echo "\nTest: XSS — title and classes escape\n";
$html = capture( function () {
	snt_an_panel_open( '<script>x</script>', array( 'panel_class' => '"onmouseover="x' ) );
	snt_an_panel_close();
} );
ok( false === strpos( $html, '<script>' ), 'title is escaped' );
ok( false === strpos( $html, 'onmouseover="x' ), 'attr injection escaped' );

echo "\nTest: row clamp wrapper\n";
$html = capture( function () {
	snt_an_clamp_open( 25, 5 );
	echo '<table class="widefat"></table>';
	snt_an_clamp_close( 25, 5 );
} );
ok( false !== strpos( $html, '<div class="sn-an-clamp sn-an-clamp--5"' ), 'clamp wrapper carries the visible-rows class' );
ok( false !== strpos( $html, 'data-sn-an-total="25"' ), 'total row count on the wrapper' );
ok( false !== strpos( $html, 'class="sn-an-viewall"' ) && false !== strpos( $html, 'View all 25' ), 'view-all button with the real total' );
$html = capture( function () {
	snt_an_clamp_open( 3, 5 );
	snt_an_clamp_close( 3, 5 );
} );
ok( false === strpos( $html, 'sn-an-viewall' ), 'no view-all button when rows fit the clamp' );
ok( false !== strpos( $html, 'sn-an-clamp--5' ), 'wrapper still emitted (stable layout hook)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
