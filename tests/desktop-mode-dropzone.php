<?php
/**
 * Standalone tests for Desktop Mode drop-to-draft
 * (inc/desktop-mode-dropzone.php).
 *
 * The module's whole PHP surface is one gated enqueue: shell pages
 * only (desktop_mode_is_enabled, per-user), edit_posts only (drops
 * create draft posts), wp-hooks + wp-api-fetch deps (the JS registers
 * a filter on the shell's drop pipeline and drafts via core REST).
 *
 * @since plugin v9.77.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }

// --- WP stubs -------------------------------------------------------------
if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; } }
if ( ! function_exists( 'plugins_url' ) ) { function plugins_url( $path = '', $plugin = '' ) { return 'https://example.test/wp-content/plugins/signal-and-noise-tools/' . ltrim( $path, '/' ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__enqueued'][ $handle ] = array( 'src' => $src, 'deps' => $deps ); return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return ! empty( $GLOBALS['__caps'][ $cap ] ); } }
if ( ! defined( 'SNT_VERSION' ) ) { define( 'SNT_VERSION', '0.0.0-test' ); }
if ( ! defined( 'SNT_PATH' ) ) { define( 'SNT_PATH', '/tmp/' ); }

$GLOBALS['__actions']  = array();
$GLOBALS['__enqueued'] = array();
$GLOBALS['__caps']     = array();

// v10.43.0: desktop-mode-dropzone.php now gates on snt_os_is_enabled()
// (inc/openstation-compat.php) instead of a raw desktop_mode_is_enabled()
// function_exists() check.
require __DIR__ . '/../inc/openstation-compat.php';
require __DIR__ . '/../inc/desktop-mode-dropzone.php';

$pass = 0; $fail = 0;
function ok( $c, $m ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS: $m\n"; } else { $fail++; echo "FAIL: $m\n"; } }
echo "desktop-mode-dropzone — v9.77.0\n\n";

// --- Gate 1: Desktop Mode absent -----------------------------------------
ok( false === snt_desktop_dropzone_enqueue(), 'no enqueue when Desktop Mode is absent' );
ok( array() === $GLOBALS['__enqueued'], 'nothing enqueued without Desktop Mode' );

// --- Gate 2: DM present but user disabled it -----------------------------
// Conditionally declared: parse-time hoisting would falsify Gate 1.
if ( ! function_exists( 'desktop_mode_is_enabled' ) ) {
	function desktop_mode_is_enabled() { return ! empty( $GLOBALS['__dm_enabled'] ); }
}
$GLOBALS['__dm_enabled'] = false;
ok( false === snt_desktop_dropzone_enqueue(), 'no enqueue when the user has Desktop Mode off (per-user opt-in)' );

// --- Gate 3: DM on, but the user cannot create posts ---------------------
$GLOBALS['__dm_enabled'] = true;
$GLOBALS['__caps']       = array();
ok( false === snt_desktop_dropzone_enqueue(), 'no enqueue without edit_posts (drops create drafts)' );
ok( array() === $GLOBALS['__enqueued'], 'still nothing enqueued' );

// --- All gates pass -------------------------------------------------------
$GLOBALS['__caps'] = array( 'edit_posts' => true );
ok( true === snt_desktop_dropzone_enqueue(), 'enqueues for a DM-enabled user who can edit posts' );
ok( isset( $GLOBALS['__enqueued']['sn-desktop-dropzone'] ), 'sn-desktop-dropzone handle enqueued' );
$deps = $GLOBALS['__enqueued']['sn-desktop-dropzone']['deps'] ?? array();
ok( in_array( 'wp-hooks', $deps, true ), 'depends on wp-hooks (the drop pipeline is a JS filter)' );
ok( in_array( 'wp-api-fetch', $deps, true ), 'depends on wp-api-fetch (drafts via core REST)' );

// --- v9.81.0: byte ceiling (JS source pins) ------------------------------
// The files-detected filter fires BEFORE the shell's MIME/size gate and
// FileReader reads whole files — above the ceiling the file must pass
// through to the shell's own gate instead of being read unbounded here.
$dz = (string) file_get_contents( dirname( __DIR__ ) . '/assets/desktop-dropzone.js' );
ok( '' !== $dz, 'assets/desktop-dropzone.js readable' );
ok( false !== strpos( $dz, 'MAX_BYTES = 2 * 1024 * 1024' ), 'a named 2MB byte ceiling exists' );
ok( false !== strpos( $dz, '( file.size || 0 ) <= MAX_BYTES' ), 'the claim branch checks file.size BEFORE any read' );
ok( strpos( $dz, 'MAX_BYTES' ) < strpos( $dz, 'readAsText' ), 'the ceiling gates ahead of the unbounded readAsText' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
