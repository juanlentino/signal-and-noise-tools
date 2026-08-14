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
			. 'Denominators, honestly named: '
			. 'it includes visitor-days that fired no pageview (e.g. feed/beacon-only), so it can exceed `views`; `unique_visitor_days` is its honest alias. '
			. '(The wp-admin Sessions tab is a third unit again: within-day sessions from the live session engine, resetting at UTC midnight: no field here carries it.) '
			. '`pageview_visits` is the headline visit metric: visitor-days with at least one pageview. `views >= pageview_visits` holds by construction, so this ratio cannot invert '
			. '(`integrity_violation: true` means a genuine rollup bug upstream, values served unclamped). '
			. '`viewless_visits` = unique_visitor_days - pageview_visits (visitor-days with zero pageviews). '
			. 'Ratios: `view_visit_ratio` = views/pageview_visits (>=1); `pageviews_per_visitor_day` = views/unique_visitor_days (may be <1). '
			. 'PREFER `view_visit_ratio` when judging engagement: `pageviews_per_visitor_day` is DILUTED BY DESIGN by feed- and beacon-only visitor-days '
			. '(a server-side feed beacon creates a visitor-day with no pageview), so it reads below 1.0 on a healthy site and a low value there is not a defect to investigate. '
			. 'Engagement in two exact denominations, both in MILLISECONDS: `time_avg_per_view` = time_sum/views; `time_avg_per_visit` = time_sum/unique_visitor_days, diluted by viewless days. '
			. '(The beacon reports `performance.now()` deltas in ms and nothing downstream converts, so 38690 is ~39 SECONDS, not ~11 hours. Divide by 1000 before presenting it.) '
			. 'Scroll depth (v9.64.0 unit): `scroll_avg_per_view` = 25 * scroll_events / views and `scroll_avg_per_visit` = 25 * scroll_events / unique_visitor_days (diluted by viewless days): the true mean max scroll depth (0-100), '
			. 'because the beacon fires one cumulative milestone event per 25/50/75/100% reached, each at most once per view; scroll_sum is stored as the same identity since v9.66.0 (25 * scroll_events, true depth units: a full-depth view contributes 100, not the pre-v9.66.0 raw milestone-point 250) and feeds no ratio. '
						. 'Exact engagement + gated fields are null over any range containing days before `exact_metrics_since` (Y-m-d; null until the backfill has run): a data discontinuity, not an error.',
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
	$totals = sn_analytics_range_totals( $from, $to, $class );
	// v10.0.0 BREAK: the deprecated legacy quartet leaves the PUBLIC surface.
	// `visits` was an approximate visitor-day count that agents read as
	// sessions; scroll_avg/time_avg were views-weighted approximations of
	// per-event means. The honest fields (unique_visitor_days,
	// pageview_visits, scroll_avg_per_view, time_avg_per_visit, …) have
	// carried the real semantics since v9.4x. Stripped at the ability
	// boundary ONLY: sn_analytics_range_totals() still returns them for
	// internal consumers (the Dashboard widget renders all three, and
	// annotations gate on visits), so no owner-facing surface changes.
	unset( $totals['visits'], $totals['scroll_avg'], $totals['time_avg'] );
	return $totals;
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
