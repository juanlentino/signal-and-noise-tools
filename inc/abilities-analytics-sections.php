<?php
/**
 * Signal & Noise Tools: the read abilities behind sn-metrics' analytics
 * sections (sources, series, geography, devices, journeys, query).
 *
 * Each is a narrow ability that sn-metrics dispatches by slug, the way
 * get-analytics-top-content is: mcp.public false, so none is a separate MCP
 * tool (the owner's 2026-09-09 rule: a read earns a section of a big tool).
 * Local door only; the remote verdicts say why. Input accepts null (a GET
 * without ?input=), like its analytics siblings.
 *
 * @package SignalNoiseTools
 * @since Unreleased
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/sn-metrics-analytics-sections.php';
require_once __DIR__ . '/sn-metrics-analytics-fetch.php';
require_once __DIR__ . '/sn-metrics-analytics-vocab.php';
require_once __DIR__ . '/sn-metrics-analytics-query.php';

/**
 * The window, class and limit every section takes.
 *
 * @return array<string,array>
 */
function snt_metrics_section_props() {
	return array(
		'range' => array( 'type' => array( 'string', 'integer' ), 'default' => 30, 'description' => '7|14|30|90|365|all, on the site\'s own day.' ),
		'class' => array( 'type' => 'string', 'default' => 'human', 'description' => 'human|suspect|bot.' ),
		'limit' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 500 ),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$floor = ' Rows under 3 visits are folded into withheld {rows, views, visits}, so rows plus withheld add up to the window.';
	$table = array(
		'signal-noise/get-analytics-sources'   => array( 'Get traffic sources', 'snt_ability_get_analytics_sources', 'Referrer sources folded to labels, each with its category (search, ai, social, direct, other), views and visits.' . $floor ),
		'signal-noise/get-analytics-series'    => array( 'Get daily series', 'snt_ability_get_analytics_series', 'Site-wide views and visits per day over the window. Not floored: these are whole-site totals.' ),
		'signal-noise/get-analytics-geography' => array( 'Get geography', 'snt_ability_get_analytics_geography', 'Views and visits by country.' . $floor ),
		'signal-noise/get-analytics-devices'   => array( 'Get devices', 'snt_ability_get_analytics_devices', 'Views and visits by device.' . $floor ),
		'signal-noise/get-analytics-journeys'  => array( 'Get entry and exit pages', 'snt_ability_get_analytics_journeys', 'Entry and exit pages with views and visits. Recorded for human traffic only, so class is not applied (class_applied says so).' . $floor ),
		'signal-noise/analytics-query'         => array( 'Query analytics', 'snt_ability_analytics_query', 'A query over the stored rollups, allowlisted vocabulary only. dimensions: one or two of day, path, source, referrer_category, country, device; two must be day plus one. metrics: views, visits, scroll_avg and time_avg (milliseconds; need path). filters: {dimension: {include, exclude}} on grouped dimensions. compare: none|previous (previous values, deltas and share of views). order_by, order, limit (max 500). Unknown words refuse (422).' . $floor ),
	);
	$query_props = array(
		'dimensions' => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => SNT_MQ_DIMENSIONS ), 'minItems' => 1, 'maxItems' => 2 ),
		'metrics'    => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => SNT_MQ_METRICS ) ),
		'filters'    => array( 'type' => 'object' ),
		'compare'    => array( 'type' => 'string', 'enum' => array( 'none', 'previous' ), 'default' => 'none' ),
		'order_by'   => array( 'type' => 'string' ),
		'order'      => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ) ),
	);
	foreach ( $table as $slug => $def ) {
		$props = snt_metrics_section_props();
		if ( 'signal-noise/analytics-query' === $slug ) {
			$props += $query_props;
		}
		wp_register_ability( $slug, array(
			'label'               => $def[0],
			'description'         => $def[2] . ' Read-only.',
			'category'            => 'analytics',
			'permission_callback' => 'snt_ability_perm_manage_options',
			'execute_callback'    => $def[1],
			'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => $props, 'additionalProperties' => false ),
			'output_schema'       => array( 'type' => 'object' ),
			'meta'                => array(
				'show_in_rest' => true,
				'mcp'          => array( 'public' => false, 'type' => 'tool' ),
				'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		) );
	}
} );
