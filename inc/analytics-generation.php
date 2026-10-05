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
// The analytics worker's SN_ROTATE_TZ: index1 rotates at midnight here. The
// split follows THIS zone, the hash rotation, which is what keeps every
// visitor-day on one side; the site's own zone (sn_analytics_site_tz_name())
// is the same today, so its rollup day does not straddle the split either.
const SN_ANALYTICS_SPLIT_TZ        = 'America/New_York';
const SN_ANALYTICS_V2_VERIFIED_OPT = 'sn_analytics_v2_verified'; // { ok, day, clean_from, events_ok, at, why }.

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
 * Whether the events dataset has been proven. Request-scoped; `$set` is the
 * writer's way to publish it and the test seam.
 *
 * @param bool|null $set A value to hold for this request.
 * @return bool
 */
function sn_analytics_v2_events_proven( $set = null ) {
	static $memo = null;
	if ( null !== $set ) {
		$memo = (bool) $set;
	}
	if ( null === $memo ) {
		$v    = function_exists( 'get_option' ) ? get_option( SN_ANALYTICS_V2_VERIFIED_OPT, array() ) : array();
		$memo = is_array( $v ) && ! empty( $v['ok'] ) && ! empty( $v['events_ok'] );
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
	if ( 'events' === $kind ) {
		// The events dataset needs its own evidence (a custom event counted
		// exactly in both generations) from a day inside the same clean range.
		return sn_analytics_v2_events_proven() ? 'sn_events_v2' : $legacy;
	}
	return 'sn_pageviews_v2';
}

/**
 * Where a read that crosses the clean day is split between the generations:
 * the first America/New_York midnight on the clean day, as a UTC
 * 'Y-m-d H:i:s' (04:00 or 05:00 UTC that date). Both generations hold every
 * row from the clean day's UTC start until the legacy write stops, so the cut
 * can sit at any instant in that span; at this one no visitor-day straddles
 * it (the worker rotates index1 at that midnight) and no site-local rollup
 * day does either, so per-key sums and distinct counts merge exactly. ''
 * while the second generation is not verified.
 *
 * @return string
 */
function sn_analytics_split_at() {
	$clean = sn_analytics_v2_clean_from();
	if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $clean ) ) {
		return '';
	}
	$at = new DateTimeImmutable( $clean . ' 00:00:00', new DateTimeZone( SN_ANALYTICS_SPLIT_TZ ) );
	return $at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
}

/**
 * The two halves of a read whose window starts before the clean day, or null
 * when one dataset answers it whole (not verified: legacy; starts on or after
 * the clean day: the second generation, through sn_analytics_source()). PURE
 * given the verdict. The legacy half reads rows before `at`, the other from
 * it; when the legacy rows age out, the legacy half is simply empty.
 *
 * @param string $from_day The first UTC day the read's window can touch.
 * @param string $kind     'pageviews' or 'events'.
 * @return array{legacy:string,v2:string,at:string}|null
 */
function sn_analytics_stitch( $from_day, $kind = 'pageviews' ) {
	$at = sn_analytics_split_at();
	if ( '' === $at || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from_day ) || (string) $from_day >= sn_analytics_v2_clean_from() ) {
		return null;
	}
	if ( 'events' === $kind && ! sn_analytics_v2_events_proven() ) {
		return null; // the events dataset has no evidence yet: legacy answers whole, as before.
	}
	return array(
		'legacy' => defined( 'SN_ANALYTICS_DATASET' ) ? SN_ANALYTICS_DATASET : 'sn_pageviews',
		'v2'     => 'events' === $kind ? 'sn_events_v2' : 'sn_pageviews_v2',
		'at'     => $at,
	);
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
	$clean  = max( SN_ANALYTICS_V2_FROM, (string) ( $before['clean_from'] ?? '' ) );
	// Proven once, it stays proven until a mismatch (which resets it below).
	$events = ! empty( $before['events_ok'] );
	$out    = array( 'ok' => false, 'day' => '', 'clean_from' => $clean, 'events_ok' => $events, 'at' => (int) $now, 'why' => '' );
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
			$clean  = max( $clean, gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + 86400 ) );
			$match  = '';
			$events = false;
		} elseif ( 'match' === ( $d['state'] ?? '' ) && $day >= $clean ) {
			$match  = $day;
			$events = $events || ! empty( $d['events_proven'] );
		}
	}
	$out['clean_from'] = $clean;
	$out['events_ok']  = $events;
	if ( '' === $match ) {
		// Nothing matched in this look, and nothing mismatched either (a
		// mismatch moved the clean day above). A verdict already earned stands:
		// quiet or sampled days are no evidence against it. Only the first
		// verification has to wait for an exact match.
		if ( ! empty( $before['ok'] ) && $clean === max( SN_ANALYTICS_V2_FROM, (string) ( $before['clean_from'] ?? '' ) ) ) {
			return array( 'ok' => true, 'day' => (string) ( $before['day'] ?? '' ), 'why' => 'kept: no complete day in this check was exact, and none mismatched' ) + $out;
		}
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
	// Two daily writers follow the verdict (the analytics rollup and the
	// session rollup), on separate hooks minutes apart. Whichever runs first
	// refreshes it; the other, inside half an hour, takes it as stored.
	$last = function_exists( 'get_option' ) ? get_option( SN_ANALYTICS_V2_VERIFIED_OPT, array() ) : array();
	// Only inside the same UTC day: across midnight a new day has become
	// complete, and a verdict from before it never looked at that day.
	if ( is_array( $last ) && isset( $last['at'] ) && $now - (int) $last['at'] < 1800 && $now >= (int) $last['at'] && gmdate( 'Y-m-d', $now ) === gmdate( 'Y-m-d', (int) $last['at'] ) ) {
		return $last;
	}
	if ( ! function_exists( 'sn_analytics_v2_check' ) || ! function_exists( 'sn_analytics_config' ) || ! sn_analytics_config() ) {
		return null;
	}
	$check = sn_analytics_v2_check( 4, SN_ANALYTICS_V2_FROM );
	if ( empty( $check['read'] ) ) {
		return null;
	}
	// The three datasets are read one after another, each against its own
	// now(). If UTC midnight fell between them they cover different days, and
	// the day only one of them reached would read as a mismatch. Such a check
	// is thrown away; tomorrow's run decides. (`$now` given: a test's clock.)
	if ( func_num_args() < 1 && gmdate( 'Y-m-d', $now ) !== gmdate( 'Y-m-d' ) ) {
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
	if ( ( $was !== $is || ! empty( $before['events_ok'] ) !== $verdict['events_ok'] ) && function_exists( 'delete_transient' ) ) {
		if ( defined( 'SNT_ANALYTICS_VDAY_CACHE_KEY' ) ) {
			delete_transient( SNT_ANALYTICS_VDAY_CACHE_KEY );
		}
		// The realtime snapshot and today's last-good count carry no dataset in
		// their keys; a count from the dataset just left must not outlive it.
		if ( defined( 'SN_ANALYTICS_REALTIME_KEY' ) ) {
			delete_transient( SN_ANALYTICS_REALTIME_KEY );
			delete_option( SN_ANALYTICS_VIEWS_TODAY_LASTGOOD );
		}
	}
	sn_analytics_v2_clean_from( $is );
	sn_analytics_v2_events_proven( $verdict['ok'] && $verdict['events_ok'] );
	return $verdict;
}
