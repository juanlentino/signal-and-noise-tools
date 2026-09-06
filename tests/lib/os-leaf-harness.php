<?php
/**
 * Shared harness for the native-window leaf suites (tests/os-leaf-*.php).
 *
 * NOT a suite (tests/run.sh globs tests/*.php non-recursively). A leaf suite
 * requires this file FIRST, then declares the stubs its own leaf's readers
 * need, then requires the leaf's classic renderer file(s) and its painter
 * file, then asserts through the helpers below.
 *
 * What it provides:
 *  - the WP stubs every painter and classic renderer touches (escaping, i18n,
 *    nonces, admin_url, formatting, hooks with a working filter chain);
 *  - the five kit helper files and the Dashboard app's frame (painters()),
 *    so `snt_leaf_paint( $tab, $sub )` runs the registered painter;
 *  - `snt_leaf_classic_html( $callable )`: the classic leaf's HTML, captured;
 *  - `snt_leaf_names( $html )`: every `name="…"` in a markup blob (both the
 *    classic form and the kit form carry them), and `snt_leaf_actions( $html )`:
 *    every `sn_action` value — the faithfulness oracle a suite compares.
 *
 * @since 13.106.0
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'SNT_PATH' ) ) {
	define( 'SNT_PATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'SNT_VERSION' ) ) {
	define( 'SNT_VERSION', '13.106.0-test' );
}
if ( ! defined( 'SNT_URL' ) ) {
	define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'SNT_OS_HOST_NONCE' ) ) {
	define( 'SNT_OS_HOST_NONCE', 'sn_theme_options_nonce' );
}

// ── Hooks: a real filter chain, so painters that register through a filter
// are reachable, and actions are recorded.
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $hook ][ $p ][] = $cb; return true; }
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$args = func_get_args();
		$by_priority = $GLOBALS['__filters'][ $hook ] ?? array();
		ksort( $by_priority, SORT_NUMERIC );
		foreach ( $by_priority as $cbs ) {
			foreach ( $cbs as $cb ) {
				$args[1] = $value;
				$value   = call_user_func_array( $cb, array_slice( $args, 1 ) );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $hook ][] = $cb; return true; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {
		$args = array_slice( func_get_args(), 1 );
		foreach ( $GLOBALS['__actions'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); }
	}
}
if ( ! function_exists( 'has_action' ) ) { function has_action( $hook ) { return ! empty( $GLOBALS['__actions'][ $hook ] ); } }

// ── Escaping + i18n + formatting.
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return esc_html( $s ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return esc_attr( $s ); } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $s, $d = null ) { echo esc_html( $s ); } }
if ( ! function_exists( 'esc_attr_e' ) ) { function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $allowed = array() ) { return (string) $s; } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_e' ) ) { function _e( $s, $d = null ) { echo $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; } }
if ( ! function_exists( '_x' ) ) { function _x( $s, $c, $d = null ) { return $s; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $dec = 0 ) { return number_format( (float) $n, (int) $dec ); } }
if ( ! function_exists( 'human_time_diff' ) ) { function human_time_diff( $from, $to = 0 ) { return '1 hour'; } }
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); } }
if ( ! function_exists( 'date_i18n' ) ) { function date_i18n( $f, $ts = null ) { return gmdate( $f, null === $ts ? time() : (int) $ts ); } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : parse_url( $u, $c ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = -1 ) { return 'nonce-' . $a; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $echo = true ) { $h = '<input type="hidden" name="' . $n . '" value="nonce-' . $a . '">'; if ( $echo ) { echo $h; } return $h; } }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return $u . '&' . $n . '=nonce-' . $a; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( $p, '/' ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'site_url' ) ) { function site_url( $p = '' ) { return 'https://example.test' . $p; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg() { $a = func_get_args(); if ( is_array( $a[0] ) ) { $u = $a[1] ?? ''; $q = $a[0]; } else { $u = $a[2] ?? ''; $q = array( $a[0] => $a[1] ); } return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $q ); } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return true; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( $text = 'Save', $type = 'primary', $name = 'submit', $wrap = true, $other = null ) { echo '<p class="submit"><input type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ) . '"></p>'; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $echo = true ) { $h = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $echo ) { echo $h; } return $h; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $echo = true ) { $h = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $echo ) { echo $h; } return $h; } }
if ( ! function_exists( 'disabled' ) ) { function disabled( $a, $b = true, $echo = true ) { $h = ( (string) $a === (string) $b ) ? ' disabled="disabled"' : ''; if ( $echo ) { echo $h; } return $h; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
if ( ! function_exists( 'size_format' ) ) { function size_format( $b, $d = 0 ) { return $b . ' B'; } }
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['__options'] = $GLOBALS['__options'] ?? array();
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $k ] : $d; }
}
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }

// ── The kit, and the Dashboard frame (painters(), leaves_for()).
foreach ( array( 'openstation-kit', 'openstation-kit-display', 'openstation-kit-data', 'openstation-kit-forms', 'openstation-kit-triggers' ) as $snt_kit_file ) {
	require_once SNT_PATH . 'inc/' . $snt_kit_file . '.php';
}
require_once SNT_PATH . 'inc/admin-tabs-data.php';
require_once SNT_PATH . 'apps/sn-dashboard/parts/nav.php';
require_once SNT_PATH . 'apps/sn-dashboard/parts/frame.php';

/**
 * Run the registered painter for a leaf. The context carries a State stand-in
 * that answers get() from the array you pass.
 *
 * @param string $tab   Top tab.
 * @param string $sub   Leaf ('' for a landing tab).
 * @param array  $state State values (sub, anchor, flash, notice, params, post).
 * @return string
 */
