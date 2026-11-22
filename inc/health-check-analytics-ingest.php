<?php
/**
 * Signal & Noise Tools — analytics ingest liveness (Content-Health check).
 *
 * If the analytics worker stops writing human pageviews (a rotated API token,
 * a broken deploy, a classifier that files every visitor as a bot), the
 * dashboards read zero and a dead collector looks exactly like a quiet day.
 * This check tells them apart: it flags when the newest human pageview is
 * more than 24 hours old, and says whether other traffic kept arriving in the
 * meantime (the collector is alive but humans vanished) or nothing did (the
 * collector itself went dark).
 *
 * A failed or unconfigured query is SKIPPED ("could not check"), never read
 * as zero: an unreadable dataset is not an empty one.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

const SN_HEALTH_INGEST_STALE_SECS = 86400;
// 2026-11-21: a quiet day is not an outage. With the hourly canary (inc/
// analytics-ingest-canary.php) seen in the last 24 hours the collector is
// proven to write, so no human pageview only flags after 72 hours.
const SN_HEALTH_INGEST_QUIET_SECS = 259200;

/**
 * The newest canary pageview in the last 24 hours: identity 7 (a SignalNoise-
 * user agent) in the owner dataset, where the Worker files this site's own
 * tools.
 *
 * @return string AE SQL.
 */
function sn_health_analytics_canary_sql() {
	return "SELECT max(toUnixTimestamp(timestamp)) AS last FROM sn_owner_hits WHERE blob1 = 'pv' AND toUInt32(double14) = 7 AND timestamp >= now() - INTERVAL '1' DAY";
}

/**
 * One grouped read over the last 7 days, one row per traffic class.
 *
 * @return string AE SQL.
 */
function sn_health_analytics_ingest_sql() {
	$dataset = sn_analytics_source( sn_analytics_trailing_from( 7 ) );
	$day     = "timestamp >= now() - INTERVAL '1' DAY";
	return 'SELECT blob7 AS cls,'
		. " sum(if({$day}, _sample_interval, 0)) AS recent,"
		. " sum(if(blob1 = 'pv' AND {$day}, _sample_interval, 0)) AS recent_pv,"
		. " max(if(blob1 = 'pv', toUnixTimestamp(timestamp), 0)) AS last_pv"
		. ' FROM ' . $dataset
		. " WHERE timestamp >= now() - INTERVAL '7' DAY"
		. ' GROUP BY cls';
}

/**
 * Pure: rows from the query above to zero or one finding.
 *
 * @param array    $rows   Rows { cls, recent, recent_pv, last_pv }.
 * @param int      $now    Current unix time.
 * @param int|null $canary The newest canary pageview in the last 24 hours (unix), 0 for none, null when not read.
 * @return array[] Finding rows.
 */
function sn_health_analytics_ingest_findings( array $rows, $now, $canary = null ) {
	$human_last = 0;
	$human_pv   = 0;
	$other      = 0;
	foreach ( $rows as $r ) {
		if ( 'human' === (string) ( $r['cls'] ?? '' ) ) {
			$human_last = (int) ( $r['last_pv'] ?? 0 );
			$human_pv   = (int) ( $r['recent_pv'] ?? 0 );
			$other     += (int) ( $r['recent'] ?? 0 ) - $human_pv;
		} else {
			$other += (int) ( $r['recent'] ?? 0 );
		}
	}
	if ( $human_pv > 0 && ( (int) $now - $human_last ) <= SN_HEALTH_INGEST_STALE_SECS ) {
		return array();
	}
	$canary_ok = null !== $canary && (int) $canary > 0 && ( (int) $now - (int) $canary ) <= SN_HEALTH_INGEST_STALE_SECS;
	if ( $canary_ok && $human_last > 0 && ( (int) $now - $human_last ) <= SN_HEALTH_INGEST_QUIET_SECS ) {
		return array(); // the collector writes; a day or two without people is a quiet stretch.
	}

	$age = $human_last > 0
		? sprintf( 'The newest human pageview is %d hours old (%s).', (int) floor( ( (int) $now - $human_last ) / 3600 ), gmdate( 'Y-m-d H:i \U\T\C', $human_last ) )
		: 'There is no human pageview in the last 7 days.';
	$why = $canary_ok
		? sprintf( ' The hourly canary arrived %d hours ago, so the collector writes, but no person has been recorded for 3 days: check the theme beacon and the worker\'s classifier.', (int) floor( ( (int) $now - (int) $canary ) / 3600 ) )
		: ( $other > 0
		? sprintf( ' Other events kept arriving (%s in the last 24 hours), so the collector is running but is not recording people: check the worker\'s classifier and recent deploys.', number_format( $other ) )
		: ' Nothing else arrived either, so the collector has likely stopped writing: check the worker deploy and its Analytics Engine binding.' );
	if ( ! $canary_ok && null !== $canary ) {
		$why .= ' The hourly canary did not arrive in the last 24 hours either.';
	}

	return array(
		array(
			'subject_type'  => 'analytics_ingest',
			'subject_id'    => 0,
			'subject_url'   => '',
			'subject_label' => 'human pageviews',
			'edit_url'      => '',
			'note'          => $age . $why,
		),
	);
}

/**
 * CHECK: analytics ingest liveness.
 *
 * @return array pack_check envelope.
 */
function sn_health_check_analytics_ingest() {
	$label    = 'Analytics ingest';
	$fix_hint = 'A dead analytics collector reads as a quiet day. This check flags when no human pageview has been recorded for 24 hours, or for 72 hours when the hourly canary (a SignalNoise- pageview kept out of every count) proves the collector writes. Look at the analytics worker (deploy, Analytics Engine binding, classifier) with `wrangler tail`.';
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() || ! function_exists( 'sn_analytics_query' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Analytics is not configured, so ingest could not be checked.' );
	}
	$rows = sn_analytics_query( sn_health_analytics_ingest_sql() );
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The analytics query failed, so ingest could not be checked. This is not a reading of zero.' );
	}
	$c      = sn_analytics_query( sn_health_analytics_canary_sql() );
	$canary = is_array( $c ) ? (int) ( $c[0]['last'] ?? 0 ) : null; // a failed read is unknown, never a canary
	return sn_health_pack_check( $label, sn_health_analytics_ingest_findings( $rows, time(), $canary ), $fix_hint );
}
