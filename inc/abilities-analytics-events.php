<?php
/**
 * Execute callbacks for signal-noise/get-analytics-events and
 * get-analytics-top-content, registered in inc/abilities-analytics.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute callback for signal-noise/get-analytics-events.
 * Resolves window from input, delegates to sn_analytics_top_events().
 *
 * @param array|null $input  Optional. { range?: int|string }.
 * @return array             Array of { name: string, events: int, visitors: int }.
 */
function sn_ability_get_analytics_events( $input ) {
	$input = is_array( $input ) ? $input : array();
	if ( isset( $input['range'] ) && function_exists( 'snt_analytics_range_is_valid' ) && ! snt_analytics_range_is_valid( $input['range'] ) ) {
		return new WP_Error( 'ability_invalid_input', 'range must be one of 7, 14, 30, 90, 365 or "all".', array( 'status' => 400 ) ); // #1239
	}
	$range = snt_analytics_resolve_range( $input['range'] ?? 30 );
	list( $from, $to ) = snt_analytics_range_dates( $range );
	return function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 100 ) : array();
}

/**
 * Ability execute callback: signal-noise/get-analytics-top-content.
 *
 * Clamps on this side as well as inside sn_analytics_top_paths(), so a hostile
 * or careless `days` cannot widen the query beyond the sensor's own range.
 *
 * @since 13.33.0
 * @param array<string,mixed>|null $input Ability input.
 * @return array<string,mixed>
 */
function snt_ability_get_analytics_top_content( $input ) {
	$input = is_array( $input ) ? $input : array();
	$days  = max( 1, min( 90, (int) ( $input['days'] ?? 7 ) ) );
	$limit = max( 1, min( 100, (int) ( $input['limit'] ?? 5 ) ) );
	$class = snt_analytics_resolve_class( $input['class'] ?? 'human' );
	$range = null;
	if ( isset( $input['range'] ) ) {
		if ( ! snt_analytics_range_is_valid( $input['range'] ) ) {
			return new WP_Error( 'snt_top_content_range', 'range must be one of 7, 14, 30, 90, 365 or all.', array( 'status' => 422 ) );
		}
		list( $range, $from, $to ) = snt_analytics_resolve_window( (string) $input['range'] );
	} else {
		// The site's own day, like the dashboard (was UTC until Unreleased).
		list( $from, $to ) = snt_analytics_range_dates( $days );
	}

	$gsc   = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	$pages = array();
	foreach ( (array) sn_analytics_top_paths( $from, $to, $class, $limit ) as $row ) {
		$path    = (string) ( $row['path'] ?? '' );
		$search  = null !== $gsc ? snt_gsc_metrics_for_path( $path ) : null;
		$pages[] = array(
			'path'        => $path,
			'views'       => (int) ( $row['views'] ?? 0 ),
			'visits'      => (int) ( $row['visits'] ?? 0 ),
			'scroll_avg'  => round( (float) ( $row['scroll_avg'] ?? 0 ), 2 ),
			'time_avg'    => round( (float) ( $row['time_avg'] ?? 0 ), 2 ),
			'impressions' => null === $search ? null : (int) $search['impressions'],
			'position'    => null === $search ? null : round( (float) $search['position'], 1 ),
		);
	}

	return array(
		'days'          => $days,
		'range'         => $range,
		'from'          => $from,
		'to'            => $to,
		'class'         => $class,
		'search_window' => null !== $gsc && isset( $gsc['window'] ) ? $gsc['window'] : null,
		'pages'         => $pages,
	);
}
