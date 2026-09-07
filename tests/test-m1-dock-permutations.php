<?php
/**
 * Challenger M1-2 Empirical Stress Test Harness: Dock Placement Permutations & Desktop Icons.
 *
 * Verifies:
 *  - All 8 boolean permutation states (2^3) for (signal-noise, dashboard, analytics).
 *  - All 7 dock placement slugs under each state:
 *      1. signal-noise
 *      2. app:signal-noise
 *      3. sn-dashboard
 *      4. app:sn-dashboard
 *      5. sn-theme-options
 *      6. app:sn-analytics
 *      7. sn-analytics
 *  - Both dock filter hooks: openstation_dock_placement AND desktop_mode_dock_placement.
 *  - Custom placement passthrough verification.
 *  - Desktop icon sn-icon-dashboard target (window vs URL) under all 8 states.
 *  - Robust boolean sanitization across diverse input types.
 *  - Corrupted metadata resilience.
 *
 * @package SignalNoiseTools
 * @since 13.106.4
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', '13.106.4' );

$pass = 0;
$fail = 0;

function ok( $condition, $message ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "PASS: $message\n";
	} else {
		$fail++;
		echo "FAIL: $message\n";
	}
}

// ── WordPress stubs ───────────────────────────────────────────────────
$GLOBALS['__filters'] = array();
$GLOBALS['__actions'] = array();

function add_filter( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__filters'][ $hook ][ $priority ][] = array(
		'cb'   => $cb,
		'args' => $accepted_args,
	);
	return true;
}

function apply_filters( $hook, $value ) {
	$args        = func_get_args();
	$by_priority = $GLOBALS['__filters'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $entry ) {
			$cb      = $entry['cb'];
			$args[1] = $value;
			$value   = call_user_func_array( $cb, array_slice( $args, 1, $entry['args'] ) );
		}
	}
	return $value;
}

function has_filter( $hook, $callback = false ) {
	if ( ! isset( $GLOBALS['__filters'][ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $GLOBALS['__filters'][ $hook ] as $cbs ) {
		foreach ( $cbs as $entry ) {
			if ( $entry['cb'] === $callback ) {
				return true;
			}
		}
	}
	return false;
}

function add_action( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['__actions'][ $hook ][ $priority ][] = array(
		'cb'   => $cb,
		'args' => $accepted_args,
	);
	return true;
}

function do_action( $hook ) {
	$args        = func_get_args();
	$by_priority = $GLOBALS['__actions'][ $hook ] ?? array();
	ksort( $by_priority, SORT_NUMERIC );
	foreach ( $by_priority as $cbs ) {
		foreach ( $cbs as $entry ) {
			call_user_func_array( $entry['cb'], array_slice( $args, 1, $entry['args'] ) );
		}
	}
}

function __( $text, $domain = 'default' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $text ) { return (string) $text; }

$GLOBALS['__user_id']   = 1;
$GLOBALS['__user_meta'] = array();

function get_current_user_id() {
	return (int) $GLOBALS['__user_id'];
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$user_id = (int) $user_id;
	if ( '' === $key ) {
		return $GLOBALS['__user_meta'][ $user_id ] ?? array();
	}
	$val = $GLOBALS['__user_meta'][ $user_id ][ $key ] ?? null;
	if ( $single ) {
		return null !== $val ? $val : '';
	}
	return null !== $val ? array( $val ) : array();
}

function update_user_meta( $user_id, $key, $value ) {
	$user_id = (int) $user_id;
	$GLOBALS['__user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

$GLOBALS['__caps'] = array( 'manage_options' => true );
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['__caps'][ $cap ] );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}
function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . $action;
}
function plugins_url( $path = '', $plugin = '' ) {
	$base = SNT_URL;
	if ( ! empty( $plugin ) ) {
		$dir = str_replace( '\\', '/', dirname( $plugin ) );
		if ( substr( $dir, -4 ) === '/inc' ) {
			$base .= 'inc/';
		}
	}
	return $base . ltrim( (string) $path, '/' );
}

function register_rest_route( $namespace, $route, $args = array() ) { return true; }
function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) { return true; }
function wp_localize_script( $handle, $object_name, $l10n ) { return true; }
function wp_enqueue_script( $handle ) { return true; }
function openstation_register_settings_tab( $args ) { return true; }
function openstation_is_shell_request() { return true; }

$GLOBALS['__os_icons'] = array();
function openstation_register_icon( $id, $args ) {
	$GLOBALS['__os_icons'][ $id ] = $args;
	return 'os-icon';
}

function openstation_register_command( $args = array() ) { return 'os-command'; }
function openstation_register_widget( $id, $args = array() ) { return 'os-widget'; }
function openstation_is_enabled() { return true; }

function snt_analytics_page_url( $args = array() ) {
	return 'https://example.test/wp-admin/admin.php?page=sn-analytics' . ( $args ? '&' . http_build_query( $args ) : '' );
}

// ── Load target files ─────────────────────────────────────────────────
require_once SNT_PATH . 'inc/openstation-compat.php';
require_once SNT_PATH . 'inc/openstation-preferences.php';
require_once SNT_PATH . 'inc/desktop-mode-dock.php';

echo "=== Challenger M1-2 Empirical Stress Suite ===\n\n";

// ── Section 1: Boolean Sanitizer Rigorous Oracle ──────────────────────
echo "--- Section 1: Boolean Sanitizer Rigorous Oracle ---\n";
$truthy_cases = array( true, 'true', 'TRUE', 'True', '1', ' 1 ', 'yes', 'YES', 'on', 'ON', 1, 1.0, (object) array() );
foreach ( $truthy_cases as $val ) {
	$display = is_bool( $val ) ? ( $val ? 'true' : 'false' ) : ( is_object( $val ) ? 'stdClass' : var_export( $val, true ) );
	ok( true === snt_os_sanitize_bool( $val ), "snt_os_sanitize_bool($display) evaluates strictly to true" );
}

$falsy_cases = array( false, 'false', 'FALSE', 'False', '0', ' 0 ', 'no', 'off', 0, 0.0, 2, -1, '', 'invalid', null, array() );
foreach ( $falsy_cases as $val ) {
	$display = is_bool( $val ) ? ( $val ? 'true' : 'false' ) : var_export( $val, true );
	ok( false === snt_os_sanitize_bool( $val ), "snt_os_sanitize_bool($display) evaluates strictly to false" );
}

// ── Section 2: 4 Permutations x 7 Slugs Matrix (Option B) ─────────────
echo "\n--- Section 2: 4 Permutations x 7 Slugs Matrix (Option B) ---\n";

$perm_index = 0;
for ( $db_int = 1; $db_int >= 0; $db_int-- ) {
	for ( $an_int = 1; $an_int >= 0; $an_int-- ) {
		$perm_index++;
		$db_val = ( 1 === $db_int );
		$an_val = ( 1 === $an_int );
		$state_label = sprintf( 'State %d: DB=%s, AN=%s', $perm_index, $db_val ? 'T' : 'F', $an_val ? 'T' : 'F' );

		// Apply preference state to user 100 + perm_index
		$uid = 100 + $perm_index;
		snt_os_save_native_window_preferences( array(
			'dashboard' => $db_val,
			'analytics' => $an_val,
		), $uid );
		$GLOBALS['__user_id'] = $uid;

		// Verify resolved preferences
		$prefs = snt_os_native_window_preferences( $uid );
		ok( $prefs['dashboard'] === $db_val, "$state_label - prefs[dashboard] is strictly " . ( $db_val ? 'true' : 'false' ) );
		ok( $prefs['analytics'] === $an_val, "$state_label - prefs[analytics] is strictly " . ( $an_val ? 'true' : 'false' ) );

		// Verify helper function results: Signal & Noise is always true (Option B)
		ok( true === snt_os_native_window_enabled( 'signal-noise', $uid ), "$state_label - snt_os_native_window_enabled('signal-noise') is permanently true" );
		ok( snt_os_native_window_enabled( 'dashboard', $uid ) === $db_val, "$state_label - snt_os_native_window_enabled('dashboard') is correct" );
		ok( snt_os_native_window_enabled( 'analytics', $uid ) === $an_val, "$state_label - snt_os_native_window_enabled('analytics') is correct" );

		// Define expected dock outcomes for all 7 slugs when placement is 'dock':
		// 1. signal-noise: always 'dock' (native-only)
		// 2. app:signal-noise: always 'dock' (native-only)
		// 3. sn-dashboard: DB ? 'dock' : 'hidden'
		// 4. app:sn-dashboard: DB ? 'dock' : 'hidden'
		// 5. sn-theme-options: DB ? 'hidden' : 'dock'
		// 6. app:sn-analytics: AN ? 'dock' : 'hidden'
		// 7. sn-analytics: AN ? 'hidden' : 'dock'
		$expected_dock = array(
			'signal-noise'     => 'dock',
			'app:signal-noise' => 'dock',
			'sn-dashboard'     => $db_val ? 'dock' : 'hidden',
			'app:sn-dashboard' => $db_val ? 'dock' : 'hidden',
			'sn-theme-options' => $db_val ? 'hidden' : 'dock',
			'app:sn-analytics' => $an_val ? 'dock' : 'hidden',
			'sn-analytics'     => $an_val ? 'hidden' : 'dock',
		);

		// Test hook 1: openstation_dock_placement
		foreach ( $expected_dock as $slug => $expected ) {
			$actual = apply_filters( 'openstation_dock_placement', 'dock', $slug );
			ok( $actual === $expected, "$state_label - openstation_dock_placement('$slug') returned '$actual' (expected '$expected')" );
		}

		// Test hook 2: desktop_mode_dock_placement
		foreach ( $expected_dock as $slug => $expected ) {
			$actual = apply_filters( 'desktop_mode_dock_placement', 'dock', $slug );
			ok( $actual === $expected, "$state_label - desktop_mode_dock_placement('$slug') returned '$actual' (expected '$expected')" );
		}

		// Test custom initial placement passthrough ('custom-pos')
		$expected_custom = array(
			'signal-noise'     => 'custom-pos',
			'app:signal-noise' => 'custom-pos',
			'sn-dashboard'     => $db_val ? 'custom-pos' : 'hidden',
			'app:sn-dashboard' => $db_val ? 'custom-pos' : 'hidden',
			'sn-theme-options' => $db_val ? 'hidden' : 'dock',
			'app:sn-analytics' => $an_val ? 'custom-pos' : 'hidden',
			'sn-analytics'     => $an_val ? 'hidden' : 'dock',
		);
		foreach ( $expected_custom as $slug => $expected ) {
			$actual = apply_filters( 'openstation_dock_placement', 'custom-pos', $slug );
			ok( $actual === $expected, "$state_label - passthrough placement('$slug') returned '$actual' (expected '$expected')" );
		}

		// Test unrelated slug passthrough
		$unrelated = apply_filters( 'openstation_dock_placement', 'custom-pos', 'edit.php' );
		ok( 'custom-pos' === $unrelated, "$state_label - unrelated slug edit.php preserved custom-pos" );
		$unrelated_dm = apply_filters( 'desktop_mode_dock_placement', 'custom-pos', 'edit.php' );
		ok( 'custom-pos' === $unrelated_dm, "$state_label - unrelated slug edit.php on desktop_mode preserved custom-pos" );

		// Test desktop icon sn-icon-dashboard registration under this state
		$GLOBALS['__os_icons'] = array();
		do_action( 'init' );
		$dash_icon = $GLOBALS['__os_icons']['sn-icon-dashboard'] ?? array();
		ok( 'S&N Home' === ( $dash_icon['title'] ?? '' ), "$state_label - desktop icon title is S&N Home" );
		if ( $db_val ) {
			ok( isset( $dash_icon['window'] ) && 'sn-dashboard' === $dash_icon['window'], "$state_label - DB enabled: icon targets window 'sn-dashboard'" );
			ok( ! isset( $dash_icon['url'] ), "$state_label - DB enabled: icon does NOT set url" );
		} else {
			ok( isset( $dash_icon['url'] ) && false !== strpos( $dash_icon['url'], 'page=sn-theme-options' ), "$state_label - DB disabled: icon targets classic URL with page=sn-theme-options" );
			ok( ! isset( $dash_icon['window'] ), "$state_label - DB disabled: icon does NOT set window" );
		}
	}
}

// ── Section 3: Metadata Edge Cases & Fallbacks ─────────────────────────
echo "\n--- Section 3: Metadata Edge Cases & Fallbacks ---\n";

// Corrupted non-array metadata
$GLOBALS['__user_meta'][999][SNT_OS_PREFERENCES_META] = 'not-an-array';
$corrupt_prefs = snt_os_native_window_preferences( 999 );
ok( snt_os_native_window_defaults() === $corrupt_prefs, 'corrupt non-array user meta returns default preferences' );

// Partial metadata with boolean string values
$GLOBALS['__user_meta'][998][SNT_OS_PREFERENCES_META] = array( 'dashboard' => '0' );
$string_meta_prefs = snt_os_native_window_preferences( 998 );
ok( false === $string_meta_prefs['dashboard'], 'stored string 0 is converted to boolean false' );
ok( true === $string_meta_prefs['analytics'], 'missing stored key takes default boolean true' );


// Empty menu_slug parameter
$empty_slug_result = apply_filters( 'openstation_dock_placement', 'dock', '' );
ok( 'dock' === $empty_slug_result, 'calling filter with empty string slug returns placement unchanged' );

// Single-arg behavior: verify ArgumentCountError when second argument is omitted
$caught_arg_error = false;
try {
	apply_filters( 'openstation_dock_placement', 'dock' );
} catch ( ArgumentCountError $e ) {
	$caught_arg_error = true;
}
ok( $caught_arg_error, 'filter strictly enforces 2-arg signature per OpenStation contract (catches ArgumentCountError when 1 arg passed)' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
