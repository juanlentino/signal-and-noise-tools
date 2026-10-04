<?php
/**
 * Signal & Noise Tools: SN Reading, what readers do once here. Scroll depth,
 * time per view, visits, custom events and the field Core Web Vitals. Every
 * figure is read from a rollup another view already fills.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-the-page rows. PURE. The beacon fires one scroll event per milestone a
 * view reaches (25, 50, 75, 100), each at most once, so the events are
 * cumulative, not exclusive bands: the share of views that reached half the
 * page is the count of 50% milestones over PAGE VIEWS, never over events.
 *
 * @param array|null $totals sn_analytics_range_totals(): views, scroll_avg_per_view, time_avg_per_view (ms).
 * @param array|null $engaged {rate, pts?}: the engaged share of views and its change in points; null when not read.
 * @param array      $dist   The scroll distribution, [{label, views}]: [0,25), [25,50), [50,75), 75+.
 * @return array<int,array{label:string,value:string}>
 */
function snt_desktop_reading_page_rows( $totals, array $dist, $engaged = null ) {
	$views = is_array( $totals ) ? (int) ( $totals['views'] ?? 0 ) : 0;
	$rows  = array();
	// Before the page-view test: a window can hold visitor-days with no page
	// view (a feed reader), and SN Site Views no longer paints this figure.
	if ( is_array( $totals ) && (int) ( $totals['visits'] ?? 0 ) > 0 ) {
		$rows[] = array( 'label' => 'Visitor-days', 'value' => number_format_i18n( (int) $totals['visits'] ) ); // the figure SN Site Views called Visits.
	}
	if ( $views < 1 ) {
		return $rows;
	}
	if ( is_array( $engaged ) && isset( $engaged['rate'] ) ) {
		// As SN Site Views painted it: an arrow and the change in percentage
		// POINTS, colored only when the change is 5 points or more.
		$pts = isset( $engaged['pts'] ) ? (int) $engaged['pts'] : 0;
		$row = array( 'label' => 'Engaged', 'value' => (int) $engaged['rate'] . '%' . ( 0 !== $pts ? ' ' . ( $pts > 0 ? '▲' : '▼' ) . ' ' . abs( $pts ) . ' pts' : '' ) );
		if ( abs( $pts ) >= 5 ) {
			$row['tone'] = $pts > 0 ? 'up' : 'down';
		}
		$rows[] = $row;
	}
	$half = (int) ( array_values( $dist )[2]['views'] ?? 0 ); // [50,75) holds exactly the 50% milestone.
	if ( 4 === count( $dist ) && $half <= $views ) {
		$rows[] = array( 'label' => 'Views that reached half the page', 'value' => snt_desktop_pct( $half, $views ) );
	}
	if ( isset( $totals['scroll_avg_per_view'] ) ) {
		$rows[] = array( 'label' => 'Average depth reached', 'value' => round( (float) $totals['scroll_avg_per_view'] ) . '%' );
	}
	if ( isset( $totals['time_avg_per_view'] ) ) {
		$rows[] = array( 'label' => 'Average time per view', 'value' => snt_desktop_reading_seconds( (float) $totals['time_avg_per_view'] / 1000 ) ); // the beacon reports milliseconds.
	}
	return $rows;
}

/**
 * Visit rows: a visits-weighted fold of the daily session rollup. PURE.
 *
 * @param array|null $days [{visits, bounce_pct, ppv, median_dur}]; null when the read failed.
 * @return array<int,array{label:string,value:string}>|null Null when the read failed.
 */
function snt_desktop_reading_visit_rows( $days ) {
	$n = 0; $bounce = 0.0; $ppv = 0.0; $dur = 0.0;
	foreach ( (array) $days as $d ) {
		$w       = (int) ( $d['visits'] ?? 0 );
		$n      += $w;
		$bounce += $w * (float) ( $d['bounce_pct'] ?? 0 );
		$ppv    += $w * (float) ( $d['ppv'] ?? 0 );
		$dur    += $w * (float) ( $d['median_dur'] ?? 0 );
	}
	if ( null === $days ) {
		return null; // the read failed: not the same thing as no visits.
	}
	if ( $n < 1 ) {
		return array();
	}
	return array(
		array( 'label' => 'Sessions', 'value' => number_format_i18n( $n ) ),
		array( 'label' => 'One page only', 'value' => round( $bounce / $n ) . '%' ),
		array( 'label' => 'Pages per session', 'value' => number_format_i18n( $ppv / $n, 2 ) ),
		array( 'label' => 'Typical session', 'value' => snt_desktop_reading_seconds( $dur / $n ) ),
	);
}

/**
 * Seconds as "42s" or "3m 05s". PURE.
 *
 * @param int|float $s Seconds.
 * @return string
 */
function snt_desktop_reading_seconds( $s ) {
	$s = (int) round( $s );
	return $s < 60 ? $s . 's' : intdiv( $s, 60 ) . 'm ' . str_pad( (string) ( $s % 60 ), 2, '0', STR_PAD_LEFT ) . 's';
}

