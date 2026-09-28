<?php
/**
 * Feed reach: subscribers, as reported by the readers themselves. Aggregators
 * put a subscriber count in their fetcher User-Agent ("Feedly/1.0 (...; 12
 * subscribers; ...)", "NewsBlur Feed Fetcher - 5 subscribers - ...", "Feedbin
 * feed-id:123 - 3 subscribers"), so one Feedly fetch stands for many people.
 * The RSS tracker (inc/rss-feed-tracker.php) hands each non-bot feed fetch here.
 *
 * Stored per UTC day in one option: for each (fetcher, feed-id or feed path) the
 * MAX count it reported that day, and the ua_hash of every fetch that reported
 * no count (a direct reader, worth 1). Only these parsed fields are kept, never
 * the raw UA: the same privacy as the tracker's own ua_hash column.
 *
 * A ceiling, never a total: counts are self-reported, include inactive
 * subscriptions, and cannot be verified. Kept apart from the north star.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SNT_FEED_SUBS_OPT     = 'snt_feed_subscribers';
const SNT_FEED_SUBS_DAYS    = 90;
const SNT_FEED_SUBS_PER_DAY = 200;     // keys kept per bucket per day; bounds a UA-spraying flood (90 days stay ~3 MB).
const SNT_FEED_SUBS_CAP     = 1000000; // a claim above this is spoofed or broken: capped and flagged.

/**
 * Parse a fetcher UA. PURE.
 *
 * @param string $ua User-Agent.
 * @return array{fetcher:string, feed_id:string, n:int, capped:bool}|null Null when no count.
 */
function snt_feed_subs_parse( $ua ) {
	$ua = (string) $ua;
	if ( ! preg_match( '/(\d[\d,]*)\s+(?:subscribers?|readers?)\b/i', $ua, $m ) ) {
		return null;
	}
	$n = (int) str_replace( ',', '', $m[1] );
	// "Mozilla/5.0 (compatible; inoreader.com; 3 subscribers)": the product is inside the parens.
	$name = preg_match( '/^Mozilla\/[\d.]+\s*\(compatible;\s*([^;)]+)/i', $ua, $c ) ? $c[1] : preg_split( '/\/|\(|;|\s-\s|\s+feed-?id/i', $ua )[0];
	$name = substr( trim( preg_replace( '/[^A-Za-z0-9 ._-]/', '', $name ) ), 0, 40 );
	$fid  = preg_match( '/feed-?id[:=]\s*([A-Za-z0-9_-]{1,40})/i', $ua, $f ) ? $f[1] : '';
	return array(
		'fetcher' => '' === $name ? 'unknown' : $name,
		'feed_id' => $fid,
		'n'       => min( $n, SNT_FEED_SUBS_CAP ),
		'capped'  => $n > SNT_FEED_SUBS_CAP,
	);
}

/**
 * Record one fetch into a store. PURE.
 *
 * @param array      $store     {Y-m-d: {agg: {key: n}, flag: {key: 1}, direct: {ua_hash: 1}}}.
 * @param array|null $parsed    snt_feed_subs_parse() result.
 * @param string     $ua_hash   Hashed UA.
 * @param string     $feed_path Feed path, the key when the fetcher sends no feed-id.
 * @param int        $now       Epoch seconds.
 * @return array The new store, pruned to SNT_FEED_SUBS_DAYS.
 */
function snt_feed_subs_record( array $store, $parsed, $ua_hash, $feed_path, $now ) {
	$day = gmdate( 'Y-m-d', $now );
	$row = $store[ $day ] ?? array( 'agg' => array(), 'flag' => array(), 'direct' => array() );
	if ( is_array( $parsed ) ) {
		$key = $parsed['fetcher'] . '|' . ( '' !== $parsed['feed_id'] ? $parsed['feed_id'] : substr( (string) $feed_path, 0, 80 ) );
		if ( isset( $row['agg'][ $key ] ) || count( $row['agg'] ) < SNT_FEED_SUBS_PER_DAY ) {
			$row['agg'][ $key ] = max( (int) ( $row['agg'][ $key ] ?? 0 ), (int) $parsed['n'] ); // max, not sum: a fetcher re-polls all day
			if ( $parsed['capped'] ) {
				$row['flag'][ $key ] = 1;
			}
		}
	} elseif ( count( $row['direct'] ) < SNT_FEED_SUBS_PER_DAY ) {
		$row['direct'][ (string) $ua_hash ] = 1;
	}
	$store[ $day ] = $row;
	$cut = gmdate( 'Y-m-d', $now - ( SNT_FEED_SUBS_DAYS - 1 ) * DAY_IN_SECONDS );
	return array_filter( $store, static fn( $d ) => $d >= $cut, ARRAY_FILTER_USE_KEY );
}

