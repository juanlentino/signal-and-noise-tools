<?php
/**
 * Signal & Noise Tools — Abilities API: sn_metrics (read-door coherence,
 * owner-reopened consolidation 2026-08-25, sibling of sn-status).
 *
 * "How is the site being read?" in one call: a sectioned batch over the
 * three readership reads. Same pattern, same contracts as sn-status —
 * sectioned batch (sn-site-facts precedent), uniform per-section
 * {error:"unavailable"} degradation via snt_sn_site_facts_dispatch(),
 * WP_Error only for invalid input, registered NEW ALONGSIDE OLD.
 *
 * Shared window args, verified against the LIVE source registrations
 * (inc/abilities-analytics.php, inc/abilities-content.php — not the spec):
 * `range` reaches analytics_summary and analytics_events (both default 30,
 * origin-validated values); `class` reaches analytics_summary only (default
 * human). rss_stats takes no input — its payload carries its own fixed 7d
 * and 30d windows — so both args are ignored for it. Args are forwarded
 * ONLY to the sources whose schemas declare them: forwarding `class` to
 * analytics_events would trip its additionalProperties:false and degrade a
 * healthy section to {error:"unavailable"} — the R1 bug's mirror image
 * (dead by extra args instead of dead by missing ones).
 *
 * @package SignalNoiseTools
 * @since 13.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-metrics', array(
		'label'               => 'Batch-read readership metrics (consolidated)',
		'description'         => 'One coherent answer to "how is the site being read?" — a sectioned batch over the three readership reads: analytics_summary (range totals with the honest-denominator semantics: prefer view_visit_ratio, engagement times are MILLISECONDS), analytics_events (top custom events for the window), and rss_stats (feed fetches and fetchers; its payload carries its own fixed 7d/30d windows). `range` (default 30) applies to analytics_summary and analytics_events; `class` (default human) applies to analytics_summary only; both are validated by the sources, which own their value sets, and both are ignored by rss_stats. Each entry in the returned map carries its source ability\'s exact payload shape — this tool never reshapes, so answers match the narrow tools byte-for-byte. If a source is unregistered or refuses, that ONE section degrades to {error:"unavailable"} while the rest still return; the call only fails as a whole on invalid input (empty or unknown sections).',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_metrics',
		'input_schema'        => array(
			'type'                 => 'object', // 'sections' is required — no bodyless-GET null union (sn-site-facts precedent).
			'required'             => array( 'sections' ),
			'properties'           => array(
				'sections' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => array_keys( snt_sn_metrics_map() ),
					),
					'minItems' => 1,
				),
				'range'    => array(
					'type'        => array( 'string', 'integer' ),
					'default'     => 30,
					'description' => 'Window for analytics_summary and analytics_events (source-validated: 7|14|30|90|365|all). Ignored by rss_stats. For machine_readers the sensor clamps to 1-90, so 365 and all are refused there, and the payload\'s own days_covered reports how many days it actually holds — asking for 90 and being handed 32 is not an error, it is when the sensor started.',
				),
				'class'    => array(
					'type'        => 'string',
					'default'     => 'human',
					'description' => 'Traffic class for analytics_summary only (source-validated: human|suspect|bot). Ignored by the other sections.',
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'       => array( 'type' => 'boolean' ),
				'sections' => array( 'type' => 'object' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	) );
} );

/**
 * section name => source ability slug. Single source of truth for the
 * input_schema enum and the dispatch loop.
 *
 * @return array<string,string>
 */
function snt_sn_metrics_map() {
	return array(
		'analytics_summary' => 'signal-noise/get-analytics-summary',
		'analytics_events'  => 'signal-noise/get-analytics-events',
		'rss_stats'         => 'signal-noise/get-rss-stats',
		// v13.44.0. "How the site is read" is exactly what the machine-readers
		// sensor measures, and until now NO MCP tool exposed it — the identity
		// fold (v13.43.0) included, which is why answering "one agent or
		// fifteen?" required shell access to the origin.
		'machine_readers'   => 'signal-noise/get-machine-readers-summary',
		// The third analytics sibling. summary and events were already
		// sections; this one was simply omitted from the family.
		'analytics_top_content' => 'signal-noise/get-analytics-top-content',
		// How the site is read, and fails to be read.
		'404_log'           => 'signal-noise/get-404-log',
	);
}

/**
 * Ability execute callback: signal-noise/sn-metrics.
 *
 * @param array|null $input { sections: string[], range?: string|int, class?: string }.
 * @return array{ok:bool,sections:array}|WP_Error
 */
function snt_ability_sn_metrics( $input ) {
	$input = is_array( $input ) ? $input : array();
	$map   = snt_sn_metrics_map();

	$sections = isset( $input['sections'] ) ? array_values( array_unique( array_map( 'strval', (array) $input['sections'] ) ) ) : array();
	if ( empty( $sections ) ) {
		return new WP_Error( 'snt_metrics_empty', __( 'sections must be a non-empty array.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$unknown = array_values( array_diff( $sections, array_keys( $map ) ) );
	if ( ! empty( $unknown ) ) {
		return new WP_Error(
			'snt_metrics_unknown',
			sprintf(
				/* translators: %s: comma-separated list of unrecognized section names. */
				__( 'Unknown section(s): %s', 'signal-and-noise-tools' ),
				implode( ', ', $unknown )
			),
			array( 'status' => 422 )
		);
	}

	// Defaults mirror the sources' own schema defaults; the sources validate
	// the VALUES (they own the accepted sets — an enum here would silently
	// narrow the capability if a source widened its set, the same reasoning
	// as sn-remote-mcp's ARG_SCHEMA_BY_KEY).
	$range = isset( $input['range'] ) ? $input['range'] : 30;
	$class = isset( $input['class'] ) ? (string) $input['class'] : 'human';

	// Per-section args, forwarded only where the source schema declares them.
	$args_by_section = array(
		'analytics_summary' => array( 'range' => $range, 'class' => $class ),
		'analytics_events'  => array( 'range' => $range ),
		'rss_stats'         => array(),
	);

	$out = array();
	foreach ( $sections as $section ) {
		$out[ $section ] = snt_sn_site_facts_dispatch( $map[ $section ], $args_by_section[ $section ] );
	}

	return array(
		'ok'       => true,
		'sections' => $out,
	);
}
