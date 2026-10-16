<?php
/**
 * SN Traffic Lanes: the report's reads. Only the lanes table is read.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The lanes each sensor can hold, in display order.
const SN_LANES_BY_SENSOR = array(
	'beacon'  => array( 'human', 'suspected_automation', 'owner', 'owner_agent', 'unverified_owner' ),
	'machine' => array( 'verified_bot', 'claimed_bot', 'unknown_machine', 'own_infra', 'owner_agent', 'unverified_owner' ),
);
const SN_LANES_WINDOWS = array( 7, 30, 90 );
const SN_LANES_DAILY   = 14;

/**
 * A lane's words. PURE.
 *
 * @param string $lane Lane key.
 * @return string
 */
function sn_lanes_label( $lane ) {
	$labels = array(
		'owner'                => 'Owner, enrolled device',
		'owner_agent'          => 'Owner, signed agent',
		'unverified_owner'     => 'Owner signal, not verified',
		'own_infra'            => 'This site\'s own tools',
		'verified_bot'         => 'Verified bot',
		'claimed_bot'          => 'Claimed bot, not proven',
		'unknown_machine'      => 'Unknown machine',
		'suspected_automation' => 'Suspected automation',
		'human'                => 'Human',
	);
	return $labels[ (string) $lane ] ?? (string) $lane;
}

/**
 * Stored rows for a day range.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array<int,array<string,mixed>>
 */
function sn_lanes_read_rows( $from, $to ) {
	global $wpdb;
	if ( ! is_object( $wpdb ) ) {
		return array();
	}
	$table = $wpdb->prefix . SN_LANES_TABLE;
	// phpcs:ignore WordPress.DB.PreparedSQL -- table is prefix + a plugin constant; values are bound.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, sensor, lane, reason, hits, rule_version FROM {$table} WHERE day >= %s AND day <= %s", (string) $from, (string) $to ), ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

/**
 * The report from stored rows. PURE.
 *
 * @param array  $rows      Stored rows (day, sensor, lane, reason, hits, rule_version).
 * @param string $yesterday Last complete site-local day, Y-m-d.
 * @param array  $since     { beacon, machine } lanes_since days ('' = not yet).
 * @return array<string,mixed>
 */
function sn_lanes_report( array $rows, $yesterday, array $since ) {
	$ago     = static fn( $n ) => gmdate( 'Y-m-d', strtotime( $yesterday . ' 12:00:00 UTC' ) - $n * 86400 );
	$windows = array();
	foreach ( SN_LANES_WINDOWS as $n ) {
		$from = $ago( $n - 1 );
		$w    = array( 'days' => $n, 'from' => $from, 'to' => (string) $yesterday, 'sensors' => array() );
		foreach ( SN_LANES_BY_SENSOR as $sensor => $lanes ) {
			$s = (string) ( $since[ $sensor ] ?? '' );
			$w['sensors'][ $sensor ] = array( 'lanes' => array_fill_keys( $lanes, 0 ), 'reasons' => array(), 'total' => 0, 'partial' => '' === $s || $from < $s );
		}
		$windows[ $n ] = $w;
	}
	$daily   = array();
	$invalid = array( 'beacon' => 0, 'machine' => 0 );
	$first   = $ago( SN_LANES_DAILY - 1 );
	$version = array();
	foreach ( $rows as $r ) {
		$day    = (string) ( $r['day'] ?? '' );
		$sensor = (string) ( $r['sensor'] ?? '' );
		$lane   = (string) ( $r['lane'] ?? '' );
		$hits   = (int) ( $r['hits'] ?? 0 );
		if ( ! isset( SN_LANES_BY_SENSOR[ $sensor ] ) || $day > $yesterday ) {
			continue;
		}
		$version[ (int) ( $r['rule_version'] ?? 0 ) ] = true;
		foreach ( $windows as $n => $w ) {
			if ( $day < $w['from'] ) {
				continue;
			}
			$cell = &$windows[ $n ]['sensors'][ $sensor ];
			$cell['lanes'][ $lane ] = (int) ( $cell['lanes'][ $lane ] ?? 0 ) + $hits;
			$rk                     = sn_lanes_label( $lane ) . ' (' . (string) ( $r['reason'] ?? '' ) . ')';
			$cell['reasons'][ $rk ] = (int) ( $cell['reasons'][ $rk ] ?? 0 ) + $hits;
			$cell['total']         += $hits;
			unset( $cell );
		}
		if ( 'owner_token_invalid' === (string) ( $r['reason'] ?? '' ) && $day >= $windows[30]['from'] ) {
			$invalid[ $sensor ] += $hits;
		}
		if ( $day >= $first ) {
			$daily[ $day ][ $sensor ][ $lane ] = (int) ( $daily[ $day ][ $sensor ][ $lane ] ?? 0 ) + $hits;
		}
	}
	foreach ( $windows as $n => $w ) {
		foreach ( $w['sensors'] as $sensor => $s ) {
			arsort( $windows[ $n ]['sensors'][ $sensor ]['reasons'] );
		}
	}
	krsort( $daily );
	ksort( $version );
	return array(
		'yesterday'     => (string) $yesterday,
		'since'         => array( 'beacon' => (string) ( $since['beacon'] ?? '' ), 'machine' => (string) ( $since['machine'] ?? '' ) ),
		'rule_version'  => sn_lanes_rule_version(),
		'stored_rules'  => array_keys( $version ),
		'windows'       => $windows,
		'daily'         => $daily,
		'token_invalid' => $invalid,
		'last_run'      => function_exists( 'sn_lanes_last_run' ) ? sn_lanes_last_run() : array(),
	);
}

/**
 * The live report: the last complete site-local day, 90 days of rows.
 *
 * @return array<string,mixed>
 */
function sn_lanes_report_data() {
	$tz   = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$days = sn_lanes_complete_days( $tz );
	$last = array() !== $days ? (string) end( $days ) : gmdate( 'Y-m-d', time() - 86400 );
	$from = gmdate( 'Y-m-d', strtotime( $last . ' 12:00:00 UTC' ) - ( max( SN_LANES_WINDOWS ) - 1 ) * 86400 );
	return sn_lanes_report( sn_lanes_read_rows( $from, $last ), $last, sn_lanes_since() );
}
