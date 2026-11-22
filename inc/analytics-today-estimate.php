<?php
/**
 * Signal & Noise — is today's view count an estimate?
 *
 * Analytics Engine samples at write time: it can keep one of a visitor-day's
 * rows and weight it to stand for two or three. The figures sum the weights
 * (sum(_sample_interval)), so they stay the right estimate, but they are no
 * longer a count, and the Sessions tab, which lists the stored rows, reads
 * lower. Measured 2026-11-11: one visitor-day kept 7 page-view rows standing
 * for 17, and "Today so far" read 31 against 20 sessions page views.
 *
 * The realtime warmer's today read (inc/analytics-realtime.php) returns the
 * stored rows beside the weighted sum, at no extra request. These accessors
 * read that cache only: no network call.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Today's weighted views and stored rows with the time they were read, from
 * the realtime cache or the same-day last-good. `at` is 0 for a last-good
 * written before 2026-11-22, which carried no read time.
 *
 * @return array{views:int,stored:int,at:int}|null
 */
function sn_analytics_today_sampling_read() {
	$cached = function_exists( 'get_transient' ) && defined( 'SN_ANALYTICS_REALTIME_KEY' ) ? get_transient( SN_ANALYTICS_REALTIME_KEY ) : false;
	if ( is_array( $cached ) && is_int( $cached['views_today'] ?? null ) && is_int( $cached['views_today_stored'] ?? null ) ) {
		return array( 'views' => $cached['views_today'], 'stored' => $cached['views_today_stored'], 'at' => (int) ( $cached['fetched'] ?? 0 ) );
	}
	$lastgood = function_exists( 'get_option' ) && defined( 'SN_ANALYTICS_VIEWS_TODAY_LASTGOOD' ) ? get_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD, array() ) : array();
	if ( is_array( $lastgood ) && is_int( $lastgood['views'] ?? null ) && is_int( $lastgood['stored'] ?? null )
		&& function_exists( 'sn_analytics_local_day' ) && ( $lastgood['day'] ?? '' ) === sn_analytics_local_day() ) {
		return array( 'views' => $lastgood['views'], 'stored' => $lastgood['stored'], 'at' => (int) ( $lastgood['at'] ?? 0 ) );
	}
	return null;
}

/**
 * Today's weighted views and stored rows, from one reading. Null when no
 * reading carries both (cold, failed, or a cache written before the stored
 * count existed): unknown, never "exact".
 *
 * @return array{views:int,stored:int}|null
 */
function sn_analytics_today_sampling() {
	$s = sn_analytics_today_sampling_read();
	return null === $s ? null : array( 'views' => $s['views'], 'stored' => $s['stored'] );
}

/**
 * Today's stored rows when they can be set beside a count read at $read_at
 * (the worker's exact count): read no earlier than the realtime cache's
 * retention before it. Otherwise null: the last-good has no age limit, and
 * rows stored at 9 am say nothing about sampling in a count read at 2 pm.
 *
 * @param int $read_at Unix time the other count was read.
 * @return int|null
 */
function sn_analytics_today_stored_beside( $read_at ) {
	$s      = sn_analytics_today_sampling_read();
	$window = defined( 'SN_ANALYTICS_REALTIME_RETENTION' ) ? (int) SN_ANALYTICS_REALTIME_RETENTION : 300;
	return null !== $s && $s['at'] > 0 && $s['at'] >= (int) $read_at - $window ? $s['stored'] : null;
}

/**
 * True when Analytics Engine sampled today's human page views, so today's
 * figure is an estimate. False when exact or not known.
 *
 * @return bool
 */
function sn_analytics_today_is_estimate() {
	$s = sn_analytics_today_sampling();
	return null !== $s && $s['stored'] < $s['views'];
}

/**
 * The admin note for a sampled today, in words; '' when today is not known
 * to be sampled. Admin surfaces only: it names stored rows, which /stats does not.
 *
 * @return string
 */
function sn_analytics_today_estimate_note() {
	$s = sn_analytics_today_sampling();
	if ( null === $s || $s['stored'] >= $s['views'] ) {
		return '';
	}
	return sprintf(
		/* translators: 1: stored page-view records, 2: the estimated views they stand for. */
		__( 'Estimate: Analytics Engine sampled today and kept %1$s page-view records, weighted to stand for %2$s views. The Sessions tab lists the kept records only.', 'signal-and-noise-tools' ),
		number_format_i18n( $s['stored'] ),
		number_format_i18n( $s['views'] )
	);
}
