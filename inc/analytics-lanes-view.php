<?php
/**
 * SN Traffic Lanes: the report's words and tables, shared by both twins
 * (the classic leaf in inc/analytics-lanes-render.php, the native painter
 * apps/sn-dashboard/parts/leaves/tools-lanes.php), so they say the same thing.
 * Plain strings and numbers only: escaping happens at each sink.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_LANES_SENSOR_WORDS = array( 'beacon' => 'Beacon (page views)', 'machine' => 'Machine sensor (reads)' );

/**
 * The view model. PURE.
 *
 * @param array $report sn_lanes_report().
 * @return array{alert:array{kind:string,text:string},notes:string[],tables:array<int,array>}
 */
function sn_lanes_view( array $report ) {
	$since   = (array) $report['since'];
	$invalid = (array) $report['token_invalid'];
	$bad     = (int) $invalid['beacon'] + (int) $invalid['machine'];
	$notes   = array(
		sprintf( 'Today is pending: the lanes cover complete days only, through %s.', $report['yesterday'] ),
		sprintf( 'Rule version %d. A day stored under an earlier rule version keeps its labels.', (int) $report['rule_version'] ),
	);
	foreach ( SN_LANES_SENSOR_WORDS as $sensor => $words ) {
		$notes[] = '' !== (string) $since[ $sensor ]
			? sprintf( '%s: lanes since %s.', $words, $since[ $sensor ] )
			: sprintf( '%s: lanes not seen yet, so owner lanes are not measured on this sensor.', $words );
	}
	$words_run = array( 'wrote' => 'wrote its days', 'not_written' => 'read, but did not write (a database error, or every day frozen under an earlier rule)', 'read_failed' => 'could not read the sensor; the stored days are kept', 'truncated' => 'read a truncated result and wrote nothing', 'empty' => 'read nothing and wrote nothing' );
	foreach ( SN_LANES_SENSOR_WORDS as $sensor => $words ) {
		$run     = (array) ( $report['last_run'][ $sensor ] ?? array() );
		$notes[] = isset( $run['outcome'], $words_run[ $run['outcome'] ] )
			? sprintf( '%s: the last run (%s UTC) %s.', $words, gmdate( 'Y-m-d H:i', (int) ( $run['at'] ?? 0 ) ), $words_run[ $run['outcome'] ] )
			: sprintf( '%s: the lanes rollup has not run yet.', $words );
	}
	$notes[] = 'Beacon days are site-local days. Machine sensor days are the sensor\'s UTC dates.';
	$notes[] = 'Views (beacon) and reads (machine) add up across lanes to each sensor\'s total. Visitor-days are counted per lane and do not add up across lanes.';
	$tables = array();
	foreach ( SN_LANES_BY_SENSOR as $sensor => $lanes ) {
		$tables[] = sn_lanes_view_windows( $report, $sensor );
	}
	foreach ( SN_LANES_BY_SENSOR as $sensor => $lanes ) {
		$tables[] = sn_lanes_view_reasons( $report, $sensor );
	}
	foreach ( SN_LANES_BY_SENSOR as $sensor => $lanes ) {
		$tables[] = sn_lanes_view_daily( $report, $sensor );
	}
	return array(
		'alert'  => array(
			'kind' => $bad > 0 ? 'warning' : 'info',
			'text' => sprintf( 'Invalid owner tokens, last 30 days: %d on the beacon, %d on the machine sensor.', (int) $invalid['beacon'], (int) $invalid['machine'] )
				. ( $bad > 0 ? ' Something presented an owner token that did not verify: an expired token, a key mismatch, or a forgery.' : '' ),
		),
		'notes'  => $notes,
		'tables' => $tables,
	);
}

/**
 * Per-lane totals for 7, 30 and 90 days. PURE.
 *
 * @param array  $report Report.
 * @param string $sensor Sensor.
 * @return array
 */
