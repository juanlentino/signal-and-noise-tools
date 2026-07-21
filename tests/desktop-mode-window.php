<?php
/**
 * Standalone tests for the Desktop Mode native monitoring window
 * (inc/desktop-mode-window.php).
 *
 * Contract under test (upstream WordPress/desktop-mode trunk @ 0.9.5,
 * includes/registries/native-windows.php + window-tabs.php):
 *   - desktop_mode_register_window( $id, $args ) — args include title, icon,
 *     template (callable), script, style, main_tab_label, capabilities, config.
 *   - desktop_mode_register_window_tab( $window_id, $args ) — value/label/
 *     template; 'main' is reserved for the window's own template.
 *   - desktop_mode_register_icon( $id, $args ) — 'window' links the tile.
 *
 * The module must be a pure no-op when Desktop Mode is absent.
 *
 * @since plugin v9.76.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; } }
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( $handle, $src, $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__scripts'][ $handle ] = array( 'src' => $src, 'deps' => $deps ); return true; } }
if ( ! function_exists( 'wp_register_style' ) ) { function wp_register_style( $handle, $src, $deps = array(), $ver = false ) { $GLOBALS['__styles'][ $handle ] = array( 'src' => $src ); return true; } }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '0.0.0-test' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', '/tmp/' ); }

$GLOBALS['__actions'] = array();
$GLOBALS['__scripts'] = array();
$GLOBALS['__styles']  = array();

require __DIR__ . '/../inc/desktop-mode-window.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function tpl_output( $cb ) { ob_start(); call_user_func( $cb ); return (string) ob_get_clean(); }
echo "desktop-mode-window — v9.76.0\n\n";

// --- Absent-DM no-op ------------------------------------------------------
// desktop_mode_register_window is NOT defined yet: registration must bail.
ok( false === snt_desktop_window_register(), 'registration returns false when Desktop Mode is absent' );

// --- Pure args ------------------------------------------------------------
$args = snt_desktop_window_args();
ok( is_string( $args['title'] ) && '' !== $args['title'], 'window args carry a title' );
ok( is_string( $args['icon'] ) && '' !== $args['icon'], 'window args carry an icon' );
ok( 'sn-desktop-window' === $args['script'] && 'sn-desktop-window' === $args['style'], 'script + style are the sn-desktop-window handles' );
ok( 'Analytics' === $args['main_tab_label'], 'main tab is Analytics' );
ok( array( 'manage_options' ) === $args['capabilities'], 'window is owner-gated via manage_options' );
ok( is_callable( $args['template'] ), 'window template is callable' );
ok( $args['width'] >= 520 && $args['height'] >= 400, 'opens at a monitoring-dashboard size' );
ok( 'signal-noise/v1' === ( $args['config']['restNamespace'] ?? null ), 'config ships the REST namespace' );

$main_html = tpl_output( $args['template'] );
ok( false !== strpos( $main_html, 'data-role="views-total"' ), 'analytics template declares the views-total mount' );
ok( false !== strpos( $main_html, 'data-role="views-series"' ), 'analytics template declares the series mount' );

// --- Tabs -----------------------------------------------------------------
$tabs = snt_desktop_window_tabs();
ok( array( 'health', 'uptime', 'deploy' ) === array_column( $tabs, 'value' ), 'three tabs: health, uptime, deploy' );
$by_value = array();
foreach ( $tabs as $t ) {
	$by_value[ $t['value'] ] = $t;
	ok( is_string( $t['label'] ) && '' !== $t['label'], "tab {$t['value']} has a label" );
	ok( is_callable( $t['template'] ), "tab {$t['value']} template is callable" );
	ok( 'main' !== $t['value'], "tab {$t['value']} avoids the reserved main value" );
}
ok( false !== strpos( tpl_output( $by_value['health']['template'] ), 'data-role="health-list"' ), 'health template declares its mount' );
ok( false !== strpos( tpl_output( $by_value['uptime']['template'] ), 'data-role="uptime-rows"' ), 'uptime template declares its mount' );
ok( false !== strpos( tpl_output( $by_value['deploy']['template'] ), 'data-role="deploy-cards"' ), 'deploy template declares its mount' );

// --- Registration against stubs ------------------------------------------
$GLOBALS['__dm_windows'] = array();
$GLOBALS['__dm_tabs']    = array();
$GLOBALS['__dm_icons']   = array();
// Conditionally declared so PHP defines them only HERE at execution time —
// an unconditional top-level declaration would be hoisted at parse time and
// falsify the absent-DM assertion above.
if ( ! function_exists( 'desktop_mode_register_window' ) ) {
	function desktop_mode_register_window( $id, $args = array() ) { $GLOBALS['__dm_windows'][ $id ] = $args; return true; }
}
if ( ! function_exists( 'desktop_mode_register_window_tab' ) ) {
	function desktop_mode_register_window_tab( $window_id, $args = array() ) { $GLOBALS['__dm_tabs'][] = array( 'window' => $window_id, 'args' => $args ); return true; }
}
if ( ! function_exists( 'desktop_mode_register_icon' ) ) {
	function desktop_mode_register_icon( $id, $args = array() ) { $GLOBALS['__dm_icons'][ $id ] = $args; return true; }
}

ok( true === snt_desktop_window_register(), 'registration returns true when Desktop Mode is present' );
ok( isset( $GLOBALS['__dm_windows']['sn-monitor'] ), 'window registered under the sn-monitor id' );
ok( 3 === count( $GLOBALS['__dm_tabs'] ), 'three tabs registered' );
ok( array( 'sn-monitor' ) === array_values( array_unique( array_column( $GLOBALS['__dm_tabs'], 'window' ) ) ), 'every tab binds to sn-monitor' );
ok( isset( $GLOBALS['__dm_icons']['sn-monitor'] ) && 'sn-monitor' === ( $GLOBALS['__dm_icons']['sn-monitor']['window'] ?? '' ), 'desktop icon links the sn-monitor window' );

// Re-running must not double-register tabs (idempotence guard).
snt_desktop_window_register();
ok( 3 === count( $GLOBALS['__dm_tabs'] ), 'second registration call does not duplicate tabs' );

// --- Script/style handle registration ------------------------------------
snt_desktop_window_register_assets();
ok( isset( $GLOBALS['__scripts']['sn-desktop-window'] ), 'script handle registered' );
ok( in_array( 'wp-api-fetch', $GLOBALS['__scripts']['sn-desktop-window']['deps'], true ), 'script depends on wp-api-fetch' );
ok( in_array( 'snt-ability-run', $GLOBALS['__scripts']['sn-desktop-window']['deps'], true ), 'script depends on snt-ability-run (abilities transport)' );
ok( in_array( 'sn-desktop-mode', $GLOBALS['__scripts']['sn-desktop-window']['deps'], true ), 'script depends on sn-desktop-mode (snDesktopData)' );
ok( isset( $GLOBALS['__styles']['sn-desktop-window'] ), 'style handle registered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