/**
 * One day's estimate: sum of each (fetcher, feed) max, plus direct readers at 1 each. PURE.
 *
 * @param array $row A store day.
 * @return int
 */
function snt_feed_subs_day( array $row ) {
	return (int) array_sum( (array) ( $row['agg'] ?? array() ) ) + count( (array) ( $row['direct'] ?? array() ) );
}

/**
 * The 7-day reading: the busiest day's estimate in the last 7 days. Not the
 * latest day, which is partial until every fetcher has polled; never a sum
 * of days, which would count the same subscribers seven times. PURE.
 *
 * @param array $store Store.
 * @param int   $now   Epoch seconds.
 * @return array
 */
function snt_feed_subs_summary( array $store, $now ) {
	$cut  = gmdate( 'Y-m-d', $now - 6 * DAY_IN_SECONDS );
	$peak = null;
	foreach ( $store as $day => $row ) {
		if ( $day >= $cut && ( null === $peak || snt_feed_subs_day( (array) $row ) > snt_feed_subs_day( (array) $store[ $peak ] ) ) ) {
			$peak = $day;
		}
	}
	$row  = null === $peak ? array() : (array) $store[ $peak ];
	$aggs = array();
	foreach ( (array) ( $row['agg'] ?? array() ) as $key => $n ) {
		$f          = strtok( (string) $key, '|' );
		$aggs[ $f ] = ( $aggs[ $f ] ?? 0 ) + (int) $n;
	}
	arsort( $aggs );
	$list = array();
	foreach ( $aggs as $f => $n ) {
		$list[] = array( 'fetcher' => (string) $f, 'subscribers' => (int) $n );
	}
	return array(
		'basis'          => 'ceiling',
		'window'         => 'max_day_7d',
		'note'           => 'Reported by the readers, a ceiling: aggregators count inactive subscriptions too. The busiest day in the last 7 days.',
		'estimate_7d'    => null === $peak ? 0 : snt_feed_subs_day( $row ),
		'peak_day'       => $peak,
		'aggregators'    => $list,
		'direct_readers' => count( (array) ( $row['direct'] ?? array() ) ),
		'flagged'        => array_values( array_unique( array_map( static fn( $k ) => strtok( (string) $k, '|' ), array_keys( (array) ( $row['flag'] ?? array() ) ) ) ) ),
	);
}

/**
 * The summary, read from the stored option.
 *
 * @return array
 */
function snt_feed_subs_stats() {
	return snt_feed_subs_summary( (array) get_option( SNT_FEED_SUBS_OPT, array() ), time() );
}

/**
 * Called by the RSS tracker for each non-bot feed fetch.
 *
 * @param string $ua        Raw UA (parsed here, never stored).
 * @param string $ua_hash   Hashed UA.
 * @param string $feed_path Feed path.
 */
function snt_feed_subs_capture( $ua, $ua_hash, $feed_path ) {
	// ponytail: read-modify-write on one option, like feed-opens; a lost race only drops one fetch
	// of a fetcher that re-polls all day. Move to a table if feed fetches reach hundreds a minute.
	$store = snt_feed_subs_record( (array) get_option( SNT_FEED_SUBS_OPT, array() ), snt_feed_subs_parse( $ua ), $ua_hash, $feed_path, time() );
	update_option( SNT_FEED_SUBS_OPT, $store, false );
}

/**
 * Feed reach as three labelled numbers, never summed.
 *
 * @return array<int,array{label:string,value:int|null}>
 */
function snt_feed_reach_tiles() {
	return array(
		array( 'label' => __( 'Subscribers (reported by readers, a ceiling)', 'signal-and-noise-tools' ), 'value' => (int) snt_feed_subs_stats()['estimate_7d'] ),
		array( 'label' => __( 'Opens (at least; many readers block images)', 'signal-and-noise-tools' ), 'value' => function_exists( 'snt_feed_opens_stats' ) ? (int) snt_feed_opens_stats( 7 )['total'] : null ),
		array( 'label' => __( 'Clicks (visits from feed links)', 'signal-and-noise-tools' ), 'value' => function_exists( 'snt_nsm_feed_clicks' ) ? snt_nsm_feed_clicks( time() ) : null ),
	);
}
