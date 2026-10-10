<?php
/**
 * Signal & Noise Tools: `signal-noise/analytics-rows` and its remote twin
 * `signal-noise/remote-analytics-rows`. The stored analytics by path,
 * referrer, country, device or day (one of them, or one with day), as counted
 * rows. One schema function and one execute callback for both, so the twin's
 * output is the admin's by construction (contract 15).
 *
 * Why it exists (2026-10-10): asked which pages moved after a Hacker News
 * submission, the remote door could report a 37% lift and name no page and no
 * source. The rules that keep stored visitor text out of a model's context
 * are in inc/analytics-rows.php and docs/ai-abilities-catalog.md.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The output schema, shared by both registrations. */
function snt_analytics_rows_output_schema() {
	$n = array( 'type' => array( 'number', 'null' ) );
	$s = array( 'type' => 'string' );
	return array(
		'type'       => 'object',
		'properties' => array(
			'rows'                => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => $s, 'referrer' => $s, 'country' => $s, 'device' => $s, 'day' => $s,
						'views'               => array( 'type' => 'integer' ),
						'pageview_visits'     => array( 'type' => 'integer' ),
						'time_avg_per_view'   => $n,
						'scroll_avg_per_view' => $n,
					),
				),
			),
			'row_count'           => array( 'type' => 'integer' ),
			'truncated'           => array( 'type' => 'boolean' ),
			'range'               => array( 'type' => array( 'string', 'integer' ) ),
			'class'               => $s,
			'exact_metrics_since' => array( 'type' => array( 'string', 'null' ) ),
		),
	);
}

/**
 * Execute: validate, read, shape. The envelope carries the validated range
 * and class, never a caller's filter text.
 *
 * @param mixed $input The ability input.
 * @return array|WP_Error
 */
function snt_ability_analytics_rows( $input = null ) {
	$q = snt_arows_validate( $input );
	if ( is_wp_error( $q ) ) {
		return $q;
	}
	list( $from, $to ) = snt_analytics_range_dates( $q['range'] );
	$read              = snt_arows_fetch( $q, $from, $to );
	if ( is_wp_error( $read ) ) {
		return $read;
	}
	$out = snt_arows_shape( $read['rows'], $q, in_array( 'path', $q['dims'], true ) ? snt_arows_real_paths() : array() );
	$out['truncated'] = $out['truncated'] || $read['capped']; // stored groups past the read cap are rows not seen.
	return $out + array(
		'range'               => (string) $q['range'],
		'class'               => $q['class'],
		'exact_metrics_since' => function_exists( 'sn_analytics_exact_metrics_since' ) ? sn_analytics_exact_metrics_since() : null,
	);
}

/** Permission for the twin: its own literal slug, as every twin does. */
function snt_ability_perm_remote_analytics_rows() {
	return sn_remote_analytics_allows( 'signal-noise/remote-analytics-rows' );
}

/** The description, shared: what the numbers are and are not. */
function snt_analytics_rows_description() {
	return 'Stored analytics as counted rows, by one of path, referrer, country, device or day, or one of the first four with day (path and day is the finest grain served: no hit-level rows, nothing below a day). '
		. 'Arguments: dimensions (required), range 7|14|30|90|365|all (default 30), class human|suspect|bot (default human), path (a site path; only with path or day), referrer (a hostname; only with referrer or day), sort views|visits|time (time only with path or day), limit 1-500 (default 50). Out-of-set values are refused, not coerced. '
		. 'Each row: its dimension values, views, pageview_visits, time_avg_per_view and scroll_avg_per_view. Times are MILLISECONDS; scroll is mean max depth 0-100. Time and scroll are stored per page only, so they are null for referrer, country and device. '
		. 'pageview_visits counts visitor-DAYS that viewed: an estimate, not a session count. views are sample-corrected. '
		. 'truncated: true means the limit cut rows, so sums over the rows are a floor. '
		. 'Referrers are hostnames only, with (direct), (internal) and (unknown) as stored; anything that is not a clean hostname is (invalid). Paths are returned only when they are the site\'s own content; scanner probes and other requests are (unmatched). '
		. 'A value seen by fewer than 3 visitor-days is folded into (withheld), its counts kept so totals hold; on a quiet site most path-by-day rows are withheld, a busy day answers. '
		. 'Nothing is subtracted for bot signals: human is the stored class; judge how much of it is automated with the bot-signals reading. '
		. 'Untruncated, the views of a path query sum to the analytics summary\'s views for the same range and class. Read-only.';
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	$base = array(
		'category'         => 'analytics',
		'execute_callback' => 'snt_ability_analytics_rows',
		'output_schema'    => snt_analytics_rows_output_schema(),
	);
	wp_register_ability( 'signal-noise/analytics-rows', $base + array(
		'label'               => 'Analytics rows',
		// Types only: the allowed values are the origin's (snt_arows_validate()).
		// Written out in both registrations for the static readers; the twin's
		// is pinned === to this one (tests/abilities-remote-set.php).
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'dimensions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'range'      => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
				'class'      => array( 'type' => 'string', 'default' => 'human' ),
				'path'       => array( 'type' => 'string' ),
				'referrer'   => array( 'type' => 'string' ),
				'sort'       => array( 'type' => 'string', 'default' => 'views' ),
				'limit'      => array( 'type' => 'integer', 'default' => 50 ),
			),
			'required'             => array( 'dimensions' ),
			'additionalProperties' => false,
		),
		'description'         => snt_analytics_rows_description(),
		'permission_callback' => 'snt_ability_perm_manage_options',
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false, 'type' => 'tool' ), // reached locally as sn-metrics{analytics_rows}, like its sibling sections.
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
	wp_register_ability( 'signal-noise/remote-analytics-rows', $base + array(
		'label'               => 'Analytics rows (remote)',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'dimensions' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'range'      => array( 'type' => array( 'string', 'integer' ), 'default' => 30 ),
				'class'      => array( 'type' => 'string', 'default' => 'human' ),
				'path'       => array( 'type' => 'string' ),
				'referrer'   => array( 'type' => 'string' ),
				'sort'       => array( 'type' => 'string', 'default' => 'views' ),
				'limit'      => array( 'type' => 'integer', 'default' => 50 ),
			),
			'required'             => array( 'dimensions' ),
			'additionalProperties' => false,
		),
		'description'         => 'Remote-scoped twin of signal-noise/analytics-rows. ' . snt_analytics_rows_description()
			. ' Reachable only by a principal holding the sn_read_remote_analytics capability, and only while the remote door is explicitly enabled.',
		'permission_callback' => 'snt_ability_perm_remote_analytics_rows',
		'meta'                => array(
			'show_in_rest' => false, // the bridge executes the ability directly; no run route, no switch-state oracle (see inc/abilities-remote-analytics.php).
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
