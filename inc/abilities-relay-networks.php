<?php
/**
 * signal-noise/relay-networks: which network numbers the relay rule's readers
 * arrive from (inc/analytics-relay-networks.php), owner-only and read-only,
 * reached as sn-metrics{relay_networks}. Local door only: it is the evidence
 * for one owner decision, and the remote verdict map says so. No remote twin.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-relay-networks.php';

const SNT_RELAY_NETWORKS_BASIS = 'Evidence for one decision: the analytics worker presumes a page view human when its network NAME matches the pattern and the browser is Safari on iOS or macOS (iCloud Private Relay), and this lists the network numbers (ASNs) those page views arrive from, before the rule is changed to match by number. Page views only, on networks whose name matches the pattern, grouped by ASN, network name, class as the worker stored it at arrival (not the read-time class the dashboard counts), browser and OS. ASN 0 means no number was stored: every row read from the first generation of the dataset (all of it before 2026-10-05), and any later row Cloudflare gave no number for. Nothing is changed or subtracted; no rule, class or count moves because this was read.';

/**
 * The read for a trailing window. A failed read is an error, never a short list.
 *
 * @param int $days 1 to 90.
 * @return array|WP_Error
 */
function sn_relay_networks_read( $days ) {
	$days = (int) $days;
	$sets = sn_analytics_stitched_rows(
		static fn( $source, $range ) => sn_relay_networks_sql( $days, $source, $range ),
		sn_analytics_trailing_from( $days ),
		gmdate( 'Y-m-d H:i:s', sn_analytics_clock() )
	);
	if ( null === $sets ) {
		return new WP_Error( 'sn_analytics_read_failed', 'Analytics Engine did not answer the relay-networks read; nothing is listed rather than a short list.', array( 'status' => 500 ) );
	}
	$fold = sn_relay_networks_fold( $sets );
	return array(
		'days'       => $days,
		'pattern'    => SNT_ANALYTICS_RELAY_ASN_SOURCE,
		'rows'       => $fold['rows'],
		'truncated'  => $fold['truncated'],
		'summary'    => $fold['summary'],
		'subtracted' => false,
		'basis'      => SNT_RELAY_NETWORKS_BASIS,
	);
}

/**
 * Execute callback. Reads Analytics Engine on each call; nothing is stored.
 *
 * @param array|null $input { days?: int } 1 to 90, default 90.
 * @return array|WP_Error
 */
function snt_ability_relay_networks( $input = null ) {
	$input = is_array( $input ) ? $input : array();
	$days  = filter_var( $input['days'] ?? 90, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => 90 ) ) );
	if ( false === $days ) {
		return new WP_Error( 'ability_invalid_input', 'days must be a whole number from 1 to 90.', array( 'status' => 400 ) );
	}
	return sn_relay_networks_read( $days );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$text  = array( 'type' => 'string' );
	$int   = array( 'type' => 'integer', 'minimum' => 0 );
	$obj   = static fn( array $props ) => array( 'type' => 'object', 'properties' => $props, 'additionalProperties' => false );
	$class = $obj( array( 'human' => $int, 'suspect' => $int, 'bot' => $int, 'unknown' => $int ) );
	wp_register_ability( 'signal-noise/relay-networks', array(
		'label'               => 'Relay networks: the ASNs behind the akamai and fastly names',
		'description'         => 'Owner-only, read-only: the network numbers (ASNs) behind the relay rule, read before that rule is changed. The analytics worker presumes a page view human when its network name matches `pattern` (akamai|fastly, case-insensitive) and the browser is Safari on iOS or macOS (iCloud Private Relay); the same network with another browser is suspect. `rows`: page views of the trailing `days` (default 90, at most 90) on networks whose name matches, grouped by asn, network (the ASN organization), class (human, suspect or bot as the worker stored it), browser and os, with views (sample-corrected) and visitor_days, most views first, at most 200. `summary` is read from every group before that cap: views (all of them); relay_human (stored human, Safari, iOS or macOS: views, the distinct asns with their views, and asn_unrecorded_views on rows with no number stored); safari_apple and not_safari_apple, each the views by stored class, which together add up to views. asn 0 means no number was stored (every row before 2026-10-05, which the first generation of the dataset answers). truncated is true when more than 200 groups came back or the read stopped at its row limit (only then is the summary short). Nothing is changed or subtracted (subtracted: false); `basis` says so. Read from Analytics Engine on each call; no visitor code, IP address or user agent is returned.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_relay_networks',
		'input_schema'        => array(
			'type'                 => 'object',
			'default'              => array(),
			'properties'           => array( 'days' => array( 'type' => 'integer', 'default' => 90, 'minimum' => 1, 'maximum' => 90 ) ),
			'additionalProperties' => false,
		),
		'output_schema'       => $obj( array(
			'days'       => $int,
			'pattern'    => $text,
			'rows'       => array(
				'type'     => 'array',
				'maxItems' => SNT_RELAY_NETWORKS_MAX,
				'items'    => $obj( array( 'asn' => $int, 'network' => $text, 'class' => array( 'type' => 'string', 'enum' => array( 'human', 'suspect', 'bot', '(unknown)' ) ), 'browser' => $text, 'os' => $text, 'views' => $int, 'visitor_days' => $int ) ),
			),
			'truncated'  => array( 'type' => 'boolean' ),
			'summary'    => $obj( array(
				'views'            => $int,
				'relay_human'      => $obj( array( 'views' => $int, 'asn_unrecorded_views' => $int, 'asns' => array( 'type' => 'array', 'items' => $obj( array( 'asn' => $int, 'views' => $int ) ) ) ) ),
				'safari_apple'     => $class,
				'not_safari_apple' => $class,
			) ),
			'subtracted' => array( 'type' => 'boolean' ),
			'basis'      => $text,
		) ),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ), // reached locally as sn-metrics{relay_networks}.
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ),
		),
	) );
} );