function sn_lanes_view_windows( array $report, $sensor ) {
	$cols    = array( array( 'key' => 'lane', 'label' => 'Lane' ) );
	$partial = array();
	foreach ( $report['windows'] as $n => $w ) {
		$cols[] = array( 'key' => 'd' . $n, 'label' => $n . ' days', 'align' => 'end' );
		if ( ! empty( $w['sensors'][ $sensor ]['partial'] ) ) {
			$partial[] = $n . ' days';
		}
	}
	$rows = array();
	foreach ( array_merge( SN_LANES_BY_SENSOR[ $sensor ], array( 'total' ) ) as $lane ) {
		$row = array( 'lane' => 'total' === $lane ? 'All lanes' : sn_lanes_label( $lane ) );
		foreach ( $report['windows'] as $n => $w ) {
			$s              = $w['sensors'][ $sensor ];
			$row[ 'd' . $n ] = 'total' === $lane ? (int) $s['total'] : (int) ( $s['lanes'][ $lane ] ?? 0 );
		}
		$rows[] = $row;
	}
	$desc = array() === $partial ? 'Every range starts on or after lanes began on this sensor.' : 'Partial: ' . implode( ', ', $partial ) . ' start before lanes began on this sensor, so their owner lanes undercount.';
	return array( 'heading' => SN_LANES_SENSOR_WORDS[ $sensor ] . ', by lane', 'description' => $desc, 'columns' => $cols, 'rows' => $rows );
}

/**
 * The reason split for 7, 30 and 90 days, largest in 90 days first. PURE.
 *
 * @param array  $report Report.
 * @param string $sensor Sensor.
 * @return array
 */
function sn_lanes_view_reasons( array $report, $sensor ) {
	$cols = array( array( 'key' => 'reason', 'label' => 'Lane and reason' ) );
	foreach ( $report['windows'] as $n => $w ) {
		$cols[] = array( 'key' => 'd' . $n, 'label' => $n . ' days', 'align' => 'end' );
	}
	$rows = array();
	foreach ( array_keys( (array) $report['windows'][90]['sensors'][ $sensor ]['reasons'] ) as $rk ) {
		$row = array( 'reason' => (string) $rk );
		foreach ( $report['windows'] as $n => $w ) {
			$row[ 'd' . $n ] = (int) ( $w['sensors'][ $sensor ]['reasons'][ $rk ] ?? 0 );
		}
		$rows[] = $row;
	}
	return array( 'heading' => SN_LANES_SENSOR_WORDS[ $sensor ] . ', by reason', 'description' => 'Why each lane holds what it holds.', 'columns' => $cols, 'rows' => $rows );
}

/**
 * The last fourteen complete days, one row per day, newest first. PURE.
 *
 * @param array  $report Report.
 * @param string $sensor Sensor.
 * @return array
 */
function sn_lanes_view_daily( array $report, $sensor ) {
	$cols = array( array( 'key' => 'day', 'label' => 'Day' ) );
	foreach ( SN_LANES_BY_SENSOR[ $sensor ] as $lane ) {
		$cols[] = array( 'key' => $lane, 'label' => sn_lanes_label( $lane ), 'align' => 'end' );
	}
	$rows = array();
	foreach ( (array) $report['daily'] as $day => $by ) {
		if ( ! isset( $by[ $sensor ] ) ) {
			continue;
		}
		$row = array( 'day' => (string) $day );
		foreach ( SN_LANES_BY_SENSOR[ $sensor ] as $lane ) {
			$row[ $lane ] = (int) ( $by[ $sensor ][ $lane ] ?? 0 );
		}
		$rows[] = $row;
	}
	return array( 'heading' => SN_LANES_SENSOR_WORDS[ $sensor ] . ', by day', 'description' => 'The last ' . SN_LANES_DAILY . ' complete days; a day with nothing stored has no row.', 'columns' => $cols, 'rows' => $rows );
}
