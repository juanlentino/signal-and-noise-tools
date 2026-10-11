<?php
/**
 * Likely automated over an analytics range (Unreleased): the reader of the
 * nightly per-day bot-signal store (inc/analytics-bot-signals-by-day.php) and
 * the schema get-analytics-summary and its remote twin share. OBSERVE-ONLY:
 * nothing is subtracted from any count, and the basis is `inferred`.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-bot-signals-by-day.php'; // the stored day map and its option name.

/**
 * Likely-automated human visitor-days over [$from, $to]. A day counts as
 * measured when the beacon's signals were stored for it; the rest are
 * unmeasured (before the beacon carried them, or not yet stored). PURE.
 *
 * @param array  $by_day Stored day map.
 * @param string $from   YYYY-MM-DD.
 * @param string $to     YYYY-MM-DD.
 * @return array{visitor_days:int|null,scored_visitor_days:int|null,measured_days:int,unmeasured_days:int,basis:string,subtracted:bool}
 */
function sn_bot_signals_likely_automated( array $by_day, $from, $to ) {
	$span     = max( 0, (int) round( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / 86400 ) + 1 ); // a UTC day, in seconds
	$likely   = 0;
	$scored   = 0;
	$measured = 0;
	foreach ( $by_day as $day => $d ) {
		if ( (string) $day < (string) $from || (string) $day > (string) $to ) {
			continue;
		}
		++$measured;
		$scored += (int) ( $d['visitor_days'] ?? 0 );
		$likely += (int) ( $d['likely_automated'] ?? 0 );
	}
	return array(
		'visitor_days'        => $measured > 0 ? $likely : null,
		'scored_visitor_days' => $measured > 0 ? $scored : null,
		'measured_days'       => $measured,
		'unmeasured_days'     => max( 0, $span - $measured ),
		'basis'               => 'inferred',
		'subtracted'          => false,
	);
}

/**
 * Likely automated over a range, for a traffic class: the stored per-day
 * reading for class human, null for suspect and bot (the score is a question
 * about presumed humans). Never queries Analytics Engine.
 *
 * @param string $from  YYYY-MM-DD.
 * @param string $to    YYYY-MM-DD.
 * @param string $class human|suspect|bot.
 * @return array|null sn_bot_signals_likely_automated(), or null.
 */
function sn_analytics_likely_automated( $from, $to, $class ) {
	if ( 'human' !== (string) $class ) {
		return null;
	}
	$by_day = function_exists( 'get_option' ) ? get_option( SNT_BOT_SIGNAL_BY_DAY_OPTION, array() ) : array();
	return sn_bot_signals_likely_automated( is_array( $by_day ) ? $by_day : array(), (string) $from, (string) $to );
}

/**
 * The likely_automated field's schema, shared by get-analytics-summary and its
 * remote twin so the pair stays byte-identical by construction. It hashes into
 * SN_REMOTE_CONTRACT_VERSION: change it and the contract moves.
 *
 * @return array
 */
function snt_analytics_likely_automated_schema() {
	$count = array( 'type' => array( 'integer', 'null' ) );
	return array(
		'type'        => array( 'object', 'null' ),
		'description' => 'Class human only (null otherwise). Presumed-human visitor-days the beacon bot-signal score marks likely automated, beside the totals, never subtracted. Summed over the UTC days the range covers (the store is kept per UTC day), so an edge of a site-local range can differ by a day; measured_days and unmeasured_days are UTC days. Basis inferred: a heuristic.',
		'properties'  => array(
			'visitor_days'        => $count,
			'scored_visitor_days' => $count,
			'measured_days'       => array( 'type' => 'integer' ),
			'unmeasured_days'     => array( 'type' => 'integer' ),
			'basis'               => array( 'type' => 'string', 'enum' => array( 'inferred' ) ),
			'subtracted'          => array( 'type' => 'boolean' ),
		),
	);
}

/**
 * The likely automated tile as a stats-row card ({l, n, sub}), the same on the
 * classic Overview and the native header: "not measured" once for null, never
 * 0 and never "of 0"; the days are UTC days.
 *
 * @param array|null $la sn_bot_signals_likely_automated(), or null for another class.
 * @return array|null
 */
function snt_analytics_likely_automated_card( $la ) {
	if ( ! is_array( $la ) ) {
		return null;
	}
	$span = (int) $la['measured_days'] + (int) $la['unmeasured_days'];
	if ( null === $la['visitor_days'] ) {
		$sub = __( 'no UTC day in this range carried beacon signals; inferred, never subtracted', 'signal-and-noise-tools' );
	} else {
		/* translators: %s: presumed-human visitor-days the beacon scored. */
		$sub = sprintf( __( 'of %s presumed-human visitor-days; inferred from beacon signals, never subtracted', 'signal-and-noise-tools' ), number_format_i18n( (int) $la['scored_visitor_days'] ) );
		if ( (int) $la['measured_days'] < $span ) {
			/* translators: 1: UTC days measured, 2: UTC days in the range. */
			$sub .= '; ' . sprintf( __( 'measured %1$d of %2$d UTC days', 'signal-and-noise-tools' ), (int) $la['measured_days'], $span );
		}
	}
	return array(
		'l'   => __( 'Likely automated (inferred)', 'signal-and-noise-tools' ),
		'n'   => null === $la['visitor_days'] ? __( 'not measured', 'signal-and-noise-tools' ) : number_format_i18n( (int) $la['visitor_days'] ),
		'sub' => $sub,
	);
}
