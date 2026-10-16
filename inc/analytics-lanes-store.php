<?php
/**
 * SN Traffic Lanes: writing the lanes table.
 *
 * A run REPLACES each complete day it read (an upsert would keep a key the
 * fresh read no longer has), in one transaction, rolled back on any database
 * error. A day already stored under another rule version is left alone: a
 * rule change never relabels the days before it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The days among $days stored under a different rule version.
 *
 * @param string   $sensor Sensor.
 * @param string[] $days   Days.
 * @return string[]
 */
function sn_lanes_frozen_days( $sensor, array $days ) {
	global $wpdb;
	if ( array() === $days ) {
		return array();
	}
	$table = $wpdb->prefix . SN_LANES_TABLE;
	$in    = implode( ',', array_fill( 0, count( $days ), '%s' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL -- table is prefix + a plugin constant; every value is bound.
	$got = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT day FROM {$table} WHERE sensor = %s AND rule_version <> %d AND day IN ({$in})", array_merge( array( (string) $sensor, sn_lanes_rule_version() ), $days ) ) );
	return array_map( 'strval', (array) $got );
}

/**
 * Replace the given days for one sensor with the derived rows.
 *
 * @param string                         $sensor  'beacon' | 'machine'.
 * @param string[]                       $days    Complete days the read covered.
 * @param array<string,array<int,array>> $derived Day => lane rows.
 * @return bool Whether the write committed.
 */
function sn_lanes_store_days( $sensor, array $days, array $derived ) {
	global $wpdb;
	$days = array_values( array_diff( $days, sn_lanes_frozen_days( $sensor, $days ) ) );
	if ( array() === $days || ! is_object( $wpdb ) ) {
		return false;
	}
	$table   = $wpdb->prefix . SN_LANES_TABLE;
	$version = sn_lanes_rule_version();
	$in      = implode( ',', array_fill( 0, count( $days ), '%s' ) );
	$wpdb->query( 'START TRANSACTION' );
	// phpcs:ignore WordPress.DB.PreparedSQL -- table is prefix + a plugin constant; every value is bound.
	$ok = false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sensor = %s AND rule_version = %d AND day IN ({$in})", array_merge( array( (string) $sensor, $version ), $days ) ) );
	foreach ( $days as $day ) {
		foreach ( (array) ( $derived[ $day ] ?? array() ) as $r ) {
			if ( ! $ok ) {
				break 2;
			}
			$ok = false !== $wpdb->insert(
				$table,
				array(
					'day'          => $day,
					'sensor'       => (string) $sensor,
					'lane'         => (string) $r['lane'],
					'reason'       => (string) $r['reason'],
					'agent'        => (string) $r['agent'],
					'family'       => substr( (string) $r['family'], 0, 32 ),
					'purpose'      => substr( (string) $r['purpose'], 0, 16 ),
					'hits'         => (int) $r['hits'],
					'visits'       => null === $r['visits'] ? null : (int) $r['visits'],
					'visitor_days' => null === $r['visitor_days'] ? null : (int) $r['visitor_days'],
					'rule_version' => $version,
				)
			);
		}
	}
	if ( $ok && '' === (string) $wpdb->last_error ) {
		$wpdb->query( 'COMMIT' );
		return true;
	}
	$wpdb->query( 'ROLLBACK' );
	error_log( '[sn-lanes] ' . $sensor . ' write rolled back, the stored days are unchanged. ' . (string) $wpdb->last_error );
	return false;
}
