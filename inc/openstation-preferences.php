<?php
/**
 * Signal & Noise Tools — OpenStation native window preferences (Option B).
 *
 * Provides per-user settings to choose between OpenStation native App Framework
 * windows and classic WordPress admin iframe windows for the two S&N apps
 * that have classic equivalents. Signal & Noise itself is native-only.
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
		'dashboard'    => true,
		'analytics'    => true,
		// 14.8.0: what MIO, the shell's companion (OpenStation 1.1.9), may do in
		// this plugin's windows. All three are per-user and live in the same
		// OS Settings tab; the shell's own master switch (Features → MIO) still
		// sits above them. `mio_look` is opt-in because it changes the mascot's
		// colours site-wide for the user until they pick their own.
		'mio_tips'     => true,  // plain-text callouts beside a control; never invoke AI
		'mio_help'     => true,  // register help documents, a prompt and read-only tools for Ask MIO
		'mio_look'     => false, // dress the mascot in the site's palette
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
 * @param string $window  'dashboard' or 'analytics'.
 * @param int    $user_id Optional user id.
 * @return bool
 */
function snt_os_native_window_enabled( $window, $user_id = 0 ) {
	if ( 'signal-noise' === (string) $window ) {
		return true;
	}
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
 * The Attention queue's cache key and TTL, as the app writes them
 * (apps/signal-noise/parts/attention.php: ATTENTION_CACHE_KEY, ATTENTION_TTL).
 * Literals here because those consts live in the App Framework namespace and
 * exist only when the app part is loaded; tests/openstation-preferences.php
 * pins the two spellings together.
 */
const SNT_OS_ATTENTION_TRANSIENT = 'snt_os_attention';
const SNT_OS_ATTENTION_TTL       = 60;
const SNT_OS_ATTENTION_LAST_OPT  = 'snt_os_attention_last';

/**
 * What the Posts window's Attention pill reads: the app's LAST composition,
 * never a new one.
 *
 * THE DECISION (docs/plans/2026-09-12-posts-window-moves.md, phase 2): the
 * pill never pays for a composition. attention_rows() runs nine readers and
 * caches them for 60 s; the app window pays that when it opens, and the pill
 * shows what the app last knew. No transient → `count: null, stale: true`,
 * and the pill paints without a number. A number here is therefore always a
 * number the owner could have seen in the app, and never a scan the Posts
 * window triggered by being looked at.
 *
 * @param int|null $now Unix time; null = time().
 * @return array{count:int|null,read_at:int|null,stamp:string,stale:bool}
 */
function snt_os_attention_snapshot( $now = null ) {
	$now    = null === $now ? time() : (int) $now;
	$cached = function_exists( 'get_transient' ) ? get_transient( SNT_OS_ATTENTION_TRANSIENT ) : false;
	if ( is_array( $cached ) && isset( $cached['rows'], $cached['read_at'] ) && is_array( $cached['rows'] ) ) {
		$count   = count( $cached['rows'] );
		$read_at = (int) $cached['read_at'];
		$stamp   = (string) ( $cached['stamp'] ?? '' );
	} else {
		// The transient EXPIRES at 60 s (14.4.0 read only it, and the pill
		// was blank whenever the app had not been opened within the minute).
		// The app also keeps its last headline in an option that outlives
		// the transient; still the app's own reading, never a composition.
		$last = function_exists( 'get_option' ) ? get_option( SNT_OS_ATTENTION_LAST_OPT, array() ) : array();
		if ( ! is_array( $last ) || ! isset( $last['count'], $last['read_at'] ) ) {
			return array( 'count' => null, 'read_at' => null, 'stamp' => '', 'stale' => true );
		}
		$count   = (int) $last['count'];
		$read_at = (int) $last['read_at'];
		$stamp   = (string) ( $last['stamp'] ?? '' );
	}
	$age = $now - $read_at;
	return array(
		'count'   => $count,
		'read_at' => $read_at,
		'stamp'   => $stamp,
		// Same rule attention_rows() applies before trusting its cache: a
		// read_at in the future is a clock that moved, not a fresh read.
		'stale'   => $age < 0 || $age >= SNT_OS_ATTENTION_TTL,
	);
}

/**
 * REST GET handler for the Attention pill.
 *
 * @return mixed
 */
function snt_os_attention_rest_get() {
	return rest_ensure_response( snt_os_attention_snapshot() );
}

/**
 * Register the REST routes for OpenStation preferences.
 *
 * @return void
 */
function snt_os_register_preferences_rest() {
	register_rest_route(
		'signal-noise/v1',
		'/openstation/attention',
		array(
			'methods'             => 'GET',
			'callback'            => 'snt_os_attention_rest_get',
			'permission_callback' => 'snt_os_preferences_rest_permission',
		)
	);
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
					'dashboard'    => array(
						'type'        => 'boolean',
						'required'    => false,
					),
					'analytics'    => array(
						'type'        => 'boolean',
						'required'    => false,
					),
					'mio_tips'     => array( 'type' => 'boolean', 'required' => false ),
					'mio_help'     => array( 'type' => 'boolean', 'required' => false ),
					'mio_look'     => array( 'type' => 'boolean', 'required' => false ),
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
			'preferences' => snt_os_native_window_preferences(),
		)
	);

	wp_enqueue_script( 'snt-os-settings-tab' );
}
// The shell harvests registered settings-tab providers at priority 10.
add_action( 'admin_enqueue_scripts', 'snt_os_enqueue_settings_script', 5 );

