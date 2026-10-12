<?php
/**
 * Signal & Noise Tools: Security › Login defense, the status lines both
 * twins print, split from inc/login-defense.php (house line cap, review on
 * #2022): the worker and the denylist, their times as how long ago, and the
 * classic Breached passwords box.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the worker status block from a probed status array: deployed worker
 * version + deploy time (parity with the analytics worker-version card), then
 * denylist size + last refresh. Extracted from the panel for testability.
 */
function sn_login_defense_render_status( $status ) {
	if ( ! is_array( $status ) ) {
		echo '<p>' . esc_html__( 'Login guard status unavailable (the Worker is not reachable or not deployed yet).', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	if ( '' !== (string) ( $status['version'] ?? '' ) ) {
		/* translators: %s: the worker and when it was deployed. */
		echo '<p>' . esc_html( sprintf( __( 'Worker: %s.', 'signal-and-noise-tools' ), sn_login_defense_worker_text( $status ) ) ) . '</p>';
	}
	/* translators: %s: the denylist's size and refresh time. */
	echo '<p>' . esc_html( sprintf( __( 'Denylist: %s.', 'signal-and-noise-tools' ), sn_login_defense_denylist_text( $status ) ) ) . '</p>';
}

/**
 * A time the worker reports, as both twins say it: how long ago (2026-10-12
 * audit: "2026-10-03T23:00:25.5453097" and "16:07 UTC" printed as sent).
 * A value that is not a readable time is printed as sent, never dropped.
 *
 * @param mixed $when The worker's timestamp (UTC).
 * @return string
 */
function sn_login_defense_ago( $when ) {
	$at = is_string( $when ) && '' !== $when ? strtotime( $when . ( preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $when ) ? '' : 'Z' ) ) : false;
	/* translators: %s: how long ago. */
	return false === $at ? (string) $when : sprintf( __( '%s ago', 'signal-and-noise-tools' ), human_time_diff( min( $at, time() ), time() ) );
}

/**
 * The worker line both twins print: "sn-login-guard v1.14.1, deployed 9 days ago".
 *
 * @param array $status sn_login_defense_status().
 * @return string
 */
function sn_login_defense_worker_text( array $status ) {
	/* translators: 1: worker version, 2: how long ago it was deployed. */
	return sprintf( __( 'sn-login-guard v%1$s, deployed %2$s', 'signal-and-noise-tools' ), (string) ( $status['version'] ?? '' ), '' !== (string) ( $status['deployed_at'] ?? '' ) ? sn_login_defense_ago( (string) $status['deployed_at'] ) : __( 'at a time not reported', 'signal-and-noise-tools' ) );
}

/**
 * The denylist line both twins print: "3,922 IPv4 and 81 IPv6 ranges, updated
 * 3 hours ago". The worker answers null when its list meta is
 * unreadable (sn-login-guard 1.12.1): that is not reported, never a 0.
 *
 * @param array $status sn_login_defense_status().
 * @return string
 */
function sn_login_defense_denylist_text( array $status ) {
	if ( ! is_numeric( $status['denylistCount'] ?? null ) ) {
		return __( 'size not reported by the worker', 'signal-and-noise-tools' );
	}
	$size = is_numeric( $status['denylist6Count'] ?? null )
		/* translators: 1: IPv4 ranges, 2: IPv6 ranges. */
		? sprintf( __( '%1$s IPv4 and %2$s IPv6 ranges', 'signal-and-noise-tools' ), number_format_i18n( (int) $status['denylistCount'] ), number_format_i18n( (int) $status['denylist6Count'] ) )
		/* translators: %s: ranges. */
		: sprintf( __( '%s ranges', 'signal-and-noise-tools' ), number_format_i18n( (int) $status['denylistCount'] ) );
	$at = (string) ( $status['compiledAt'] ?? '' );
	/* translators: 1: the size, 2: how long ago the list was compiled. */
	return '' === $at || false === strtotime( $at ) ? $size : sprintf( __( '%1$s, updated %2$s', 'signal-and-noise-tools' ), $size, sn_login_defense_ago( $at ) );
}

/**
 * Breached passwords, classic: the native box's verdict and three rows
 * (sn_hibp_health(), sn_hibp_surface_rows()), the same words.
 *
 * @return void
 */
function sn_login_defense_render_breach() {
	if ( ! function_exists( 'sn_hibp_surface_data' ) || ! function_exists( 'sn_hibp_health' ) || ! function_exists( 'sn_hibp_surface_rows' ) ) {
		return;
	}
	$sd = sn_hibp_surface_data();
	$hv = sn_hibp_health( $sd, time() );
	echo '<div class="sn-fieldset"><h2 class="sn-fieldset-h">' . esc_html__( 'Breached passwords', 'signal-and-noise-tools' ) . '</h2>';
	echo '<p' . ( 'good' === (string) $hv['status'] ? ' class="sn-field-helper"' : '' ) . '>' . esc_html( (string) $hv['summary'] ) . '</p><table class="widefat striped"><tbody>';
	foreach ( sn_hibp_surface_rows( $sd, $hv ) as $row ) {
		echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . ( empty( $row['tone'] ) ? esc_html( $row['value'] ) : '<strong>' . esc_html( $row['value'] ) . '</strong>' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
}