function snt_leaf_paint( $tab, $sub, array $state = array() ) {
	$painters = \SignalNoise\OpenStationHost\Dashboard\painters();
	$key      = $tab . '/' . $sub;
	if ( ! isset( $painters[ $key ] ) ) {
		return '';
	}
	$stub = new class( $state ) {
		private $v;
		public function __construct( array $v ) { $this->v = $v; }
		public function get( $k ) { return $this->v[ $k ] ?? null; }
		public function set( $k, $x ) { $this->v[ $k ] = $x; return $this; }
	};
	return (string) call_user_func( $painters[ $key ], array( 'tab' => $tab, 'sub' => $sub, 'state' => $stub, 'os' => null ) );
}

/** @param callable $render The classic leaf renderer. @return string */
function snt_leaf_classic_html( callable $render ) {
	ob_start();
	$returned = call_user_func( $render );
	return (string) ob_get_clean() . ( is_string( $returned ) ? $returned : '' );
}

/** @param string $html Markup. @return string[] Sorted unique `name` attributes. */
function snt_leaf_names( $html ) {
	preg_match_all( '/\sname=(["\'])([^"\']+)\1/', (string) $html, $m );
	$names = array_values( array_unique( array_map( 'html_entity_decode', $m[2] ) ) );
	sort( $names );
	return $names;
}

/** @param string $html Markup. @return string[] Sorted unique sn_action values (hidden fields, submit buttons, os-arg-action). */
function snt_leaf_actions( $html ) {
	preg_match_all( '/name=(["\'])sn_action\1[^>]*value=(["\'])([^"\']+)\2|value=(["\'])([^"\']+)\4[^>]*name=(["\'])sn_action\6|os-arg-action=(["\'])([^"\']+)\7/', (string) $html, $m );
	$out = array_values( array_unique( array_filter( array_merge( $m[3], $m[5], $m[8] ) ) ) );
	sort( $out );
	return $out;
}

/** Classic markup the kit port must not carry. @param string $html @return string[] Offending markers found. */
function snt_leaf_classic_markers( $html ) {
	$markers = array( 'class="widefat', 'class="form-table', 'class="button', 'class="notice', 'class="sn-fieldset', 'class="sn-card', 'class="nav-tab', 'class="sn-sub-tabs', '<table', '<script' );
	$found   = array();
	foreach ( $markers as $marker ) {
		if ( false !== stripos( (string) $html, $marker ) ) {
			$found[] = $marker;
		}
	}
	return $found;
}
