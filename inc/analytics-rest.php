<?php
/**
 * Read-only REST surface for analytics — the same durable data the dashboard
 * renders, for programmatic/AI consumers. manage_options-gated; never mutates.
 *
 * Routes (all GET, all under signal-noise/v1):
 *   GET /analytics/summary               — range totals (views, visits, scroll_avg, time_avg)
 *   GET /analytics/series                — daily/weekly time series
 *   GET /analytics/dimension/<dim>       — top-N for a dimension (page, referrer, country, device…)
 *   GET /analytics/distribution/<metric> — bucket distribution (scroll, time)
 *
 * Auth: every route's permission_callback is sn_analytics_rest_can_read()
 * which gates on current_user_can('manage_options'). Cookie auth + Application
 * Passwords both flow through current_user_can() correctly. Never __return_true
 * — these routes expose personal-site analytics.
 *
 * Query params shared by all four routes:
 *   range  int|string  7 | 30 | 90 | 365 | "all"  (default 30)
 *   class  string      human | suspect | bot       (default human)
 *
 * @package SignalAndNoiseTools
 * @since   6.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET permission: authenticated admin only.
 *
 * @return true|WP_Error
 */
function sn_analytics_rest_can_read() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'sn_rest_forbidden',
			'You do not have permission to read analytics.',
			array( 'status' => rest_authorization_required_code() )
		);
	}
	return true;
}

add_action( 'rest_api_init', function () {
	$ns = defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1';

	register_rest_route( $ns, '/analytics/summary', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_analytics_rest_can_read',
		'callback'            => 'sn_analytics_rest_summary',
	) );

	register_rest_route( $ns, '/analytics/series', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_analytics_rest_can_read',
		'callback'            => 'sn_analytics_rest_series',
	) );

	register_rest_route( $ns, '/analytics/dimension/(?P<dim>[a-z]+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_analytics_rest_can_read',
		'callback'            => 'sn_analytics_rest_dimension',
	) );

	register_rest_route( $ns, '/analytics/distribution/(?P<metric>[a-z]+)', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_analytics_rest_can_read',
		'callback'            => 'sn_analytics_rest_distribution',
	) );

	register_rest_route( $ns, '/analytics/events', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_analytics_rest_can_read',
		'callback'            => 'sn_analytics_rest_events',
	) );

	register_rest_route( $ns, '/analytics/event-props', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => 'sn_analytics_rest_can_read',
		'callback'            => 'sn_analytics_rest_event_props',
	) );
} );

// ── Shared window resolver ────────────────────────────────────────────────────

/**
 * Resolve range, class, and date window from a request object.
 * Mirrors the logic the dashboard uses so REST and UI see the same data.
 *
 * @param WP_REST_Request $request
 * @return array{ 0: string, 1: string, 2: string, 3: int|string }  [ $from, $to, $class, $range ]
 */
function sn_analytics_rest_window( $request ) {
	$range = snt_analytics_resolve_range( $request->get_param( 'range' ) ?? 30 );
	$class = snt_analytics_resolve_class( $request->get_param( 'class' ) );
	list( $from, $to ) = snt_analytics_range_dates( $range );
	return array( $from, $to, $class, $range );
}

// ── Route callbacks ───────────────────────────────────────────────────────────

/**
 * GET /analytics/summary
 * Returns views, visits, scroll_avg, time_avg for the requested window.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function sn_analytics_rest_summary( $request ) {
	snt_rest_deprecated_notice( '/signal-noise/v1/analytics/summary', 'signal-noise/get-analytics-summary' );
	list( $from, $to, $class ) = sn_analytics_rest_window( $request );
	return sn_analytics_range_totals( $from, $to, $class );
}

/**
 * GET /analytics/series
 * Returns a daily (or weekly for >90 d windows) time series.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function sn_analytics_rest_series( $request ) {
	list( $from, $to, $class, $range ) = sn_analytics_rest_window( $request );
	$days = ( 'all' === $range )
		? ( (int) floor( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1 )
		: (int) $range;
	$gran = sn_analytics_granularity( $days );
	return sn_analytics_daily_series( $from, $to, $class, $gran );
}

/**
 * GET /analytics/dimension/<dim>
 * Returns the top-25 entries for the requested dimension.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function sn_analytics_rest_dimension( $request ) {
	list( $from, $to, $class ) = sn_analytics_rest_window( $request );
	return sn_analytics_top_dimension( (string) $request->get_param( 'dim' ), $from, $to, $class, 25 );
}

/**
 * GET /analytics/distribution/<metric>
 * Returns the bucket distribution for the requested metric (scroll, time).
 *
 * @param WP_REST_Request $request
 * @return array
 */
function sn_analytics_rest_distribution( $request ) {
	list( $from, $to, $class ) = sn_analytics_rest_window( $request );
	return sn_analytics_distribution( (string) $request->get_param( 'metric' ), $from, $to, $class );
}

/**
 * GET /analytics/events
 * Returns the top custom events (name → events/visitors) for the requested window.
 * Events have no class dimension; $from/$to are the only filters applied.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function sn_analytics_rest_events( $request ) {
	snt_rest_deprecated_notice( '/signal-noise/v1/analytics/events', 'signal-noise/get-analytics-events' );
	list( $from, $to ) = sn_analytics_rest_window( $request );
	return function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 100 ) : array();
}

/**
 * GET /analytics/event-props
 * Returns the top property→value breakdown for a custom event property.
 * Optional ?property= param filters to a specific property name.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function sn_analytics_rest_event_props( $request ) {
	list( $from, $to ) = sn_analytics_rest_window( $request );
	$property = (string) $request->get_param( 'property' );
	return function_exists( 'sn_analytics_top_event_props' ) ? sn_analytics_top_event_props( $from, $to, $property, 200 ) : array();
}
