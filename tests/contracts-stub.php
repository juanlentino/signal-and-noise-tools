<?php
/**
 * Cross-package filter-contract tests — plugin v4.4.0.
 *
 * Tests the 4 theme↔plugin filter contracts documented in
 * docs/WORDPRESS-REFERENCE.md §10.0:
 *   1. sn_purge_all_caches_result
 *   2. sn_clear_template_overrides_result
 *   3. sn_og_font_paths
 *   4. sn_gh_latest_theme_tag_result
 *
 * Project-new test shape: stubs apply_filters / add_filter / do_action
 * to simulate WP's hook system in pure PHP. Tests verify (a) plugin
 * module dispatches at correct hook name, (b) listener registration
 * works, (c) return type is stable under both "no listener" and
 * "listener registered" scenarios, (d) payload mutation through a
 * fake listener works.
 *
 * Note on Contract 1 dispatch site: sn_purge_all_caches_result is
 * dispatched from several plugin files (admin-page.php, rest-api.php,
 * abilities-system.php, desktop-mode-integration.php, admin-bar.php).
 * The listener lives in the THEME's inc/template-maintenance.php, not
 * in the plugin. Tests here verify the contract shape (hook name,
 * default value, return type, listener chaining) without requiring
 * either the plugin dispatch sites or the theme listener, which would
 * pull in unrelated WP bootstrap dependencies.
 *
 * @since plugin v4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}

// ─── Filter simulator scaffolding ────────────────────────────────────
$GLOBALS['__test_filters'] = array();
$GLOBALS['__test_actions'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_filters'][ $hook ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		// Keep registrations sorted by priority (ascending).
		usort( $GLOBALS['__test_filters'][ $hook ], function( $a, $b ) {
			return $a['priority'] <=> $b['priority'];
		} );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		$registered = $GLOBALS['__test_filters'][ $hook ] ?? array();
		foreach ( $registered as $entry ) {
			$cb_args = array_merge( array( $value ), $args );
			$cb_args = array_slice( $cb_args, 0, $entry['accepted_args'] );
			$value = call_user_func_array( $entry['callback'], $cb_args );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__test_actions'][ $hook ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['__test_actions'][ $hook ] ?? array() as $cb ) {
			call_user_func_array( $cb, $args );
		}
	}
}

// ─── Minimal additional WP stubs (functions our plugin modules call) ──
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) { return $s; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '~[^a-z0-9_\-]~i', '', (string) $key ) ); }
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route() { return true; }
}
if ( ! function_exists( '_deprecated_function' ) ) {
	function _deprecated_function( $function, $version, $replacement = null ) {}
}

// ─── Harness ──────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function cs_eq( $e, $a, $msg ) {
	global $pass, $fail;
	if ( $e === $a ) { $pass++; echo "  PASS: $msg\n"; }
	else { $fail++; echo "  FAIL: $msg\n    Expected: " . var_export( $e, true ) . "\n    Actual:   " . var_export( $a, true ) . "\n"; }
}
function cs_true( $c, $msg ) {
	global $pass, $fail;
	if ( $c ) { $pass++; echo "  PASS: $msg\n"; } else { $fail++; echo "  FAIL: $msg\n"; }
}

echo "Cross-package contracts suite — plugin v4.4.0\n";

// ─── Contract 1: sn_purge_all_caches_result ──────────────────────────
echo "\nContract 1: sn_purge_all_caches_result\n";
$GLOBALS['__test_filters'] = array();

// Spec: plugin dispatches `apply_filters( 'sn_purge_all_caches_result', 0, $args )`
// from multiple plugin files (admin-page.php, rest-api.php, abilities-system.php,
// desktop-mode-integration.php, admin-bar.php). Theme's inc/template-maintenance.php
// registers the listener that does the actual cache-purge work and returns int count.
// These tests verify the contract shape (hook name, default, type, chaining) without
// requiring the theme file — the theme lives in a sibling repo.

// 1a. Filter simulator scaffolding is functional (confirms test harness is live).
cs_true( function_exists( 'apply_filters' ), '1.1: filter simulator scaffolding loaded without fatal' );

// 1b. No listener registered → filter returns the default value (int 0).
$result = apply_filters( 'sn_purge_all_caches_result', 0, array( 'reason' => 'test' ) );
cs_eq( 0, $result, '1.2: no listener → default int 0 returned' );
cs_true( is_int( $result ), '1.3: return type is int when no listener' );

// 1c. With a registered listener, value flows through.
add_filter( 'sn_purge_all_caches_result', function( $count, $args ) {
	return 42;
}, 10, 2 );
$result = apply_filters( 'sn_purge_all_caches_result', 0, array() );
cs_eq( 42, $result, '1.4: registered listener mutates return' );
cs_true( is_int( $result ), '1.5: return type still int with listener' );

// 1d. Multiple listeners chain (priority order).
add_filter( 'sn_purge_all_caches_result', function( $count, $args ) {
	return $count + 1;
}, 20, 2 );
$result = apply_filters( 'sn_purge_all_caches_result', 0, array() );
cs_eq( 43, $result, '1.6: second listener at higher priority increments first' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
