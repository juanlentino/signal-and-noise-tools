<?php
/**
 * S&N Dashboard: Settings > Security, painted from the kit. The second pane
 * of the Settings tab, the native twin of inc/admin-forms/security-settings.php.
 *
 * 1. The login address. This file WAS the Security > Login URL leaf
 *    (security-login.php) until 2026-11; the leaf held nothing but the slug
 *    setting and its own state, so it moved whole. Same readings as the
 *    classic section (sn_login_module_state()), same sentences
 *    (sn_login_status_copy()), same field and action (`login_slug`,
 *    `save_login`; no form at all when `SN_LOGIN_SLUG` locks the slug).
 * 2. Audit log "Retention (days)", from Security > Audit log, which keeps
 *    its log. Same field, same action (`audit_save_retention`). It was a
 *    Setup fold there; a setting on a settings pane is open.
 *
 * 2026-10-16 (docs/kit-leaf-anatomy.md): the login state leads as a status
 * line, the module's explanation is a How this works fold, and the
 * emergency-unlock reference is a Setup fold beside the slug form. A save is
 * answered on the tab and leaf it was posted from (sn-dashboard.os.php, the
 * `post` action), so it lands back on this pane.
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
 * The audit log retention box: one number field and its Save.
 *
 * @return string
 */
function security_retention_html() {
	$field = \snt_kit_field(
		'number',
		'audit_retention_days',
		__( 'Retention (days)', 'signal-and-noise-tools' ),
		(int) \sn_setting( 'audit.retention_days', 90 ),
		array(
			'min'  => 7,
			'max'  => 365,
			'hint' => __( 'How long to keep the daily counts, the successful-login records and the change records, 7 to 365 days. A daily cleanup deletes anything older.', 'signal-and-noise-tools' ),
		)
	);
	return \snt_kit_section( __( 'Audit log retention', 'signal-and-noise-tools' ), \snt_kit_form( 'audit_save_retention', $field, array( 'submit' => __( 'Save retention', 'signal-and-noise-tools' ) ) ) );
}

/**
 * The pane: the login status, then one row of three boxes, the slug form,
 * the emergency unlock beside it, and audit log retention.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_settings_security( array $ctx ) {
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
	return login_status_html( $s ) . \snt_kit_grid( array( $slug, login_setup_html( $s ), security_retention_html() ), 260 );
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['settings/security'] = __NAMESPACE__ . '\\paint_settings_security';
		return $painters;
	}
);
