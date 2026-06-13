<?php
/**
 * Signal & Noise — derived analytics views computed in PHP over the existing
 * rollup accessors (v5.4.0). No AE query, no new table, no dialect risk:
 *
 *   - Referrer categories  — search / social / direct / other, folded from the
 *                            referrer dimension (sn_analytics_top_dimension).
 *   - Period-over-period   — current window vs the immediately-preceding window
 *                            of equal length (two sn_analytics_range_totals reads).
 *   - Bot breakdown        — per-class totals (sn_analytics_class_totals) plus
 *                            the top bot ASNs from the NEW network dimension.
 *
 * Every function degrades to zeros / empty when its upstream accessor is missing
 * (defence in depth — they're always loaded before this file in the manifest).
 *
 * @package SignalNoiseTools
 * @since 5.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classify a referrer HOST into a source category. Hosts only (no paths), so
 * short ambiguous shorteners (t.co, x.com) match exactly to avoid substring
 * false-positives (e.g. "first.co"); branded hosts match as substrings so
 * subdomains (mobile.twitter.com) still resolve. The dims layer stores an empty
 * referrer as the '(direct)' sentinel.
 *
 * @param string $host Referrer host (or '(direct)' / '(unknown)' sentinel).
 * @return string 'search' | 'social' | 'direct' | 'other'
 */
function sn_analytics_referrer_category( $host ) {
	$h = strtolower( trim( (string) $host ) );
	if ( '' === $h || '(direct)' === $h || '(unknown)' === $h ) {
		return 'direct';
	}

	$exact_social = array( 't.co', 'x.com', 'lnkd.in', 'fb.me', 'youtu.be', 'redd.it', 't.me', 'buff.ly', 'dlvr.it' );
	if ( in_array( $h, $exact_social, true ) ) {
		return 'social';
	}

	$search = array( 'google.', 'bing.', 'duckduckgo', 'yahoo.', 'yandex.', 'baidu.', 'ecosia.', 'startpage.', 'search.brave', 'qwant.', 'searx.' );
	foreach ( $search as $p ) {
		if ( strpos( $h, $p ) !== false ) {
			return 'search';
		}
	}

	$social = array( 'twitter.com', 'facebook.', 'instagram.', 'linkedin.', 'reddit.', 'ycombinator', 'lobste.rs', 'mastodon', 'bsky.', 'bluesky', 'threads.net', 'youtube.', 'tiktok.', 'pinterest.', 'telegram', 'substack.com', 'medium.com' );
	foreach ( $social as $p ) {
		if ( strpos( $h, $p ) !== false ) {
			return 'social';
		}
	}

	return 'other';
}

/**
 * Fold the referrer dimension into the 4 source categories (all returned,
 * zero-filled, in a stable order). Reads up to 500 referrer rows for the window.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @return array<int, array{category:string, label:string, views:int, visits:int}>
 */
function sn_analytics_referrer_categories( $from, $to, $class = 'human' ) {
	$rows = function_exists( 'sn_analytics_top_dimension' )
		? sn_analytics_top_dimension( 'referrer', $from, $to, $class, 500 )
		: array();

	$cats = array(
		'search' => array( 'label' => 'Search', 'views' => 0, 'visits' => 0 ),
		'social' => array( 'label' => 'Social', 'views' => 0, 'visits' => 0 ),
		'direct' => array( 'label' => 'Direct', 'views' => 0, 'visits' => 0 ),
		'other'  => array( 'label' => 'Other',  'views' => 0, 'visits' => 0 ),
	);

	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$cat                     = sn_analytics_referrer_category( $r['value'] ?? '' );
		$cats[ $cat ]['views']  += (int) ( $r['views'] ?? 0 );
		$cats[ $cat ]['visits'] += (int) ( $r['visits'] ?? 0 );
	}

	$out = array();
	foreach ( $cats as $key => $c ) {
		$out[] = array(
			'category' => $key,
			'label'    => $c['label'],
			'views'    => (int) $c['views'],
			'visits'   => (int) $c['visits'],
		);
	}
	return $out;
}

/**
 * A single metric's change between two values. pct is null when the previous
 * value is 0 (no division — avoids a bogus +∞ for brand-new traffic); the
 * direction is still derived from the raw comparison.
 *
 * @param float $cur
 * @param float $prev
 * @return array{pct:?int, dir:string} dir ∈ 'up' | 'down' | 'flat'
 */
function sn_analytics_delta( $cur, $prev ) {
	$cur  = (float) $cur;
	$prev = (float) $prev;
	if ( $prev <= 0 ) {
		return array( 'pct' => null, 'dir' => $cur > 0 ? 'up' : 'flat' );
	}
	$pct = (int) round( ( $cur - $prev ) / $prev * 100 );
	$dir = $cur > $prev ? 'up' : ( $cur < $prev ? 'down' : 'flat' );
	return array( 'pct' => $pct, 'dir' => $dir );
}

/**
 * The window immediately preceding [from,to], same length. Used for
 * period-over-period comparisons.
 *
 * @return array{0:string,1:string} [prior_from, prior_to]
 */
