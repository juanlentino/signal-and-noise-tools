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
		'description'         => 'One coherent answer to "how is the site being read?": a sectioned batch over the readership reads. analytics_summary (range totals with the honest-denominator semantics: prefer view_visit_ratio, engagement times are MILLISECONDS), analytics_events (top custom events), rss_stats (feed fetches; its own fixed 7d/30d windows), machine_readers, analytics_top_content (pages with visits, scroll, time, and Search Console impressions and position over search_window), 404_log, analytics_sources (labels with category), analytics_series (views and visits per day), analytics_geography (country), analytics_devices, analytics_journeys (entry and exit pages, human only), analytics_query (an allowlisted query: one or two dimensions, the second always day; see `query`), and analytics_rows (counted rows by path, referrer, country, device or day, the shape the remote door serves; see `rows`). Windows follow the site\'s own day. Sources, geography, devices, journeys and query fold rows under 3 visits into withheld, so rows plus withheld add up. `range` (default 30) and `class` (default human) apply as each section\'s description says. Each entry carries its source ability\'s exact payload. If a source refuses, that ONE section degrades to {error:"unavailable"}; the call fails as a whole only on invalid input (empty or unknown sections, or a bad query).',
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
					'description' => 'Traffic class (human|suspect|bot) for analytics_summary, top_content, sources, series, geography, devices and query. journeys is recorded for human traffic only and says so; the others ignore it.',
				),
				'limit'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Rows for analytics_top_content (default 5, max 100), sources, geography, devices and journeys (default 25).',
				),
				'rows'     => array(
					'type'        => 'object',
					'description' => 'analytics_rows only: {dimensions, path, referrer, sort, limit}; range and class come from the top level. dimensions is one of path, referrer, country, device, day, or one of the first four with day. Paths outside site content read (unmatched), referrers are hostnames, values under 3 visitor-days are (withheld). Out-of-set values fail the whole call.',
				),
				'query'    => array(
					'type'        => 'object',
					'description' => 'analytics_query only: {dimensions, metrics, filters, compare, order_by, order, limit}. range and class come from the top level. Unknown words fail the whole call (422).',
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
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
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
		// 20.4.0: the dashboard's other readings, and a query over them.
		'analytics_sources'   => 'signal-noise/get-analytics-sources',
		'analytics_series'    => 'signal-noise/get-analytics-series',
		'analytics_geography' => 'signal-noise/get-analytics-geography',
		'analytics_devices'   => 'signal-noise/get-analytics-devices',
		'analytics_journeys'  => 'signal-noise/get-analytics-journeys',
		'analytics_query'     => 'signal-noise/analytics-query',
		// Owner brief 2026-10-10: counted rows by path, referrer, country,
		// device or day; its twin is the remote door's one query tool.
		'analytics_rows'      => 'signal-noise/analytics-rows',
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
	$limit = isset( $input['limit'] ) ? (int) $input['limit'] : null;
	$query = array_merge( isset( $input['query'] ) && is_array( $input['query'] ) ? $input['query'] : array(), array( 'range' => $range, 'class' => $class ) );

	// A bad query fails the call: the dispatch below folds every WP_Error into
	// {error:"unavailable"}, which would read a typo as an outage.
	if ( in_array( 'analytics_query', $sections, true ) && function_exists( 'snt_mq_validate' ) ) {
		$valid = snt_mq_validate( $query );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}
	$rows = array_merge( isset( $input['rows'] ) && is_array( $input['rows'] ) ? $input['rows'] : array(), array( 'range' => $range, 'class' => $class ) );
	if ( in_array( 'analytics_rows', $sections, true ) && function_exists( 'snt_arows_validate' ) ) {
		$valid = snt_arows_validate( $rows );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
	}
	$windowed = array_filter( array( 'range' => $range, 'class' => $class, 'limit' => $limit ), static fn( $v ) => null !== $v );

	// Per-section args, forwarded only where the source schema declares them.
	// machine_readers and analytics_top_content take `days`, not `range` — the
	// sn-metrics-level `range` arg is renamed on the way in, same as `class` is
	// scoped to analytics_summary only.
	$args_by_section = array(
		'analytics_summary'     => array( 'range' => $range, 'class' => $class ),
		'analytics_events'      => array( 'range' => $range ),
		'rss_stats'             => array(),
		'machine_readers'       => array( 'days' => $range ),
		'analytics_top_content' => array( 'limit' => null === $limit ? 5 : min( 100, $limit ) ) + $windowed, // its own cap is 100
		'404_log'               => array(),
		'analytics_sources'     => $windowed,
		'analytics_series'      => array( 'range' => $range, 'class' => $class ),
		'analytics_geography'   => $windowed,
		'analytics_devices'     => $windowed,
		'analytics_journeys'    => array_diff_key( $windowed, array( 'class' => 1 ) ),
		'analytics_query'       => $query,
		'analytics_rows'        => $rows,
	);

	$out = array();
	foreach ( $sections as $section ) {
		$out[ $section ] = snt_sn_site_facts_dispatch( $map[ $section ], $args_by_section[ $section ] ?? array() );
	}

	return array(
		'ok'       => true,
		'sections' => $out,
	);
}
