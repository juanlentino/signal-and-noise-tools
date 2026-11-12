<?php
/**
 * Signal & Noise — exact page-view counts: classify and reconcile (PURE + one read).
 *
 * The analytics worker (2.2.0) counts every public page view in D1 by site day,
 * visitor-day hash and path, and pushes today and yesterday with each refresh
 * poke (inc/analytics-exact.php). Those counts carry no class. This file reads
 * each visitor-day's class from Analytics Engine's own rows, under the same
 * human rule and over-cap list every other figure uses, then totals the exact
 * counts per class. A day is called exact only when nothing contradicts it.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_EXACT_VID_MAX = 5000; // visitor-days per day the class read takes; one more says it was cut.

/**
 * A site day's [start, end) as UTC 'Y-m-d H:i:s'. PURE given the zone.
 *
 * @param string $day Y-m-d, site time.
 * @return array{0:string,1:string}|null
 */
function sn_analytics_exact_day_bounds( $day ) {
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $day ) ) {
		return null;
	}
	$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$utc = new DateTimeZone( 'UTC' );
	$lo  = new DateTimeImmutable( $day . ' 00:00:00', $tz );
	return array( $lo->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $lo->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
}

/**
 * AE SQL: each visitor-day of a site day, its class under the counted rule and
 * the page-view rows Analytics Engine kept. Every event type classifies, so a
 * visitor-day whose page views were all sampled away still has a class. PURE.
 *
 * @param string $day    Y-m-d, site time.
 * @param array  $hashes Over-cap hashes (sn_analytics_overcap_vdays()).
 * @return string '' for a bad day.
 */
function sn_analytics_exact_class_sql( $day, array $hashes ) {
	$b = sn_analytics_exact_day_bounds( $day );
	if ( null === $b ) {
		return '';
	}
	return 'SELECT index1 AS vid, ' . sn_analytics_counted_class_select( $hashes ) . ' AS class, sum(if(blob1 = \'pv\', 1, 0)) AS pv_stored'
		. ' FROM ' . sn_analytics_source( $day )
		. " WHERE timestamp >= toDateTime('{$b[0]}') AND timestamp < toDateTime('{$b[1]}')"
		. ' GROUP BY vid, class LIMIT ' . ( SN_ANALYTICS_EXACT_VID_MAX + 1 );
}

/**
 * Reconcile one day's exact counts with Analytics Engine's classes. PURE.
 *
 * @param array      $counts  [[vid, path, views], ...] from the worker.
 * @param array|null $classes AE rows {vid, class, pv_stored}; null when the read failed.
 * @param bool       $full    The day is on or after the worker's first full day.
 * @param bool       $cap_ok  The over-cap list read whole.
 * @return array{exact:bool,why:string,views:array<string,int>,unclassed:int}
 */
function sn_analytics_exact_reconcile( array $counts, $classes, $full, $cap_ok ) {
	$per_vid = array();
	foreach ( $counts as $c ) {
		$per_vid[ (string) $c[0] ] = ( $per_vid[ (string) $c[0] ] ?? 0 ) + (int) $c[2];
	}
	$views = array( 'human' => 0, 'suspect' => 0, 'bot' => 0 );
	$out   = static fn( $exact, $why, $vw, $unclassed = 0 ) => array( 'exact' => $exact, 'why' => $why, 'views' => $vw, 'unclassed' => $unclassed );
	if ( ! is_array( $classes ) ) {
		return $out( false, 'the class read failed', $views );
	}
	if ( count( $classes ) > SN_ANALYTICS_EXACT_VID_MAX ) {
		return $out( false, 'the class read was cut short', $views );
	}
	$class  = array();
	$stored = array();
	$mixed  = false;
	foreach ( $classes as $r ) {
		$vid = strtolower( (string) ( $r['vid'] ?? '' ) );
		$cls = (string) ( $r['class'] ?? '' );
		$mixed           = $mixed || ( isset( $class[ $vid ] ) && $class[ $vid ] !== $cls );
		$class[ $vid ]   = $cls;
		$stored[ $vid ]  = ( $stored[ $vid ] ?? 0 ) + (int) round( (float) ( $r['pv_stored'] ?? 0 ) );
	}
	$unclassed = 0;
	$short     = false;
	foreach ( $per_vid as $vid => $n ) {
		if ( ! isset( $class[ $vid ], $views[ $class[ $vid ] ] ) ) {
			$unclassed += $n;
			continue;
		}
		$views[ $class[ $vid ] ] += $n;
		$short = $short || $n < ( $stored[ $vid ] ?? 0 );
	}
	$why = ! $full ? 'the counter started partway through this day'
		: ( ! $cap_ok ? 'the over-cap list did not read whole'
		: ( $mixed ? 'a visitor-day read as two classes'
		: ( $unclassed > 0 ? 'some counted page views have no class in Analytics Engine'
		: ( $short ? 'a visitor-day has fewer counted page views than Analytics Engine kept' : '' ) ) ) );
	return $out( '' === $why, $why, $views, $unclassed );
}
