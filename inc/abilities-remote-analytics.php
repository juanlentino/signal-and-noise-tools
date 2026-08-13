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
			. 'Read-only. Identical response contract to the admin ability — see that ability\'s '
			. 'description for every denominator and its traps. Reachable only by a principal '
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
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'   => true,
				'idempotent' => true,
			),
		),
	) );
} );
