<?php
/**
 * Signal & Noise — the remote analytics ability (R3 §3D, Increment 1 origin half).
 *
 * ONE slug, `signal-noise/remote-get-analytics-summary`, sharing the existing
 * `sn_ability_get_analytics_summary` reader and gated ONLY by the remote
 * callback below.
 *
 * WHY A SEPARATE SLUG rather than a union callback on the existing
 * get-analytics-summary. A union (manage_options OR remote scope) can only ever
 * ADD allow paths, so a bug in the remote branch could not break the admin
 * caller. But it would place remote logic inside an admin-facing gate, and any
 * future widening of the remote branch would widen an admin surface with it.
 * Registering separately keeps `snt_ability_perm_manage_options` on the existing
 * ability byte-identical — the same isolation mcp-read-guard.php keeps from
 * mcp-rw-guard.php.
 *
 * THIS SLUG IS DELIBERATELY OFF sn_mcp_allowlist(). The laptop read door already
 * reaches get-analytics-summary with an application password; it gains nothing
 * here, and a test pins that absence.
 *
 * The schemas below are duplicated from inc/abilities-analytics.php rather than
 * extracted from it, because extracting would modify the registration this
 * increment promises to leave unchanged. A parity test pins the two in step, so
 * the duplication cannot drift silently.
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callback for `signal-noise/remote-get-analytics-summary`.
 *
 * Passes its own slug as a LITERAL. A permission callback is handed only the
 * ability's arguments (`$ability->check_permissions( $args )` in
 * inc/mcp/mcp-tools.php), never its own name, so a shared callback would have to
 * infer the slug from ambient request state — and would infer wrongly whenever
 * one ability executes another.
 *
 * @return bool
 */
function snt_ability_perm_remote_analytics_summary() {
	return sn_remote_analytics_allows( 'signal-noise/remote-get-analytics-summary' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/remote-get-analytics-summary', array(
		'label'               => 'Get analytics summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/get-analytics-summary. '
			. 'Returns range analytics totals (range: 7|14|30|90|365|all, class: human|suspect|bot). '
			. 'Read-only. Identical response contract to the admin ability. '
			. 'UNITS, stated here rather than by reference: engagement times are MILLISECONDS '
			. '(38690 is ~39 seconds, not ~11 hours); `scroll_avg_*` is true mean max depth 0-100. '
			. '`visits`/`unique_visitor_days` counts visitor-DAYS over all event types with no '
			. 'pageview gate, so `viewless_visits` is expected and is not a defect; `views` is '
			. 'sample-corrected while visitor-days are a raw distinct count, so treat views/visits '
			. 'as an estimate and not a precise ratio. See the admin ability\'s description for the '
			. 'full denominator set and its traps — though note that ability is deliberately absent '
			. 'from the remote allowlist, so a remote caller cannot read it. Reachable only by a principal '
			. 'holding the sn_read_remote_analytics capability, and only while the remote door '
			. 'is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_analytics_summary',
		'execute_callback'    => 'sn_ability_get_analytics_summary',
		'input_schema'        => array(
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
			// FALSE, DELIBERATELY — this is the surface, not a setting.
			//
			// With `true`, WordPress registers
			// POST /wp-abilities/v1/abilities/<slug>/run on every install, and an
			// UNAUTHENTICATED caller learns the switch state from the error code:
			// sn_mcp_remote_disabled when off, ability_invalid_permissions when on.
			// A switch-state oracle, present the day the plugin is installed.
			//
			// That run route is also what reopened the F2-shaped gap the origin
			// half then had to paper over with a guard. The bridge dispatches via
			// wp_get_ability( $slug )->execute( $args ) and never needs it.
			// Deleting the surface beats guarding it.
			'show_in_rest' => false,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
} );
