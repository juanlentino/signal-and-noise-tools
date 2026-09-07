<?php
/**
 * Per-user opt-outs for the S&N Dashboard and Analytics native windows.
 *
 * Run: php tests/openstation-native-window-preferences.php
 */

if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) {
	http_response_code( 404 );
	exit;
}

define( 'ABSPATH', '/' );
define( 'SNT_PATH', dirname( __DIR__ ) . '/' );
define( 'SNT_URL', 'https://example.test/wp-content/plugins/signal-and-noise-tools/' );
define( 'SNT_VERSION', 'test' );

$GLOBALS['__actions']  = array();
$GLOBALS['__meta']     = array();
$GLOBALS['__scripts']  = array();
$GLOBALS['__localized'] = array();
$GLOBALS['__tabs']     = array();
$GLOBALS['__routes']   = array();
$GLOBALS['__user_id']  = 7;

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['__actions'][ $hook ][] = array( $callback, $priority, $args );
}
function get_current_user_id() {
	return $GLOBALS['__user_id'];
}
function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['__meta'][ $user_id ][ $key ] ?? ( $single ? '' : array() );
}
function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['__meta'][ $user_id ][ $key ] = $value;
	return true;
}
function current_user_can( $capability ) {
	return 'manage_options' === $capability;
}
function rest_ensure_response( $value ) {
	return $value;
}
function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['__routes'][ $namespace . $route ] = $args;
}
function rest_url( $path ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}
function esc_url_raw( $url ) {
	return $url;
}
function wp_create_nonce( $action ) {
	return 'nonce-' . $action;
}
function __( $text, $domain = null ) {
	return $text;
}
function wp_register_script( $handle, $url, $deps, $version, $footer ) {
	$GLOBALS['__scripts'][ $handle ] = compact( 'url', 'deps', 'version', 'footer' );
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['__localized'][ $handle ] = compact( 'name', 'data' );
}
function wp_enqueue_script( $handle ) {
	$GLOBALS['__scripts'][ $handle ]['enqueued'] = true;
}
function wp_set_script_translations( $handle, $domain = 'default' ) {
	$GLOBALS['__scripts'][ $handle ]['translation_domain'] = $domain;
	return true;
}
function openstation_register_settings_tab( $args ) {
	$GLOBALS['__tabs'][ $args['id'] ] = $args;
	return true;
}

require_once __DIR__ . '/../inc/openstation-native-windows.php';

$pass = 0;
$fail = 0;
function ok( $condition, $message ) {
	global $pass, $fail;
	if ( $condition ) {
		++$pass;
		echo "PASS: $message\n";
	} else {
		++$fail;
		echo "FAIL: $message\n";
	}
}

echo "OpenStation native-window preferences\n\nGroup 1: defaults and storage\n";
ok(
	array( 'dashboard' => true, 'analytics' => true ) === snt_os_native_window_preferences(),
	'no stored preference preserves both native windows'
);
$GLOBALS['__meta'][7][ SNT_OS_NATIVE_WINDOWS_META ] = array(
	'dashboard' => false,
	'unknown'   => false,
);
ok(
	array( 'dashboard' => false, 'analytics' => true ) === snt_os_native_window_preferences(),
	'stored values override known windows only and missing keys retain defaults'
);
ok( ! snt_os_native_window_enabled( 'dashboard' ) && snt_os_native_window_enabled( 'analytics' ), 'each window has an independent per-user answer' );
ok(
	'dock' === snt_os_native_menu_placement( 'dock', 'sn-theme-options' )
		&& 'hidden' === snt_os_native_menu_placement( 'dock', 'sn-analytics' ),
	'an opted-out window restores its classic menu tile while an enabled replacement suppresses the duplicate'
);

echo "\nGroup 2: registry fallback\n";
$registry = new class() {
	public $removed = array();
	public function remove( $id ) {
		$this->removed[] = $id;
	}
};
snt_os_apply_native_window_preferences( $registry );
ok( array( 'sn-dashboard' ) === $registry->removed, 'only the opted-out app is removed, leaving its classic admin URL as the fallback' );
$GLOBALS['__meta'][7][ SNT_OS_NATIVE_WINDOWS_META ]['analytics'] = false;
$registry->removed = array();
snt_os_apply_native_window_preferences( $registry );
ok( array( 'sn-dashboard', 'sn-analytics' ) === $registry->removed, 'both apps can be opted out independently' );

echo "\nGroup 3: REST patch and Preferences tab\n";
$request = new class() {
	public function get_json_params() {
		return array( 'dashboard' => true, 'analytics' => 'false', 'unknown' => false );
	}
};
$saved = snt_os_native_windows_rest_update( $request );
ok(
	array( 'dashboard' => true, 'analytics' => false ) === $saved,
	'the endpoint accepts JSON booleans for known keys and refuses ambiguous strings and unknown keys'
);
ok( snt_os_native_windows_rest_permission(), 'the endpoint is restricted to accounts that can manage options' );
snt_os_native_windows_register_rest();
ok( isset( $GLOBALS['__routes']['signal-noise/v1/openstation/native-windows'] ), 'the personal preference endpoint is registered' );
snt_os_native_windows_register_settings_tab();
$tab = $GLOBALS['__tabs']['signal-noise'] ?? array();
ok(
	'manage_options' === ( $tab['capability'] ?? '' )
		&& 'snt-openstation-native-windows' === ( $tab['script'] ?? '' )
		&& array( 'openstation', 'wp-i18n' ) === ( $GLOBALS['__scripts']['snt-openstation-native-windows']['deps'] ?? array() )
		&& 'signal-and-noise-tools' === ( $GLOBALS['__scripts']['snt-openstation-native-windows']['translation_domain'] ?? '' ),
	'OpenStation Preferences gets one admin-only Signal & Noise tab with the shell and translation runtimes'
);
$localized = $GLOBALS['__localized']['snt-openstation-native-windows']['data'] ?? array();
ok(
	false !== strpos( (string) ( $localized['endpoint'] ?? '' ), '/openstation/native-windows' )
		&& array( 'dashboard' => true, 'analytics' => false ) === ( $localized['preferences'] ?? array() ),
	'the tab receives the endpoint, nonce, and current per-user state'
);
$script = (string) file_get_contents( SNT_PATH . 'assets/openstation-native-windows.js' );
ok(
	false !== strpos( $script, 'Use the native S&N Dashboard window' )
		&& false !== strpos( $script, 'Use the native S&N Analytics window' )
		&& false !== strpos( $script, 'refreshMenu' ),
	'the tab exposes both switches and refreshes OpenStation after a save'
);
$dock = (string) file_get_contents( SNT_PATH . 'inc/desktop-mode-dock.php' );
ok(
	false !== strpos( $dock, "snt_os_native_window_enabled( 'dashboard' )" )
		&& false !== strpos( $dock, "snt_desktop_admin_url( 'sn-theme-options' )" ),
	'the existing Dashboard desktop icon follows the preference to the native app or the classic URL'
);

echo "\nResult: $pass passed, $fail failed.\n";
exit( $fail > 0 ? 1 : 0 );
