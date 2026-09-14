<?php
/**
 * Signal & Noise Tools — MIO, the shell's companion, in this plugin's windows.
 *
 * OpenStation 1.1.9 lets a window opt into MIO: a dynamic prompt, linked
 * Markdown help, private read tools, and plain-text callouts beside a
 * control. Three per-user preferences (inc/openstation-preferences.php,
 * managed from OS Settings › Signal & Noise) decide what this plugin uses:
 *
 *   mio_tips  callouts. Plain text, never a model call. The Signal & Noise
 *             app explains the state an item is in ("this verdict is from
 *             the 04:00 sweep"); S&N Home points at Trust checks from an
 *             empty Commits table.
 *   mio_help  the documents under apps/signal-noise/help/, a prompt scoped
 *             to the window, and read-only tools over what the window
 *             already shows. Dormant until someone opens Ask MIO, which the
 *             shell gates on its AI switch and a configured connector; the
 *             plugin makes no model call of its own.
 *   mio_look  the site's palette on the mascot, through the shell's
 *             openstation_mio_config filter. The user's saved look wins.
 *
 * This file is the PHP half: the filter, the document loader and the bag
 * the scripts read. The JS halves are apps/signal-noise/signal-noise-client.js
 * (the app) and assets/os-host.js (the two host windows).
 *
 * @package SignalNoiseTools
 * @since 14.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The help documents, in the shape MIO takes: {id, title, markdown}. Ids are
 * the relative paths the documents link each other by.
 *
 * @return array<int,array{id:string,title:string,markdown:string}>
 */
function snt_os_mio_documents() {
	$dir  = SNT_PATH . 'apps/signal-noise/help/';
	$docs = array();
	foreach ( array( 'index.md', 'attention.md', 'readers.md', 'stamps.md', 'windows.md' ) as $id ) {
		$path = $dir . $id;
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$md    = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a plugin-shipped Markdown file, never a URL.
		$title = preg_match( '/^#\s+(.+)$/m', $md, $m ) ? trim( $m[1] ) : $id;
		$docs[] = array(
			'id'       => $id,
			'title'    => $title,
			'markdown' => $md,
			'version'  => defined( 'SNT_VERSION' ) ? SNT_VERSION : '',
		);
	}
	return $docs;
}

/**
 * The static half of the prompt. The app appends the live state (section,
 * counts) on every round; this part names the voice and the boundary.
 *
 * @param string $window 'app' | 'dashboard' | 'analytics'.
 * @return string
 */
function snt_os_mio_prompt_base( $window ) {
	$names = array(
		'app'       => __( 'the Signal & Noise app, the site\'s editorial desk', 'signal-and-noise-tools' ),
		'dashboard' => __( 'S&N Home, the plugin\'s dashboard window', 'signal-and-noise-tools' ),
		'analytics' => __( 'S&N Analytics, the plugin\'s analytics window', 'signal-and-noise-tools' ),
	);
	$name = $names[ $window ] ?? $names['app'];
	return sprintf(
		/* translators: %s: the window's name. */
		__( 'You are MIO inside %s on this WordPress site. Answer from the help documents and the read tools offered to you, about this window only. Every reading here carries the time it was taken; say when a reading is from, never that something is true now. You cannot run a sweep, a scan or a sync, and you cannot acknowledge, publish or edit anything: say so and name the control that does. Plain sentences, no em dashes.', 'signal-and-noise-tools' ),
		$name
	);
}

/**
 * The bag both scripts read: preferences plus, when help is on, the
 * documents and prompt bases. Localized once on every shell request.
 *
 * @return array<string,mixed>
 */
function snt_os_mio_client_bag() {
	$prefs = function_exists( 'snt_os_native_window_preferences' ) ? snt_os_native_window_preferences() : array();
	$help  = ! empty( $prefs['mio_help'] );
	return array(
		'tips'      => ! empty( $prefs['mio_tips'] ),
		'help'      => $help,
		'documents' => $help ? snt_os_mio_documents() : array(),
		'prompts'   => $help ? array(
			'app'       => snt_os_mio_prompt_base( 'app' ),
			'dashboard' => snt_os_mio_prompt_base( 'dashboard' ),
			'analytics' => snt_os_mio_prompt_base( 'analytics' ),
		) : array(),
	);
}

/**
 * Localize the bag onto the settings-tab script, which loads on every shell
 * request before either consumer.
 *
 * @return void
 */
function snt_os_mio_localize() {
	if ( ! function_exists( 'wp_script_is' ) || ! wp_script_is( 'snt-os-settings-tab', 'registered' ) ) {
		return;
	}
	wp_localize_script( 'snt-os-settings-tab', 'sntMio', snt_os_mio_client_bag() );
}
add_action( 'admin_enqueue_scripts', 'snt_os_mio_localize', 6 ); // after snt_os_enqueue_settings_script at 5

/**
 * The site's palette on the mascot: body in bone (#000000), the chroma ring
 * from blood (#e00404) toward signal (#ff4c47). Per user, and only when
 * they opted in; the shell re-clamps every value and the user's own saved
 * look still overrides the site default.
 *
 * @param array<string,mixed> $config The shell's appearance + physics config.
 * @return array<string,mixed>
 */
function snt_os_mio_config( $config ) {
	$prefs = function_exists( 'snt_os_native_window_preferences' ) ? snt_os_native_window_preferences() : array();
	if ( empty( $prefs['mio_look'] ) || ! is_array( $config ) ) {
		return $config;
	}
	$appearance = isset( $config['appearance'] ) && is_array( $config['appearance'] ) ? $config['appearance'] : array();
	$config['appearance'] = array_merge(
		$appearance,
		array(
			'bodyColor'  => '#000000',
			'hueStart'   => 0,
			'hueSpan'    => 12,
			'hueLoop'    => true,
			'saturation' => 0.96,
			'lightness'  => 0.64,
		)
	);
	return $config;
}
add_filter( 'openstation_mio_config', 'snt_os_mio_config' );
