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

/** Execute callback. */
function snt_ability_live_now( $input ) {
	unset( $input );
	if ( ! function_exists( 'sn_analytics_live_payload' ) ) {
		return new WP_Error( 'snt_live_now_unavailable', 'The live analytics module is unavailable.', array( 'status' => 500 ) );
	}
	return sn_analytics_live_payload( true );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/live-now', array(
		'label'               => 'What the site looks like right now',
		'description'         => 'The live analytics answer, read from the realtime cache (refreshed at most every 30 s while anyone is watching; never queries Analytics Engine on read). `now`: human readers active in the last 5 minutes; `classes`: the same count per traffic class (human, suspect, bot); `today`: human pageviews since midnight in the site timezone; `hour`: twelve 5-minute slots, oldest first, the last one current and partial, each { t (unix seconds, slot start), readers }; `pages`: the top five published, indexable notes and pages being read now, { label, url, readers }; `sources`: where readers whose pageview landed in the last 5 minutes arrived from, in the dashboard source names, { label, readers }; `surge`: the live-surge analytics signal (deliberately not an ML pipeline: reader data stays out of the ML family) on the last COMPLETED slot against the same time of day on earlier days (robust z over median and MAD, one slot either side): state learning (under 4 days of history, no verdict), usual, or surge (z >= 3 and at least 3 readers), with readers, usual (the median), ratio (null when the usual is 0), z and days; `fetched`: when the cache was written (null = never). Null for any part means not read, never zero; a quiet site is 0 and [] .',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_live_now',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
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
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
