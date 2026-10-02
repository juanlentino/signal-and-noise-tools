<?php
/**
 * Signal & Noise Tools: the sn-metrics analytics sections (sources, series,
 * geography, devices, journeys) and the helpers analytics_query shares.
 *
 * Every builder reads the same functions the dashboard calls, over a window
 * resolved on the site's own day (the WordPress timezone), so a section and
 * the Analytics leaf agree. A failed read returns WP_Error, which sn-metrics'
 * dispatch turns into that one section's {error:"unavailable"}.
 *
 * The floor: a row under SNT_METRICS_FLOOR visits is folded into `withheld`
 * (rows, views, visits) instead of being listed, so a country or a source
 * with one or two visits cannot single anyone out, and rows plus withheld
 * still add up to the window's total. Series is site-wide per day and
 * top_content lists published pages, so neither is floored.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rows with fewer visits than this are withheld, never listed (owner, 2026-10-02). */
const SNT_METRICS_FLOOR = 3;

/** Widest read a section folds before it slices; the read functions cap here too. */
const SNT_METRICS_WIDE = 500;

/**
 * The window, on the site's own day. Refuses a range outside the vocabulary.
 *
 * @param array $input Ability input.
 * @return array{range:int|string,from:string,to:string}|WP_Error
 */
function snt_metrics_window( array $input ) {
	$raw = $input['range'] ?? 30;
	if ( ! snt_analytics_range_is_valid( $raw ) ) {
		return new WP_Error( 'snt_metrics_range', 'range must be one of 7, 14, 30, 90, 365 or all.', array( 'status' => 422 ) );
	}
	list( $range, $from, $to ) = snt_analytics_resolve_window( (string) $raw );
	return array( 'range' => $range, 'from' => $from, 'to' => $to );
}

/**
 * The traffic class; anything outside human|suspect|bot reads as human.
 *
 * @param array $input Ability input.
 * @return string
 */
function snt_metrics_class( array $input ) {
	return snt_analytics_resolve_class( $input['class'] ?? 'human' );
}

/**
 * Fold rows under the floor into one withheld total, then rank and slice.
 *
 * @param array $rows  Rows carrying views and visits.
 * @param int   $limit Rows to list.
 * @return array{rows:array,withheld:array{rows:int,views:int,visits:int}}
 */
function snt_metrics_floor( array $rows, $limit ) {
	$kept     = array();
	$withheld = array( 'rows' => 0, 'views' => 0, 'visits' => 0 );
	foreach ( $rows as $r ) {
		if ( (int) $r['visits'] < SNT_METRICS_FLOOR ) {
			$withheld['rows']++;
			$withheld['views']  += (int) $r['views'];
			$withheld['visits'] += (int) $r['visits'];
			continue;
		}
		$kept[] = $r;
	}
	usort( $kept, static fn( $a, $b ) => (int) $b['views'] <=> (int) $a['views'] );
	return array( 'rows' => array_slice( $kept, 0, max( 1, (int) $limit ) ), 'withheld' => $withheld );
}

/**
 * The envelope every floored section shares.
 *
 * @param array      $win    snt_metrics_window().
 * @param string     $class  Traffic class reported.
 * @param array|null $rows   Rows, or null when the read failed.
 * @param int        $limit  Rows to list.
 * @return array|WP_Error
 */
function snt_metrics_floored_payload( array $win, $class, $rows, $limit ) {
	if ( null === $rows ) {
		return new WP_Error( 'snt_metrics_read', 'The analytics read failed.' );
	}
	$f = snt_metrics_floor( (array) $rows, $limit );
	return $win + array( 'class' => $class, 'floor' => SNT_METRICS_FLOOR, 'rows' => $f['rows'], 'withheld' => $f['withheld'] );
}

/**
 * Shared prologue: window, class and limit, or the refusal.
 *
 * @param mixed $input Ability input.
 * @return array{0:array,1:string,2:int}|WP_Error
 */
function snt_metrics_args( $input ) {
	$input = is_array( $input ) ? $input : array();
	$win   = snt_metrics_window( $input );
	if ( is_wp_error( $win ) ) {
		return $win;
	}
	return array( $win, snt_metrics_class( $input ), max( 1, min( SNT_METRICS_WIDE, (int) ( $input['limit'] ?? 25 ) ) ) );
}

/** Sources: label, category, views, visits. */
function snt_ability_get_analytics_sources( $input ) {
	$a = snt_metrics_args( $input );
	if ( is_wp_error( $a ) ) {
		return $a;
	}
	list( $win, $class, $limit ) = $a;
	$raw  = sn_analytics_top_sources( $win['from'], $win['to'], $class, SNT_METRICS_WIDE );
	$rows = null === $raw ? null : array_map(
		static fn( $r ) => array( 'value' => (string) $r['value'], 'category' => sn_analytics_source_category_of_label( (string) $r['value'] ), 'views' => (int) $r['views'], 'visits' => (int) $r['visits'] ),
		(array) $raw
	);
	return snt_metrics_floored_payload( $win, $class, $rows, $limit );
}

/** Geography (country) and devices: one dims column each. */
function snt_metrics_dimension_section( $dim, $input ) {
	$a = snt_metrics_args( $input );
	if ( is_wp_error( $a ) ) {
		return $a;
	}
	list( $win, $class, $limit ) = $a;
	return snt_metrics_floored_payload( $win, $class, sn_analytics_top_dimension( $dim, $win['from'], $win['to'], $class, SNT_METRICS_WIDE ), $limit );
}
function snt_ability_get_analytics_geography( $input ) { return snt_metrics_dimension_section( 'country', $input ); }
function snt_ability_get_analytics_devices( $input ) { return snt_metrics_dimension_section( 'device', $input ); }

/** Series: site-wide views and visits per day. Not floored. */
function snt_ability_get_analytics_series( $input ) {
	$a = snt_metrics_args( $input );
	if ( is_wp_error( $a ) ) {
		return $a;
	}
	list( $win, $class ) = $a;
	return $win + array( 'class' => $class, 'days' => sn_analytics_daily_series( $win['from'], $win['to'], $class, 'day' ) );
}

/** Journeys: entry and exit pages. Recorded for human traffic only, so class is not applied. */
function snt_ability_get_analytics_journeys( $input ) {
	$a = snt_metrics_args( $input );
	if ( is_wp_error( $a ) ) {
		return $a;
	}
	list( $win, , $limit ) = $a;
	$entry = snt_metrics_floored_payload( $win, 'human', sn_analytics_top_entry_pages( $win['from'], $win['to'], SNT_METRICS_WIDE ), $limit );
	$exit  = snt_metrics_floored_payload( $win, 'human', sn_analytics_top_exit_pages( $win['from'], $win['to'], SNT_METRICS_WIDE ), $limit );
	if ( is_wp_error( $entry ) || is_wp_error( $exit ) ) {
		return new WP_Error( 'snt_metrics_read', 'The journeys read failed.' );
	}
	return $win + array( 'class_applied' => 'human', 'note' => 'Entry and exit pages are recorded for human traffic only; class is not applied.', 'floor' => SNT_METRICS_FLOOR, 'entry' => array( 'rows' => $entry['rows'], 'withheld' => $entry['withheld'] ), 'exit' => array( 'rows' => $exit['rows'], 'withheld' => $exit['withheld'] ) );
}
