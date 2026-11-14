<?php
/**
 * The reads store (inc/analytics-reads.php): one option, site day => page =>
 * class => reads. A day present with no pages is measured with none; a day
 * absent is not measured, never 0. Refreshed with the nightly rollup (the
 * trailing week) and backfilled night by night as far back as Analytics
 * Engine holds, about 90 days.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_READS_OPTION          = 'sn_analytics_reads_by_day';
const SN_ANALYTICS_READS_BACKFILL_OPTION = 'sn_analytics_reads_backfill_v2'; // v2 (2026-11-14): walk again, a read now needs a page view.
const SN_ANALYTICS_READS_KEEP            = 400;
const SN_ANALYTICS_READS_NIGHTLY_DAYS    = 7;
const SN_ANALYTICS_READS_CHUNK           = 7;
const SN_ANALYTICS_READS_HOLD_DAYS       = 88; // Analytics Engine keeps about 90 days; the oldest is partial.

/** Today in the site's zone, 'Y-m-d'. */
function sn_analytics_reads_today() {
	$tz = sn_analytics_site_tz_name();
	return ( new DateTimeImmutable( '@' . sn_analytics_clock() ) )->setTimezone( new DateTimeZone( '' !== $tz ? $tz : 'UTC' ) )->format( 'Y-m-d' );
}

/** $day shifted by $n days. PURE. */
function sn_analytics_reads_shift( $day, $n ) {
	return gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + (int) $n * 86400 );
}

/**
 * Read site days [$first, $end) and merge them into the store. False when the
 * read failed or reached its cap: nothing is stored, so no day reads measured.
 *
 * @param string $first First site day.
 * @param string $end   Exclusive end site day.
 * @return bool
 */
