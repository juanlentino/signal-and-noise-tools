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
 * @param array|null $days [{visits, bounce_pct, ppv, median_dur, two_pages?, deep_pages?}]; null when the read failed.
 * @return array<int,array{label:string,value:string}>|null Null when the read failed.
 */
function snt_desktop_reading_visit_rows( $days ) {
	$n = 0; $bounce = 0.0; $ppv = 0.0; $dur = 0.0;
	$known = 0; $two = 0; $deep = 0; // the depth split, over the days that measured it.
	foreach ( (array) $days as $d ) {
		$w       = (int) ( $d['visits'] ?? 0 );
		if ( isset( $d['two_pages'], $d['deep_pages'] ) ) {
			$known += $w;
			$two   += (int) $d['two_pages'];
			$deep  += (int) $d['deep_pages'];
		}
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
	$rows = array(
		array( 'label' => 'Sessions', 'value' => number_format_i18n( $n ) ),
		// With the depth split measured, all three shares come from the same
		// sessions (the days that measured it), so they add up; before any day
		// measured it, the one-page share is the rollup's own.
		// `split` (with the depth split measured): one page, two, three or more,
		// as numbers for the bar under the row.
		array( 'label' => 'One page only', 'value' => $known > 0 ? snt_desktop_pct( max( 0, $known - $two - $deep ), $known ) : round( $bounce / $n ) . '%' ) + ( $known > 0 ? array( 'split' => array( round( 100 * max( 0, $known - $two - $deep ) / $known, 1 ), round( 100 * $two / $known, 1 ), round( 100 * $deep / $known, 1 ) ) ) : array() ),
		array( 'label' => 'Pages per session', 'value' => number_format_i18n( $ppv / $n, 2 ) ),
		array( 'label' => 'Typical session', 'value' => snt_desktop_reading_seconds( $dur / $n ) ),
	);
	// Days rolled up before the split existed do not count toward it: a share
	// of the days that measured it, never zeros for the ones that did not.
	if ( $known > 0 ) {
		// One row for the rest of the split, so the card keeps its height.
		array_splice( $rows, 2, 0, array(
			array( 'label' => 'Two pages · three or more', 'value' => snt_desktop_pct( $two, $known ) . ' · ' . snt_desktop_pct( $deep, $known ) ),
		) );
	}
	return $rows;
}

/**
 * Seconds as "42s" or "3m 05s". PURE.
 *
 * @param int|float $s Seconds.
 * @return string
 */
/**
 * "1 event", "3 events": a count with its unit, singular when it is one. PURE.
 *
 * @param int    $n    The count.
 * @param string $one  Singular unit.
 * @param string $many Plural unit.
 * @return string
 */
function snt_desktop_reading_count( $n, $one, $many ) {
	return number_format_i18n( (int) $n ) . ' ' . ( 1 === (int) $n ? $one : $many );
}

/**
 * The tile's opening figure: the engaged share and its change. PURE. The same
 * threshold as the row it replaces: under 5 points the change is shown plain.
 *
 * @param array|null $engaged { rate, pts? } or null when the rate is unknown.
 * @return array|null { value, label, change?, tone? }
 */
function snt_desktop_reading_hero( $engaged ) {
	if ( ! is_array( $engaged ) || ! isset( $engaged['rate'] ) ) {
		return null;
	}
	$hero = array( 'value' => (int) $engaged['rate'] . '%', 'label' => 'of views engaged' );
	$pts  = isset( $engaged['pts'] ) ? (int) $engaged['pts'] : 0;
	if ( 0 !== $pts ) {
		$hero['change'] = ( $pts > 0 ? '▲ ' : '▼ ' ) . abs( $pts ) . ' pts vs. prior 14 days';
		if ( abs( $pts ) >= 5 ) {
			$hero['tone'] = $pts > 0 ? 'up' : 'down';
		}
	}
	return $hero;
}

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
 * @return array{label:string,value:string,split:array<int,float>,quality:bool}|null Null when nothing was measured.
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
	// `split`: good, needs work, poor as numbers; `quality` colors the bar by band.
	return array(
		'label'   => $name . ( '' !== $p75 ? ' · p75 ' . $p75 : '' ),
		'value'   => snt_desktop_pct( $v[0], $total ) . ' good · ' . snt_desktop_pct( $v[2], $total ) . ' poor',
		'split'   => array_map( static fn( $x ) => round( 100 * $x / $total, 1 ), $v ),
		'quality' => true,
	);
}

/**
 * The groups, read live.
 *
 * @param array{from:string,to:string,days:int} $win Window.
 * @return array<int,array<string,mixed>>
 */
function snt_desktop_reading_groups( array $win ) {
	// The two shared readers below answer a FAILED query with zeros or an empty
	// list (their contract, relied on by every Analytics view). Here that would
	// paint a broken table as "0%" or "none", so each call is followed by a look
	// at the database's own error.
	$failed = array();
	$dist   = static function ( $m ) use ( $win, &$failed ) {
		$rows = function_exists( 'sn_analytics_distribution' ) ? (array) sn_analytics_distribution( $m, $win['from'], $win['to'], 'human' ) : array();
		if ( snt_desktop_db_failed() ) {
			$failed[ $m ] = true;
			return array();
		}
		return $rows;
	};
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
	$top    = function_exists( 'sn_analytics_top_events' ) ? (array) sn_analytics_top_events( $win['from'], $win['to'], 4 ) : array();
	if ( snt_desktop_db_failed() ) {
		$failed['events'] = true;
		$top              = array();
	}
	foreach ( $top as $e ) {
		$events[] = array( 'label' => (string) $e['name'], 'value' => snt_desktop_reading_count( (int) $e['events'], 'event', 'events' ) . ' · ' . snt_desktop_reading_count( (int) $e['visitors'], 'visitor-day', 'visitor-days' ) ); // the rollup counts distinct visitors per day and the visitor hash rotates daily, so the sum over a window is visitor-days, not people.
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
	// The engaged share opens the tile and the visitor-days open SN Audience,
	// so neither is repeated as a row here.
	$page = array_values( array_filter( snt_desktop_reading_page_rows( $totals, $dist( 'scroll' ), $engaged ), static fn( $r ) => ! in_array( $r['label'], array( 'Engaged', 'Visitor-days' ), true ) ) );
	$hero = is_array( $totals ) && (int) ( $totals['views'] ?? 0 ) > 0 ? snt_desktop_reading_hero( $engaged ) : null;
	return ( $hero ? array( 'hero' => $hero ) : array() ) + array(
		snt_desktop_group( 'On the page', $page, 'No page views in this window, or the daily totals could not be read.' ), // a failed read and an empty window share one shape (views 0); the sentence claims neither.
		snt_desktop_group( 'Sessions', (array) $visits, null === $visits ? 'The sessions could not be read.' : 'No sessions rolled up in this window.' ), // sessions, not Site Views' visitor-days: the heading keeps the two apart.
		snt_desktop_group( 'Custom events · all traffic', $events, // the events rollup has no traffic class; unlike the rows above, this is not people only.
			isset( $failed['events'] ) ? 'The custom events could not be read.' : 'No custom events in this window.' ),
		snt_desktop_group( 'Core Web Vitals', $vitals, array_intersect_key( $failed, array( 'lcp' => 1, 'inp' => 1, 'cls' => 1 ) ) ? 'The field measurements could not be read.' : 'No field measurements in this window.' ),
	);
}
