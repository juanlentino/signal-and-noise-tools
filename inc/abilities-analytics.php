<?php
/**
 * Read-only analytics Abilities — let AI agents and the abilities REST controller
 * read durable analytics without the dashboard UI. Non-destructive, idempotent.
 *
 * Abilities registered:
 *   - signal-noise/get-analytics-summary  — range totals: the kept-deprecated
 *                                           legacy quartet (views, visits,
 *                                           scroll_avg, time_avg) PLUS the
 *                                           honest Phase A vocabulary (spec §4:
 *                                           unique_visitor_days, gated
 *                                           pageview_visits, viewless_visits,
 *                                           transparent ratios, exact per-view
 *                                           / per-visit engagement,
 *                                           integrity_violation,
 *                                           exact_metrics_since)
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
		// Every denominator documented (Phase A spec §4 — "show the most"):
		// an AI caller must be able to pick the right field from this text
		// alone, so each metric names its exact denominator and its traps.
		'description'         => 'Returns range analytics totals (range: 7|14|30|90|365|all, class: human|suspect|bot). Read-only. '
			. 'Denominators, honestly named: `visits` is DEPRECATED — an approximate count of unique visitor-days (distinct IP+date), NOT sessions; '
			. 'it includes visitor-days that fired no pageview (e.g. feed/beacon-only), so it can exceed `views`; `unique_visitor_days` is its honest alias. '
			. '`pageview_visits` is the headline visit metric: visitor-days with at least one pageview — `views >= pageview_visits` holds by construction, so this ratio cannot invert '
			. '(`integrity_violation: true` means a genuine rollup bug upstream, values served unclamped). '
			. '`viewless_visits` = unique_visitor_days - pageview_visits (visitor-days with zero pageviews). '
			. 'Ratios: `view_visit_ratio` = views/pageview_visits (>=1); `pageviews_per_visitor_day` = views/unique_visitor_days (may be <1). '
			. 'Engagement in two exact denominations: `scroll_avg_per_view`/`time_avg_per_view` divide by views; `scroll_avg_per_visit`/`time_avg_per_visit` divide by unique_visitor_days and are diluted by viewless days. '
			. 'Legacy `scroll_avg`/`time_avg` are DEPRECATED views-weighted approximations of per-event means. '
			. 'Exact engagement + gated fields are null over any range containing days before `exact_metrics_since` (Y-m-d; null until the backfill has run) — a data discontinuity, not an error.',
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
		// ADDITIVE ONLY (Desktop Mode normalizes ability schemas at
		// desktop_mode_ai_tools — never rename/remove a field). Property order
		// mirrors the sn_analytics_range_totals() response; nullable unions
		// mark exactly where the derive layer returns null ("never measured" /
		// pre-backfill — never a fabricated 0).
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'views'                     => array( 'type' => 'integer' ),
				'visits'                    => array( 'type' => 'integer' ),
				'scroll_avg'                => array( 'type' => 'number' ),
				'time_avg'                  => array( 'type' => 'number' ),
				'unique_visitor_days'       => array( 'type' => array( 'integer', 'null' ) ),
				'pageview_visits'           => array( 'type' => array( 'integer', 'null' ) ),
				'viewless_visits'           => array( 'type' => array( 'integer', 'null' ) ),
				'view_visit_ratio'          => array( 'type' => array( 'number', 'null' ) ),
				'pageviews_per_visitor_day' => array( 'type' => array( 'number', 'null' ) ),
				'scroll_avg_per_view'       => array( 'type' => array( 'number', 'null' ) ),
				'time_avg_per_view'         => array( 'type' => array( 'number', 'null' ) ),
				'scroll_avg_per_visit'      => array( 'type' => array( 'number', 'null' ) ),
				'time_avg_per_visit'        => array( 'type' => array( 'number', 'null' ) ),
				'integrity_violation'       => array( 'type' => 'boolean' ),
				'exact_metrics_since'       => array( 'type' => array( 'string', 'null' ) ),
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
 * @return array             The full sn_analytics_range_totals() contract: the
 *                           legacy quartet (views/visits/scroll_avg/time_avg)
 *                           plus every spec-§4 derived field and
 *                           exact_metrics_since — see the output_schema above.
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
