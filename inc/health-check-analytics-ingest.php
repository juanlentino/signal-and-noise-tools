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

require_once __DIR__ . '/analytics-ingest-canary.php'; // SN_INGEST_CANARY_PATH, the path the canary is read back by.
require_once __DIR__ . '/analytics-generation.php'; // which dataset a read uses (legacy, or the second generation once verified).

const SN_HEALTH_INGEST_STALE_SECS = 86400;
// 2026-11-21: a quiet day is not an outage. With the hourly canary (inc/
// analytics-ingest-canary.php) seen in the last 24 hours the collector is
// proven to write, so no human pageview only flags after 72 hours.
const SN_HEALTH_INGEST_QUIET_SECS = 259200;
const SN_HEALTH_INGEST_WHY_MAX    = 200; // characters of a stored send reason that reach a note.

/**
 * The newest canary pageview in the last 24 hours: identity 7 (a SignalNoise-
 * user agent) on the canary's own path, in the owner dataset, where the Worker
 * files this site's own tools. The path is what makes it the canary: any other
 * SignalNoise- tool is identity 7 too.
 *
 * @param bool $legacy Also take the path /, which the canary sent before 2026-11-22.
 * @return string AE SQL.
 */
function sn_health_analytics_canary_sql( $legacy = false ) {
	$path = $legacy ? "blob2 IN ('" . SN_INGEST_CANARY_PATH . "', '/')" : "blob2 = '" . SN_INGEST_CANARY_PATH . "'";
	return "SELECT max(toUnixTimestamp(timestamp)) AS last FROM sn_owner_hits WHERE blob1 = 'pv' AND toUInt32(double14) = 7 AND {$path} AND timestamp >= now() - INTERVAL '1' DAY";
}

/**
 * Pure: whether a canary row on the old path / can still be inside the 24-hour
 * window: until the canary's first send on its own path is 24 hours old, or
 * while that time is not stored (it has not sent on it yet).
 *
 * @param mixed $last_send The stored { at, http, why, since }.
 * @param int   $now       Current unix time.
 * @return bool
 */
function sn_health_analytics_canary_legacy( $last_send, $now ) {
	$since = is_array( $last_send ) && is_numeric( $last_send['since'] ?? null ) ? (int) $last_send['since'] : 0;
	return $since <= 0 || (int) $now - $since < SN_HEALTH_INGEST_STALE_SECS;
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
 * Pure: one sentence (leading space) on a last canary send that was not a clean 204, else ''. Not escaped here.
 * @param mixed $last_send The stored { at, http, why }.
 * @param int   $now       Current unix time.
 * @return string
 */
function sn_health_analytics_ingest_send_note( $last_send, $now ) {
	if ( ! is_array( $last_send ) || ! is_numeric( $last_send['http'] ?? null ) ) {
		return ''; // unreadable is not a status
	}
	$http = (int) $last_send['http'];
	$why  = is_string( $last_send['why'] ?? null ) ? $last_send['why'] : '';
	$why  = mb_substr( rtrim( trim( (string) preg_replace( '/\s+/', ' ', $why ) ), '.' ), 0, SN_HEALTH_INGEST_WHY_MAX );
	if ( 204 === $http && '' === $why ) {
		return '';
	}
	$at  = (int) ( $last_send['at'] ?? 0 );
	$age = (int) $now - $at;
	$ago = ( $at <= 0 || $age < 0 ) ? '' : ( $age < 3600 ? sprintf( ', %d minutes ago,', (int) floor( $age / 60 ) ) : sprintf( ', %d hours ago,', (int) floor( $age / 3600 ) ) );
	return ' The last canary send' . $ago . ( $http > 0 ? ' was answered HTTP ' . $http : ' got no answer' ) . ( '' !== $why ? ': ' . $why : '' ) . '.';
}

/**
 * Pure: rows from the query above to zero or one finding.
 *
 * @param array    $rows   Rows { cls, recent, recent_pv, last_pv }.
 * @param int      $now    Current unix time.
 * @param int|null $canary The newest canary pageview in the last 24 hours (unix), 0 for none, null when not read.
 * @param mixed    $last_send The stored last canary send { at, http, why }, null when not read.
 * @return array[] Finding rows.
 */
function sn_health_analytics_ingest_findings( array $rows, $now, $canary = null, $last_send = null ) {
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
			'note'          => $age . $why . sn_health_analytics_ingest_send_note( $last_send, $now ),
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
	$fix_hint = 'A dead analytics collector reads as a quiet day. This check flags when no human pageview has been recorded for 24 hours, or for 72 hours when the hourly canary (a SignalNoise- pageview of /_sn/canary, in none of the classes and left out of the own-tools figure) proves the collector writes. Look at the analytics worker (deploy, Analytics Engine binding, classifier) with `wrangler tail`.';
	if ( ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() || ! function_exists( 'sn_analytics_query' ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'Analytics is not configured, so ingest could not be checked.' );
	}
	$rows = sn_analytics_query( sn_health_analytics_ingest_sql() );
	if ( ! is_array( $rows ) ) {
		return sn_health_pack_check( $label, array(), $fix_hint, 'The analytics query failed, so ingest could not be checked. This is not a reading of zero.' );
	}
	$last_send = function_exists( 'get_option' ) ? get_option( SN_INGEST_CANARY_OPT, null ) : null;
	$c         = sn_analytics_query( sn_health_analytics_canary_sql( sn_health_analytics_canary_legacy( $last_send, time() ) ) );
	$canary    = is_array( $c ) ? (int) ( $c[0]['last'] ?? 0 ) : null; // a failed read is unknown, never a canary
	return sn_health_pack_check( $label, sn_health_analytics_ingest_findings( $rows, time(), $canary, $last_send ), $fix_hint );
}
