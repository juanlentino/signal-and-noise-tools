<?php
/**
 * S&N Analytics — view/login-defense.
 *
 * Classic: sn_login_defense_render_body() in inc/login-defense-analytics.php.
 * Header KPIs live in chrome/login-header; this is the body tables.
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
function paint_view_login_defense( array $ctx ) {
	$days = (int) ( $ctx['lg_range'] ?? 7 );
	if ( ! function_exists( 'sn_analytics_query' ) ) {
		return '';
	}
	$asn  = function_exists( 'sn_login_defense_top_asn_sql' ) ? ( sn_analytics_query( sn_login_defense_top_asn_sql( $days, 10 ) ) ?: array() ) : array();
	$ctry = function_exists( 'sn_login_defense_top_country_sql' ) ? ( sn_analytics_query( sn_login_defense_top_country_sql( $days, 10 ) ) ?: array() ) : array();
	$asn_rows = array();
	foreach ( (array) $asn as $row ) {
		if ( is_array( $row ) ) {
			$asn_rows[] = array( 'value' => (string) ( $row['asorg'] ?? '' ), 'views' => $row['hits'] ?? 0 );
		}
	}
	$ctry_rows = array();
	foreach ( (array) $ctry as $row ) {
		if ( is_array( $row ) ) {
			$ctry_rows[] = array( 'value' => (string) ( $row['country'] ?? '' ), 'views' => $row['hits'] ?? 0 );
		}
	}
	return '<div class="snt-grid">'
		. dim_table( __( 'Top attacker networks', 'signal-and-noise-tools' ), $asn_rows, __( 'No attacker-network rows in this range.', 'signal-and-noise-tools' ) )
		. dim_table( __( 'Top attacker countries', 'signal-and-noise-tools' ), $ctry_rows, __( 'No attacker-country rows in this range.', 'signal-and-noise-tools' ) )
		. '</div>';
}

add_filter(
	'snt_os_analytics_painters',
	static function ( array $painters ) {
		$painters['view/login-defense'] = __NAMESPACE__ . '\\paint_view_login_defense';
		return $painters;
	}
);
