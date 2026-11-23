<?php
/**
 * S&N Dashboard: Security > Login defense, painted from the kit.
 *
 * The classic leaf (inc/login-defense.php, `sn_login_defense_render()`) paints
 * a read-only status card (worker version/deploy time, denylist size/refresh,
 * attribution, a link to the Analytics-dashboard Login defense view). Same
 * readings; the kit's parts instead of wp-admin's. Beside the status card, a
 * "Breached passwords" box (security-login-defense-parts.php) carries the
 * viewer's login memo and the Site Health row's figures. The weekly security
 * digest's settings sat here until they became a pane of Settings
 * (settings-notifications.php); one line points there. No form is painted.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

require_once __DIR__ . '/security-login-defense-parts.php';

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
	if ( '' !== (string) ( $status['version'] ?? '' ) ) {
		$rows[] = array(
			'label' => __( 'Worker', 'signal-and-noise-tools' ),
			'value' => \sn_login_defense_worker_text( $status ),
		);
	}
	$rows[] = array(
		'label' => __( 'Denylist', 'signal-and-noise-tools' ),
		'value' => \sn_login_defense_denylist_text( $status ),
	);
	return \snt_kit_kv( $rows );
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
	$status_box = \snt_kit_section( __( 'Login guard status', 'signal-and-noise-tools' ), $status_inner );
	// 17.2.1: boxes share a row. 2026-10-12 audit: the digest's two forms sat
	// alone at full width under them, half the window empty; the three boxes
	// are of a height, so they share one row. A side that paints nothing
	// (module absent) is dropped from the row.
	// The weekly digest's box shared this row until its switch moved to
	// Settings > Notifications and reports; one line under the row says so.
	$moved = '<p class="snt-hint">' . \snt_kit_esc( __( 'The weekly security digest switch moved to', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_go( __( 'Settings › Notifications and reports', 'signal-and-noise-tools' ), array( 'tab' => 'settings', 'sub' => 'notifications', 'current' => 'security' ) ) . '.</p>';
	return \snt_kit_grid( array( $status_box, login_defense_breach_html() ), 280 ) . $moved;
}

add_filter(
	'snt_os_dashboard_painters',
	static function ( array $painters ) {
		$painters['security/login-defense'] = __NAMESPACE__ . '\\paint_security_login_defense';
		return $painters;
	}
);
