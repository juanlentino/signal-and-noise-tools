<?php
/**
 * SN Traffic Lanes: this site's own tools as a figure of their own.
 *
 * Traffic the site makes about itself (its contrast check, its verifiers) is
 * not traffic. The analytics Worker keeps those beacons out of the public
 * datasets (identity 6, the x-sn-first-party header, proven; identity 7, a
 * SignalNoise- user agent, a claim), so they are in none of the classes
 * human, suspect and bot. Here they are counted beside the classes, never
 * added to one, from the beacon rows the lanes table already stores per
 * site-local complete day. Read by the Quality view (both twins) and the
 * local door's get-analytics-summary; the remote twin does not carry it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Reason => side: the header proves it, the user agent only claims it.
const SN_LANES_FIRST_PARTY = array( 'first_party' => 'proven', 'first_party_claimed' => 'claimed' );

/**
 * The figure from stored lane rows. Beacon rows only: the machine sensor's
 * own-tools reads are reads, not page views. Null when no beacon day in the
 * window is stored (not measured, never a fabricated 0). PURE.
 *
 * @param array  $rows Stored rows (day, sensor, lane, reason, hits, visitor_days).
 * @param string $from First day, Y-m-d.
 * @param string $to   Last day, Y-m-d.
 * @return array{views:int,proven:array{views:int,visitor_days:int},claimed:array{views:int,visitor_days:int},from:string,through:string,days:int,partial:bool}|null
 */
function sn_lanes_first_party( array $rows, $from, $to ) {
	$zero = array( 'views' => 0, 'visitor_days' => 0 );
	$out  = array( 'views' => 0, 'proven' => $zero, 'claimed' => $zero );
	$days = array();
	foreach ( $rows as $r ) {
		$day = (string) ( $r['day'] ?? '' );
		if ( 'beacon' !== (string) ( $r['sensor'] ?? '' ) || $day < (string) $from || $day > (string) $to ) {
			continue;
		}
		$days[ $day ] = true;
		$side         = 'own_infra' === (string) ( $r['lane'] ?? '' ) ? ( SN_LANES_FIRST_PARTY[ (string) ( $r['reason'] ?? '' ) ] ?? '' ) : '';
		if ( '' === $side ) {
			continue;
		}
		$out[ $side ]['views']        += (int) ( $r['hits'] ?? 0 );
		$out[ $side ]['visitor_days'] += (int) ( $r['visitor_days'] ?? 0 );
		$out['views']                 += (int) ( $r['hits'] ?? 0 );
	}
	if ( array() === $days ) {
		return null;
	}
	ksort( $days );
	$span = (int) round( ( strtotime( $to . ' 12:00:00 UTC' ) - strtotime( $from . ' 12:00:00 UTC' ) ) / 86400 ) + 1;
	return $out + array(
		'from'    => (string) array_key_first( $days ),
		'through' => (string) array_key_last( $days ),
		'days'    => count( $days ),
		'partial' => count( $days ) < $span,
	);
}

/**
 * The figure for a window of site-local days, from the lanes table.
 *
 * @param string $from Y-m-d.
 * @param string $to   Y-m-d.
 * @return array|null
 */
function sn_lanes_first_party_figure( $from, $to ) {
	return function_exists( 'sn_lanes_read_rows' ) ? sn_lanes_first_party( sn_lanes_read_rows( $from, $to ), $from, $to ) : null;
}

/**
 * The one sentence both Quality twins paint beside the class totals. PURE.
 *
 * @param array|null $fp sn_lanes_first_party().
 * @return string
 */
function sn_lanes_first_party_line( $fp ) {
	if ( ! is_array( $fp ) ) {
		return 'This site\'s own tools, in none of the three classes: not measured in this range (the traffic lanes hold no complete day of it).';
	}
	$num = static fn( $n ) => function_exists( 'number_format_i18n' ) ? (string) number_format_i18n( (int) $n ) : number_format( (int) $n );
	return sprintf(
		'This site\'s own tools, in none of the three classes: %1$s page views, proven %2$s on %3$s visitor-days (the x-sn-first-party header) and claimed %4$s on %5$s visitor-days (a SignalNoise- user agent), complete days %6$s to %7$s%8$s.',
		$num( $fp['views'] ),
		$num( $fp['proven']['views'] ),
		$num( $fp['proven']['visitor_days'] ),
		$num( $fp['claimed']['views'] ),
		$num( $fp['claimed']['visitor_days'] ),
		(string) $fp['from'],
		(string) $fp['through'],
		empty( $fp['partial'] ) ? '' : ', so it covers part of this range'
	);
}

/**
 * The field's schema on the local summary (never on its remote twin). PURE.
 *
 * @return array
 */
function sn_lanes_first_party_schema() {
	$int  = array( 'type' => 'integer' );
	$half = array( 'type' => 'object', 'properties' => array( 'views' => $int, 'visitor_days' => $int ) );
	return array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'views'   => $int,
			'proven'  => $half,
			'claimed' => $half,
			'from'    => array( 'type' => 'string' ),
			'through' => array( 'type' => 'string' ),
			'days'    => $int,
			'partial' => array( 'type' => 'boolean' ),
		),
	);
}
