<?php
/**
 * signal-noise/bot-signals-flagged: the flagged-humans readout
 * (inc/analytics-bot-signals-flagged.php), owner-only and read-only, reached
 * as sn-metrics{bot_signals_flagged}. Local door only: it lists individual
 * visitor-days, and the remote verdict map says so. No remote twin.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-bot-signals-flagged.php';

/**
 * The readout for a trailing window, both halves of the split. A failed read
 * is an error, never a short list.
 *
 * @param int $days 1 to 90.
 * @return array|WP_Error
 */
function sn_bot_signals_flagged_read( $days ) {
	$days = (int) $days;
	$from = sn_analytics_trailing_from( $days );
	$now  = gmdate( 'Y-m-d H:i:s', sn_analytics_clock() );
	$tz   = function_exists( 'sn_analytics_site_tz_name' ) ? sn_analytics_site_tz_name() : '';
	$main = sn_analytics_stitched_rows( static fn( $s, $r ) => sn_bot_signals_sql( $days, $s, $r ), $from, $now );
	$det  = null === $main ? null : sn_analytics_stitched_rows( static fn( $s, $r ) => sn_bot_signals_flagged_details_sql( $days, $s, $r, $tz ), $from, $now );
	$cnt  = null === $det ? null : sn_analytics_stitched_rows( static fn( $s, $r ) => sn_bot_signals_flagged_count_sql( $days, $s, $r ), $from, $now );
	if ( null === $cnt ) {
		return new WP_Error( 'sn_analytics_read_failed', 'Analytics Engine did not answer the bot-signals read; nothing is listed rather than a short list.', array( 'status' => 500 ) );
	}
	$rows   = array_merge( array(), ...$main );
	$window = array_sum( array_map( static fn( $set ) => (int) ( ( (array) ( $set[0] ?? array() ) )['n'] ?? 0 ), $cnt ) );
	$capped = array() !== array_filter( array_merge( $main, $det ), static fn( $set ) => count( $set ) >= SNT_BOT_SIGNAL_LIMIT );
	return array(
		'days'                => $days,
		'threshold'           => SNT_BOT_SIGNAL_THRESHOLD,
		'window_visitor_days' => $window,
		'truncated'           => $capped || count( $rows ) < $window,
		'subtracted'          => false,
	) + sn_bot_signals_flagged_derive( $rows, array_merge( array(), ...$det ) );
}

/**
 * Execute callback. Reads Analytics Engine on each call; nothing is stored.
 *
 * @param array|null $input { days?: int } 1 to 90, default 30.
 * @return array|WP_Error
 */
function snt_ability_bot_signals_flagged( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	$days  = filter_var( $input['days'] ?? 30, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 90 ) ) );
	if ( false === $days ) {
		return new WP_Error( 'ability_invalid_input', 'days must be a whole number from 1 to 90.', array( 'status' => 400 ) );
	}
	return sn_bot_signals_flagged_read( $days );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$text = array( 'type' => 'string' );
	$int  = array( 'type' => 'integer' );
	$row  = array(
		'type'       => 'object',
		'properties' => array(
			'day'     => $text,
			'source'  => $text,
			'country' => $text,
			'region'  => $text,
			'city'    => $text,
			'device'  => $text,
			'browser' => $text,
			'os'      => $text,
			'network' => $text,
			'views'   => $int,
			'time_ms' => array( 'type' => array( 'integer', 'null' ) ),
			'signals' => array( 'type' => 'array', 'items' => $text ),
			'score'   => $int,
			'relay'   => array( 'type' => 'boolean' ),
			'intent'  => array( 'type' => 'boolean' ),
		),
	);
	wp_register_ability( 'signal-noise/bot-signals-flagged', array(
		'label'               => 'Bot signals: flagged human visitor-days',
		'description'         => 'Owner-only, read-only: every visitor-day the bot-signals readout counts as human (presumed human, not over the page-view cap) whose bot-signal score is at or above the threshold (flagged), and apart those scoring exactly one below it (near_miss), over the trailing `days` (default 30, at most 90). The list a "strict human" figure would take out, so the decision can be made on rows. Each row: day (site-local), source (the first page view\'s, as the dashboard labels it), country, region, city, device, browser, os, network (the ASN organization), views, time_ms (visible time, null when not read), signals (which of webdriver, no_input, ua_mismatch, headless, tz_mismatch fired), score, relay and intent (the cohort flags). read_visitor_days and human_visitor_days say how many it read and scored; window_visitor_days is how many the window holds, and truncated is true when the read stopped at its row limit or returned fewer than that. Nothing is subtracted from any count (subtracted: false). Read from Analytics Engine on each call; no visitor code, IP address or user agent is returned.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_bot_signals_flagged',
		'input_schema'        => array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array( 'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 90 ) ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'days'                => $int,
				'threshold'           => $int,
				'window_visitor_days' => $int,
				'truncated'           => array( 'type' => 'boolean' ),
				'subtracted'          => array( 'type' => 'boolean' ),
				'flagged'             => array( 'type' => 'array', 'items' => $row ),
				'near_miss'           => array( 'type' => 'array', 'items' => $row ),
				'read_visitor_days'   => $int,
				'human_visitor_days'  => $int,
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ), // reached locally as sn-metrics{bot_signals_flagged}.
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ),
		),
	) );
} );