/**
 * Enqueue our columns for OpenStation's native Posts window.
 *
 * One script (`assets/os-posts.js`: Provenance + Edge), its own handle, on
 * every shell request: the columns are read by the Posts window's
 * `openstation.postsWindow.columns` filter on each paint, so the
 * registration has to exist before that window ever opens — which rules out
 * riding the lazily-loaded Explorer bundle. Deps: wp-hooks only (the shell's
 * loader does not walk the dependency graph; see the Explorer's docblock).
 *
 * @return void
 */
function snt_os_enqueue_posts_script() {
	if ( ! function_exists( 'openstation_is_shell_request' ) || ! openstation_is_shell_request() ) {
		return;
	}
	$plugin_file = defined( 'SNT_PATH' ) ? SNT_PATH . 'signal-and-noise-tools.php' : dirname( __DIR__ ) . '/signal-and-noise-tools.php';
	wp_register_script(
		'snt-os-posts',
		plugins_url( 'assets/os-posts.js', $plugin_file ),
		array( 'wp-hooks' ),
		defined( 'SNT_VERSION' ) ? SNT_VERSION : '1.0.0',
		true
	);
	wp_localize_script(
		'snt-os-posts',
		'sntOsPosts',
		array(
			'attentionEndpoint'  => rest_url( 'signal-noise/v1/openstation/attention' ),
			'rescheduleEndpoint' => rest_url( 'signal-noise/v1/openstation/reschedule' ),
			'timezone'           => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : '',
		)
	);
	wp_enqueue_script( 'snt-os-posts' );

	// The Reschedule bulk action (17.3.0): gated as the classic dropdown is,
	// so no button paints that the route would 403. Depends on snt-os-posts
	// for the localized object; both ride the ordinary admin_enqueue_scripts
	// path on the shell request, not the shell's lazy loader.
	if ( current_user_can( 'edit_others_posts' ) ) {
		wp_register_script(
			'snt-os-posts-reschedule',
			plugins_url( 'assets/os-posts-reschedule.js', $plugin_file ),
			array( 'wp-hooks', 'snt-os-posts' ),
			defined( 'SNT_VERSION' ) ? SNT_VERSION : '1.0.0',
			true
		);
		wp_enqueue_script( 'snt-os-posts-reschedule' );
	}
}
add_action( 'admin_enqueue_scripts', 'snt_os_enqueue_posts_script', 5 );

/**
 * The REST fields our Posts-window columns read. One list, so the PHP side
 * (what rides `_fields`) and the JS side (what renders) cannot drift.
 */
const SNT_OS_POSTS_FIELDS = array( 'sn_provenance', 'sn_edge' );

/**
 * Ship our fields on the Posts window's list request.
 *
 * The window trims every `/wp/v2/posts` fetch with a `_fields` allowlist, so
 * a registered REST field does not ride the list unless it is named there.
 * v14.3.0 registered the column and left the cell empty on every row: the
 * data never arrived. The shell's own filter exists for exactly this
 * ("extend `_fields` to ship more columns" — docs/hooks-reference.md).
 * Append, never replace: the shell owns the rest of the list.
 *
 * @param array<string,mixed> $args Default outbound query args.
 * @return array<string,mixed>
 */
function snt_os_posts_window_query_args( $args ) {
	$fields = isset( $args['_fields'] ) ? (string) $args['_fields'] : '';
	if ( '' === $fields ) {
		return $args;
	}
	$list = array_filter( array_map( 'trim', explode( ',', $fields ) ) );
	foreach ( SNT_OS_POSTS_FIELDS as $field ) {
		if ( ! in_array( $field, $list, true ) ) {
			$list[] = $field;
		}
	}
	$args['_fields'] = implode( ',', $list );
	return $args;
}
add_filter( 'openstation_posts_window_query_args', 'snt_os_posts_window_query_args' );
