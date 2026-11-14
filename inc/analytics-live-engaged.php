<?php
/**
 * Readers apart from arrivals (2026-11-14). The live figures counted any
 * presumed-human visitor active in the last 5 minutes, so a scraper that
 * loaded /cv and sent nothing else read as "Reading now 1" for five minutes.
 * The owner's live figures now count only visitors whose visit sent an
 * engagement signal in the window: visible time (a `tm` with time in it, which
 * the theme's "still here" ping sends at 10 seconds), a scroll milestone, or a
 * custom event. Visitors with a page view and nothing else yet are `arrived`.
 * The public /stats strip keeps its count unchanged.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The engagement rows, the shape already live in the strict rule's read (inc/analytics-strict-no-engagement.php). */
const SN_ANALYTICS_LIVE_ENGAGED_SQL = "(blob1 = 'sc' OR blob1 = 'ce' OR blob1 = 'cp' OR (blob1 = 'tm' AND double2 > 0))";

/**
 * Whether a visit sent an engagement signal at or after $since. PURE.
 *
 * @param array $events A visit's rows { ts, ev, dwell? }.
 * @param int   $since  Unix time.
 * @return bool
 */
function sn_analytics_live_visit_engaged( array $events, $since ) {
	foreach ( $events as $e ) {
		$ev = (string) ( $e['ev'] ?? '' );
		if ( (int) ( $e['ts'] ?? 0 ) >= (int) $since && ( in_array( $ev, array( 'sc', 'ce', 'cp' ), true ) || ( 'tm' === $ev && (float) ( $e['dwell'] ?? 0 ) > 0 ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Readers and arrivals per class from the two counts. A cold or older cache
 * (no engaged counts) answers null for both: not read, never the arrivals'
 * total passed off as readers. PURE.
 *
 * @param array|null $counts  class => visitors active in the window.
 * @param array|null $engaged class => visitors with an engagement signal.
 * @return array{readers:array<string,int>|null, arrived:array<string,int>|null}
 */
function sn_analytics_live_split( $counts, $engaged ) {
	if ( ! is_array( $counts ) || ! is_array( $engaged ) ) {
		return array( 'readers' => null, 'arrived' => null );
	}
	$readers = array();
	$arrived = array();
	foreach ( array( 'human', 'suspect', 'bot' ) as $class ) {
		$all               = max( 0, (int) ( $counts[ $class ] ?? 0 ) );
		$readers[ $class ] = min( $all, max( 0, (int) ( $engaged[ $class ] ?? 0 ) ) );
		$arrived[ $class ] = $all - $readers[ $class ];
	}
	return array( 'readers' => $readers, 'arrived' => $arrived );
}
