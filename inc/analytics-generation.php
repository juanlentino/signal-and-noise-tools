<?php
/**
 * Signal & Noise Tools: which Analytics Engine dataset a read uses.
 *
 * The analytics worker writes two generations (see inc/analytics-v2-compare.php).
 * A read moves to the second generation when BOTH hold:
 *   - the daily check has verified it: at least one full day on or after
 *     SN_ANALYTICS_V2_FROM matches the legacy dataset and none mismatches;
 *   - the read's window starts on or after SN_ANALYTICS_V2_FROM, the first
 *     full UTC day both generations hold. A window reaching further back
 *     stays on the legacy dataset, which holds all of it.
 * Otherwise the legacy dataset answers, exactly as before. The switch is per
 * read and reverses by itself: a later mismatch sends every read back.
 *
 * Column map. In `sn_pageviews_v2` blobs 1..15 and doubles 1..10 are where the
 * legacy row has them; the visitor timezone moved from blob19 to blob16, and
 * a custom event's NAME (legacy blob16) is blob17 on its `ce` row. In
 * `sn_events_v2` the name, property and value (legacy blob16..18) are
 * blob17..19 and the timezone is blob16.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_ANALYTICS_V2_FROM         = '2026-10-05';
const SN_ANALYTICS_V2_VERIFIED_OPT = 'sn_analytics_v2_verified'; // { ok: bool, day: Y-m-d, at: unix, why: string }.

/**
 * Whether the second generation is verified. Request-scoped; `$set` is the
 * test seam and the writer's way to publish a fresh verdict.
 *
 * @param bool|null $set A verdict to hold for this request.
 * @return bool
 */
function sn_analytics_v2_verified( $set = null ) {
	static $memo = null;
	if ( null !== $set ) {
		$memo = (bool) $set;
	}
	if ( null === $memo ) {
		$v    = function_exists( 'get_option' ) ? get_option( SN_ANALYTICS_V2_VERIFIED_OPT, array() ) : array();
		$memo = is_array( $v ) && ! empty( $v['ok'] );
	}
	return $memo;
}

/**
 * The dataset for a read. PURE given the verdict.
 *
 * @param string $from_day The first UTC day the read's window can touch, Y-m-d.
 * @param string $kind     'pageviews' (everything but property rows) or 'events' (ce and cp).
 * @return string Dataset name.
 */
function sn_analytics_source( $from_day, $kind = 'pageviews' ) {
	$legacy = defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews';
	if ( ! sn_analytics_v2_verified() || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from_day ) || (string) $from_day < SN_ANALYTICS_V2_FROM ) {
		return $legacy;
	}
	return 'events' === $kind ? 'sn_events_v2' : 'sn_pageviews_v2';
}

/**
 * The first UTC day a trailing window of `$days` can touch. One day of slack:
 * the rollups floor to a LOCAL day start, which can sit up to 14 hours before
 * the UTC one.
 *
 * @param int      $days Trailing days.
 * @param int|null $now  Unix time; null reads the clock.
 * @return string Y-m-d.
 */
function sn_analytics_trailing_from( $days, $now = null ) {
	return gmdate( 'Y-m-d', ( null === $now ? sn_analytics_clock() : (int) $now ) - ( max( 0, (int) $days ) + 1 ) * 86400 );
}

/**
 * The clock the dataset choice reads. `$set` pins it for a test.
 *
 * @param int|null $set Unix time to hold; 0 releases it.
 * @return int
 */
function sn_analytics_clock( $set = null ) {
	static $pinned = 0;
	if ( null !== $set ) {
		$pinned = (int) $set;
	}
	return $pinned > 0 ? $pinned : time();
}

/**
 * A legacy column name as the given dataset spells it. PURE.
 *
 * @param string $col     Legacy column (blob16..blob19 are the ones that moved).
 * @param string $dataset From sn_analytics_source().
 * @return string
 */
function sn_analytics_col( $col, $dataset ) {
	$map = array(
		'sn_pageviews_v2' => array( 'blob19' => 'blob16', 'blob16' => 'blob17' ),
		'sn_events_v2'    => array( 'blob19' => 'blob16', 'blob16' => 'blob17', 'blob17' => 'blob18', 'blob18' => 'blob19' ),
	);
	return $map[ (string) $dataset ][ (string) $col ] ?? (string) $col;
}

/**
 * The verdict from one comparison. PURE.
 *
 * @param array<string,mixed> $check sn_analytics_v2_check() output.
 * @param int                 $now   Unix time.
 * @return array{ok:bool,day:string,at:int,why:string}
 */
function sn_analytics_v2_verdict( array $check, $now ) {
	$out = array( 'ok' => false, 'day' => '', 'at' => (int) $now, 'why' => '' );
	if ( empty( $check['read'] ) ) {
		$out['why'] = 'not read: ' . (string) ( $check['failed'] ?? '' ) . ' ' . (string) ( $check['error'] ?? '' );
		return $out;
	}
	$match = '';
	foreach ( (array) ( $check['days'] ?? array() ) as $d ) {
		if ( 'mismatch' === ( $d['state'] ?? '' ) ) {
			$out['why'] = 'mismatch on ' . (string) $d['day'];
			return $out;
		}
		// Today is still filling: a match there can turn before midnight.
		if ( 'match' === ( $d['state'] ?? '' ) && (string) $d['day'] < gmdate( 'Y-m-d', (int) $now ) ) {
			$match = (string) $d['day'];
		}
	}
	if ( '' === $match ) {
		$out['why'] = 'no complete day has matched yet';
		return $out;
	}
	return array( 'ok' => true, 'day' => $match, 'at' => (int) $now, 'why' => '' );
}

/**
 * Daily: compare the generations and store the verdict. A failed read keeps
 * the previous verdict (not knowing is not a mismatch); a mismatch clears it.
 *
 * @return array<string,mixed>|null The stored verdict, null when nothing was stored.
 */
function sn_analytics_v2_verify() {
	if ( ! function_exists( 'sn_analytics_v2_check' ) || ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return null;
	}
	$check = sn_analytics_v2_check( 4, SN_ANALYTICS_V2_FROM );
	if ( empty( $check['read'] ) ) {
		return null;
	}
	$verdict = sn_analytics_v2_verdict( $check, time() );
	update_option( SN_ANALYTICS_V2_VERIFIED_OPT, $verdict, false );
	sn_analytics_v2_verified( $verdict['ok'] );
	return $verdict;
}
