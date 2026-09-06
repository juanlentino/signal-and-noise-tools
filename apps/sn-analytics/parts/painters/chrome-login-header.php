<?php
/**
 * S&N Analytics — chrome/login-header: login-defense range + Overview KPIs.
 *
 * Classic: sn_login_defense_render_header() in inc/login-defense-analytics.php.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Analytics\Painters;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * @param array<string,mixed> $ctx Frame context.
 * @return string
 */
function paint_chrome_login_header( array $ctx ) {
	$days    = (int) ( $ctx['lg_range'] ?? 7 );
	$allowed = array( 7, 30, 90 );
	if ( ! in_array( $days, $allowed, true ) ) {
		$days = function_exists( 'sn_login_defense_resolve_days' ) ? (int) sn_login_defense_resolve_days() : 7;
	}
	$row = '<span class="snt-toolbar__k">' . \snt_kit_esc( __( 'Range', 'signal-and-noise-tools' ) ) . '</span>';
	foreach ( $allowed as $token ) {
		$row .= pick( (string) $token . 'd', 'lg_range', (string) $token, $token === $days );
	}
	$html = '<div class="snt-toolbar"><div class="snt-toolbar__group">' . $row . '</div></div>';

	$kpis = array();
	if ( function_exists( 'sn_login_defense_decisions_sql' ) && function_exists( 'sn_analytics_query' ) && function_exists( 'sn_login_defense_kpis_from_rows' ) ) {
		$dec  = sn_analytics_query( sn_login_defense_decisions_sql( $days ) ) ?: array();
		$kpis = sn_login_defense_kpis_from_rows( $dec );
	}
	$cards = array();
	foreach ( array(
		'blocked' => __( 'Blocked', 'signal-and-noise-tools' ),
		'passed'  => __( 'Passed', 'signal-and-noise-tools' ),
		'rate'    => __( 'Block rate', 'signal-and-noise-tools' ),
	) as $key => $label ) {
		if ( isset( $kpis[ $key ] ) ) {
			$cards[] = array( 'l' => $label, 'n' => is_numeric( $kpis[ $key ] ) && 'rate' === $key ? (int) $kpis[ $key ] . '%' : num( $kpis[ $key ] ) );
		}
	}
	$pills = '';
	foreach ( array( 'block', 'throttle', 'lockout', 'pass', 'bypass', 'killswitch', 'degraded', 'failopen' ) as $decision ) {
		$pills .= \snt_kit_chip( $decision . ' ' . num( $kpis['breakdown'][ $decision ] ?? 0 ) );
	}
	$html .= \snt_kit_section( __( 'Overview', 'signal-and-noise-tools' ), stats( $cards ) . $pills );
	return $html;
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['chrome/login-header'] = __NAMESPACE__ . '\\paint_chrome_login_header';
		return $painters;
	}
);
