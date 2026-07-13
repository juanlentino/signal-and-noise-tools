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
 * Classify a referrer HOST into a source category. Delegates to the canonical
 * source mapper (inc/analytics-sources.php) so the brand vocabulary used by "Top
 * sources" and the Search/Social/Direct/Other category split can never drift:
 * host → canonical label → category. Self-referrals + empty/sentinel resolve to
 * '(direct)' → 'direct'; a known brand carries its category; an unknown host is
 * 'other'.
 *
 * @param string $host Referrer host (or '(direct)' / '(unknown)' sentinel).
 * @return string 'search' | 'social' | 'direct' | 'other'
 */
function sn_analytics_referrer_category( $host ) {
	if ( ! function_exists( 'sn_analytics_canonical_source' ) ) {
		return 'other';
	}
	return sn_analytics_source_category_of_label( sn_analytics_canonical_source( $host ) );
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

// Anomaly arc (v8.9.0) thresholds.
const SN_ANALYTICS_ANOMALY_MIN_VIEWS    = 20;     // ignore low-traffic noise
const SN_ANALYTICS_ANOMALY_SKIM_SCROLL  = 50;     // % scroll considered "deep"
const SN_ANALYTICS_ANOMALY_SKIM_TIME    = 5000;   // ms; under this = "fast leave"
const SN_ANALYTICS_ANOMALY_DWELL_TIME   = 30000;  // ms; over this = "long dwell"
const SN_ANALYTICS_ANOMALY_DWELL_SCROLL = 25;     // % scroll; under this = "stalled"
const SN_ANALYTICS_ANOMALY_Z            = 2.0;    // |z| cutoff for an outlier
const SN_ANALYTICS_BASELINE_WEEKS       = 6;      // trailing weeks for the narrator baseline

/**
 * Population mean + standard deviation of a numeric list.
 * Population (÷n) not sample (÷n-1): stable for the tiny n we feed it and
 * never NaN on n=1. Returns zeros for the empty case (callers guard on n/sd).
 *
 * @param array<int,float|int> $nums
 * @return array{n:int,mean:float,sd:float}
 */
function sn_analytics_stat_summary( array $nums ) {
	$n = count( $nums );
	if ( 0 === $n ) {
		return array( 'n' => 0, 'mean' => 0.0, 'sd' => 0.0 );
	}
	$mean = array_sum( $nums ) / $n;
	$var  = 0.0;
	foreach ( $nums as $x ) {
		$var += ( (float) $x - $mean ) ** 2;
	}
	return array( 'n' => $n, 'mean' => $mean, 'sd' => sqrt( $var / $n ) );
}

/**
 * Standard score. Returns 0.0 when sd<=0 (a flat series has no outliers),
 * so callers never divide by zero.
 *
 * @param float|int $x    The observation.
 * @param float     $mean Series mean.
 * @param float     $sd   Series standard deviation.
 * @return float
 */
function sn_analytics_zscore( $x, $mean, $sd ) {
	if ( $sd <= 0.0 ) {
		return 0.0;
	}
	return ( (float) $x - $mean ) / $sd;
}

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
 * @param string $from  Inclusive start day, YYYY-MM-DD.
 * @param string $to    Inclusive end day, YYYY-MM-DD.
 * @param string $class Traffic class (default 'human').
 * @param array{0:string,1:string}|null $cwin Explicit comparison window (v9.38.0 one-frame); null = the adjacent prior window.
 * @return array{current:?int, previous:?int, pct:?int, dir:string}
 */
function sn_analytics_engaged_rate_delta( $from, $to, $class = 'human', $cwin = null ) {
	$cur = sn_analytics_engaged_rate( $from, $to, $class );
	list( $pf, $pt ) = ( is_array( $cwin ) && '' !== (string) ( $cwin[0] ?? '' ) ) ? $cwin : sn_analytics_prior_window( $from, $to );
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
 * @param array{0:string,1:string}|null $cwin Explicit comparison window (v9.38.0 one-frame); null = the adjacent prior window.
 * @return array<string, array{current:int|float, previous:int|float, pct:?int, dir:string}>
 */
function sn_analytics_period_deltas( $from, $to, $class = 'human', $cwin = null ) {
	$cur = function_exists( 'sn_analytics_range_totals' )
		? sn_analytics_range_totals( $from, $to, $class )
		: array();

	list( $prior_from, $prior_to ) = ( is_array( $cwin ) && '' !== (string) ( $cwin[0] ?? '' ) ) ? $cwin : sn_analytics_prior_window( $from, $to );
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

/**
 * Cross-metric per-path engagement anomalies for a window. Two kinds:
 *  - divergence: pages whose scroll and dwell disagree (deep-scroll/fast-leave
 *    "skim", or long-dwell/low-scroll "stall").
 *  - outliers: pages >|Z| standard deviations from the per-metric mean across
 *    the window's paths (scroll_avg, time_avg), high or low.
 * Cookieless: reads only per-path daily aggregates. No identity, no cross-day.
 *
 * @param string $from  Y-m-d inclusive start.
 * @param string $to    Y-m-d inclusive end.
 * @param string $class Traffic class.
 * @return array{divergence:array<int,array>,outliers:array<int,array>}
 */
function sn_analytics_engagement_anomalies( $from, $to, $class = 'human' ) {
	if ( ! function_exists( 'sn_analytics_top_paths' ) ) {
		return array( 'divergence' => array(), 'outliers' => array() );
	}
	$rows = array_values(
		array_filter(
			sn_analytics_top_paths( $from, $to, $class, 100 ),
			static function ( $r ) {
				return (int) ( $r['views'] ?? 0 ) >= SN_ANALYTICS_ANOMALY_MIN_VIEWS;
			}
		)
	);

	$divergence = array();
	foreach ( $rows as $r ) {
		$scroll = (float) ( $r['scroll_avg'] ?? 0 );
		$time   = (float) ( $r['time_avg'] ?? 0 );
		$type   = '';
		if ( $scroll >= SN_ANALYTICS_ANOMALY_SKIM_SCROLL && $time < SN_ANALYTICS_ANOMALY_SKIM_TIME ) {
			$type = 'skim';
		} elseif ( $time >= SN_ANALYTICS_ANOMALY_DWELL_TIME && $scroll < SN_ANALYTICS_ANOMALY_DWELL_SCROLL ) {
			$type = 'stall';
		}
		if ( '' !== $type ) {
			$divergence[] = array(
				'path'        => (string) $r['path'],
				'type'        => $type,
				'scroll_avg'  => round( $scroll, 1 ),
				'time_avg_ms' => (int) round( $time ),
				'views'       => (int) $r['views'],
			);
		}
	}

	$outliers = array();
	foreach ( array( 'scroll_avg', 'time_avg' ) as $metric ) {
		$vals = array_map(
			static function ( $r ) use ( $metric ) {
				return (float) ( $r[ $metric ] ?? 0 );
			},
			$rows
		);
		$stat = sn_analytics_stat_summary( $vals );
		if ( $stat['n'] < 4 || $stat['sd'] <= 0.0 ) {
			continue;
		}
		foreach ( $rows as $r ) {
			$z = sn_analytics_zscore( (float) ( $r[ $metric ] ?? 0 ), $stat['mean'], $stat['sd'] );
			if ( abs( $z ) >= SN_ANALYTICS_ANOMALY_Z ) {
				$outliers[] = array(
					'path'   => (string) $r['path'],
					'metric' => $metric,
					'value'  => round( (float) $r[ $metric ], 1 ),
					'mean'   => round( $stat['mean'], 1 ),
					'z'      => round( $z, 2 ),
					'dir'    => $z > 0 ? 'high' : 'low',
					'views'  => (int) $r['views'],
				);
			}
		}
	}

	return array( 'divergence' => $divergence, 'outliers' => $outliers );
}

/**
 * Flag this week's totals that sit >|Z| sd from their trailing-N-week mean.
 * Gives the narrator "typical range" context (e.g. views 1,500 vs typical
 * 990-1,010). Aggregate + within-week only — no identity, no cross-day.
 *
 * @param string $from  Y-m-d inclusive start of the current window.
 * @param string $to    Y-m-d inclusive end of the current window.
 * @param string $class Traffic class.
 * @param int    $weeks Trailing weeks to baseline against.
 * @return array<int,array{metric:string,current:float|int,typical_low:float,typical_high:float,z:float,dir:string}>
 */
function sn_analytics_baseline_movers( $from, $to, $class = 'human', $weeks = SN_ANALYTICS_BASELINE_WEEKS ) {
	if ( ! function_exists( 'sn_analytics_range_totals' ) || ! function_exists( 'sn_analytics_prior_window' ) ) {
		return array();
	}
	$current = sn_analytics_range_totals( $from, $to, $class );
	$metrics = array( 'views', 'visits', 'scroll_avg', 'time_avg' );
	$series  = array_fill_keys( $metrics, array() );

	$wf = $from;
	$wt = $to;
	for ( $i = 0; $i < (int) $weeks; $i++ ) {
		list( $wf, $wt ) = sn_analytics_prior_window( $wf, $wt );
		$t = sn_analytics_range_totals( $wf, $wt, $class );
		foreach ( $metrics as $m ) {
			$series[ $m ][] = (float) ( $t[ $m ] ?? 0 );
		}
	}

	$flags = array();
	foreach ( $metrics as $m ) {
		$stat = sn_analytics_stat_summary( $series[ $m ] );
		if ( $stat['n'] < 3 || $stat['sd'] <= 0.0 ) {
			continue;
		}
		$cur = (float) ( $current[ $m ] ?? 0 );
		$z   = sn_analytics_zscore( $cur, $stat['mean'], $stat['sd'] );
		if ( abs( $z ) < SN_ANALYTICS_ANOMALY_Z ) {
			continue;
		}
		$is_rate = in_array( $m, array( 'scroll_avg', 'time_avg' ), true );
		$flags[] = array(
			'metric'       => $m,
			'current'      => $is_rate ? round( $cur, 1 ) : (int) round( $cur ),
			'typical_low'  => round( $stat['mean'] - $stat['sd'], 1 ),
			'typical_high' => round( $stat['mean'] + $stat['sd'], 1 ),
			'z'            => round( $z, 2 ),
			'dir'          => $z > 0 ? 'above' : 'below',
		);
	}
	return $flags;
}
