<?php
/**
 * Standalone fixture tests for the pre-publish advisory gate enqueue wiring
 * (inc/pre-publish-gate.php), plugin v4.11.0 Task 2.
 *
 * The user-visible behaviour lives in assets/pre-publish-gate.js (a
 * PluginPrePublishPanel that lists advisory warnings). JavaScript has no
 * test harness in this project, so this suite covers the PHP enqueue
 * contract ONLY — the JS logic is kept trivial + pure and validated by
 * `node --check` + a manual-UAT checklist (see the v4.11.0 verify gate).
 *
 * Asserts the admin_enqueue_scripts callback:
 *   - registers + enqueues `snt-pre-publish-gate` ONLY on post.php /
 *     post-new.php (no other hook suffix enqueues anything)
 *   - registers ONLY when current_user_can('edit_posts')
 *   - declares the editor JS deps (wp-plugins, wp-editor, wp-data,
 *     wp-element, wp-i18n)
 *   - points at assets/pre-publish-gate.js with SNT_VERSION, in_footer
 *   - does NOT gate on snt_ai_is_available() (no AI dependency)
 *
 * Run: php tests/pre-publish-gate.php
 *
 * @since plugin v4.11.0
 */

// SECURITY: Prevent web access. CLI / WP-CLI only.
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
define( 'SNT_VERSION', '4.11.0' );
define( 'SNT_PATH', '/var/www/plugins/sn/' );

// ─── WP stubs ─────────────────────────────────────────────────────────

// Capture the admin_enqueue_scripts callbacks so tests can fire them.
$GLOBALS['__test_aes_callbacks'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		if ( 'admin_enqueue_scripts' === $tag ) {
			$GLOBALS['__test_aes_callbacks'][] = $callback;
		}
		return true;
	}
}

// current_user_can — toggle which caps return true.
$GLOBALS['__test_caps'] = array( 'edit_posts' => true );
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['__test_caps'][ $cap ] );
	}
}

// snt_ai_is_available — if the gate ever calls this it MUST still enqueue
// regardless of the return value. We default it to false: an AI-gated
// implementation would then fail to enqueue, exposing the dependency.
$GLOBALS['__test_ai_available'] = false;
$GLOBALS['__test_ai_available_called'] = false;
if ( ! function_exists( 'snt_ai_is_available' ) ) {
	function snt_ai_is_available() {
		$GLOBALS['__test_ai_available_called'] = true;
		return ! empty( $GLOBALS['__test_ai_available'] );
	}
}

// Script registry capture.
$GLOBALS['__test_registered'] = array();
$GLOBALS['__test_enqueued']   = array();
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['__test_registered'][ $handle ] = array(
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		);
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) {
		$GLOBALS['__test_enqueued'][] = $handle;
		return true;
	}
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = null ) { return true; }
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.test/wp-content/plugins/sn/' . ltrim( (string) $path, '/' );
	}
}

require_once __DIR__ . '/../inc/pre-publish-gate.php';

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function pg_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}
function pg_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}

// Fire every captured admin_enqueue_scripts callback for a given hook
// suffix after resetting the registry capture. Returns nothing; tests read
// the $GLOBALS capture arrays afterwards.
function pg_fire( $hook_suffix ) {
	$GLOBALS['__test_registered'] = array();
	$GLOBALS['__test_enqueued']   = array();
	$GLOBALS['__test_ai_available_called'] = false;
	foreach ( $GLOBALS['__test_aes_callbacks'] as $cb ) {
		call_user_func( $cb, $hook_suffix );
	}
}

pg_true( ! empty( $GLOBALS['__test_aes_callbacks'] ), 'the file registered at least one admin_enqueue_scripts callback' );

// ─── Test 1: enqueues on post.php for an edit_posts user ──────────────
echo "\nTest 1: post.php + edit_posts → registers + enqueues\n";
$GLOBALS['__test_caps'] = array( 'edit_posts' => true );
pg_fire( 'post.php' );
pg_true( isset( $GLOBALS['__test_registered']['snt-pre-publish-gate'] ), 'snt-pre-publish-gate registered' );
pg_true( in_array( 'snt-pre-publish-gate', $GLOBALS['__test_enqueued'], true ), 'snt-pre-publish-gate enqueued' );

