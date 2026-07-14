<?php
/**
 * Read-only analytics Abilities — let AI agents and the abilities REST controller
 * read durable analytics without the dashboard UI. Non-destructive, idempotent.
 *
 * Abilities registered:
 *   - signal-noise/get-analytics-summary  — range totals (views, visits,
 *                                           scroll_avg, time_avg)
 *   - signal-noise/get-analytics-events   — top custom events (name →
 *                                           events/visitors) for a window
 *
 * Permission: snt_ability_perm_manage_options (manage_options cap).
 * Execution delegates to the same read accessors the dashboard uses.
 *
 * @package SignalAndNoiseTools
 * @since   6.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-analytics-events', array(
		'label'               => 'Get custom events',
		'description'         => 'Returns top custom events (name → events/visitors) for a window. Read-only; historical Plausible-imported data.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'sn_ability_get_analytics_events',
		'input_schema'        => array(
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'  => 'array',
			'items' => array( 'type' => 'object' ),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );

	wp_register_ability( 'signal-noise/get-analytics-summary', array(
		'label'               => 'Get analytics summary',
		'description'         => 'Returns views, visits, average scroll and time for a window (range: 7|14|30|90|365|all, class: human|suspect|bot). Read-only.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'sn_ability_get_analytics_summary',
		'input_schema'        => array(
			// Accept null because readonly abilities (GET) receive null when
			// the caller omits ?input= — mirrors the pattern in abilities-system.php.
			'type'       => array( 'object', 'null' ),
			'properties' => array(
				'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
				'class' => array( 'type' => 'string', 'default' => 'human' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'views'      => array( 'type' => 'integer' ),
				'visits'     => array( 'type' => 'integer' ),
				'scroll_avg' => array( 'type' => 'number' ),
				'time_avg'   => array( 'type' => 'number' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
} );

/**
 * Execute callback for signal-noise/get-analytics-summary.
 * Resolves window from input, delegates to sn_analytics_range_totals().
 *
 * @param array|null $input  Optional. { range?: int|string, class?: string }.
 * @return array             { views: int, visits: int, scroll_avg: float, time_avg: float }
 */
function sn_ability_get_analytics_summary( $input ) {
	$input = is_array( $input ) ? $input : array();
	$range = snt_analytics_resolve_range( $input['range'] ?? 30 );
	$class = snt_analytics_resolve_class( $input['class'] ?? 'human' );
	list( $from, $to ) = snt_analytics_range_dates( $range );
	return sn_analytics_range_totals( $from, $to, $class );
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
	$range = snt_analytics_resolve_range( $input['range'] ?? 30 );
	list( $from, $to ) = snt_analytics_range_dates( $range );
	return function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 100 ) : array();
}
