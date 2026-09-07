<?php
/**
 * Per-user opt-outs for the S&N native Dashboard and Analytics windows.
 *
 * OpenStation keeps replacement windows optional. S&N follows that contract:
 * both windows remain enabled for existing users, and either can be disabled
 * from a Signal & Noise tab in OpenStation Preferences. Removing an app from
 * the framework registry lets the ordinary wp-admin menu URL fall through to
 * OpenStation's classic iframe window.
 *
 * @package SignalNoiseTools
 * @since 13.106.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_OS_NATIVE_WINDOWS_META = 'snt_os_native_windows';

/**
 * Defaults preserve the behavior that shipped before preferences existed.
 *
 * @return array<string,bool>
 */
function snt_os_native_window_defaults() {
	return array(
		'dashboard' => true,
		'analytics' => true,
	);
}

/**
 * Resolve one user's native-window preferences.
 *
 * @param int $user_id User id; current user when omitted.
 * @return array<string,bool>
 */
function snt_os_native_window_preferences( $user_id = 0 ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
		$user_id = (int) get_current_user_id();
	}
	$out = snt_os_native_window_defaults();
	if ( $user_id <= 0 || ! function_exists( 'get_user_meta' ) ) {
		return $out;
	}
	$stored = get_user_meta( $user_id, SNT_OS_NATIVE_WINDOWS_META, true );
	if ( ! is_array( $stored ) ) {
		return $out;
	}
	foreach ( array_keys( $out ) as $key ) {
		if ( array_key_exists( $key, $stored ) ) {
			$out[ $key ] = (bool) $stored[ $key ];
		}
	}
	return $out;
}

/**
 * Whether one S&N replacement window is enabled for a user.
 *
 * @param string $window  Dashboard or analytics.
 * @param int    $user_id Optional user id.
 * @return bool
 */
function snt_os_native_window_enabled( $window, $user_id = 0 ) {
	$preferences = snt_os_native_window_preferences( $user_id );
	return isset( $preferences[ (string) $window ] ) && $preferences[ (string) $window ];
}

/**
 * Hide the classic menu tile only while its native replacement is enabled.
 *
 * @param string $placement Existing OpenStation placement.
 * @param string $menu_slug WordPress menu slug.
 * @return string
 */
function snt_os_native_menu_placement( $placement, $menu_slug ) {
	$windows = array(
		'sn-theme-options' => 'dashboard',
		'sn-analytics'     => 'analytics',
	);
	$window  = $windows[ (string) $menu_slug ] ?? '';
	if ( '' !== $window && snt_os_native_window_enabled( $window ) ) {
		return 'hidden';
	}
	return $placement;
}

/**
 * Remove opted-out apps after the framework has loaded every app file.
 *
 * @param object $registry OpenStation app registry.
 * @return void
 */
function snt_os_apply_native_window_preferences( $registry ) {
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'remove' ) ) {
		return;
	}
	if ( ! snt_os_native_window_enabled( 'dashboard' ) ) {
		$registry->remove( 'sn-dashboard' );
	}
	if ( ! snt_os_native_window_enabled( 'analytics' ) ) {
		$registry->remove( 'sn-analytics' );
	}
}
add_action( 'openstation_apps_loaded', 'snt_os_apply_native_window_preferences', 20, 1 );

/**
 * REST permission for personal native-window preferences.
 *
 * @return bool
 */
function snt_os_native_windows_rest_permission() {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * Return the current user's preferences.
 *
 * @return mixed
 */
function snt_os_native_windows_rest_get() {
	return rest_ensure_response( snt_os_native_window_preferences() );
}

/**
 * Store a partial preference patch for the current user.
 *
 * @param object $request REST request.
 * @return mixed
 */
function snt_os_native_windows_rest_update( $request ) {
	$input = is_object( $request ) && method_exists( $request, 'get_json_params' )
		? $request->get_json_params()
		: array();
	$input = is_array( $input ) ? $input : array();
	$next  = snt_os_native_window_preferences();
	foreach ( array_keys( $next ) as $key ) {
		if ( array_key_exists( $key, $input ) && is_bool( $input[ $key ] ) ) {
			$next[ $key ] = $input[ $key ];
		}
	}
	update_user_meta( get_current_user_id(), SNT_OS_NATIVE_WINDOWS_META, $next );
	return rest_ensure_response( $next );
}

/**
 * Register the small per-user preference endpoint.
 *
 * @return void
 */
function snt_os_native_windows_register_rest() {
	register_rest_route(
		'signal-noise/v1',
		'/openstation/native-windows',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'snt_os_native_windows_rest_get',
				'permission_callback' => 'snt_os_native_windows_rest_permission',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'snt_os_native_windows_rest_update',
				'permission_callback' => 'snt_os_native_windows_rest_permission',
			),
		)
	);
}
add_action( 'rest_api_init', 'snt_os_native_windows_register_rest' );

/**
 * Register S&N's tab in OpenStation Preferences.
 *
 * @return void
 */
function snt_os_native_windows_register_settings_tab() {
	if ( ! function_exists( 'openstation_register_settings_tab' ) ) {
		return;
	}
	$handle = 'snt-openstation-native-windows';
	$file   = SNT_PATH . 'assets/openstation-native-windows.js';
	wp_register_script(
		$handle,
		SNT_URL . 'assets/openstation-native-windows.js',
		array( 'openstation', 'wp-i18n' ),
		is_file( $file ) ? (string) filemtime( $file ) : SNT_VERSION,
		true
	);
	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( $handle, 'signal-and-noise-tools' );
	}
	wp_localize_script(
		$handle,
		'sntOpenStationNativeWindows',
		array(
			'endpoint'    => esc_url_raw( rest_url( 'signal-noise/v1/openstation/native-windows' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'preferences' => snt_os_native_window_preferences(),
		)
	);
	wp_enqueue_script( $handle );
	openstation_register_settings_tab(
		array(
			'id'         => 'signal-noise',
			'label'      => __( 'Signal & Noise', 'signal-and-noise-tools' ),
			'capability' => 'manage_options',
			'order'      => 35,
			'script'     => $handle,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'snt_os_native_windows_register_settings_tab', 5 );
