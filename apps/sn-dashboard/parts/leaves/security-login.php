<?php
/**
 * S&N Dashboard — Security → Login URL, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/login.php, `sn_admin_render_login_section()`)
 * paints a module status, one form (`sn_action=save_login`, field `login_slug`,
 * locked by the `SN_LOGIN_SLUG` constant), the current login URL, and the
 * emergency-unlock callout. Same readings, same form, same field, same
 * handler; the kit's parts instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The module's state, read the way the classic leaf reads it.
 *
 * @return array{bypassed:bool,wps_active:bool,slug:string,locked:bool,login_url:string}
 */
function login_state() {
	if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$wps_basename = 'wps-hide-login/wps-hide-login.php';
	$wps_active   = function_exists( 'is_plugin_active' ) && is_plugin_active( $wps_basename ) && defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $wps_basename );
	$slug         = function_exists( 'sn_login_get_slug' ) ? sn_login_get_slug() : ( function_exists( 'sn_setting' ) ? sn_setting( 'login.slug', 'sn-login' ) : 'sn-login' );
	return array(
		'bypassed'   => defined( 'SN_LOGIN_BYPASS' ) && SN_LOGIN_BYPASS,
		'wps_active' => $wps_active,
		'slug'       => (string) $slug,
		'locked'     => defined( 'SN_LOGIN_SLUG' ) && SN_LOGIN_SLUG,
		'login_url'  => home_url( '/' . $slug ),
	);
}

/**
 * The status card: which of the three states the module is in.
 *
 * @param array $s From login_state().
 * @return string
 */
function login_status_html( array $s ) {
	if ( $s['bypassed'] ) {
		$kind  = 'warn';
		$title = __( 'Module bypassed', 'signal-and-noise-tools' );
		$body  = __( 'The SN_LOGIN_BYPASS constant is set in wp-config.php. Default /wp-login.php behavior is restored. Remove the constant to re-enable.', 'signal-and-noise-tools' );
		$pill  = __( 'Bypassed', 'signal-and-noise-tools' );
	} elseif ( $s['wps_active'] ) {
		$kind  = 'warn';
		$title = __( 'Module dormant: conflict with wps-hide-login', 'signal-and-noise-tools' );
		$body  = __( 'The wps-hide-login plugin is still active. Our built-in module stands down to avoid rewrite conflicts. Deactivate that plugin to switch over to this one.', 'signal-and-noise-tools' );
		$pill  = __( 'Dormant', 'signal-and-noise-tools' );
	} else {
		$kind  = 'ok';
		$title = __( 'Module active', 'signal-and-noise-tools' );
		$body  = __( 'Direct visits to /wp-login.php and unauthenticated /wp-admin return 404. Login form reachable at the custom URL below.', 'signal-and-noise-tools' );
		$pill  = __( 'Active', 'signal-and-noise-tools' );
	}
	return \snt_kit_notice(
		$kind,
		'<b>' . \snt_kit_esc( $title ) . '</b> ' . \snt_kit_badge( $kind, $pill ) . '<br>' . \snt_kit_esc( $body )
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_security_login( array $ctx ) {
	unset( $ctx );
	$s   = login_state();
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( 'Custom login URL module: replaces /wp-login.php with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= login_status_html( $s );

	$slug_row = $s['locked']
		? '<div class="snt-field-static">'
			. '<span class="snt-field-static__k">' . \snt_kit_esc( __( 'Slug', 'signal-and-noise-tools' ) ) . '</span>'
			. \snt_kit_tag( 'os-text-field', array( 'type' => 'text', 'value' => $s['slug'], 'disabled' => true ) )
			. '<span class="snt-field-static__hint">' . \snt_kit_esc( __( 'Locked. The SN_LOGIN_SLUG constant in wp-config.php is overriding this field. Remove the constant to edit here.', 'signal-and-noise-tools' ) ) . '</span>'
			. '</div>'
		: \snt_kit_field( 'text', 'login_slug', __( 'Slug', 'signal-and-noise-tools' ), $s['slug'], array( 'placeholder' => 'sn-login', 'hint' => __( 'Letters, numbers, dashes only. Avoid common guesses (admin, login, panel, etc.).', 'signal-and-noise-tools' ) ) );
	$url_row = '<div class="snt-field-static">'
		. '<span class="snt-field-static__k">' . \snt_kit_esc( __( 'Current login URL', 'signal-and-noise-tools' ) ) . '</span>'
		. \snt_kit_link( $s['login_url'], $s['login_url'] )
		. '<span class="snt-field-static__hint">' . \snt_kit_esc( __( 'Bookmark this URL. The default /wp-login.php 404s for unauthenticated visitors.', 'signal-and-noise-tools' ) ) . '</span>'
		. '</div>';
	$inner = $slug_row . $url_row . ( $s['locked'] ? '<p class="snt-hint">' . \snt_kit_esc( __( 'Slug locked by SN_LOGIN_SLUG constant.', 'signal-and-noise-tools' ) ) . '</p>' : '' );
	// os-form has no `disabled` prop (kit-help: submit-label/reset-label/error/busy/columns/min-column/show-reset/align),
	// so in the locked state the Save button stays live and the handler is the guard — as it already is on the
	// classic page, where a crafted POST is likewise unblocked (leaves.json note).
	$form = \snt_kit_form( 'save_login', $inner, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
	$out .= \snt_kit_section(
		__( 'Custom login slug', 'signal-and-noise-tools' ),
		$form,
		__( 'The path segment used in place of wp-login.php.', 'signal-and-noise-tools' )
	);

	$out .= \snt_kit_section(
		__( 'Emergency unlock', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( "If you ever lock yourself out (forgot the slug, can't reach the login form), add either of these constants to wp-config.php via SSH or your host's file manager:", 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_code( "// Option 1: pin the slug. Reachable at /<slug-here>.\ndefine( 'SN_LOGIN_SLUG', 'your-fallback-slug' );\n\n// Option 2 — disable the module entirely. Restores /wp-login.php.\ndefine( 'SN_LOGIN_BYPASS', true );" )
		. '<p class="snt-prose">' . \snt_kit_esc( __( "The constants take priority over the setting and persist across plugin updates. Remove them once you've regained access.", 'signal-and-noise-tools' ) ) . '</p>'
	);
	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/login'] = __NAMESPACE__ . '\\paint_security_login';
		return $painters;
	}
);
