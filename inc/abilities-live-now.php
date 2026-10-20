<?php
/**
 * Read-door ability: what the site looks like right now.
 *
 * The admin live answer (inc/analytics-live.php) for agents: readers in the
 * last 5 minutes (every class), human views today, the last hour in 5-minute
 * slots, the pages being read, where readers arrived from, and the live-surge
 * analytics signal (inc/analytics-live-surge.php). Reads the realtime cache only;
 * never queries Analytics Engine on read. The sn-status `live` section.
 *
 * @package Signal_And_Noise_Tools
 * @since   23.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Execute callback. With no argument the answer is the live payload,
 * unchanged; `hours_back` (1 to 24) adds `slots`, the stored snapshots
 * (inc/analytics-live-slots.php).
 *
 * @param array|null $input { hours_back? }.
 * @return array|WP_Error
 */
function snt_ability_live_now( $input ) {
	if ( ! function_exists( 'sn_analytics_live_payload' ) ) {
		return new WP_Error( 'snt_live_now_unavailable', 'The live analytics module is unavailable.', array( 'status' => 500 ) );
	}
	$out = sn_analytics_live_payload( true );
	if ( ! is_array( $input ) || ! array_key_exists( 'hours_back', $input ) ) {
		return $out;
	}
	$hours = filter_var( $input['hours_back'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 24 ) ) );
	if ( false === $hours ) {
		return new WP_Error( 'snt_live_now_hours_back', 'hours_back must be a whole number from 1 to 24.', array( 'status' => 400 ) );
	}
	$out['slots'] = function_exists( 'sn_analytics_live_slots' ) ? sn_analytics_live_slots( (int) $hours ) : null;
	return $out;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/live-now', array(
		'label'               => 'What the site looks like right now',
		'description'         => 'The live analytics answer, read from the realtime cache (refreshed at most every 30 s while anyone is watching; never queries Analytics Engine on read). `now`: presumed-human readers (not flagged) active in the last 5 minutes; `classes`: the same count per traffic class (human, suspect, bot; inferred at read time); `today`: presumed-human pageviews since midnight in the site timezone; `hour`: twelve 5-minute slots, oldest first, the last one current and partial, each { t (unix seconds, slot start), readers }; `pages`: the top five published, indexable notes and pages being read now, { label, url, readers, scroll }, where scroll { reached_25, reached_50, reached_75, reached_100, readers_with_scroll } counts how many of that page\'s current readers passed each scroll milestone in the same 5-minute window (cumulative, from each reader\'s deepest milestone: reached_50 includes those at the end; readers_with_scroll = reached_25; readers minus it have not passed 25% yet), null when the scroll read failed, 0s when it succeeded and nobody has scrolled, absent in a cache written before the field; `sources`: where readers whose pageview landed in the last 5 minutes arrived from, in the dashboard source names, { label, readers }; `surge`: the live-surge analytics signal (deliberately not an ML pipeline: reader data stays out of the ML family) on the last COMPLETED slot against the same time of day on earlier days (robust z over median and MAD, one slot either side): state learning (under 4 days of history, no verdict), usual, or surge (z >= 3 and at least 3 readers), with readers, usual (the median), ratio (null when the usual is 0), z and days; `fetched`: when the cache was written (null = never). Null for any part means not read, never zero; a quiet site is 0 and [] . Optional `hours_back` (1 to 24) adds `slots`: the stored rolling snapshots of the last N hours, oldest first, one per COMPLETED 5-minute slot, each { t (slot start), readers (human, as in `hour`), classes { human, suspect, bot }, pages (as `pages`, without scroll), sources (as `sources`) }. A slot is stored when a realtime refresh runs after it completes and is kept 24 hours; a slot no refresh saw is absent, never 0. Aggregates only, no withholding floor (live has none). Without `hours_back` the answer is exactly the live answer.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_live_now',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'hours_back' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 24, 'description' => 'Add `slots`: the stored 5-minute snapshots of the last N hours (1 to 24).' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'now'     => array( 'type' => array( 'integer', 'null' ) ),
				'today'   => array( 'type' => array( 'integer', 'null' ) ),
				'pages'   => array( 'type' => array( 'array', 'null' ) ),
				'hour'    => array( 'type' => array( 'array', 'null' ) ),
				'fetched' => array( 'type' => array( 'integer', 'null' ) ),
				'surge'   => array( 'type' => array( 'object', 'null' ) ),
				'sources' => array( 'type' => array( 'array', 'null' ) ),
				'classes' => array( 'type' => 'object' ),
				'slots'   => array( 'type' => array( 'array', 'null' ), 'description' => 'Only with hours_back.' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
