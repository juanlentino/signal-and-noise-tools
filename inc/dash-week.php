<?php
/**
 * Signal & Noise: Home's seven days, one definition for both twins (the
 * classic measurement signals and lists, inc/dash-zone-measurement.php and
 * inc/admin-tab-dashboard.php, and the S&N Home leaf's tiles,
 * apps/sn-dashboard/parts/leaves/dashboard-pulse.php), so they cannot drift.
 * Its own file so the leaf can load it without the measurement gatherer.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Home's seven days: the Analytics app's 7d window, the site's days (review
 * on #2020: UTC days put Home's Visits a day off the app's 7d Visits it links
 * to). UTC days only where the app's resolver is not loaded.
 *
 * @param int|null $now Unix time; defaults to now.
 * @return array{0:string,1:string} From and to, inclusive, Y-m-d.
 */
function snt_dashboard_week( $now = null ) {
	if ( function_exists( 'snt_analytics_range_dates' ) ) {
		return snt_analytics_range_dates( 7, $now );
	}
	$now = null === $now ? time() : (int) $now;
	return array( gmdate( 'Y-m-d', $now - 6 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now ) );
}
