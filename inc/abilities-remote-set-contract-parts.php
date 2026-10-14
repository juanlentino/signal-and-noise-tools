<?php
/**
 * Signal & Noise Tools: the remote set, the edge-errors and contract-14 twins (18.0.0 on).
 *
 * Split from inc/abilities-remote-set.php (house line cap, 2026-10-13); a pure
 * move, no code changed. The file header there carries the isolation rules every
 * twin here follows. Loaded by require_once at the end of that file, in the
 * order the registrations always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callback for `signal-noise/remote-edge-errors-summary` (18.0.0).
 * Its own slug as a LITERAL, like every callback above.
 *
 * @return bool
 */
function snt_ability_perm_remote_edge_errors() {
	return sn_remote_analytics_allows( 'signal-noise/remote-edge-errors-summary' );
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'snt_edge_errors_output_schema' ) ) {
		return;
	}
	/* ── 18.0.0 — owner ruling 2026-09-23: the 5xx rollup joins the door.
	 * The output_schema is READ FROM THE SAME FUNCTION the admin registration
	 * reads (inc/abilities-edge-errors.php), so byte-identity is by
	 * construction; tests/abilities-remote-set.php still pins the pair.
	 * ───────────────────────────────────────────────────────────────── */
	wp_register_ability( 'signal-noise/remote-edge-errors-summary', array(
		'label'               => 'Edge 5xx summary (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/edge-errors-summary. The last '
			. 'seven days of 5xx from the daily edge rollup: total, failing paths and who '
			. 'answered, Early Hints cache lookups excluded, plus (18.2.0) one row per day '
			. 'with who asked. Counts only; the perimeter '
			. '(token, firewall, WAF) stays local on cloudflare-status. Read-only. '
			. 'Reachable only by a principal holding the sn_read_remote_analytics '
			. 'capability, and only while the remote door is explicitly enabled.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_remote_edge_errors',
		'execute_callback'    => 'snt_ability_edge_errors_summary',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => snt_edge_errors_output_schema(),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );

/**
 * Permission callbacks for the two contract-14 twins. Their own slugs as
 * LITERALS, like every callback above.
 *
 * @return bool
 */
function snt_ability_perm_remote_bot_signals() {
	return sn_remote_analytics_allows( 'signal-noise/remote-bot-signals' );
}

/** @return bool */
function snt_ability_perm_remote_agent_networks() {
	return sn_remote_analytics_allows( 'signal-noise/remote-machine-readers-networks' );
}

/**
 * Execute callback for `signal-noise/remote-machine-readers-networks`: the
 * crosstab payload with only the agent_networks answer kept. The owner ruled
 * the FIELD remote (2026-09-29), not the crosstab, so cells, total, families
 * and taxonomy_absent stay local.
 *
 * @param array|null $input { days?: int }.
 * @return array
 */
function snt_ability_remote_agent_networks( $input = null ) {
	$out = snt_ability_get_machine_readers_crosstab( $input, false );
	return is_array( $out ) ? array_intersect_key( $out, array_flip( array( 'ok', 'days', 'agent_networks', 'truncated', 'error' ) ) ) : $out;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'snt_bot_signals_output_schema' ) ) {
		return;
	}
	/* ── Contract 14 — owner ruling 2026-09-29: the bot-signals readout and
	 * the machine readers' agent_networks join the door. Aggregate counts
	 * only: cohort sizes and fire rates, agent and network names with hits.
	 * ───────────────────────────────────────────────────────────────── */
	wp_register_ability( 'signal-noise/remote-bot-signals', array(
		'label'               => 'Beacon bot signals (remote)',
		'description'         => 'Remote-scoped twin of signal-noise/bot-signals. Observe-only: '
			. 'per cohort (relay, intent, stored_bot, over_cap, hosting) the visitor-days and how '
			. 'often each beacon signal fired, and human.likely_automated beside human.visitor_days. '
			. 'Nothing is subtracted from any count. measured:false means nothing is stored yet. '
			. 'Counts only, stored nightly. Read-only. Reachable only by a principal holding the '
			. 'sn_read_remote_analytics capability, and only while the remote door is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_bot_signals',
		'execute_callback'    => 'snt_ability_bot_signals',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => snt_bot_signals_output_schema(),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );

	wp_register_ability( 'signal-noise/remote-machine-readers-networks', array(
		'label'               => 'Machine readers: is this crawler real (remote)',
		'description'         => 'Remote-scoped slice of signal-noise/get-machine-readers-crosstab: '
			. 'agent_networks[] only. Per claimed agent {agent, hits, verified_hits, not_measured, '
			. 'networks[] of the top 5 {network, hits}}. Unverified hits from a network the vendor '
			. 'does not own are an impostor. ok:false carries the sensor error and no rows. Counts '
			. 'only. Read-only. Reachable only by a principal holding the sn_read_remote_analytics '
			. 'capability, and only while the remote door is explicitly enabled.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_remote_agent_networks',
		'execute_callback'    => 'snt_ability_remote_agent_networks',
		'input_schema'        => array(
			// A window is a read parameter, not a lever (same ruling as the summary twin).
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 90, 'description' => 'Window in days, clamped to the sensor\'s 1-90.' ),
			),
			'additionalProperties' => false,
		),
		// output_schema: the crosstab's, byte-identical, MINUS cells, total,
		// families and taxonomy_absent; tests/abilities-remote-set.php pins the strip.
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'             => array( 'type' => 'boolean' ),
				'days'           => array( 'type' => 'integer' ),
				'agent_networks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'truncated'      => array( 'type' => 'boolean' ),
				'error'          => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => false, // the surface, not a setting — see file header.
			'mcp'          => array( 'public' => false, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		),
	) );
} );
