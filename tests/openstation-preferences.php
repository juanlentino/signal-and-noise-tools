<?php
/**
 * Standalone fixture test suite for OpenStation Preferences Toggles (Option B).
 *
 * Verifies:
 *  - Preference defaults: all 3 enabled (signal-noise, dashboard, analytics).
 *  - Persistence and getter/helper/saver functions in inc/openstation-preferences.php.
 *  - Partial updates, sanitization, and user isolation.
 *  - REST API endpoint registration, GET/POST handlers, and manage_options capability gating.
 *  - Dock placement filtering: classic menu hidden when native enabled; classic menu visible
 *    and native window hidden when native disabled.
 *  - Desktop icon registration for sn-icon-dashboard: window target when enabled, classic URL when disabled.
 *  - Settings tab registration and script enqueue in shell requests.
 *
 * Run: php tests/openstation-preferences.php
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

// ── Test harness ───────────────────────────────────────────────────────
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

function has_action( $hook, $callback = false ) {
	if ( ! isset( $GLOBALS['__actions'][ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $GLOBALS['__actions'][ $hook ] as $cbs ) {
		foreach ( $cbs as $entry ) {
			if ( $entry['cb'] === $callback ) {
				return true;
			}
		}
	}
	return false;
}

// ── i18n & escaping stubs ─────────────────────────────────────────────
function __( $text, $domain = 'default' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $text ) { return (string) $text; }

// ── User and user-meta stubs ──────────────────────────────────────────
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

// ── Capabilities stub ─────────────────────────────────────────────────
$GLOBALS['__caps'] = array( 'manage_options' => true );
function current_user_can( $cap ) {
	return ! empty( $GLOBALS['__caps'][ $cap ] );
}

// ── REST API stubs ────────────────────────────────────────────────────
$GLOBALS['__rest_routes'] = array();

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = (int) $status;
	}
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

class WP_REST_Request {
	private $json_params;
	public function __construct( $json_params = array() ) {
		$this->json_params = $json_params;
	}
	public function get_json_params() {
		return $this->json_params;
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function rest_ensure_response( $response ) {
	if ( $response instanceof WP_REST_Response || $response instanceof WP_Error ) {
		return $response;
	}
	return new WP_REST_Response( $response );
}

function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['__rest_routes'][ $namespace ][ $route ] = $args;
	return true;
}

// ── URL & Nonce stubs ─────────────────────────────────────────────────
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

// ── Scripts stubs ─────────────────────────────────────────────────────
$GLOBALS['__scripts']           = array();
$GLOBALS['__localized_scripts'] = array();
$GLOBALS['__enqueued_scripts']  = array();

function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['__scripts'][ $handle ] = array(
		'src'       => $src,
		'deps'      => $deps,
		'ver'       => $ver,
		'in_footer' => $in_footer,
	);
	return true;
}

function wp_localize_script( $handle, $object_name, $l10n ) {
	$GLOBALS['__localized_scripts'][ $handle ][ $object_name ] = $l10n;
	return true;
}

function wp_enqueue_script( $handle ) {
	$GLOBALS['__enqueued_scripts'][ $handle ] = true;
	return true;
}

// ── OpenStation stubs ─────────────────────────────────────────────────
$GLOBALS['__os_settings_tabs'] = array();
function openstation_register_settings_tab( $args ) {
	$GLOBALS['__os_settings_tabs'][] = $args;
	return true;
}

$GLOBALS['__os_shell_request'] = true;
function openstation_is_shell_request() {
	return ! empty( $GLOBALS['__os_shell_request'] );
}

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

echo "openstation-preferences — Option B Unit Tests\n\n";

// ── Group 1: Default Preferences ──────────────────────────────────────
echo "Group 1: Default preferences\n";
$defaults = snt_os_native_window_defaults();
ok( is_array( $defaults ), 'snt_os_native_window_defaults() returns an array' );
ok( array( 'signal-noise', 'dashboard', 'analytics' ) === array_keys( $defaults ), 'defaults has exact keys: signal-noise, dashboard, analytics' );
ok( true === $defaults['signal-noise'] && true === $defaults['dashboard'] && true === $defaults['analytics'], 'all default values are boolean true' );

$user_prefs = snt_os_native_window_preferences( 99 );
ok( $defaults === $user_prefs, 'fresh user without stored meta receives defaults' );

$current_prefs = snt_os_native_window_preferences();
ok( $defaults === $current_prefs, 'calling without user id defaults to current user defaults' );

$GLOBALS['__user_id'] = 0;
$zero_prefs = snt_os_native_window_preferences( 0 );
ok( $defaults === $zero_prefs, 'user_id 0 and unauthenticated returns defaults' );
$GLOBALS['__user_id'] = 1;

ok( true === snt_os_native_window_enabled( 'signal-noise', 99 ), 'helper: signal-noise is enabled by default' );
ok( true === snt_os_native_window_enabled( 'dashboard', 99 ), 'helper: dashboard is enabled by default' );
ok( true === snt_os_native_window_enabled( 'analytics', 99 ), 'helper: analytics is enabled by default' );
ok( false === snt_os_native_window_enabled( 'non-existent', 99 ), 'helper: non-existent key returns false' );

// ── Group 2: Persistence and User Meta Storage ────────────────────────
echo "\nGroup 2: Persistence and user meta storage\n";
ok( defined( 'SNT_OS_PREFERENCES_META' ) && '_snt_os_native_windows' === SNT_OS_PREFERENCES_META, 'SNT_OS_PREFERENCES_META constant equals _snt_os_native_windows' );

$saved = snt_os_save_native_window_preferences( array( 'dashboard' => false ), 10 );
ok( false === $saved['dashboard'] && true === $saved['analytics'] && true === $saved['signal-noise'], 'snt_os_save_native_window_preferences() returns merged preferences' );

$meta_stored = get_user_meta( 10, SNT_OS_PREFERENCES_META, true );
ok( is_array( $meta_stored ) && false === $meta_stored['dashboard'], 'update_user_meta stored updated array under _snt_os_native_windows' );

$retrieved = snt_os_native_window_preferences( 10 );
ok( false === $retrieved['dashboard'] && true === $retrieved['analytics'] && true === $retrieved['signal-noise'], 'subsequent snt_os_native_window_preferences(10) returns persisted changes' );
ok( false === snt_os_native_window_enabled( 'dashboard', 10 ), 'snt_os_native_window_enabled reflects saved false state' );

// ── Group 3: Partial Updates and Key Sanitization ─────────────────────
echo "\nGroup 3: Partial updates and key sanitization\n";
$step2 = snt_os_save_native_window_preferences( array( 'analytics' => false ), 10 );
ok( false === $step2['dashboard'] && false === $step2['analytics'] && true === $step2['signal-noise'], 'cumulative partial update preserves previously saved preferences' );

$step_single = snt_os_save_native_window_preferences( array( 'signal-noise' => false ), 10 );
ok( false === $step_single['signal-noise'] && false === $step_single['dashboard'] && false === $step_single['analytics'], 'single toggle update preserves other two preferences' );

$step3 = snt_os_save_native_window_preferences( array( 'unknown_window' => true, 'invalid_flag' => false ), 10 );
ok( array( 'signal-noise', 'dashboard', 'analytics' ) === array_keys( $step3 ), 'saving unknown keys does not pollute preferences array' );

$step4 = snt_os_save_native_window_preferences( array( 'dashboard' => 1, 'analytics' => 0, 'signal-noise' => true ), 10 );
ok( true === $step4['dashboard'] && false === $step4['analytics'] && true === $step4['signal-noise'], 'integer inputs 1 and 0 are strictly coerced to booleans' );

$empty_patch = snt_os_save_native_window_preferences( array(), 10 );
ok( true === $empty_patch['dashboard'] && false === $empty_patch['analytics'] && true === $empty_patch['signal-noise'], 'empty patch preserves existing preferences without alteration' );

// User isolation
$user1_prefs = snt_os_native_window_preferences( 10 );
$user2_prefs = snt_os_native_window_preferences( 20 );
ok( true === $user1_prefs['dashboard'] && false === $user1_prefs['analytics'] && true === $user1_prefs['signal-noise'], 'user 10 preferences intact' );
ok( true === $user2_prefs['dashboard'] && true === $user2_prefs['analytics'] && true === $user2_prefs['signal-noise'], 'user 20 still has defaults, isolated from user 10' );

// ── Group 4: REST API Endpoint Registration and Capability Check ──────
echo "\nGroup 4: REST API registration and capabilities\n";
ok( has_action( 'rest_api_init', 'snt_os_register_preferences_rest' ), 'rest_api_init action is hooked to snt_os_register_preferences_rest' );

snt_os_register_preferences_rest();
ok( isset( $GLOBALS['__rest_routes']['signal-noise/v1']['/openstation/preferences'] ), 'route signal-noise/v1 /openstation/preferences is registered' );

$route_defs = $GLOBALS['__rest_routes']['signal-noise/v1']['/openstation/preferences'];
ok( count( $route_defs ) >= 2, 'route defines both GET and POST endpoints' );

$methods = array_column( $route_defs, 'methods' );
ok( in_array( 'GET', $methods, true ) && in_array( 'POST', $methods, true ), 'endpoints contain GET and POST methods' );

$get_def = null;
$post_def = null;
foreach ( $route_defs as $def ) {
	if ( 'GET' === $def['methods'] ) { $get_def = $def; }
	if ( 'POST' === $def['methods'] ) { $post_def = $def; }
}
ok( 'snt_os_preferences_rest_get' === ( $get_def['callback'] ?? '' ), 'GET endpoint callback is snt_os_preferences_rest_get' );
ok( 'snt_os_preferences_rest_permission' === ( $get_def['permission_callback'] ?? '' ), 'GET endpoint permission is snt_os_preferences_rest_permission' );
ok( 'snt_os_preferences_rest_update' === ( $post_def['callback'] ?? '' ), 'POST endpoint callback is snt_os_preferences_rest_update' );
ok( 'snt_os_preferences_rest_permission' === ( $post_def['permission_callback'] ?? '' ), 'POST endpoint permission is snt_os_preferences_rest_permission' );

$GLOBALS['__caps'] = array( 'manage_options' => true );
ok( true === snt_os_preferences_rest_permission(), 'permission check passes when user has manage_options' );

$GLOBALS['__caps'] = array( 'manage_options' => false );
ok( false === snt_os_preferences_rest_permission(), 'permission check fails when user lacks manage_options' );

$GLOBALS['__caps'] = array( 'edit_posts' => true );
ok( false === snt_os_preferences_rest_permission(), 'permission check fails for non-admin user with edit_posts only' );
$GLOBALS['__caps'] = array( 'manage_options' => true );

// ── Group 5: REST API Handlers ────────────────────────────────────────
echo "\nGroup 5: REST API GET & POST handlers\n";
$GLOBALS['__user_id'] = 1;
snt_os_save_native_window_preferences( array( 'dashboard' => true, 'analytics' => true, 'signal-noise' => true ), 1 );

$get_response = snt_os_preferences_rest_get();
ok( $get_response instanceof WP_REST_Response, 'GET handler returns WP_REST_Response' );
ok( 200 === $get_response->get_status(), 'GET handler returns HTTP 200' );
ok( array( 'signal-noise' => true, 'dashboard' => true, 'analytics' => true ) === $get_response->get_data(), 'GET handler returns current preferences' );

$post_req = new WP_REST_Request( array( 'dashboard' => false ) );
$post_response = snt_os_preferences_rest_update( $post_req );
ok( $post_response instanceof WP_REST_Response, 'POST handler returns WP_REST_Response on valid update' );
ok( 200 === $post_response->get_status(), 'POST handler returns HTTP 200' );
$post_data = $post_response->get_data();
ok( false === $post_data['dashboard'] && true === $post_data['analytics'] && true === $post_data['signal-noise'], 'POST handler updates and returns modified preferences' );

$invalid_req = new WP_REST_Request( 'invalid string payload' );
$invalid_response = snt_os_preferences_rest_update( $invalid_req );
ok( is_wp_error( $invalid_response ), 'POST handler returns WP_Error on non-array input' );
ok( 'rest_invalid_json' === $invalid_response->get_error_code(), 'error code is rest_invalid_json' );
ok( 400 === ( $invalid_response->get_error_data()['status'] ?? 0 ), 'error status is HTTP 400' );

// ── Group 6: Dock Placement Filtering ─────────────────────────────────
echo "\nGroup 6: Dock placement filtering\n";
ok( has_filter( 'openstation_dock_placement' ) && has_filter( 'desktop_mode_dock_placement' ), 'dock placement filter is dual-registered under openstation_* and desktop_mode_*' );
ok( has_filter( 'openstation_app_window_args' ) && has_filter( 'desktop_mode_app_window_args' ), 'native app placement filter is dual-registered without altering the app registry' );

// Enabled state
snt_os_save_native_window_preferences( array( 'dashboard' => true, 'analytics' => true, 'signal-noise' => true ), 1 );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'sn-theme-options' ), 'dashboard enabled: sn-theme-options classic menu is hidden' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'sn-analytics' ), 'analytics enabled: sn-analytics classic menu is hidden' );
ok( 'dock' === apply_filters( 'openstation_dock_placement', 'dock', 'signal-noise' ), 'signal-noise enabled: app tile remains dock' );
ok( 'dock' === apply_filters( 'openstation_dock_placement', 'dock', 'app:signal-noise' ), 'app:signal-noise enabled: app tile remains dock' );
ok( 'dock' === apply_filters( 'openstation_dock_placement', 'dock', 'other-page' ), 'unrelated slug is passed through unchanged' );
ok( 'hidden' === apply_filters( 'desktop_mode_dock_placement', 'dock', 'sn-theme-options' ), 'dual-hook: desktop_mode_dock_placement hides sn-theme-options when enabled' );
$native_args = array( 'placement' => 'dock' );
ok( 'dock' === apply_filters( 'openstation_app_window_args', $native_args, 'signal-noise' )['placement'], 'signal-noise enabled: App Framework launcher stays on dock' );
ok( 'dock' === apply_filters( 'openstation_app_window_args', $native_args, 'sn-dashboard' )['placement'], 'dashboard enabled: App Framework launcher stays on dock' );
ok( 'dock' === apply_filters( 'openstation_app_window_args', $native_args, 'sn-analytics' )['placement'], 'analytics enabled: App Framework launcher stays on dock' );

// Disabled state
snt_os_save_native_window_preferences( array( 'dashboard' => false, 'analytics' => false, 'signal-noise' => false ), 1 );
ok( 'dock' === apply_filters( 'openstation_dock_placement', 'dock', 'sn-theme-options' ), 'dashboard disabled: sn-theme-options classic menu appears on dock' );
ok( 'dock' === apply_filters( 'openstation_dock_placement', 'dock', 'sn-analytics' ), 'analytics disabled: sn-analytics classic menu appears on dock' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'signal-noise' ), 'signal-noise disabled: tile is hidden' );
ok( 'hidden' === apply_filters( 'openstation_dock_placement', 'dock', 'app:signal-noise' ), 'app:signal-noise disabled: tile is hidden' );
ok( 'none' === apply_filters( 'openstation_app_window_args', $native_args, 'signal-noise' )['placement'], 'signal-noise disabled: registered app launcher placement is none' );
ok( 'none' === apply_filters( 'openstation_app_window_args', $native_args, 'sn-dashboard' )['placement'], 'dashboard disabled: registered app launcher placement is none' );
ok( 'none' === apply_filters( 'openstation_app_window_args', $native_args, 'sn-analytics' )['placement'], 'analytics disabled: registered app launcher placement is none' );
ok( 'dock' === apply_filters( 'openstation_app_window_args', $native_args, 'unrelated-app' )['placement'], 'unrelated App Framework launcher placement is unchanged' );

// ── Group 7: Desktop Icon Registration ────────────────────────────────
echo "\nGroup 7: Desktop icon registration\n";
snt_os_save_native_window_preferences( array( 'dashboard' => true ), 1 );
$GLOBALS['__os_icons'] = array();
do_action( 'init' );
ok( isset( $GLOBALS['__os_icons']['sn-icon-dashboard'] ), 'sn-icon-dashboard registered on init' );
$dash_icon_enabled = $GLOBALS['__os_icons']['sn-icon-dashboard'];
ok( 'S&N Dashboard' === $dash_icon_enabled['title'], 'desktop icon title is S&N Dashboard' );
ok( 'dashicons-shield-alt' === $dash_icon_enabled['icon'], 'desktop icon has shield-alt dashicon' );
ok( 'sn-dashboard' === ( $dash_icon_enabled['window'] ?? '' ), 'dashboard enabled: desktop icon points to window sn-dashboard' );
ok( ! isset( $dash_icon_enabled['url'] ), 'dashboard enabled: desktop icon sets no URL' );

snt_os_save_native_window_preferences( array( 'dashboard' => false ), 1 );
$GLOBALS['__os_icons'] = array();
do_action( 'init' );
$dash_icon_disabled = $GLOBALS['__os_icons']['sn-icon-dashboard'];
ok( ! isset( $dash_icon_disabled['window'] ), 'dashboard disabled: desktop icon does not set window' );
ok( admin_url( 'admin.php?page=sn-theme-options' ) === ( $dash_icon_disabled['url'] ?? '' ), 'dashboard disabled: desktop icon points to classic admin URL' );

// Sibling icon unchanged
ok( isset( $GLOBALS['__os_icons']['sn-icon-identity'] ), 'sn-icon-identity icon is registered beside dashboard' );
ok( 'SN Identity' === $GLOBALS['__os_icons']['sn-icon-identity']['title'], 'sn-icon-identity retains title' );

// ── Group 8: Settings Tab Registration and Script Enqueue ─────────────
echo "\nGroup 8: Settings tab registration and script enqueue\n";
ok( has_action( 'admin_init', 'snt_os_register_settings_tab' ), 'admin_init hooks snt_os_register_settings_tab' );

$GLOBALS['__os_settings_tabs'] = array();
snt_os_register_settings_tab();
ok( 1 === count( $GLOBALS['__os_settings_tabs'] ), 'snt_os_register_settings_tab registers 1 tab' );
$tab = $GLOBALS['__os_settings_tabs'][0];
ok( 'signal-noise' === $tab['id'], 'settings tab id is signal-noise' );
ok( 'Signal & Noise' === $tab['label'], 'settings tab label is Signal & Noise' );
ok( 'manage_options' === $tab['capability'], 'settings tab capability is manage_options' );
ok( 32 === $tab['order'], 'settings tab order is 32' );
ok( 'snt-os-settings-tab' === $tab['script'], 'settings tab script handle is snt-os-settings-tab' );

// Script enqueue
ok( has_action( 'admin_enqueue_scripts', 'snt_os_enqueue_settings_script' ), 'admin_enqueue_scripts hooks snt_os_enqueue_settings_script' );

$GLOBALS['__os_shell_request'] = false;
$GLOBALS['__scripts']          = array();
$GLOBALS['__enqueued_scripts'] = array();
snt_os_enqueue_settings_script();
ok( empty( $GLOBALS['__scripts'] ) && empty( $GLOBALS['__enqueued_scripts'] ), 'non-shell request: script is not registered or enqueued' );

$GLOBALS['__os_shell_request'] = true;
snt_os_enqueue_settings_script();
ok( isset( $GLOBALS['__scripts']['snt-os-settings-tab'] ), 'shell request: snt-os-settings-tab script is registered' );
ok( isset( $GLOBALS['__enqueued_scripts']['snt-os-settings-tab'] ), 'shell request: snt-os-settings-tab script is enqueued' );

$script_reg = $GLOBALS['__scripts']['snt-os-settings-tab'];
ok( SNT_VERSION === $script_reg['ver'] && true === $script_reg['in_footer'], 'script registered with SNT_VERSION in footer' );
ok( false !== strpos( $script_reg['src'], 'assets/os-settings-tab.js' ), 'script source points to assets/os-settings-tab.js' );
ok( array( 'openstation', 'wp-i18n' ) === $script_reg['deps'], 'script waits for the OpenStation and i18n APIs' );
ok( isset( $GLOBALS['__actions']['admin_enqueue_scripts'][5] ), 'settings provider is registered before the shell harvest at priority 10' );

$l10n = $GLOBALS['__localized_scripts']['snt-os-settings-tab']['sntOpenStationPreferences'] ?? array();
ok( 'https://example.test/wp-json/signal-noise/v1/openstation/preferences' === ( $l10n['endpoint'] ?? '' ), 'localized endpoint matches REST preferences URL' );
ok( 'nonce-wp_rest' === ( $l10n['nonce'] ?? '' ), 'localized nonce created for wp_rest' );
ok( is_array( $l10n['preferences'] ?? null ), 'localized preferences contains current preferences array' );

// Client remap contract: two classic screens map to their native windows,
// both are preference-gated, and their deep-link parameters are forwarded.
$settings_js = file_get_contents( SNT_PATH . 'assets/os-settings-tab.js' );
ok( is_string( $settings_js ), 'settings-tab client source is readable' );
ok( 2 === substr_count( $settings_js, 'window.wp.os.registerNativeUrlRemap( {' ), 'client registers exactly two classic-page remaps' );
ok( false !== strpos( $settings_js, "nativeWindowId: 'sn-dashboard'" ), 'Dashboard classic page remaps to sn-dashboard' );
ok( false !== strpos( $settings_js, "nativeWindowId: 'sn-analytics'" ), 'Analytics classic page remaps to sn-analytics' );
ok( false !== strpos( $settings_js, "parsed.pathname.endsWith( '/admin.php' )" ), 'remaps are scoped to WordPress admin.php URLs' );
ok( 2 === substr_count( $settings_js, 'params: function' ), 'both remaps forward open-time parameters' );
ok( false !== strpos( $settings_js, 'preferences.dashboard === true' ), 'Dashboard remap follows its saved preference' );
ok( false !== strpos( $settings_js, 'preferences.analytics === true' ), 'Analytics remap follows its saved preference' );

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
