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
 * The view model. PURE. `notes` and the per-lane tables are the summary and
 * paint open; the reason split, the daily view and `reference` (how a lane
 * is decided) are detail and reference, each folded behind a `fold` summary
 * that states its answer closed.
 *
 * @param array $report sn_lanes_report().
 * @return array{alert:array{kind:string,text:string},notes:string[],tables:array<int,array>,reference:array{summary:string,lines:string[]}}
 */
function sn_lanes_view( array $report ) {
	$since   = (array) $report['since'];
	$invalid = (array) $report['token_invalid'];
	$bad     = (int) $invalid['beacon'] + (int) $invalid['machine'];
	$notes   = array( sprintf( 'Today is pending: the lanes cover complete days only, through %s.', $report['yesterday'] ) );
	$runs    = array( 'wrote' => 'wrote its days', 'not_written' => 'read, but did not write (a database error, or every day frozen under an earlier rule)', 'read_failed' => 'could not read the sensor; the stored days are kept', 'truncated' => 'read a truncated result and wrote nothing', 'empty' => 'read nothing and wrote nothing', 'overcap_failed' => 'was skipped: the over-cap visitor-day list did not read; the stored days are kept' );
	foreach ( SN_LANES_SENSOR_WORDS as $sensor => $words ) {
		$run     = (array) ( $report['last_run'][ $sensor ] ?? array() );
		$notes[] = ( '' !== (string) $since[ $sensor ]
			? sprintf( '%s: lanes since %s.', $words, $since[ $sensor ] )
			: sprintf( '%s: lanes not seen yet, so owner lanes are not measured on this sensor.', $words ) )
			. ' ' . ( isset( $run['outcome'], $runs[ $run['outcome'] ] )
			? sprintf( 'The last run (%s UTC) %s.', gmdate( 'Y-m-d H:i', (int) ( $run['at'] ?? 0 ) ), $runs[ $run['outcome'] ] )
			: 'The lanes rollup has not run yet.' );
	}
	$notes[] = SN_LANES_HUMAN_NOTE;
	$tables  = array();
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
		'alert'     => array(
			'kind' => $bad > 0 ? 'warning' : 'info',
			'text' => sprintf( 'Invalid owner tokens, last 30 days: %d on the beacon, %d on the machine sensor.', (int) $invalid['beacon'], (int) $invalid['machine'] )
				. ( $bad > 0 ? ' Something presented an owner token that did not verify: an expired token, a key mismatch, or a forgery.' : '' ),
		),
		'notes'     => $notes,
		'tables'    => $tables,
		'reference' => sn_lanes_view_reference( (int) $report['rule_version'] ),
	);
}

/** The approved human-lane sentence (owner review, 2026-10-16). */
const SN_LANES_HUMAN_NOTE = 'Human is the dashboard\'s human minus the visitor-days the bot-signal score marks; those are under suspected automation, reason bot_score.';

/**
 * How a lane is decided: reference, folded. PURE.
 *
 * @param int $version Rule version.
 * @return array{summary:string,lines:string[]}
 */
function sn_lanes_view_reference( $version ) {
	$cap   = defined( 'SNT_ANALYTICS_VDAY_PV_CAP' ) ? (int) SNT_ANALYTICS_VDAY_PV_CAP : 50;
	$score = defined( 'SNT_BOT_SIGNAL_THRESHOLD' ) ? (int) SNT_BOT_SIGNAL_THRESHOLD : 3;
	return array(
		'summary' => sprintf( 'Rule version %d. Each hit lands in one lane: the first rule that matches decides.', (int) $version ),
		'lines'   => array(
			sprintf( 'Page views, in order: a bot the analytics Worker named by its user agent (suspected automation, ua_list); an RSS feed server event (human, feed_server); a data-center or hosting network (suspected automation, network); a visitor-day over %1$d page views (over_cap); a bot-signal score of %2$d or more (bot_score); everything else is human (remainder). Owner lanes hold only the hits the analytics Worker kept out of the public counts: an enrolled device, a signed agent token, or an owner signal it could not verify.', $cap, $score ),
			'Machine reads, in order: a valid owner token (owner agent); this site\'s own tools (own infrastructure); a robots.txt control token such as Google-Extended (claimed bot, reason impersonation), credited to no family and no purpose, because no vendor crawls under a control token; a valid Web Bot Auth signature (verified bot, web_bot_auth); a Cloudflare verified-bot category that fits the claimed purpose (verified bot, cf_verified); a category that does not fit (claimed bot, category_mismatch); a user-agent claim (claimed bot, ua_claim); anything else is an unknown machine. A read with an invalid owner token keeps its lane, with reason owner_token_invalid.',
			sprintf( 'Rule version %d. A day stored under an earlier rule version keeps its labels; a new rule applies from the day it ships.', (int) $version ),
			'Beacon days are site-local days. Machine sensor days are the sensor\'s UTC dates.',
			'Views (beacon) and reads (machine) add up across lanes to each sensor\'s total. Visitor-days are counted per lane and do not add up across lanes.',
		),
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
	return array( 'heading' => SN_LANES_SENSOR_WORDS[ $sensor ] . ', by lane', 'description' => $desc, 'columns' => $cols, 'rows' => $rows, 'fold' => '' );
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
	return array( 'heading' => SN_LANES_SENSOR_WORDS[ $sensor ] . ', by reason', 'description' => 'Why each lane holds what it holds.', 'columns' => $cols, 'rows' => $rows, 'fold' => sprintf( '%d lane and reason pairs in the last 90 days.', count( $rows ) ) );
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
	return array( 'heading' => SN_LANES_SENSOR_WORDS[ $sensor ] . ', by day', 'description' => 'The last ' . SN_LANES_DAILY . ' complete days; a day with nothing stored has no row.', 'columns' => $cols, 'rows' => $rows, 'fold' => sprintf( '%d of the last %d complete days stored.', count( $rows ), SN_LANES_DAILY ) );
}
