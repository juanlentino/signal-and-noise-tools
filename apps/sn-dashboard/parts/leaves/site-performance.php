<?php
/**
 * S&N Dashboard — Site → Performance, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/performance.php,
 * `sn_admin_render_performance_section()`) paints, in the two-column shell,
 * one form (`sn_action=perf_save`, checkbox `speculative_loading`) with its
 * intro and helper in the MAIN column, and in the rail a status box
 * (Enabled/On or Disabled/Off) plus the Profile reference (mode, eagerness,
 * exclusions, browser support). Same reading (`perf.speculative_loading`),
 * same form, same field, same handler; the kit's parts instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The module's state, read the way the classic leaf reads it: the setting,
 * default on, reduced to a boolean before anything is painted.
 *
 * @return array{enabled:bool}
 */
function performance_state() {
	return array(
		'enabled' => function_exists( 'sn_setting' ) ? (bool) sn_setting( 'perf.speculative_loading', true ) : true,
	);
}

/**
 * The intro the classic fieldset opens with: the Speculation Rules link and
 * core's default profile, inline.
 *
 * @return string
 */
function performance_intro_html() {
	return '<p class="snt-prose">'
		. \snt_kit_esc( __( 'WordPress 7.0 ships native', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_link( __( 'Speculation Rules', 'signal-and-noise-tools' ), 'https://developer.chrome.com/docs/web-platform/prerender-pages' )
		. ' ' . \snt_kit_esc( __( '(default:', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'auto', false ) . '/' . \snt_kit_code( 'auto', false ) . '). '
		. \snt_kit_esc( __( 'Enabling this opts the site into a more aggressive profile: links the visitor is likely to click are rendered in the background, so navigation feels instant. The profile and exclusions are summarized alongside.', 'signal-and-noise-tools' ) )
		. '</p>';
}

/**
 * The form: the one checkbox in its "Status" row (`<os-field-row label hint>`
 * — kit-help "Field row": label, hint, default slot = the control), posting
 * `perf_save` through the shared handler table, as the classic form does.
 *
 * @param array $s From performance_state().
 * @return string
 */
function performance_form_html( array $s ) {
	$row = \snt_kit_tag(
		'os-field-row',
		array(
			'label' => __( 'Status', 'signal-and-noise-tools' ),
			'hint'  => __( 'Turning this off disables speculative loading entirely (core emits no speculation rules).', 'signal-and-noise-tools' ),
		),
		\snt_kit_field( 'checkbox', 'speculative_loading', __( 'Enabled: prerender the pages a visitor is likely to open next', 'signal-and-noise-tools' ), $s['enabled'] )
	);
	return \snt_kit_form( 'perf_save', performance_intro_html() . $row, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
}

/**
 * The rail's status box: on (success) or off (warning), with the pill.
 *
 * @param array $s From performance_state().
 * @return string
 */
function performance_status_html( array $s ) {
	$kind = $s['enabled'] ? 'ok' : 'warn';
	$body = $s['enabled'] ? __( 'Enabled', 'signal-and-noise-tools' ) : __( 'Disabled', 'signal-and-noise-tools' );
	$pill = $s['enabled']
		? \snt_kit_badge( 'ok', __( 'On', 'signal-and-noise-tools' ) )
		: \snt_kit_badge( '', __( 'Off', 'signal-and-noise-tools' ) );
	return \snt_kit_notice(
		$kind,
		'<b>' . \snt_kit_esc( __( 'Speculative loading', 'signal-and-noise-tools' ) ) . '</b> ' . $pill . '<br>' . \snt_kit_esc( $body )
	);
}

/**
 * The rail's Profile reference: mode, eagerness, exclusions, support.
 *
 * @return string
 */
function performance_profile_html() {
	$mode = '<p class="snt-hint">'
		. \snt_kit_esc( __( 'Mode', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'prerender', false ) . ', '
		. \snt_kit_esc( __( 'eagerness', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'moderate', false ) . ': '
		. \snt_kit_esc( __( "more aggressive than core's", 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'auto', false ) . '/' . \snt_kit_code( 'auto', false ) . '.'
		. '</p>';
	$excluded = '<p class="snt-hint"><strong>' . \snt_kit_esc( __( 'Excluded automatically:', 'signal-and-noise-tools' ) ) . '</strong> '
		. \snt_kit_esc( __( 'the custom login URL and', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/contact/*', false ) . '.'
		. '</p>';
	$support = '<p class="snt-hint"><strong>' . \snt_kit_esc( __( 'Support:', 'signal-and-noise-tools' ) ) . '</strong> '
		. \snt_kit_esc( __( 'only modern Chromium browsers act on speculation rules; others safely ignore them.', 'signal-and-noise-tools' ) )
		. '</p>';
	return \snt_kit_section( __( 'Profile', 'signal-and-noise-tools' ), $mode . $excluded . $support );
}

/**
 * The leaf: the form column, then the status rail — the classic shell's
 * two columns as the app's column grid, the rail keeping its landmark name.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_site_performance( array $ctx ) {
	unset( $ctx );
	$s = performance_state();
	return '<div class="snt-cols">'
		. '<section class="snt-col">'
		. \snt_kit_section( __( 'Speculative loading', 'signal-and-noise-tools' ), performance_form_html( $s ) )
		. '</section>'
		. '<aside class="snt-col" aria-label="' . \snt_kit_esc( __( 'Speculative loading status', 'signal-and-noise-tools' ) ) . '">'
		. performance_status_html( $s )
		. performance_profile_html()
		. '</aside>'
		. '</div>';
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/performance'] = __NAMESPACE__ . '\\paint_site_performance';
		return $painters;
	}
);
