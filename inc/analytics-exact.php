<?php
/**
 * Signal & Noise — exact page-view counts from the analytics worker (2.2.0).
 *
 * Analytics Engine samples a busy visitor-day at write time, so its weighted
 * figures are estimates (inc/analytics-today-estimate.php). The worker also
 * counts every public page view in D1 and sends today and yesterday in the body
 * of its authenticated refresh poke (inc/analytics-refresh-rest.php). Here that
 * block is validated, held in memory until the response is gone, classified
 * against Analytics Engine (inc/analytics-exact-reconcile.php) and stored as
 * per-day totals per class. No visitor hash is ever stored or returned.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-exact-reconcile.php';

const SN_ANALYTICS_EXACT_OPT      = 'sn_analytics_exact_v1';
const SN_ANALYTICS_EXACT_KEEP     = 400;   // stored days
const SN_ANALYTICS_EXACT_ROWS_MAX = 20000; // the worker's own cap per push
const SN_ANALYTICS_EXACT_FRESH    = 45 * MINUTE_IN_SECONDS; // a today figure older than this is not shown

/**
 * The pushed block, validated; null when absent or malformed in any part.
 * The sender is authenticated, but nothing it sends is trusted unchecked. PURE.
 *
 * @param mixed $x The `exact` member of the refresh body.
 * @return array{first_full_day:?string,read_at:int,truncated:bool,days:array<string,array>}|null
 */
function sn_analytics_exact_validate( $x ) {
	$date = static fn( $d ) => is_string( $d ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
	if ( ! is_array( $x ) || 1 !== ( $x['v'] ?? null ) || ! is_array( $x['days'] ?? null ) || ! is_int( $x['read_at'] ?? null ) ) {
		return null;
	}
	$ffd = $x['first_full_day'] ?? null;
	if ( null !== $ffd && ! $date( $ffd ) ) {
		return null;
	}
	$days = array();
	$n    = 0;
	foreach ( $x['days'] as $day => $rows ) {
		if ( ! $date( $day ) || ! is_array( $rows ) ) {
			return null;
		}
		foreach ( $rows as $r ) {
			$ok = is_array( $r ) && 3 === count( $r ) && is_string( $r[0] ) && 1 === preg_match( '/^[0-9a-f]{8,64}$/', $r[0] )
				&& is_string( $r[1] ) && '/' === substr( $r[1], 0, 1 ) && strlen( $r[1] ) <= 1024
				&& is_int( $r[2] ) && $r[2] >= 0 && $r[2] <= 10000000;
			if ( ! $ok || ++$n > SN_ANALYTICS_EXACT_ROWS_MAX ) {
				return null;
			}
		}
		$days[ $day ] = array_values( $rows );
	}
	return array( 'first_full_day' => $ffd, 'read_at' => $x['read_at'], 'truncated' => true === ( $x['truncated'] ?? false ), 'days' => $days );
}

/**
 * The block from this request's poke, held until the response is sent.
 *
 * @param array|null $set A validated block to hold; omit to read.
 * @return array|null
 */
function sn_analytics_exact_pending( $set = null ) {
	static $held = null;
	if ( null !== $set ) {
		$held = $set;
	}
	return $held;
}

/**
 * Classify and store the held block. One Analytics Engine read per pushed day.
 * Runs after the response (inc/analytics-refresh-rest.php).
 *
 * @return void
 */
function sn_analytics_exact_ingest() {
	$block = sn_analytics_exact_pending();
	if ( null === $block || ! function_exists( 'sn_analytics_query' ) || ! function_exists( 'sn_analytics_overcap_vdays' ) ) {
		return;
	}
	$cap    = sn_analytics_overcap_vdays();
	$cap_ok = ! empty( $cap['ok'] ) && empty( $cap['truncated'] );
	$store  = get_option( SN_ANALYTICS_EXACT_OPT, array() );
	$store  = is_array( $store ) && is_array( $store['days'] ?? null ) ? $store : array( 'days' => array() );
	foreach ( $block['days'] as $day => $rows ) {
		$full = null !== $block['first_full_day'] && $day >= $block['first_full_day'] && ! $block['truncated'];
		$r    = sn_analytics_exact_reconcile( $rows, sn_analytics_query( sn_analytics_exact_class_sql( $day, (array) $cap['hashes'] ) ), $full, $cap_ok );
		if ( $block['truncated'] ) {
			$r['why'] = 'the worker\'s read was cut short';
		}
		$store['days'][ $day ] = $r + array( 'read_at' => $block['read_at'] );
	}
	ksort( $store['days'] );
	$store['days']           = array_slice( $store['days'], -SN_ANALYTICS_EXACT_KEEP, null, true );
	$store['first_full_day'] = $block['first_full_day'];
	update_option( SN_ANALYTICS_EXACT_OPT, $store, false );
}

/**
 * A stored day's reading, or null when none was stored.
 *
 * @param string $day Y-m-d, site time.
 * @return array{exact:bool,why:string,views:array<string,int>,unclassed:int,read_at:int}|null
 */
function sn_analytics_exact_day( $day ) {
	$store = get_option( SN_ANALYTICS_EXACT_OPT, array() );
	$d     = is_array( $store ) ? ( $store['days'][ (string) $day ] ?? null ) : null;
	return is_array( $d ) && isset( $d['exact'], $d['views'], $d['read_at'] ) ? $d : null;
}

/**
 * Today's exact human page views when the reading is exact and fresh, else null.
 *
 * @param int|null $now Unix time.
 * @return array{views:int,read_at:int}|null
 */
function sn_analytics_exact_today( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	$d   = function_exists( 'sn_analytics_local_day' ) ? sn_analytics_exact_day( sn_analytics_local_day( $now ) ) : null;
	if ( null === $d || true !== $d['exact'] || $now - (int) $d['read_at'] > SN_ANALYTICS_EXACT_FRESH ) {
		return null;
	}
	return array( 'views' => (int) $d['views']['human'], 'read_at' => (int) $d['read_at'] );
}
