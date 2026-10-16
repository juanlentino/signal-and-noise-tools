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
 * 2026-10-16 (the leaf anatomy, docs/kit-leaf-anatomy.md): one status line
 * (speculative loading, the outbound calls' failures), the intro in How this
 * works, the outbound-call ledger as the working content, and the setting and
 * its Profile as two folds of one Setup card. Since 2026-10-16 the status card
 * and the Setup card share one row above the ledger.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/site-performance-parts.php';

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
		. \snt_kit_esc( __( 'Enabling this opts the site into a more aggressive profile: links the visitor is likely to click are rendered in the background, so navigation feels instant. The Profile box lists the profile and what it leaves out.', 'signal-and-noise-tools' ) )
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
			'hint'  => __( 'Turning this off disables speculative loading entirely: WordPress then adds no speculation rules to any page.', 'signal-and-noise-tools' ),
		),
		\snt_kit_field( 'checkbox', 'speculative_loading', __( 'Enabled: prerender the pages a visitor is likely to open next', 'signal-and-noise-tools' ), $s['enabled'] )
	);
	return \snt_kit_form( 'perf_save', $row, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
}

/**
 * The status card: is speculative loading on, and did an outbound call from
 * an admin page fail; the intro under it as How this works. The failure's
 * own warning stays open on the ledger below.
 *
 * @param array      $s From performance_state().
 * @param array|null $d From performance_slow_calls(), null when the module is not loaded.
 * @return string
 */
function performance_status_html( array $s, $d ) {
	$chips = array(
		array(
			'tone'  => $s['enabled'] ? 'ok' : 'warn',
			'label' => $s['enabled'] ? __( 'Speculative loading is on', 'signal-and-noise-tools' ) : __( 'Speculative loading is off', 'signal-and-noise-tools' ),
		),
	);
	if ( is_array( $d ) ) {
		$failed  = (int) $d['failed'];
		$chips[] = 0 === (int) $d['entries']
			? array( 'tone' => 'neutral', 'label' => __( 'No outbound call captured', 'signal-and-noise-tools' ) )
			/* translators: %s: failed outbound calls. */
			: array( 'tone' => $failed > 0 ? 'warn' : 'ok', 'label' => $failed > 0 ? sprintf( _n( '%s failed outbound call', '%s failed outbound calls', $failed, 'signal-and-noise-tools' ), number_format_i18n( $failed ) ) : __( 'No failed outbound call', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_section(
		__( 'Performance', 'signal-and-noise-tools' ),
		\snt_kit_status_line( $chips ) . \snt_kit_how_fold( __( 'Speculation Rules prerender the pages a visitor is likely to open next.', 'signal-and-noise-tools' ), performance_intro_html() )
	);
}

/**
 * The Setup card: the setting (its summary On or Off) and the Profile
 * reference (mode, eagerness, exclusions, support), one fold each.
 *
 * @param array $s From performance_state().
 * @return string
 */
function performance_setup_html( array $s ) {
	// 2026.10.4 audit: each Profile fact under its own label, not a bold lead-in.
	$profile = \snt_kit_kv(
		array(
			array(
				'label' => __( 'Mode', 'signal-and-noise-tools' ),
				'value' => \snt_kit_code( 'prerender', false ) . ', ' . \snt_kit_esc( __( 'eagerness', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'moderate', false ) . ': '
					. \snt_kit_esc( __( "more aggressive than core's", 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'auto', false ) . '/' . \snt_kit_code( 'auto', false ) . '.',
				'html'  => true,
			),
			array(
				'label' => __( 'Excluded automatically', 'signal-and-noise-tools' ),
				'value' => \snt_kit_esc( __( 'the custom login URL and', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( '/contact/*', false ) . '.',
				'html'  => true,
			),
			array(
				'label' => __( 'Browser support', 'signal-and-noise-tools' ),
				'value' => __( 'only modern Chromium browsers act on speculation rules; others safely ignore them.', 'signal-and-noise-tools' ),
			),
		)
	);
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array( 'title' => __( 'Speculative loading', 'signal-and-noise-tools' ), 'summary' => $s['enabled'] ? __( 'On', 'signal-and-noise-tools' ) : __( 'Off', 'signal-and-noise-tools' ), 'body' => performance_form_html( $s ) ),
			array( 'title' => __( 'Profile', 'signal-and-noise-tools' ), 'summary' => __( 'prerender, moderate eagerness', 'signal-and-noise-tools' ), 'body' => $profile ),
		),
		__( 'Each line is the setting as it stands; open one to change it.', 'signal-and-noise-tools' )
	);
}

/**
 * The leaf: the status card and the Setup card side by side in one row (the
 * state and the settings that set it, together), then the outbound calls
 * from admin pages at full width (the parts file).
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_site_performance( array $ctx ) {
	unset( $ctx );
	$s = performance_state();
	$d = function_exists( 'sn_httpdiag_visible' ) ? performance_slow_calls() : null;
	return \snt_kit_grid( array( performance_status_html( $s, $d ), performance_setup_html( $s ) ) ) . performance_slow_calls_html( $d );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['site/performance'] = __NAMESPACE__ . '\\paint_site_performance';
		return $painters;
	}
);
