<?php
/**
 * Tests for the dashboard-widget stylesheet enqueue (refinement-audit item E5).
 *
 * The .sn-aw-* CSS moved out of an inline <style> echoed mid-body by
 * sn_aw_styles() into a properly enqueued external stylesheet, gated to the WP
 * Dashboard home screen (index.php) and cache-busted by SNT_VERSION — mirroring
 * the analytics-admin.css enqueue in inc/admin-menu.php.
 *
 * Regression guard: a body-injected <style> can render the widgets UNSTYLED on
 * the live page (edge/cache HTML rewriting + a strict CSP) — the v6.5.0-class
 * bug fixed for the analytics dashboard in v6.5.1. A WRONG screen gate would
 * reintroduce it, so this asserts the stylesheet loads on the dashboard and
 * NOT on any other admin screen.
 *
 * Run: php tests/analytics-widget-enqueue.php
 * @since plugin v6.11.3
 */
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '9.9.9-test' );

// Stubs for the WP functions the widget file touches at require time.
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $c = null, $p = 10, $a = 1 ) {} }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }

// Recorder for wp_enqueue_style — captures every enqueue call for assertions.
$GLOBALS['__enq'] = array();
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['__enq'][] = array( 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver );
}

// Minimal seam so a widget can be rendered to assert it emits no inline <style>.
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function sn_analytics_config() { return array( 'a' => 1 ); }
function sn_analytics_realtime( $class = 'human' ) { return 7; }

require_once __DIR__ . '/../inc/analytics-widget.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { ++$pass; echo "PASS: $m\n"; } else { ++$fail; echo "FAIL: $m\n"; } }

/**
 * Invoke the enqueue callback for a hook and return the widget-stylesheet
 * enqueues it produced. Tolerates the function not existing yet (RED phase):
 * returns an empty list rather than fataling, so the summary line still prints.
 */
function aw_enqueues_for( $hook ) {
	$GLOBALS['__enq'] = array();
	if ( function_exists( 'sn_aw_enqueue_styles' ) ) {
		sn_aw_enqueue_styles( $hook );
	}
	return array_values( array_filter(
		$GLOBALS['__enq'],
		function ( $e ) { return strpos( (string) $e['src'], 'analytics-widget.css' ) !== false; }
	) );
}

echo "Dashboard-widget stylesheet enqueue (E5)\n\n";

ok( function_exists( 'sn_aw_enqueue_styles' ), 'enqueue: sn_aw_enqueue_styles() is defined (named, testable callback)' );

echo "\nGroup: enqueued on the Dashboard home screen\n";
$dash = aw_enqueues_for( 'index.php' );
ok( count( $dash ) === 1, 'enqueue: widget stylesheet enqueued exactly once on the dashboard (index.php)' );
$row = $dash[0] ?? array();
ok( ( $row['handle'] ?? '' ) === 'sn-analytics-widget', 'enqueue: registered under the sn-analytics-widget handle' );
ok( ( $row['src'] ?? '' ) === SNT_URL . 'assets/analytics/analytics-widget.css', 'enqueue: src is assets/analytics/analytics-widget.css under SNT_URL' );
ok( ( $row['ver'] ?? '' ) === SNT_VERSION, 'enqueue: cache-busted by SNT_VERSION (mirrors analytics-admin.css)' );

echo "\nGroup: NOT enqueued off the dashboard (the unstyled-bug screen guard)\n";
foreach ( array( 'post.php', 'edit.php', 'options-general.php', 'toplevel_page_sn-theme-options', 'sn-theme-options_page_sn-monitoring' ) as $other ) {
	ok( count( aw_enqueues_for( $other ) ) === 0, "enqueue: NOT loaded on '$other'" );
}

echo "\nGroup: CSS lives in an external asset, not inline\n";
$css_path = __DIR__ . '/../assets/analytics/analytics-widget.css';
ok( is_file( $css_path ), 'asset: assets/analytics/analytics-widget.css exists' );
$css = is_file( $css_path ) ? (string) file_get_contents( $css_path ) : '';
ok( strpos( $css, '.sn-aw-grid' ) !== false, 'asset: contains the .sn-aw-grid rule (moved from sn_aw_styles)' );
ok( strpos( $css, '.sn-aw-config-snippet' ) !== false, 'asset: contains the .sn-aw-config-snippet rule (full block moved)' );
// Match the CLOSING tag: a real <style>…</style> wrapper has </style>; the file
// header's prose mention of the old inline "<style>" does not.
ok( stripos( $css, '</style>' ) === false, 'asset: pure CSS — not the inline <style>…</style> block pasted verbatim' );

echo "\nGroup: inline emitter removed — behaviorally, not by source grep\n";
ok( ! function_exists( 'sn_aw_styles' ), 'cleanup: sn_aw_styles() inline emitter no longer exists' );
// Render a widget: a lingering sn_aw_styles() call would either fatal (undefined
// function) or emit an inline <style>. Neither may happen now.
ob_start();
sn_aw_realtime();
$rendered = ob_get_clean();
ok( $rendered !== '' && stripos( $rendered, '<style' ) === false, 'cleanup: rendering a widget emits no inline <style> (CSS is enqueued, not echoed)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