function sn_analytics_reads_measure( $first, $end ) {
	if ( ! function_exists( 'sn_analytics_stitched_rows' ) || ! function_exists( 'sn_analytics_rollup_class_parts' ) ) {
		return false;
	}
	$tz    = sn_analytics_site_tz_name();
	$zone  = new DateTimeZone( '' !== $tz ? $tz : 'UTC' );
	$utc   = static fn( $d ) => ( new DateTimeImmutable( $d . ' 00:00:00', $zone ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	$lo    = $utc( $first );
	$hi    = $utc( $end );
	$class = sn_analytics_rollup_class_parts( true )[0];
	$sets  = sn_analytics_stitched_rows( static fn( $s, $r ) => sn_analytics_reads_sql( $s, $r, $lo, $hi, $tz, $class ), substr( $lo, 0, 10 ), $hi );
	if ( null === $sets || array() !== array_filter( $sets, static fn( $set ) => count( $set ) >= SN_ANALYTICS_READS_LIMIT ) ) {
		error_log( '[sn-analytics] reads: the read of ' . $first . ' to ' . $end . ' failed or reached its cap; those days stay as they were' );
		return false;
	}
	$days = array();
	for ( $d = $first; $d < $end; $d = sn_analytics_reads_shift( $d, 1 ) ) {
		$days[] = $d;
	}
	$stored = get_option( SN_ANALYTICS_READS_OPTION, array() );
	$out    = array_merge( is_array( $stored ) ? $stored : array(), sn_analytics_reads_fold( array_merge( ...$sets ), $days ) );
	ksort( $out );
	update_option( SN_ANALYTICS_READS_OPTION, array_slice( $out, -SN_ANALYTICS_READS_KEEP, null, true ), false );
	return true;
}

/**
 * Nightly: the trailing week again, then ONE backfill chunk back (the
 * bot-signal job runs on the same tick and has most of WP-Cron's minute;
 * about 12 nights reach the 88 days Analytics Engine holds).
 */
function sn_analytics_reads_refresh() {
	$today = sn_analytics_reads_today();
	sn_analytics_reads_measure( sn_analytics_reads_shift( $today, 1 - SN_ANALYTICS_READS_NIGHTLY_DAYS ), sn_analytics_reads_shift( $today, 1 ) );
	$floor  = sn_analytics_reads_shift( $today, -SN_ANALYTICS_READS_HOLD_DAYS );
	$cursor = (string) get_option( SN_ANALYTICS_READS_BACKFILL_OPTION, '' );
	$cursor = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $cursor ) ? $cursor : sn_analytics_reads_shift( $today, 1 - SN_ANALYTICS_READS_NIGHTLY_DAYS );
	if ( $cursor > $floor ) {
		$from = max( $floor, sn_analytics_reads_shift( $cursor, -SN_ANALYTICS_READS_CHUNK ) );
		if ( sn_analytics_reads_measure( $from, $cursor ) ) {
			update_option( SN_ANALYTICS_READS_BACKFILL_OPTION, $from, false );
		}
	}
}

/** With the on-demand rollup (the hourly warmer): yesterday and today, so reads keep pace with the views under them. */
function sn_analytics_reads_refresh_recent() {
	$today = sn_analytics_reads_today();
	sn_analytics_reads_measure( sn_analytics_reads_shift( $today, -1 ), sn_analytics_reads_shift( $today, 1 ) );
}
if ( defined( 'SN_ANALYTICS_ROLLUP_DAILY_HOOK' ) ) {
	add_action( SN_ANALYTICS_ROLLUP_DAILY_HOOK, 'sn_analytics_reads_refresh', 21 );
}
if ( defined( 'SN_ANALYTICS_ROLLUP_HOOK' ) ) {
	add_action( SN_ANALYTICS_ROLLUP_HOOK, 'sn_analytics_reads_refresh_recent', 21 );
}

/**
 * Reads over site days [$from, $to] for one class, optionally for the pages
 * one test keeps. reads is null when no day in the window was measured.
 *
 * @param string        $from  First site day.
 * @param string        $to    Last site day (inclusive).
 * @param string        $class human|suspect|bot.
 * @param callable|null $keep  fn( string $path ): bool.
 * @param array|null    $store The store (null reads the option).
 * @return array{reads:int|null, days:int, measured_days:int, partial:bool, by_day:array<string,int|null>}
 */
function sn_analytics_reads_window( $from, $to, $class = 'human', $keep = null, $store = null ) {
	$store = is_array( $store ) ? $store : (array) get_option( SN_ANALYTICS_READS_OPTION, array() );
	$by    = array();
	$from  = max( (string) $from, sn_analytics_reads_shift( (string) $to, 1 - SN_ANALYTICS_READS_KEEP ) ); // older days are never stored
	for ( $d = $from; $d <= (string) $to; $d = sn_analytics_reads_shift( $d, 1 ) ) {
		if ( ! isset( $store[ $d ] ) || ! is_array( $store[ $d ] ) ) {
			$by[ $d ] = null;
			continue;
		}
		$n = 0;
		foreach ( $store[ $d ] as $path => $classes ) {
			$n += ( null === $keep || $keep( (string) $path ) ) ? (int) ( $classes[ $class ] ?? 0 ) : 0;
		}
		$by[ $d ] = $n;
	}
	$measured = array_filter( $by, static fn( $n ) => null !== $n );
	return array(
		'reads'         => array() === $measured ? null : array_sum( $measured ),
		'days'          => count( $by ),
		'measured_days' => count( $measured ),
		'partial'       => array() !== $measured && count( $measured ) < count( $by ),
		'by_day'        => $by,
	);
}

/**
 * Human reads per /r/ code over site days [$from, $to]. PURE given the store.
 *
 * @return array<string,int> code => reads (codes with a measured day only).
 */
function sn_analytics_reads_by_code( $from, $to, $store = null ) {
	$store = is_array( $store ) ? $store : (array) get_option( SN_ANALYTICS_READS_OPTION, array() );
	$out   = array();
	foreach ( $store as $day => $paths ) {
		if ( (string) $day < (string) $from || (string) $day > (string) $to || ! is_array( $paths ) ) {
			continue;
		}
		foreach ( $paths as $path => $classes ) {
			$code = sn_link_code_from_path( (string) $path ); // the Links summary's own test, so the rows join.
			if ( '' !== (string) $code ) {
				$out[ $code ] = (int) ( $out[ $code ] ?? 0 ) + (int) ( $classes['human'] ?? 0 );
			}
		}
	}
	return $out;
}