$reg = $GLOBALS['__test_registered']['snt-pre-publish-gate'];
pg_true( false !== strpos( $reg['src'], 'assets/pre-publish-gate.js' ), 'src points at assets/pre-publish-gate.js' );
pg_eq( '4.11.0', $reg['ver'], 'version is SNT_VERSION' );
pg_eq( true, $reg['in_footer'], 'enqueued in_footer' );
foreach ( array( 'wp-plugins', 'wp-editor', 'wp-data', 'wp-element', 'wp-i18n' ) as $dep ) {
	pg_true( in_array( $dep, $reg['deps'], true ), "deps include $dep" );
}

// ─── Test 2: also enqueues on post-new.php ────────────────────────────
echo "\nTest 2: post-new.php + edit_posts → registers + enqueues\n";
pg_fire( 'post-new.php' );
pg_true( isset( $GLOBALS['__test_registered']['snt-pre-publish-gate'] ), 'registered on post-new.php' );
pg_true( in_array( 'snt-pre-publish-gate', $GLOBALS['__test_enqueued'], true ), 'enqueued on post-new.php' );

// ─── Test 3: no enqueue on an unrelated screen ────────────────────────
echo "\nTest 3: edit.php (list table) → no enqueue\n";
pg_fire( 'edit.php' );
pg_true( ! isset( $GLOBALS['__test_registered']['snt-pre-publish-gate'] ), 'not registered on edit.php' );
pg_true( empty( $GLOBALS['__test_enqueued'] ), 'nothing enqueued on edit.php' );

echo "\nTest 3b: dashboard (index.php) → no enqueue\n";
pg_fire( 'index.php' );
pg_true( ! isset( $GLOBALS['__test_registered']['snt-pre-publish-gate'] ), 'not registered on index.php' );

// ─── Test 4: capability gate ──────────────────────────────────────────
echo "\nTest 4: post.php WITHOUT edit_posts → no enqueue\n";
$GLOBALS['__test_caps'] = array( 'edit_posts' => false );
pg_fire( 'post.php' );
pg_true( ! isset( $GLOBALS['__test_registered']['snt-pre-publish-gate'] ), 'not registered for a user lacking edit_posts' );
pg_true( empty( $GLOBALS['__test_enqueued'] ), 'nothing enqueued for a user lacking edit_posts' );

// ─── Test 5: AI availability MUST NOT gate the enqueue ────────────────
echo "\nTest 5: AI unavailable → STILL enqueues (no AI dependency)\n";
$GLOBALS['__test_caps'] = array( 'edit_posts' => true );
$GLOBALS['__test_ai_available'] = false; // AI down
pg_fire( 'post.php' );
pg_true( isset( $GLOBALS['__test_registered']['snt-pre-publish-gate'] ), 'enqueues even when snt_ai_is_available() is false' );
pg_true( false === $GLOBALS['__test_ai_available_called'], 'the gate never calls snt_ai_is_available() (client-side, no AI)' );

// ─── Test 6: v9.81.0 advisory additions (JS source pins) ──────────────
echo "\nTest 6: missing-alt + empty-excerpt advisories, still advisory-only\n";
$gate_js = (string) file_get_contents( __DIR__ . '/../assets/pre-publish-gate.js' );
pg_true( '' !== $gate_js, 'assets/pre-publish-gate.js readable' );
pg_true( false !== strpos( $gate_js, 'countImgsWithoutAlt' ), 'JS counts img tags without alt (missing-alt advisory)' );
pg_true( false !== strpos( $gate_js, '\\balt\\s*=' ), 'the alt detection mirrors sn_health_extract_inline_imgs_without_alt (any alt= attribute passes)' );
pg_true( false !== strpos( $gate_js, "getEditedPostAttribute( 'content' )" ), 'content is read via wp.data (getEditedPostAttribute), no network' );
pg_true( false !== strpos( $gate_js, "getEditedPostAttribute( 'excerpt' )" ), 'excerpt is read via wp.data (empty-excerpt advisory)' );
pg_true( false !== strpos( $gate_js, 'No excerpt set' ), 'the empty-excerpt advisory copy exists' );
pg_true( false === strpos( $gate_js, 'lockPostSaving(' ), 'the gate NEVER calls lockPostSaving — advisory only, forever (the docblock may name it, code may not)' );
pg_true( false === strpos( $gate_js, 'apiFetch' ), 'the gate makes zero network calls (wp.data only)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
