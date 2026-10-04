<?php
/**
 * Signal & Noise Tools: which Analytics Engine dataset a read uses.
 *
 * The analytics worker writes two generations (see inc/analytics-v2-compare.php).
 * A read moves to the second generation when BOTH hold:
 *   - the daily check has verified it: a complete day matched the legacy
 *     dataset event by event, counted exactly;
 *   - the read's window starts on or after the clean day: the first full UTC
 *     day both generations hold (SN_ANALYTICS_V2_FROM), or the day after the
 *     latest mismatch if there has been one. A window reaching further back
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
const SN_ANALYTICS_V2_VERIFIED_OPT = 'sn_analytics_v2_verified'; // { ok, day, clean_from, at, why }.

/**
 * Whether the second generation is verified. Request-scoped; `$set` is the
 * test seam and the writer's way to publish a fresh verdict.
 *
 * @param bool|null $set A verdict to hold for this request.
 * @return bool
 */
function sn_analytics_v2_verified( $set = null ) {
	return '' !== sn_analytics_v2_clean_from( null === $set ? null : ( $set ? SN_ANALYTICS_V2_FROM : '' ) );
}

/**
 * The first day from which the second generation may be read: the first full
 * day, or the day after the latest mismatch, whichever is later. '' while it
 * is not verified. Request-scoped; `$set` publishes a fresh answer (and is the
 * test seam).
 *
 * @param string|null $set A day to hold for this request, '' for "not verified".
 * @return string Y-m-d or ''.
 */
function sn_analytics_v2_clean_from( $set = null ) {
	static $memo = null;
	if ( null !== $set ) {
		$memo = (string) $set;
	}
	if ( null === $memo ) {
		$v    = function_exists( 'get_option' ) ? get_option( SN_ANALYTICS_V2_VERIFIED_OPT, array() ) : array();
		$memo = is_array( $v ) && ! empty( $v['ok'] ) ? max( SN_ANALYTICS_V2_FROM, (string) ( $v['clean_from'] ?? '' ) ) : '';
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
	// A window is served by the second generation only when every day in it
	// was dual-written AND none of them is a day the two generations disagreed
	// on: it must start at or after the clean day.
	$clean = sn_analytics_v2_clean_from();
	if ( '' === $clean || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from_day ) || (string) $from_day < $clean ) {
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
	// The pageviews dataset has no entry for blob17/blob18: those are a property
	// row's columns, and property rows are only ever read from the events dataset.
	$map = array(
		'sn_pageviews_v2' => array( 'blob19' => 'blob16', 'blob16' => 'blob17' ),
		'sn_events_v2'    => array( 'blob19' => 'blob16', 'blob16' => 'blob17', 'blob17' => 'blob18', 'blob18' => 'blob19' ),
	);
	return $map[ (string) $dataset ][ (string) $col ] ?? (string) $col;
}

/**
 * The verdict from one comparison. PURE.
 *
 * Only COMPLETE days count, either way: today is still filling, and the three
 * datasets are read one after another, so a beacon landing between two reads
 * makes today differ with nothing wrong. A mismatch is remembered: the clean
 * day moves past it and stays there on later runs, so a read whose window
 * still holds the bad day keeps to the legacy dataset after the check itself
 * has stopped looking that far back.
 *
 * @param array<string,mixed> $check  sn_analytics_v2_check() output.
 * @param int                 $now    Unix time.
 * @param array<string,mixed> $before The stored verdict, for the remembered clean day.
 * @return array{ok:bool,day:string,clean_from:string,at:int,why:string}
 */
function sn_analytics_v2_verdict( array $check, $now, array $before = array() ) {
	$clean = max( SN_ANALYTICS_V2_FROM, (string) ( $before['clean_from'] ?? '' ) );
	$out   = array( 'ok' => false, 'day' => '', 'clean_from' => $clean, 'at' => (int) $now, 'why' => '' );
	if ( empty( $check['read'] ) ) {
		$out['why'] = 'not read: ' . (string) ( $check['failed'] ?? '' ) . ' ' . (string) ( $check['error'] ?? '' );
		return $out;
	}
	$today = gmdate( 'Y-m-d', (int) $now );
	$match = '';
	foreach ( (array) ( $check['days'] ?? array() ) as $d ) {
		$day = (string) ( $d['day'] ?? '' );
		if ( $day >= $today ) {
			continue;
		}
		if ( 'mismatch' === ( $d['state'] ?? '' ) ) {
			$clean = max( $clean, gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + 86400 ) );
			$match = '';
		} elseif ( 'match' === ( $d['state'] ?? '' ) && $day >= $clean ) {
			$match = $day;
		}
	}
	$out['clean_from'] = $clean;
	if ( '' === $match ) {
		$out['why'] = $clean > SN_ANALYTICS_V2_FROM ? 'no complete day has matched since the mismatch before ' . $clean : 'no complete day has matched yet';
		return $out;
	}
	return array( 'ok' => true, 'day' => $match ) + $out;
}

/**
 * Daily: compare the generations and store the verdict. A failed read keeps
 * the previous verdict (not knowing is not a mismatch); a mismatch clears it.
 *
 * @param int|null $now Unix time; null reads the clock.
 * @return array<string,mixed>|null The stored verdict, null when nothing was stored.
 */
function sn_analytics_v2_verify( $now = null ) {
	$now = null === $now ? time() : (int) $now;
	if ( ! function_exists( 'sn_analytics_v2_check' ) || ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return null;
	}
	$check = sn_analytics_v2_check( 4, SN_ANALYTICS_V2_FROM );
	if ( empty( $check['read'] ) ) {
		return null;
	}
	$before  = (array) get_option( SN_ANALYTICS_V2_VERIFIED_OPT, array() );
	$verdict = sn_analytics_v2_verdict( $check, $now, $before );
	update_option( SN_ANALYTICS_V2_VERIFIED_OPT, $verdict, false );
	// When the verdict flips, the reads change dataset. The over-cap visitor
	// list is cached for an hour and goes into every human/bot predicate, so
	// the rollup that runs next must not classify with a list read from the
	// dataset just left.
	$was = ! empty( $before['ok'] ) ? max( SN_ANALYTICS_V2_FROM, (string) ( $before['clean_from'] ?? '' ) ) : '';
	$is  = $verdict['ok'] ? $verdict['clean_from'] : '';
	if ( $was !== $is && defined( 'SNT_ANALYTICS_VDAY_CACHE_KEY' ) && function_exists( 'delete_transient' ) ) {
		delete_transient( SNT_ANALYTICS_VDAY_CACHE_KEY );
	}
	sn_analytics_v2_clean_from( $is );
	return $verdict;
}
