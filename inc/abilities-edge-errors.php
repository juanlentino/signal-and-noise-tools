<?php
/**
 * Signal & Noise Tools — `signal-noise/edge-errors-summary`: the last seven
 * days of 5xx from the daily edge rollup, on its own.
 *
 * WHY ITS OWN ABILITY, when `cloudflare-status` already carries the same
 * reading as `errors_5xx`: that section is RULED LOCAL (it describes the
 * perimeter: token, firewall, WAF rules), and a remote twin must copy its
 * admin schema byte-identically, so it cannot be narrowed to the one field a
 * phone needs. A 5xx is our own failure, not a description of the defences,
 * so it earns a section, and a twin, of its own. Both read
 * sn_edge_errors_reading(), so the two surfaces cannot disagree.
 *
 * 17.9.3 is why it exists now: Early Hints cache misses were ~98% of every
 * stored 5xx, and "did they go away?" is a question asked from a phone.
 *
 * @package SignalNoiseTools
 * @since 18.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The output_schema, one definition for the admin ability and its remote
 * twin (inc/abilities-remote-set.php), so byte-identity is by construction.
 *
 * @return array<string,mixed>
 */
function snt_edge_errors_output_schema() {
	$row = array(
		'type'       => 'object',
		'properties' => array(
			'value'    => array( 'type' => 'string' ),
			'requests' => array( 'type' => 'integer' ),
			'bytes'    => array( 'type' => 'integer' ),
		),
	);
	$source                        = $row;
	$source['properties']['label'] = array( 'type' => 'string' );
	$asked                         = array(
		'visitor'    => array( 'type' => 'integer', 'description' => 'Asked by a visitor (requestSource eyeball).' ),
		'worker'     => array( 'type' => 'integer', 'description' => 'Asked by a Worker subrequest (edgeWorker*).' ),
		'other'      => array( 'type' => 'integer', 'description' => 'Any other Cloudflare request source.' ),
		'unrecorded' => array( 'type' => 'integer', 'description' => 'Stored before the query carried requestSource (17.9.3): in a window spanning that change, the pre-filter leftover.' ),
	);
	$day                           = array(
		'type'       => 'object',
		'properties' => array_merge(
			array(
				'day'   => array( 'type' => 'string' ),
				'total' => array( 'type' => 'integer' ),
				'read'  => array( 'type' => 'string', 'enum' => array( 'read', 'failed', 'pending', 'untracked' ), 'description' => '18.3.0: read = the errors query answered for this day; failed = it was refused, so a 0 means NOT read; pending = no rollup has covered it yet (today, or yesterday before the daily run), so a 0 means not yet; untracked = stored before this bookkeeping.' ),
			),
			$asked
		),
	);
	return array(
		'type'       => 'object',
		'properties' => array(
			'state'       => array( 'type' => 'string', 'enum' => array( 'recorded', 'unavailable' ), 'description' => 'unavailable means the edge rollup is not loaded on this install: NOT zero errors.' ),
			'from'        => array( 'type' => array( 'string', 'null' ), 'description' => 'First day of the window, YYYY-MM-DD (UTC).' ),
			'to'          => array( 'type' => array( 'string', 'null' ), 'description' => 'Last day of the window, YYYY-MM-DD (UTC). Today is partial.' ),
			'honest_from' => array( 'type' => array( 'string', 'null' ), 'description' => '17.9.1: sampled rows before this day were counted twice over. Blank until the one-shot repair has run.' ),
			'query'       => array( 'type' => array( 'object', 'null' ), 'description' => 'The errors query\'s last outcome: at (unix), day, error. A non-empty error means the window was NOT read, which is not the same as no errors.' ),
			'total'       => array( 'type' => 'integer', 'description' => '5xx over the window, Early Hints cache lookups excluded (17.9.3).' ),
			'paths'       => array( 'type' => 'array', 'items' => $row, 'description' => 'Which URLs failed, most first (top 10).' ),
			'sources'     => array( 'type' => 'array', 'items' => $source, 'description' => 'Who answered, most first (top 10). `origin=-` is Cloudflare or a Worker answering by itself; a matching origin status is the origin failing.' ),
			'days'        => array( 'type' => 'array', 'items' => $day, 'description' => '18.2.0: one row per day of the window, oldest first, zero-filled: total and who asked. Read the day a filter changed here instead of inferring it from the week.' ),
			'asked_by'    => array( 'type' => 'object', 'properties' => $asked, 'description' => '18.2.0: the window\'s 5xx by who asked, summed from days.' ),
		),
	);
}

/**
 * @param mixed $input Unused.
 * @return array<string,mixed>
 */
function snt_ability_edge_errors_summary( $input = null ) {
	unset( $input );
	if ( ! function_exists( 'sn_edge_errors_reading' ) ) {
		return array( 'state' => 'unavailable', 'from' => null, 'to' => null, 'honest_from' => null, 'query' => null, 'total' => 0, 'paths' => array(), 'sources' => array(), 'days' => array(), 'asked_by' => array( 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 ) );
	}
	$r = sn_edge_errors_reading( 7 );
	return array(
		'state'       => 'recorded',
		'from'        => (string) $r['from'],
		'to'          => (string) $r['to'],
		'honest_from' => (string) $r['honest_from'],
		'query'       => is_array( $r['query'] ) ? $r['query'] : null,
		'total'       => (int) $r['total'],
		'paths'       => array_values( (array) $r['paths'] ),
		'sources'     => array_values( (array) $r['sources'] ),
		'days'        => array_values( (array) ( $r['days'] ?? array() ) ),
		'asked_by'    => (array) ( $r['asked_by'] ?? array( 'visitor' => 0, 'worker' => 0, 'other' => 0, 'unrecorded' => 0 ) ),
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/edge-errors-summary', array(
		'label'               => 'Edge 5xx summary',
		'description'         => 'The last seven days of 5xx from the daily edge rollup: total, which paths failed, who answered (edge vs origin status, request source), and since 18.2.0 one row per day with who asked (visitor, worker, other, unrecorded; unrecorded rows predate 17.9.3 and are the pre-filter leftover). Cloudflare\'s own Early Hints cache lookups are excluded (17.9.3); they were ~98% of every stored 5xx and no visitor ever saw one. Read `query.error` before trusting a zero: a failed read is not a clean week. Same reader as cloudflare-status errors_5xx. Read-only; never fetches.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_edge_errors_summary',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => snt_edge_errors_output_schema(),
		'meta'                => array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
