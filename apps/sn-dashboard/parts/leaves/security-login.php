<?php
/**
 * S&N Dashboard — Security → Login URL, painted from the kit.
 *
 * The classic leaf (inc/admin-forms/login.php, `sn_admin_render_login_section()`)
 * paints a module status, one form (`sn_action=save_login`, field `login_slug`;
 * no form at all when the `SN_LOGIN_SLUG` constant locks the slug), the
 * current login URL, and the emergency-unlock callout. Same readings
 * (sn_login_module_state()), same sentences (sn_login_status_copy()), same
 * field, same handler; the kit's parts instead of wp-admin's.
 *
 * 2026-10-16 (the leaf anatomy, docs/kit-leaf-anatomy.md): the state leads
 * as a status line, the module's explanation is a How this works fold, and
 * the emergency-unlock reference is a Setup fold beside the slug form.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The status card: the module's state on the leaf's status line, in the
 * classic page's words (sn_login_status_copy(), inc/admin-forms/login.php).
 * A state that needs the owner keeps its open notice; the active state's
 * sentence is a hint. How this works sits at the card's foot.
 *
 * @param array $s From sn_login_module_state().
 * @return string
 */
function login_status_html( array $s ) {
	$c     = \sn_login_status_copy( $s['state'] );
	$chips = array( array( 'tone' => $c['kind'], 'label' => $c['pill'], 'title' => $c['title'] ) );
	if ( $s['locked'] ) {
		$chips[] = array( 'tone' => 'neutral', 'label' => __( 'Slug pinned in wp-config.php', 'signal-and-noise-tools' ) );
	}
	$body = 'ok' === $c['kind']
		? '<p class="snt-hint"><b>' . \snt_kit_esc( $c['title'] ) . '.</b> ' . \snt_kit_esc( $c['body'] ) . '</p>'
		: \snt_kit_notice( $c['kind'], '<b>' . \snt_kit_esc( $c['title'] ) . '</b> ' . \snt_kit_badge( $c['kind'], $c['pill'] ) . '<br>' . \snt_kit_esc( $c['body'] ) );
	return \snt_kit_section(
		__( 'Login URL', 'signal-and-noise-tools' ),
		\snt_kit_status_line( $chips ) . $body
		. \snt_kit_how_fold(
			__( 'A custom slug stands in for /wp-login.php.', 'signal-and-noise-tools' ),
			'<p class="snt-prose">' . \snt_kit_esc( __( 'Custom login URL module: replaces /wp-login.php with a configurable slug. Designed to mask the WordPress login surface from automated bots without changing real user flows (password-reset emails, logout redirects, etc. are rewritten automatically).', 'signal-and-noise-tools' ) ) . '</p>'
		)
	);
}

/**
 * The Setup card: Emergency unlock, the two wp-config.php constants, as one
 * fold whose summary says which one is set. Open while one is set: the
 * classic copy asks to remove it once access is back.
 *
 * @param array $s From sn_login_module_state().
 * @return string
 */
function login_setup_html( array $s ) {
	$bypass  = 'bypassed' === $s['state'];
	$summary = $bypass ? __( 'SN_LOGIN_BYPASS is set', 'signal-and-noise-tools' ) : ( $s['locked'] ? __( 'SN_LOGIN_SLUG is set', 'signal-and-noise-tools' ) : __( 'No unlock constant set', 'signal-and-noise-tools' ) );
	$body    = '<p class="snt-prose">' . \snt_kit_esc( __( "If you ever lock yourself out (forgot the slug, can't reach the login form), add either of these constants to wp-config.php via SSH or your host's file manager:", 'signal-and-noise-tools' ) ) . '</p>'
		. \snt_kit_code( "// Option 1: pin the slug. Reachable at /<slug-here>.\ndefine( 'SN_LOGIN_SLUG', 'your-fallback-slug' );\n\n// Option 2: disable the module entirely. Restores /wp-login.php.\ndefine( 'SN_LOGIN_BYPASS', true );" )
		. '<p class="snt-prose">' . \snt_kit_esc( __( "The constants take priority over the setting and persist across plugin updates. Remove them once you've regained access.", 'signal-and-noise-tools' ) ) . '</p>';
	return \snt_kit_setup_card(
		__( 'Setup', 'signal-and-noise-tools' ),
		array(
			array(
				'title'   => __( 'Emergency unlock', 'signal-and-noise-tools' ),
				'summary' => $summary,
				'body'    => $body,
				'open'    => $bypass || $s['locked'],
			),
		)
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
	$s = \sn_login_module_state();

	$slug_row = $s['locked']
		? \snt_kit_static(
			__( 'Slug', 'signal-and-noise-tools' ),
			\snt_kit_tag( 'os-text-field', array( 'type' => 'text', 'value' => $s['slug'], 'disabled' => true ) ),
			\snt_kit_esc( __( 'Locked. The SN_LOGIN_SLUG constant in wp-config.php is overriding this field. Remove the constant to edit here.', 'signal-and-noise-tools' ) )
		)
		: \snt_kit_field( 'text', 'login_slug', __( 'Slug', 'signal-and-noise-tools' ), $s['slug'], array( 'placeholder' => 'sn-login', 'hint' => __( 'Letters, numbers, dashes only. Avoid common guesses (admin, login, panel, etc.).', 'signal-and-noise-tools' ) ) );
	$url_row = \snt_kit_static(
		__( 'Current login URL', 'signal-and-noise-tools' ),
		\snt_kit_link( $s['login_url'], $s['login_url'] ),
		\snt_kit_esc( \sn_login_status_copy( $s['state'] )['url_hint'] )
	);
	// Locked: no form. A constant decides the slug, so a Save could only fail
	// (it posted no slug and answered "Login slug cannot be empty").
	$form = $s['locked'] ? \snt_kit_tag( 'div', array( 'class' => 'snt-form snt-form--rows' ), $slug_row . $url_row ) : \snt_kit_form( 'save_login', $slug_row . $url_row, array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) ) );
	// Siblings: both are how the login surface is reached -- one sets the door,
	// the other is the way back in when you lose it.
	$slug = \snt_kit_section(
		__( 'Custom login slug', 'signal-and-noise-tools' ),
		$form,
		__( 'The path segment used in place of wp-login.php.', 'signal-and-noise-tools' )
	);
	return login_status_html( $s ) . \snt_kit_grid( array( $slug, login_setup_html( $s ) ) );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/login'] = __NAMESPACE__ . '\\paint_security_login';
		return $painters;
	}
);
