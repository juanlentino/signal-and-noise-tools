<?php
/**
 * Signal & Noise Tools, Abilities API: Machine Readers summary (read-only).
 *
 * Exposes the edge sensor's crawler aggregates to AI / automation callers as
 * the same compact glance the Desktop Mode tile renders:
 *
 *   - signal-noise/get-machine-readers-summary
 *
 * The Machine Readers tab (inc/machine-readers-admin.php) is the full picture;
 * this is the answer to "who read this site, and did the declared AI-training
 * crawlers touch the rights files" without a human loading wp-admin.
 *
 * TWO THINGS THIS FILE REFUSES TO DO.
 *
 * 1. Fabricate a zero. The sensor is a remote worker behind a token, so "no
 *    rows" has two very different causes: nobody crawled, or we never asked.
 *    A failed or unconfigured read returns ok:false plus the machine-readable
 *    reason and NO counts at all: an absent total cannot be misread as a
 *    measured zero, which is exactly what an agent would do with `total: 0`.
 *    Same contract the tab's connection callout states in prose.
 *
 * 2. Fork the payload. snt_desktop_machine_readers_payload()
 *    (inc/desktop-mode-integration.php) already shapes this exact glance for
 *    the tile's REST route, so the tile window delegates to it rather than
 *    keeping a second implementation that would drift the first time either
 *    side gained a field. The DM route hardcodes its 30-day window, so a
 *    caller asking for any other window takes the rebuild path below, which
 *    is the same algorithm over the same helpers.
 *
 * Read-only: reads snt_mr_fetch() (a short display transient in front of one
 * GET) and never writes, never triggers the sensor's own collection.
 *
 * @package SignalNoiseTools
 * @since 10.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The DM tile's window, and this ability's default (the delegation hinge). */
const SN_MR_ABILITY_DEFAULT_DAYS = 30;

/** How many families a glance carries. The full table is one click away. */
const SN_MR_ABILITY_TOP_FAMILIES = 3;

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/get-machine-readers-summary', array(
		'label'               => 'Get Machine Readers Summary',
		// Every field's meaning stated here, because an agent picks fields from
		// this text alone: what the numbers count, what they do NOT prove, and
		// what an ok:false response means (see the honesty note in the header).
		'description'         => 'Returns a compact summary of machine (crawler) reads observed at the edge over a window (days: 1-90, default 30): '
			. '`total` reads across all surfaces, `families` (the top 3 crawler families by reads, descending), '
			. '`ai_training` (reads from families whose public declarations class them as AI-training crawlers) and `ai_rights` (the slice of those reads that hit the `rights` surface class: /.well-known/tdmrep.json, /license.xml, /tdm-policy. NOTE: /robots.txt and /llms.txt are their OWN surface classes and are NOT counted here, so a low ai_rights does not mean the crawler ignored every declaration). '
			. '`ai_surfaces` breaks the SAME ai_training reads down per surface class instead of collapsing them into one number, so "did AI-training crawlers fetch robots.txt / llms.txt / the sitemap here" has a direct answer; each entry is `{surface, hits}`, descending by hits, and it is an empty array (never omitted) when no AI-training family read anything in the window. '
			. 'Provenance rides along so the numbers can be judged: `sensor_version` is the deployed edge worker, `crawler_list` is its list-drift verdict (in sync | drift | check failed), null when either document could not be read. '
			. 'User agents are self-reported, so this is observation, never proof of identity. '
			. 'When the sensor is unconfigured or unreachable the response is `ok: false` with a machine-readable `error` (not_configured, blocked, network, bad_schema, http_NNN) and NO counts at all: an absent total means "we never asked", which is not the same claim as zero crawlers. Read-only.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_machine_readers_summary',
		'input_schema'        => array(
			// Accept null: readonly abilities (GET) receive null when the caller
			// omits ?input= (the pattern in abilities-analytics.php).
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array(
					'type'        => 'integer',
					'default'     => SN_MR_ABILITY_DEFAULT_DAYS,
					'minimum'     => 1,
					'maximum'     => 90,
					'description' => 'Window in days; clamped to the sensor\'s own 1-90 range.',
				),
			),
			'additionalProperties' => false,
		),
		// Mirrors snt_desktop_machine_readers_payload()'s response order, with
		// `error` appended. Nothing is `required`: the failure response carries
		// ok/error/days ONLY, deliberately (see the header). Additive only:
		// Desktop Mode normalizes ability schemas at desktop_mode_ai_tools.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'             => array( 'type' => 'boolean' ),
				'days'           => array( 'type' => 'integer' ),
				'total'          => array( 'type' => 'integer' ),
				'families'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'family' => array( 'type' => 'string' ),
							'hits'   => array( 'type' => 'integer' ),
						),
					),
				),
				'ai_training'    => array( 'type' => 'integer' ),
				'ai_rights'      => array( 'type' => 'integer' ),
				'ai_surfaces'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'surface' => array( 'type' => 'string' ),
							'hits'    => array( 'type' => 'integer' ),
						),
					),
				),
				'sensor_version' => array( 'type' => array( 'string', 'null' ) ),
				'crawler_list'   => array( 'type' => array( 'string', 'null' ) ),
				'error'          => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				// readonly => the agent run-path resolves this to GET. Dropping
				// it forces POST and 405s the semantically-correct call.
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/get-machine-readers-summary.
 *
 * Clamps the window to the sensor's own 1-90 range, then either delegates to
 * the Desktop Mode tile route (its window, its payload) or rebuilds the same
 * glance for the window actually asked for.
 *
 * @param array|null $input Optional. { days?: int }.
 * @return array The tile payload (see the output_schema above). ok:false plus
 *               `error` and no counts when the sensor did not answer.
 */
function snt_ability_get_machine_readers_summary( $input ) {
	$input = is_array( $input ) ? $input : array();
	$days  = isset( $input['days'] ) ? (int) $input['days'] : 30;
	$days  = max( 1, min( 90, $days ) );
	// v10.2.0: ONE builder, no fork. snt_mr_summary_payload() lives beside the
	// fetch it uses and is the single source both this ability and the desktop
	// tile route read, so neither can drift when the other gains a field.
	return snt_mr_summary_payload( $days );
}

