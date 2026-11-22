<?php
/**
 * Signal & Noise Tools: the sampled-day record.
 *
 * Analytics Engine sampled this site's writes (rows carry _sample_interval
 * above 1), so a day's stored figures are estimates from then until the
 * worker fix. This keeps, per site day and event type (blob1), how many rows
 * Analytics Engine stored and how many events they stand for, for every class
 * and for the counted-human class alone (the human rule's own class
 * expression). Reports mark a day estimated from it; no number is rewritten.
 *
 * The record only grows: a day is added or overwritten from a fresh complete
 * read, never deleted, because Analytics Engine drops rows after about 90 days
 * and this record is then the only evidence. Per day and event the reading
 * that holds more stored rows wins: a re-read at the edge of retention, or one
 * Analytics Engine answers at a coarser resolution, can come back thinner.
 * The nightly run lives in inc/analytics-sampled-days-run.php, the day labels
 * in inc/analytics-sampled-days-label.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SAMPLED_OPT         = 'sn_analytics_sampled_days';
const SN_ANALYTICS_SAMPLED_WINDOW_DAYS = 90;
const SN_ANALYTICS_SAMPLED_RUN_OPT     = 'sn_analytics_sampled_days_run'; // {at, outcome} of the last nightly run.

/**
 * AE SQL for one dataset (or one side of the split). Day buckets and the lower
 * bound are the rollup's own (sn_analytics_rollup_window_exprs). PURE.
 *
 * @param string $source Dataset.
 * @param string $range  '' or one side of the split.
 * @param string $tz     Site zone ('' = UTC days).
 * @param string $class  Class SELECT expression (sn_analytics_rollup_class_parts()).
 * @param string $tail   WHERE tail from the same parts ('' when folded).
 * @return string
 */
function sn_analytics_sampled_sql( $source, $range, $tz, $class, $tail = '' ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( SN_ANALYTICS_SAMPLED_WINDOW_DAYS, $tz );
	$range = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	return "SELECT {$day_col} AS day, blob1 AS ev, {$class} AS class, count() AS r, sum(_sample_interval) AS n"
		. ' FROM ' . $source . " WHERE timestamp >= {$lower}{$range}{$tail} GROUP BY day, ev, class";
}

/**
 * Fold row sets into day => ev => {stored, stands_for, human_stored, human_stands_for}. PURE.
 *
 * @param array<int,array> $sets Row sets (one per dataset side).
 * @return array
 */
function sn_analytics_sampled_fold( array $sets ) {
	$out = array();
	foreach ( $sets as $rows ) {
		foreach ( (array) $rows as $r ) {
			$r   = (array) $r;
			$day = (string) ( $r['day'] ?? '' );
			$ev  = (string) ( $r['ev'] ?? '' );
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || 1 !== preg_match( '/^[a-z0-9_]{1,12}$/', $ev ) ) {
				continue;
			}
			$c     = $out[ $day ][ $ev ] ?? array( 'stored' => 0, 'stands_for' => 0, 'human_stored' => 0, 'human_stands_for' => 0 );
			$rr    = (int) ( $r['r'] ?? 0 );
			$nn    = (int) round( (float) ( $r['n'] ?? 0 ) );
			$human = 'human' === (string) ( $r['class'] ?? '' );
			$out[ $day ][ $ev ] = array(
				'stored'           => $c['stored'] + $rr,
				'stands_for'       => $c['stands_for'] + $nn,
				'human_stored'     => $c['human_stored'] + ( $human ? $rr : 0 ),
				'human_stands_for' => $c['human_stands_for'] + ( $human ? $nn : 0 ),
			);
		}
	}
	return $out;
}

/**
 * Merge a fresh fold into the stored record: add or overwrite, never delete.
 * Per day and event the reading with more stored rows wins (ties go to the
 * fresh one), so a thinner re-read never replaces a fuller one. PURE.
 *
 * @param array $stored Stored record.
 * @param array $fresh  sn_analytics_sampled_fold() output.
 * @return array
 */
function sn_analytics_sampled_merge( array $stored, array $fresh ) {
	foreach ( $fresh as $day => $events ) {
		foreach ( (array) $events as $ev => $c ) {
			$old = $stored[ $day ][ $ev ] ?? null;
			if ( null === $old || (int) $c['stored'] >= (int) ( $old['stored'] ?? 0 ) ) {
				$stored[ $day ][ $ev ] = $c;
			}
		}
	}
	ksort( $stored );
	return $stored;
}

/**
 * The record as the ability reports it: per day, per event {sampled, stored,
 * stands_for} for all classes and for human, `estimated` when any human row
 * that day was sampled, and `onset`, the earliest such day. PURE.
 *
 * `last_run` is the nightly run's {at, outcome}: an empty record with no run,
 * or a run that read nothing, is not evidence that nothing was sampled.
 *
 * @param array $stored Stored record.
 * @param array $run    Stored {at, outcome} of the last run.
 * @return array{onset:?string,days:array,last_run:array}
 */
function sn_analytics_sampled_report( array $stored, array $run = array() ) {
	$days  = array();
	$onset = null;
	ksort( $stored );
	foreach ( $stored as $day => $events ) {
		$row = array( 'day' => (string) $day, 'estimated' => false, 'events' => array() );
		foreach ( (array) $events as $ev => $c ) {
			$hs = (int) ( $c['human_stands_for'] ?? 0 ) > (int) ( $c['human_stored'] ?? 0 );
			$row['events'][ $ev ] = array(
				'sampled'    => (int) ( $c['stands_for'] ?? 0 ) > (int) ( $c['stored'] ?? 0 ),
				'stored'     => (int) ( $c['stored'] ?? 0 ),
				'stands_for' => (int) ( $c['stands_for'] ?? 0 ),
				'human'      => array( 'sampled' => $hs, 'stored' => (int) ( $c['human_stored'] ?? 0 ), 'stands_for' => (int) ( $c['human_stands_for'] ?? 0 ) ),
			);
			$row['estimated'] = $row['estimated'] || $hs;
		}
		if ( $row['estimated'] && null === $onset ) {
			$onset = (string) $day;
		}
		$days[] = $row;
	}
	return array( 'onset' => $onset, 'days' => $days, 'last_run' => $run ? $run : array( 'at' => null, 'outcome' => 'never_ran' ) );
}

require_once __DIR__ . '/analytics-sampled-days-label.php';
require_once __DIR__ . '/analytics-sampled-days-run.php';
