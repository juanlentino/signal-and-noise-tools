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
 * and this record is then the only evidence. A day older than
 * SN_ANALYTICS_SAMPLED_FREEZE_DAYS keeps what was first stored: a re-read at
 * the edge of retention can come back thinner.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_SAMPLED_OPT         = 'sn_analytics_sampled_days';
const SN_ANALYTICS_SAMPLED_WINDOW_DAYS = 90;
const SN_ANALYTICS_SAMPLED_FREEZE_DAYS = 80;

/**
 * AE SQL for one dataset (or one side of the split). Day buckets and the lower
 * bound are the rollup's own (sn_analytics_rollup_window_exprs). PURE.
 *
 * @param string $source Dataset.
 * @param string $range  '' or one side of the split.
 * @param string $tz     Site zone ('' = UTC days).
 * @param string $class  Class SELECT expression (sn_analytics_counted_class_select()).
 * @return string
 */
function sn_analytics_sampled_sql( $source, $range, $tz, $class ) {
	list( $day_col, $lower ) = sn_analytics_rollup_window_exprs( SN_ANALYTICS_SAMPLED_WINDOW_DAYS, $tz );
	$range = sn_analytics_split_range_ok( $range ) ? (string) $range : '';
	return "SELECT {$day_col} AS day, blob1 AS ev, {$class} AS class, count() AS r, sum(_sample_interval) AS n"
		. ' FROM ' . $source . " WHERE timestamp >= {$lower}{$range} GROUP BY day, ev, class";
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
 * Merge a fresh fold into the stored record: add or overwrite days, never
 * delete one, and keep a frozen (old) day as first stored. PURE.
 *
 * @param array  $stored Stored record.
 * @param array  $fresh  sn_analytics_sampled_fold() output.
 * @param string $today  Site-local Y-m-d.
 * @return array
 */
function sn_analytics_sampled_merge( array $stored, array $fresh, $today ) {
	$frozen = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) - SN_ANALYTICS_SAMPLED_FREEZE_DAYS * 86400 );
	foreach ( $fresh as $day => $events ) {
		if ( isset( $stored[ $day ] ) && (string) $day < $frozen ) {
			continue;
		}
		$stored[ $day ] = $events;
	}
	ksort( $stored );
	return $stored;
}

/**
 * The record as the ability reports it: per day, per event {sampled, stored,
 * stands_for} for all classes and for human, `estimated` when any human row
 * that day was sampled, and `onset`, the earliest such day. PURE.
 *
 * @param array $stored Stored record.
 * @return array{onset:?string,days:array}
 */
function sn_analytics_sampled_report( array $stored ) {
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
	return array( 'onset' => $onset, 'days' => $days );
}

/**
 * Whether a site day's figures are estimates: Analytics Engine sampled at least
 * one counted-human row that day. Per-day evidence, not a range from onset to
 * a fix date (no fix date exists yet). Label only; never change a number.
 *
 * @param string $day Y-m-d (site day).
 * @return bool
 */
function sn_analytics_day_estimated( $day ) {
	static $estimated = null;
	if ( null === $estimated ) {
		$report    = sn_analytics_sampled_report( (array) get_option( SN_ANALYTICS_SAMPLED_OPT, array() ) );
		$estimated = array_column( array_filter( $report['days'], static fn( $d ) => $d['estimated'] ), 'estimated', 'day' );
	}
	return isset( $estimated[ (string) $day ] );
}

/**
 * Daily run: read the trailing 90 days and merge. Writes nothing when the
 * over-cap list did not read (bots would read as human and fake an onset), a
 * read failed, or a set reached the row cap.
 */
function sn_analytics_sampled_days_run() {
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() || ! function_exists( 'sn_analytics_stitched_rows' ) ) {
		return;
	}
	$list = sn_analytics_overcap_vdays();
	if ( empty( $list['ok'] ) ) {
		error_log( '[sn-analytics] sampled-day record skipped: the over-cap list did not read' );
		return;
	}
	$tz    = sn_analytics_site_tz_name();
	$class = sn_analytics_counted_class_select( (array) $list['hashes'] );
	$sets  = sn_analytics_stitched_rows(
		static fn( $source, $range ) => sn_analytics_sampled_sql( $source, $range, $tz, $class ),
		sn_analytics_trailing_from( SN_ANALYTICS_SAMPLED_WINDOW_DAYS ),
		gmdate( 'Y-m-d H:i:s' )
	);
	$cap = defined( 'SN_ANALYTICS_AE_ROW_CAP' ) ? SN_ANALYTICS_AE_ROW_CAP : 10000;
	if ( null === $sets || array() !== array_filter( $sets, static fn( $s ) => count( $s ) >= $cap ) ) {
		error_log( '[sn-analytics] sampled-day record skipped: the read failed or reached the row cap' );
		return;
	}
	$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	update_option( SN_ANALYTICS_SAMPLED_OPT, sn_analytics_sampled_merge( (array) get_option( SN_ANALYTICS_SAMPLED_OPT, array() ), sn_analytics_sampled_fold( $sets ), $today ), false );
}
add_action( 'sn_analytics_rollup_daily', 'sn_analytics_sampled_days_run', 20 ); // SN_ANALYTICS_ROLLUP_DAILY_HOOK, after the rollup; never the 15-minute on-demand hook.
