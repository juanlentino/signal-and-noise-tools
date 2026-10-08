<?php
/**
 * Signal & Noise Tools: when each Cloudflare worker was last deployed, as
 * the five-minute version probe saw it (inc/deploy-workers.php).
 *
 * Nothing upstream records a worker deploy: GitHub tags carry no date, a
 * merge is not a deploy (Workers Builds can skip one), and the Cloudflare
 * deployments API needs a token this plugin does not hold. So the moment a
 * probe first reads a NEW live version is stamped, accurate to the probe's
 * five minutes. The first version ever read for a worker is a baseline, not a
 * deploy; until a worker changes, the card says since when it has watched.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_DEPLOY_WORKERS_SEEN_OPT = 'snt_deploy_workers_seen';

/**
 * The log after one probe read. PURE.
 *
 * Shape: { since: int, workers: { id: { version, at|null } }, last: { id, version, at }|null }.
 * A version that is not a reading ('' or "unprobeable") changes nothing.
 *
 * @param mixed  $log     The stored log (anything not an array starts a new one).
 * @param string $id      Worker id.
 * @param string $version Live version just read.
 * @param int    $now     Unix time of the read.
 * @return array
 */
function snt_deploy_workers_seen_merge( $log, $id, $version, $now ) {
	$log = is_array( $log ) ? $log : array();
	$log += array( 'since' => (int) $now, 'workers' => array(), 'last' => null );
	$version = (string) $version;
	if ( '' === $version || 'unprobeable' === $version ) {
		return $log;
	}
	$was = $log['workers'][ $id ] ?? null;
	if ( ! is_array( $was ) ) {
		$log['workers'][ $id ] = array( 'version' => $version, 'at' => null ); // baseline: watched, not deployed
		return $log;
	}
	if ( (string) ( $was['version'] ?? '' ) === $version ) {
		return $log;
	}
	$log['workers'][ $id ] = array( 'version' => $version, 'at' => (int) $now );
	$log['last']           = array( 'id' => (string) $id, 'version' => $version, 'at' => (int) $now );
	return $log;
}

/**
 * Record one probe read. Writes only when the log changed.
 *
 * @param string $id      Worker id.
 * @param string $version Live version just read.
 * @return void
 */
function snt_deploy_workers_seen_note( $id, $version ) {
	$old = get_option( SNT_DEPLOY_WORKERS_SEEN_OPT, array() );
	$new = snt_deploy_workers_seen_merge( $old, (string) $id, (string) $version, time() );
	if ( $new !== $old ) {
		update_option( SNT_DEPLOY_WORKERS_SEEN_OPT, $new, false );
	}
}

/**
 * The card's worker deploy line. PURE given the log and the labels.
 *
 * @param mixed                $log    The stored log.
 * @param array<string,string> $labels Worker id => label.
 * @return array{label:string,version:string,at:string}|array{since:string}|null
 *         The last deploy seen (ISO time), or since when the log has watched, or null when it has not started.
 */
function snt_deploy_workers_seen_last( $log, array $labels ) {
	if ( ! is_array( $log ) || empty( $log['since'] ) ) {
		return null;
	}
	$last = $log['last'] ?? null;
	if ( is_array( $last ) && ! empty( $last['at'] ) ) {
		$id = (string) ( $last['id'] ?? '' );
		return array( 'label' => (string) ( $labels[ $id ] ?? $id ), 'version' => (string) ( $last['version'] ?? '' ), 'at' => gmdate( 'c', (int) $last['at'] ) );
	}
	return array( 'since' => gmdate( 'c', (int) $log['since'] ) );
}
