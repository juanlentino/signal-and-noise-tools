<?php
/**
 * Standalone tests for the Desktop Mode "S&N Workbench" native window
 * (inc/desktop-mode-workbench.php).
 *
 * Contract under test (upstream WordPress/desktop-mode trunk @ 0.9.5):
 * desktop_mode_register_window / _window_tab / _icon — and the module's
 * own promises: pure no-op without Desktop Mode, idempotent
 * registration, owner gating, and the pane mount points the JS engine
 * (assets/desktop-workbench.js) queries for.
 *
 * @since plugin v9.77.0
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

require __DIR__ . '/../inc/desktop-mode-workbench.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
function tpl_output( $cb ) { ob_start(); call_user_func( $cb ); return (string) ob_get_clean(); }
echo "desktop-mode-workbench — v9.77.0\n\n";

// --- Absent-DM no-op ------------------------------------------------------
ok( false === snt_desktop_workbench_register(), 'registration returns false when Desktop Mode is absent' );

// --- Pure args ------------------------------------------------------------
$args = snt_desktop_workbench_args();
ok( is_string( $args['title'] ) && '' !== $args['title'], 'window args carry a title' );
ok( is_string( $args['icon'] ) && '' !== $args['icon'], 'window args carry an icon' );
ok( 'sn-desktop-workbench' === $args['script'] && 'sn-desktop-workbench' === $args['style'], 'script + style are the sn-desktop-workbench handles' );
ok( 'Migrations' === $args['main_tab_label'], 'main tab is Migrations' );
ok( array( 'manage_options' ) === $args['capabilities'], 'window is owner-gated via manage_options' );
ok( is_callable( $args['template'] ), 'window template is callable' );

$mig_html = tpl_output( $args['template'] );
foreach ( array( 'mig-count', 'mig-refresh', 'mig-list', 'mig-note' ) as $role ) {
	ok( false !== strpos( $mig_html, 'data-role="' . $role . '"' ), "migrations template declares the $role mount" );
}
ok( false !== strpos( $mig_html, 'data-surface="block-migrations"' ), 'migrations pane names its dismiss surface' );

// --- Tabs -----------------------------------------------------------------
$tabs = snt_desktop_workbench_tabs();
ok( array( 'patterns' ) === array_column( $tabs, 'value' ), 'one secondary tab: patterns' );
ok( is_callable( $tabs[0]['template'] ), 'patterns template is callable' );
$pat_html = tpl_output( $tabs[0]['template'] );
foreach ( array( 'pat-count', 'pat-refresh', 'pat-list', 'pat-note' ) as $role ) {
	ok( false !== strpos( $pat_html, 'data-role="' . $role . '"' ), "patterns template declares the $role mount" );
}
ok( false !== strpos( $pat_html, 'data-surface="pattern-adoption"' ), 'patterns pane names its dismiss surface' );

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

ok( true === snt_desktop_workbench_register(), 'registration returns true when Desktop Mode is present' );
ok( isset( $GLOBALS['__dm_windows']['sn-workbench'] ), 'window registered under the sn-workbench id' );
ok( 1 === count( $GLOBALS['__dm_tabs'] ) && 'sn-workbench' === $GLOBALS['__dm_tabs'][0]['window'], 'the patterns tab binds to sn-workbench' );
ok( isset( $GLOBALS['__dm_icons']['sn-workbench'] ) && 'sn-workbench' === ( $GLOBALS['__dm_icons']['sn-workbench']['window'] ?? '' ), 'desktop icon links the sn-workbench window' );

snt_desktop_workbench_register();
ok( 1 === count( $GLOBALS['__dm_tabs'] ), 'second registration call does not duplicate the tab' );

// --- Handle registration --------------------------------------------------
snt_desktop_workbench_register_assets();
ok( isset( $GLOBALS['__scripts']['sn-desktop-workbench'] ), 'script handle registered' );
ok( array( 'snt-ability-run' ) === $GLOBALS['__scripts']['sn-desktop-workbench']['deps'], 'the abilities client is the sole script dependency (all transport rides the run-path)' );
ok( isset( $GLOBALS['__styles']['sn-desktop-workbench'] ), 'style handle registered' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