/**
 * One Core Web Vital: its 75th percentile (the figure Google assesses a page
 * on) when it could be read, then the good and poor shares. PURE.
 *
 * @param string     $name LCP | INP | CLS.
 * @param array      $dist [{label, views}] in band order: good, needs work, poor.
 * @param array|null $pct  sn_analytics_percentiles() rows; null when not read.
 * @return array{label:string,value:string}|null Null when nothing was measured.
 */
function snt_desktop_reading_vital_row( $name, array $dist, $pct = null ) {
	$v     = array_map( static fn( $r ) => (int) ( $r['views'] ?? 0 ), array_values( $dist ) );
	$total = array_sum( $v );
	if ( 3 !== count( $v ) || $total < 1 ) {
		return null;
	}
	$p75 = '';
	foreach ( (array) $pct as $p ) {
		if ( 'p75' === ( $p['label'] ?? '' ) ) {
			$x   = (float) $p['value'];
			$p75 = 'CLS' === $name ? number_format_i18n( $x / 1000, 2 ) : ( 'LCP' === $name ? number_format_i18n( $x / 1000, 1 ) . 's' : number_format_i18n( $x ) . 'ms' );
		}
	}
	return array( 'label' => $name . ( '' !== $p75 ? ' · p75 ' . $p75 : '' ), 'value' => snt_desktop_pct( $v[0], $total ) . ' good · ' . snt_desktop_pct( $v[2], $total ) . ' poor' );
}

/**
 * The groups, read live.
 *
 * @param array{from:string,to:string,days:int} $win Window.
 * @return array<int,array<string,mixed>>
 */
function snt_desktop_reading_groups( array $win ) {
	$dist   = static fn( $m ) => function_exists( 'sn_analytics_distribution' ) ? (array) sn_analytics_distribution( $m, $win['from'], $win['to'], 'human' ) : array();
	$totals = function_exists( 'sn_analytics_range_totals' ) ? sn_analytics_range_totals( $win['from'], $win['to'], 'human' ) : null;
	// The engaged share and its change against the prior window: the row SN Site Views used to carry.
	$engaged = null;
	$rate    = function_exists( 'sn_analytics_engaged_rate' ) ? sn_analytics_engaged_rate( $win['from'], $win['to'], 'human' ) : null;
	if ( null !== $rate ) {
		$engaged = array( 'rate' => (int) $rate );
		$d       = function_exists( 'sn_analytics_engaged_rate_delta' ) ? sn_analytics_engaged_rate_delta( $win['from'], $win['to'], 'human' ) : null;
		if ( is_array( $d ) && isset( $d['current'], $d['previous'] ) && is_numeric( $d['current'] ) && is_numeric( $d['previous'] ) ) {
			$engaged['pts'] = (int) $d['current'] - (int) $d['previous'];
		}
	}
	$events = array();
	foreach ( function_exists( 'sn_analytics_top_events' ) ? (array) sn_analytics_top_events( $win['from'], $win['to'], 4 ) : array() as $e ) {
		$events[] = array( 'label' => (string) $e['name'], 'value' => number_format_i18n( (int) $e['events'] ) . ' · ' . number_format_i18n( (int) $e['visitors'] ) . ' visitor-days' ); // the rollup counts distinct visitors per day and the visitor hash rotates daily, so the sum over a window is visitor-days, not people.
	}
	// The percentile is one Analytics Engine request per vital (cached 15
	// minutes, a failure 5). After the first one that cannot be read the rest
	// are not asked, so a slow API costs this payload one timeout, not three.
	$vitals = array();
	$ask    = function_exists( 'sn_analytics_percentiles' );
	foreach ( array( 'LCP' => 'lcp', 'INP' => 'inp', 'CLS' => 'cls' ) as $name => $metric ) {
		$d = $dist( $metric );
		if ( null === snt_desktop_reading_vital_row( $name, $d ) ) {
			continue; // nothing measured: no request either.
		}
		$pct = $ask ? sn_analytics_percentiles( $metric, $win['from'], $win['to'], 'human' ) : null;
		$ask = $ask && null !== $pct;
		$vitals[] = snt_desktop_reading_vital_row( $name, $d, $pct );
	}
	$visits = snt_desktop_reading_visit_rows( function_exists( 'sn_session_rollup_read' ) ? sn_session_rollup_read( $win['from'], $win['to'], 'human' ) : null );
	return array(
		snt_desktop_group( 'On the page', snt_desktop_reading_page_rows( $totals, $dist( 'scroll' ), $engaged ), 'No page views in this window, or the daily totals could not be read.' ), // a failed read and an empty window share one shape (views 0); the sentence claims neither.
		snt_desktop_group( 'Sessions', (array) $visits, null === $visits ? 'The sessions could not be read.' : 'No sessions rolled up in this window.' ), // sessions, not Site Views' visitor-days: the heading keeps the two apart.
		snt_desktop_group( 'Custom events · all traffic', $events, // the events rollup has no traffic class; unlike the rows above, this is not people only.
			 'No custom events in this window.' ),
		snt_desktop_group( 'Core Web Vitals', $vitals, 'No field measurements in this window.' ),
	);
}
