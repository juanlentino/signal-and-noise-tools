<?php
/**
 * Signal & Noise Tools: the SN Audience and SN Reading desktop widgets, the
 * shared parts. Each payload is a list of groups, each group a list of rows
 * (label, value); the script paints what it is handed and knows no metric.
 * A group whose reader failed, or has nothing, carries `empty` instead of
 * rows: not measured is never painted as zero.
 *
 * Both follow SN Site Views: fetch on render, a 15-minute transient stamped
 * with the site's day, the same 14-day window.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One group. PURE.
 *
 * @param string                                       $title Heading.
 * @param array<int,array{label:string,value:string}>  $rows  Rows; empty means nothing to show.
 * @param string                                       $empty What to say when there are no rows.
 * @return array<string,mixed>
 */
function snt_desktop_group( $title, array $rows, $empty ) {
	return array() === $rows ? array( 'title' => (string) $title, 'rows' => array(), 'empty' => (string) $empty ) : array( 'title' => (string) $title, 'rows' => array_values( $rows ) );
}

/**
 * Whether the database reported an error on the read just made. The shared
 * analytics readers fold a failed query into an empty answer; a tile that
 * must not paint that as zero asks here, straight after the call.
 *
 * @return bool
 */
function snt_desktop_db_failed() {
	return isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && '' !== (string) ( $GLOBALS['wpdb']->last_error ?? '' );
}

/**
 * A share as a whole percent, '' when the whole is zero. PURE.
 *
 * @param int|float $part  Part.
 * @param int|float $whole Whole.
 * @return string
 */
function snt_desktop_pct( $part, $whole ) {
	return $whole > 0 ? round( 100 * $part / $whole ) . '%' : '';
}

/**
 * The widgets' window: the site's last 14 days, today included.
 *
 * @return array{from:string,to:string,days:int}
 */
function snt_desktop_widget_window() {
	$to = substr( (string) current_time( 'mysql' ), 0, 10 );
	return array( 'from' => gmdate( 'Y-m-d', (int) strtotime( $to . ' -13 days' ) ), 'to' => $to, 'days' => 14 );
}

/**
 * Serve a payload from its transient, building it when cold.
 *
 * @param string   $name  'audience' | 'reading'.
 * @param callable $build Takes the window, returns the groups.
 * @return WP_REST_Response
 */
function snt_desktop_widget_response( $name, callable $build ) {
	$win    = snt_desktop_widget_window();
	$key    = 'sn_desktop_' . $name . '_' . $win['to'];
	$cached = get_transient( $key );
	if ( ! is_array( $cached ) ) {
		$cached = array( 'window' => $win, 'groups' => array_values( (array) call_user_func( $build, $win ) ) );
		set_transient( $key, $cached, 15 * MINUTE_IN_SECONDS );
	}
	return new WP_REST_Response( $cached, 200 );
}

add_action( 'rest_api_init', function () {
	// Two literal registrations: tests/rest-routes.php reads each route and
	// its gate from source.
	register_rest_route( 'signal-noise/v1', '/desktop/audience', array(
		'methods'             => 'GET',
		'callback'            => static function () {
			return snt_desktop_widget_response( 'audience', 'snt_desktop_audience_groups' );
		},
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
	register_rest_route( 'signal-noise/v1', '/desktop/reading', array(
		'methods'             => 'GET',
		'callback'            => static function () {
			return snt_desktop_widget_response( 'reading', 'snt_desktop_reading_groups' );
		},
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
} );
