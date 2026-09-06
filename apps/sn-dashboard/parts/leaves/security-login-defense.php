<?php
/**
 * S&N Dashboard — Security → Login defense, painted from the kit.
 *
 * The classic leaf (inc/login-defense.php, `sn_login_defense_render()`) paints
 * a read-only status card (worker version/deploy time, denylist size/refresh,
 * attribution, a link to the Analytics-dashboard Login defense view) followed
 * by the weekly security-digest settings card from inc/security-digest.php
 * (`snt_security_digest_render_settings()`): a toggle, last-sent/last-error
 * readout, and two submit buttons — Save and Send test digest — sharing one
 * classic `<form>` and one `sn_action` (`security_digest_save`), the click
 * differentiated by an extra `sn_digest_test` field only the test button
 * carries. Same readings, same field names, same one action; the kit's parts
 * instead of wp-admin's.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The worker-status readout, read the way the classic leaf reads it.
 *
 * @param mixed $status From sn_login_defense_status() (null when unreachable).
 * @return string
 */
function login_defense_status_html( $status ) {
	if ( ! is_array( $status ) ) {
		return '<p class="snt-prose">' . \snt_kit_esc( __( 'Login guard status unavailable (the Worker is not reachable or not deployed yet).', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$rows = array();
	$ver  = (string) ( $status['version'] ?? '' );
	if ( '' !== $ver ) {
		$rows[] = array(
			'label' => __( 'Worker', 'signal-and-noise-tools' ),
			'value' => sprintf(
				/* translators: 1: worker version, 2: deploy timestamp */
				__( 'sn-login-guard v%1$s (deployed %2$s)', 'signal-and-noise-tools' ),
				$ver,
				(string) ( $status['deployed_at'] ?? '?' )
			),
		);
	}
	$rows[] = array(
		'label' => __( 'Denylist', 'signal-and-noise-tools' ),
		'value' => sprintf(
			/* translators: 1: number of denylist ranges, 2: ISO timestamp */
			__( '%1$s ranges, updated %2$s', 'signal-and-noise-tools' ),
			number_format_i18n( (int) ( $status['denylistCount'] ?? 0 ) ),
			(string) ( $status['compiledAt'] ?? '?' )
		),
	);
	return \snt_kit_kv( $rows );
}

/**
 * The digest settings' state, read the way the classic leaf reads it.
 *
 * @return array{enabled:bool,last_sent:int,last_error:mixed}
 */
function login_defense_digest_state() {
	$sent_key  = defined( 'SN_SECURITY_DIGEST_LAST_SENT' ) ? SN_SECURITY_DIGEST_LAST_SENT : 'sn_security_digest_last_sent';
	$error_key = defined( 'SN_SECURITY_DIGEST_LAST_ERROR' ) ? SN_SECURITY_DIGEST_LAST_ERROR : 'sn_security_digest_last_error';
	return array(
		'enabled'    => function_exists( 'snt_security_digest_enabled' ) ? (bool) snt_security_digest_enabled() : false,
		'last_sent'  => (int) get_option( $sent_key, 0 ),
		'last_error' => get_option( $error_key ),
	);
}

/**
 * The weekly security-digest card: toggle + last-sent/last-error + Save +
 * Send test digest. The classic leaf shares ONE `<form>`/ONE `sn_action`
 * between both buttons, differentiated by whether `sn_digest_test` rode
 * along — an `<os-form>` fires one event per submit and cannot tell which of
 * two buttons was pressed, so this becomes two kit forms, each carrying the
 * same `sn_action`, the second pre-loading the differentiator as a hidden
 * field. See the file docblock and the report's `changed` note.
 *
 * @param array $d From login_defense_digest_state().
 * @return string
 */
function login_defense_digest_html( array $d ) {
	// Not `os-switch`: os-form's field reader (os-form.ts) only special-cases
	// OS-CHECKBOX / OS-CHECKBOX-LABEL / INPUT[type=checkbox] as booleans; any
	// other tag falls through to its static `value` attribute regardless of
	// on/off state, so a switch here would always submit '1'. `checkbox` is
	// what every sibling leaf uses (connections-indexnow.php, site-performance.php,
	// site-front-end.php, connections-webhooks.php) — see the refuter's finding.
	$checkbox  = \snt_kit_field(
		'checkbox',
		'sn_digest_enabled',
		__( 'Email a weekly security digest to the admin address', 'signal-and-noise-tools' ),
		$d['enabled'],
		array( 'value' => '1' )
	);
	// os-checkbox-label has no `description` prop (unlike os-switch), so the
	// helper sentence is painted separately, mirroring the classic
	// <p class="sn-field-helper"> at inc/security-digest.php:314.
	$checkbox .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Failed logins, recon probes, lockouts, login-guard blocks, and denylist freshness for the last 7 days. Sends every week, including quiet weeks: the quiet email is the heartbeat.', 'signal-and-noise-tools' ) ) . '</p>';

	$hints = '';
	if ( $d['last_sent'] > 0 ) {
		$hints .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: %s: human time diff */
				__( 'Last sent %s ago.', 'signal-and-noise-tools' ),
				human_time_diff( $d['last_sent'], time() )
			)
		) . '</p>';
	}
	if ( is_array( $d['last_error'] ) && ! empty( $d['last_error']['message'] ) ) {
		$hints .= \snt_kit_notice(
			'err',
			'<b>' . \snt_kit_esc( __( 'Last send failed:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $d['last_error']['message'] )
		);
	}

	// Reading order mirrors the classic card (inc/security-digest.php:314-336):
	// toggle, helper, last-sent, last-error, THEN both buttons — so the
	// readouts live inside the Save form's body, not between the two forms.
	$save_form = \snt_kit_form(
		'security_digest_save',
		$checkbox . $hints,
		array( 'submit' => __( 'Save', 'signal-and-noise-tools' ) )
	);
	$test_form = \snt_kit_form(
		'security_digest_save',
		\snt_kit_field( 'hidden', 'sn_digest_test', '', '1' ),
		array( 'submit' => __( 'Send test digest', 'signal-and-noise-tools' ) )
	);

	return \snt_kit_section(
		__( 'Weekly security digest', 'signal-and-noise-tools' ),
		$save_form . $test_form
	);
}

/**
 * The leaf.
 *
 * @param array<string,mixed> $ctx tab, sub, state, os.
 * @return string
 */
function paint_security_login_defense( array $ctx ) {
	unset( $ctx );

	$status        = function_exists( 'sn_login_defense_status' ) ? sn_login_defense_status() : null;
	$attribution   = function_exists( 'sn_login_defense_attribution' ) ? (string) sn_login_defense_attribution() : '';
	$analytics_url = function_exists( 'snt_analytics_page_url' ) ? (string) snt_analytics_page_url( array( 'sn_view' => 'login-defense' ) ) : '';

	$status_inner = login_defense_status_html( $status );
	if ( '' !== $attribution ) {
		$status_inner .= '<p class="snt-prose">' . \snt_kit_esc( $attribution ) . '</p>';
	}
	if ( '' !== $analytics_url ) {
		$status_inner .= \snt_kit_door( __( 'View login defense analytics →', 'signal-and-noise-tools' ), $analytics_url );
	}
	$out = \snt_kit_section( __( 'Login guard status', 'signal-and-noise-tools' ), $status_inner );

	// v7.2.1: the digest settings card mounts AFTER the status card (mirrors
	// the classic leaf's own ordering — see inc/login-defense.php).
	if ( function_exists( 'snt_security_digest_render_settings' ) ) {
		$out .= login_defense_digest_html( login_defense_digest_state() );
	}

	return $out;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/login-defense'] = __NAMESPACE__ . '\\paint_security_login_defense';
		return $painters;
	}
);