function sn_analytics_prior_window( $from, $to ) {
	$from_ts = strtotime( $from . ' 00:00:00 UTC' );
	$to_ts   = strtotime( $to . ' 00:00:00 UTC' );
	$days    = ( $from_ts && $to_ts ) ? ( (int) floor( ( $to_ts - $from_ts ) / DAY_IN_SECONDS ) + 1 ) : 1;
	$pto     = $from_ts - DAY_IN_SECONDS;
	$pfrom   = $pto - ( max( 1, $days ) - 1 ) * DAY_IN_SECONDS;
	return array( gmdate( 'Y-m-d', $pfrom ), gmdate( 'Y-m-d', $pto ) );
}

const SN_ANALYTICS_ENGAGED_TIME_MS = 10000; // ≥10s = an "engaged" pageview (GA4-style)

/**
 * Engaged-pageview rate: % of timed pageviews lasting ≥10s, from the time
 * distribution buckets. Single-signal by design — an "≥10s OR scrolled>50%"
 * union would need a per-visitor join the marginal buckets cannot express.
 *
 * @return int|null 0–100, or null when there are no timed pageviews.
 */
function sn_analytics_engaged_rate( $from, $to, $class = 'human' ) {
	$dist  = function_exists( 'sn_analytics_distribution' ) ? sn_analytics_distribution( 'time', $from, $to, $class ) : array();
	$bands = sn_analytics_buckets_metrics()['time']['buckets'] ?? array();
	$total = 0;
	$eng   = 0;
	foreach ( (array) $dist as $i => $b ) {
		$v      = (int) ( $b['views'] ?? 0 );
		$total += $v;
		if ( isset( $bands[ $i ]['lo'] ) && (int) $bands[ $i ]['lo'] >= SN_ANALYTICS_ENGAGED_TIME_MS ) {
			$eng += $v;
		}
	}
	if ( $total <= 0 ) {
		return null;
	}
	return (int) round( $eng / $total * 100 );
}

/**
 * Period-over-period delta for the engaged-pageview rate, shaped like
 * sn_analytics_period_deltas() entries so it renders with the same badge.
 *
 * @return array{current:?int, previous:?int, pct:?int, dir:string}
 */
function sn_analytics_engaged_rate_delta( $from, $to, $class = 'human' ) {
	$cur = sn_analytics_engaged_rate( $from, $to, $class );
	list( $pf, $pt ) = sn_analytics_prior_window( $from, $to );
	$prev = sn_analytics_engaged_rate( $pf, $pt, $class );
	if ( null === $cur || null === $prev ) {
		$d = array( 'pct' => null, 'dir' => 'flat' );
	} else {
		$d = sn_analytics_delta( $cur, $prev );
	}
	return array(
		'current'  => $cur,
		'previous' => $prev,
		'pct'      => $d['pct'],
		'dir'      => $d['dir'],
	);
}

/**
 * Period-over-period deltas: the current [$from,$to] window vs the immediately-
 * preceding window of equal length, for views / visits / scroll_avg / time_avg.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @return array<string, array{current:int|float, previous:int|float, pct:?int, dir:string}>
 */
function sn_analytics_period_deltas( $from, $to, $class = 'human' ) {
	$cur = function_exists( 'sn_analytics_range_totals' )
		? sn_analytics_range_totals( $from, $to, $class )
		: array();

	list( $prior_from, $prior_to ) = sn_analytics_prior_window( $from, $to );
	$prev = function_exists( 'sn_analytics_range_totals' )
		? sn_analytics_range_totals( $prior_from, $prior_to, $class )
		: array();

	$int_metrics = array( 'views', 'visits' );
	$out         = array();
	foreach ( array( 'views', 'visits', 'scroll_avg', 'time_avg' ) as $m ) {
		$c     = $cur[ $m ] ?? 0;
		$p     = $prev[ $m ] ?? 0;
		$delta = sn_analytics_delta( $c, $p );
		$out[ $m ] = array(
			'current'  => in_array( $m, $int_metrics, true ) ? (int) $c : (float) $c,
			'previous' => in_array( $m, $int_metrics, true ) ? (int) $p : (float) $p,
			'pct'      => $delta['pct'],
			'dir'      => $delta['dir'],
		);
	}
	return $out;
}

/**
 * Human / suspect / bot split for the window, plus the top bot ASNs (from the
 * NEW network dimension filtered to class='bot') — the "who's scraping me" view.
 *
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param int    $limit Max bot networks to return.
 * @return array{totals:array{human:int,suspect:int,bot:int,total:int}, top_bot_networks:array}
 */
function sn_analytics_bot_breakdown( $from, $to, $limit = 10 ) {
	$class_totals = function_exists( 'sn_analytics_class_totals' )
		? sn_analytics_class_totals( $from, $to )
		: array();

	$human   = (int) ( $class_totals['human']['views'] ?? 0 );
	$suspect = (int) ( $class_totals['suspect']['views'] ?? 0 );
	$bot     = (int) ( $class_totals['bot']['views'] ?? 0 );

	$networks = function_exists( 'sn_analytics_top_dimension' )
		? sn_analytics_top_dimension( 'network', $from, $to, 'bot', max( 1, (int) $limit ) )
		: array();

	return array(
		'totals'           => array(
			'human'   => $human,
			'suspect' => $suspect,
			'bot'     => $bot,
			'total'   => $human + $suspect + $bot,
		),
		'top_bot_networks' => is_array( $networks ) ? $networks : array(),
	);
}
