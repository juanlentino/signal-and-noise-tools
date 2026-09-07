<?php
/**
 * Signal & Noise Tools — OpenStation native window preferences (Option B).
 *
 * Provides per-user settings to choose between OpenStation native App Framework
 * windows and classic WordPress admin iframe windows for all three S&N apps.
 *
 * Apps remain permanently registered in the App Framework registry so contracts
 * never break; toggles cleanly control dock placement, URL routing, and shell
 * navigation.
 *
 * @package SignalNoiseTools
 * @since 13.106.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_OS_PREFERENCES_META = '_snt_os_native_windows';

/**
 * Default native-window preferences.
 *
 * @return array<string,bool>
 */
function snt_os_native_window_defaults() {
	return array(
		'signal-noise' => true,
		'dashboard'    => true,
		'analytics'    => true,
	);
}

/**
 * Sanitize a boolean preference value.
 *
 * Coerces boolean, integer, and string representations (e.g. 'false', '0', 'true', '1')
 * safely to boolean.
 *
 * @param mixed $value Raw input value.
 * @return bool
 */
function snt_os_sanitize_bool( $value ) {
	if ( is_bool( $value ) ) {
		return $value;
	}
	if ( is_string( $value ) ) {
		$lower = strtolower( trim( $value ) );
		return in_array( $lower, array( '1', 'true', 'yes', 'on' ), true );
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return 1 === (int) $value;
	}
	return (bool) $value;
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
	$stored = get_user_meta( $user_id, SNT_OS_PREFERENCES_META, true );
	if ( ! is_array( $stored ) ) {
		return $out;
	}
	foreach ( array_keys( $out ) as $key ) {
		if ( array_key_exists( $key, $stored ) ) {
			$out[ $key ] = snt_os_sanitize_bool( $stored[ $key ] );
		}
	}
	return $out;
}

/**
 * Whether one S&N replacement window is enabled for a user.
 *
 * @param string $window  'signal-noise', 'dashboard', or 'analytics'.
 * @param int    $user_id Optional user id.
 * @return bool
 */
function snt_os_native_window_enabled( $window, $user_id = 0 ) {
	$preferences = snt_os_native_window_preferences( $user_id );
	return isset( $preferences[ (string) $window ] ) && $preferences[ (string) $window ];
}

/**
 * Save native-window preferences for a user.
 *
 * @param array<string,bool> $patch   Preferences to update.
 * @param int                $user_id User id.
 * @return array<string,bool> Updated preferences.
 */
function snt_os_save_native_window_preferences( array $patch, $user_id = 0 ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 && function_exists( 'get_current_user_id' ) ) {
		$user_id = (int) get_current_user_id();
	}
	$current = snt_os_native_window_preferences( $user_id );
	foreach ( array_keys( $current ) as $key ) {
		if ( array_key_exists( $key, $patch ) ) {
			$current[ $key ] = snt_os_sanitize_bool( $patch[ $key ] );
		}
	}
	if ( $user_id > 0 && function_exists( 'update_user_meta' ) ) {
		update_user_meta( $user_id, SNT_OS_PREFERENCES_META, $current );
	}
	return $current;
}

/**
 * REST permission callback for preferences.
 *
 * @return bool
 */
function snt_os_preferences_rest_permission() {
	return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
}

/**
 * REST GET handler for preferences.
 *
 * @return mixed
 */
function snt_os_preferences_rest_get() {
	return rest_ensure_response( snt_os_native_window_preferences() );
}

/**
 * REST POST handler for preferences.
 *
 * @param mixed $request REST request.
 * @return mixed
 */
function snt_os_preferences_rest_update( $request ) {
	if ( is_array( $request ) ) {
		$input = $request;
	} elseif ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
		$input = $request->get_json_params();
		if ( ( null === $input || empty( $input ) ) && method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();
			if ( is_array( $params ) && ! empty( $params ) ) {
				$input = $params;
			}
		}
	} else {
		$input = null;
	}

	if ( ! is_array( $input ) ) {
		return new WP_Error(
			'rest_invalid_json',
			__( 'Invalid JSON payload.', 'signal-and-noise-tools' ),
			array( 'status' => 400 )
		);
	}

	$defaults = snt_os_native_window_defaults();
	$patch    = array();
	foreach ( array_keys( $defaults ) as $key ) {
		if ( array_key_exists( $key, $input ) ) {
			$patch[ $key ] = snt_os_sanitize_bool( $input[ $key ] );
		}
	}

	$saved = snt_os_save_native_window_preferences( $patch );
	return rest_ensure_response( $saved );
}

/**
 * Register the REST routes for OpenStation preferences.
 *
 * @return void
 */
function snt_os_register_preferences_rest() {
	register_rest_route(
		'signal-noise/v1',
		'/openstation/preferences',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'snt_os_preferences_rest_get',
				'permission_callback' => 'snt_os_preferences_rest_permission',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'snt_os_preferences_rest_update',
				'permission_callback' => 'snt_os_preferences_rest_permission',
				'args'                => array(
					'signal-noise' => array(
						'type'        => 'boolean',
						'required'    => false,
					),
					'dashboard'    => array(
						'type'        => 'boolean',
						'required'    => false,
					),
					'analytics'    => array(
						'type'        => 'boolean',
						'required'    => false,
					),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'snt_os_register_preferences_rest' );

/**
 * Register the settings tab with OpenStation.
 *
 * @return void
 */
function snt_os_register_settings_tab() {
	if ( ! function_exists( 'openstation_register_settings_tab' ) ) {
		return;
	}

	openstation_register_settings_tab(
		array(
			'id'         => 'signal-noise',
			'label'      => __( 'Signal & Noise', 'signal-and-noise-tools' ),
			'capability' => 'manage_options',
			'order'      => 32,
			'script'     => 'snt-os-settings-tab',
		)
	);
}
add_action( 'admin_init', 'snt_os_register_settings_tab' );

/**
 * Register and enqueue the OpenStation settings tab script.
 *
 * @return void
 */
function snt_os_enqueue_settings_script() {
	if ( ! function_exists( 'openstation_is_shell_request' ) || ! openstation_is_shell_request() ) {
		return;
	}

	$plugin_file = defined( 'SNT_PATH' ) ? SNT_PATH . 'signal-and-noise-tools.php' : dirname( __DIR__ ) . '/signal-and-noise-tools.php';
	$src         = plugins_url( 'assets/os-settings-tab.js', $plugin_file );

	wp_register_script(
		'snt-os-settings-tab',
		$src,
		array( 'openstation', 'wp-i18n' ),
		defined( 'SNT_VERSION' ) ? SNT_VERSION : '1.0.0',
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'snt-os-settings-tab', 'signal-and-noise-tools' );
	}

	wp_localize_script(
		'snt-os-settings-tab',
		'sntOpenStationPreferences',
		array(
			'endpoint'    => rest_url( 'signal-noise/v1/openstation/preferences' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'preferences' => snt_os_native_window_preferences(),
		)
	);

	wp_enqueue_script( 'snt-os-settings-tab' );
}
// The shell harvests registered settings-tab providers at priority 10.
add_action( 'admin_enqueue_scripts', 'snt_os_enqueue_settings_script', 5 );
