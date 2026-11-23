<?php
/**
 * S&N Dashboard: Site > Performance, painted from the kit.
 *
 * The readings about speculative loading, the native twin of
 * inc/admin-forms/performance.php: which profile the site serves (this
 * plugin's prerender/moderate, or WordPress's own default), the Profile
 * reference (mode, eagerness, exclusions, browser support) and, in the
 * window only, the outbound calls from admin pages. Same reading
 * (`perf.speculative_loading`), same words (snt_speculative_loading_copy()).
 * The switch left for Settings > Front end in 2026-11 (settings-front-end.php);
 * this leaf keeps one line saying so. Neither state is a warning: off is
 * WordPress's default, not nothing (inc/speculation-rules.php).
 *
 * 2026-10-16 (the leaf anatomy, docs/kit-leaf-anatomy.md): one status line
 * (the profile, the outbound calls' failures), the intro in How this works,
 * the outbound-call ledger as the working content, and the Profile as the
 * one fold of a Setup card. The status card and the Setup card share one row
 * above the ledger.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once dirname( __DIR__, 4 ) . '/inc/admin-forms/performance.php'; // snt_speculative_loading_copy(): the reading's words, the classic twin's.
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
 * The intro the classic fieldset opens with: the Speculation Rules link,
 * core's default profile inline, and what the switch chooses between.
 *
 * @return string
 */
function performance_intro_html() {
	return '<p class="snt-prose">'
		. \snt_kit_esc( __( 'Since 6.8, WordPress ships native', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_link( __( 'Speculation Rules', 'signal-and-noise-tools' ), 'https://developer.chrome.com/docs/web-platform/prerender-pages' )
		. ' ' . \snt_kit_esc( __( '(default:', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_code( 'auto', false ) . '/' . \snt_kit_code( 'auto', false ) . \snt_kit_esc( __( ', which WordPress resolves to prefetch unless the host set another). ', 'signal-and-noise-tools' ) )
		. \snt_kit_esc( __( 'With its switch on, this plugin asks for a more aggressive profile: links the visitor is likely to click are rendered in the background, so navigation feels instant. With it off, WordPress’s default stays. The Profile box lists what is in effect and what it leaves out.', 'signal-and-noise-tools' ) )
		. '</p>';
}

/**
 * The one line this leaf keeps about its setting: the switch is on Settings >
 * Front end (settings-front-end.php) since 2026-11. The sentence is the
 * classic twin's (inc/admin-forms/settings-front-end.php).
 *
 * @return string
 */
function performance_moved_html() {
	if ( ! function_exists( 'snt_settings_front_end_moved_text' ) ) {
		return '';
	}
	return '<p class="snt-hint">' . \snt_kit_esc( \snt_settings_front_end_moved_text() ) . ' ' . \snt_kit_go( __( 'Settings › Front end', 'signal-and-noise-tools' ), array( 'tab' => 'settings', 'sub' => 'front-end', 'current' => 'site' ) ) . '.</p>';
}

/**
 * The status card: which profile is in effect, and did an outbound call from
 * an admin page fail; where the switch went; the intro as How this works.
 * The failure's own warning stays open on the ledger below.
 *
 * @param array      $s From performance_state().
 * @param array|null $d From performance_slow_calls(), null when the module is not loaded.
 * @return string
 */
function performance_status_html( array $s, $d ) {
	// Off is neutral, not a warning: WordPress's own default is in effect.
	$chips = array( array( 'tone' => $s['enabled'] ? 'ok' : 'neutral', 'label' => \snt_speculative_loading_copy( $s['enabled'] )['state'] ) );
	if ( is_array( $d ) ) {
		$failed  = (int) $d['failed'];
		$chips[] = 0 === (int) $d['entries']
			? array( 'tone' => 'neutral', 'label' => __( 'No outbound call captured', 'signal-and-noise-tools' ) )
			/* translators: %s: failed outbound calls. */
			: array( 'tone' => $failed > 0 ? 'warn' : 'ok', 'label' => $failed > 0 ? sprintf( _n( '%s failed outbound call', '%s failed outbound calls', $failed, 'signal-and-noise-tools' ), number_format_i18n( $failed ) ) : __( 'No failed outbound call', 'signal-and-noise-tools' ) );
	}
	return \snt_kit_section(
		__( 'Performance', 'signal-and-noise-tools' ),
		\snt_kit_status_line( $chips ) . performance_moved_html() . \snt_kit_how_fold( __( 'Speculation Rules load the pages a visitor is likely to open next before the click.', 'signal-and-noise-tools' ), performance_intro_html() )
	);
}

/**
 * The Setup card: the Profile reference (mode, eagerness, exclusions,
 * support), one fold whose summary is the profile in effect.
 *
 * @param array $s From performance_state().
 * @return string
 */
function performance_setup_html( array $s ) {
	$mode = $s['enabled']
		? \snt_kit_code( 'prerender', false ) . ', ' . \snt_kit_esc( __( 'eagerness', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'moderate', false ) . ': '
			. \snt_kit_esc( __( "more aggressive than core's", 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'auto', false ) . '/' . \snt_kit_code( 'auto', false ) . '.'
		: \snt_kit_esc( __( 'WordPress’s default', 'signal-and-noise-tools' ) ) . ' (' . \snt_kit_code( 'auto', false ) . '/' . \snt_kit_code( 'auto', false ) . '): ' . \snt_kit_code( 'prefetch', false ) . ', '
			. \snt_kit_esc( __( 'eagerness', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_code( 'conservative', false ) . \snt_kit_esc( __( ', unless the host set another.', 'signal-and-noise-tools' ) );
	// 2026.10.4 audit: each Profile fact under its own label, not a bold lead-in.
	$profile = \snt_kit_kv(
		array(
			array( 'label' => __( 'Mode', 'signal-and-noise-tools' ), 'value' => $mode, 'html' => true ),
			array( 'label' => __( 'Excluded automatically', 'signal-and-noise-tools' ), 'value' => \snt_kit_code( '/contact/*', false ) . '.', 'html' => true ),
			array( 'label' => __( 'Browser support', 'signal-and-noise-tools' ), 'value' => __( 'only modern Chromium browsers act on speculation rules; others safely ignore them.', 'signal-and-noise-tools' ) ),
		)
	);
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array( array( 'title' => __( 'Profile', 'signal-and-noise-tools' ), 'summary' => \snt_speculative_loading_copy( $s['enabled'] )['profile'], 'body' => $profile ) )
	);
}

/**
 * The leaf: the status card and the Setup card side by side in one row (the
 * state and its reference, together), then the outbound calls
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
