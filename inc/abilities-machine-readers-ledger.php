<?php
/**
 * Signal & Noise Tools, Abilities API: the machine-readers ledger reads.
 *
 *   - signal-noise/get-machine-readers-crosstab   family x purpose x agent
 *   - signal-noise/get-rights-reads               who fetched the rights files, when
 *
 * Both read the sensor through snt_mr_fetch() (a 15-minute display transient
 * in front of one GET per view) and fold with inc/machine-readers-ledger.php.
 * Neither writes, neither touches a remote twin: the summary's payload is the
 * one that hashes into SN_REMOTE_CONTRACT_VERSION and it is not changed here.
 * Same honesty contract as the summary: a sensor that did not answer is
 * `ok: false` with the reason and NO counts, never a zero.
 *
 * @package SignalNoiseTools
 * @since 17.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/get-machine-readers-crosstab', array(
		'label'               => 'Get Machine Readers Crosstab',
		'description'         => 'Crawler reads at the edge over a window (days: 1-90, default 30) as family x purpose x agent cells, hits descending: `cells[]` of {family, purpose, agent, hits, days, surfaces}, where `days` is how many distinct days the cell was seen on and `surfaces` its hits per surface class. '
			. 'Answers "which purpose did each family read for" directly, which the summary\'s per-family and per-purpose totals cannot: a family whose reads split between `train` and `search` shows as two cells. Purpose `unknown` is an UNMAPPED reader, not a reader with no purpose; `taxonomy_absent: true` means the edge sent no taxonomy at all and every purpose is unknown for that reason. '
			. '`truncated: true` means the aggregate read hit the edge\'s row cap and the cells under-count the newest days; `total` sums the cells. User agents are self-reported: observation, never proof of identity. `ok: false` carries the sensor `error` and no cells. Read-only.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_machine_readers_crosstab',
		// Spelled out per registration: tests/abilities-categories.php reads the
		// input_schema off the source, and a shared variable is invisible to it.
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 90, 'description' => 'Window in days, clamped to the sensor\'s 1-90.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'              => array( 'type' => 'boolean' ),
				'days'            => array( 'type' => 'integer' ),
				'total'           => array( 'type' => 'integer' ),
				'families'        => array( 'type' => 'integer' ),
				'cells'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'taxonomy_absent' => array( 'type' => 'boolean' ),
				'truncated'       => array( 'type' => 'boolean' ),
				'error'           => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			// readonly is read LITERALLY by tests/mcp-capabilities.php's source
			// scan, so it is spelled out per registration rather than shared.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );

	wp_register_ability( 'signal-noise/get-rights-reads', array(
		'label'               => 'Get Rights Reads',
		'description'         => 'Every fetch of the rights surfaces (/.well-known/tdmrep.json, /license.xml, /tdm-policy) over a window (days: 1-90, default 30), newest first, from the edge\'s full-fidelity stream: `reads[]` of {observed_at, family, vendor, purpose, path, hits}. No user-agent string is carried; the family and vendor are the edge\'s classification of it. '
			. '`cadence[]` folds the same reads per (family, path): reads, first, last, `median_interval_s`, `regularity` (coefficient of variation of the gaps between reads, 0 is a metronome, null under two gaps) and `poller` (three reads or more at regularity 0.5 or under: a scheduled fetch rather than a visit). '
			. '`ai_rights` gives the AI-training families\' rights reads counted twice, `aggregate` from the summary\'s dataset and `detail` from this one; the two are written by different paths at the edge and a gap between them is a sensor finding, not a rounding. '
			. '`truncated: true` means the stream hit the edge\'s 500-row cap and the OLDEST reads in the window are missing. `ok: false` carries the sensor `error` and no rows. Read-only.',
		'category'            => 'analytics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_get_rights_reads',
		// Spelled out per registration: tests/abilities-categories.php reads the
		// input_schema off the source, and a shared variable is invisible to it.
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'days' => array( 'type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 90, 'description' => 'Window in days, clamped to the sensor\'s 1-90.' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'        => array( 'type' => 'boolean' ),
				'days'      => array( 'type' => 'integer' ),
				'reads'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'cadence'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'ai_rights' => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'aggregate' => array( 'type' => 'integer' ), 'detail' => array( 'type' => 'integer' ) ) ),
				'truncated' => array( 'type' => 'boolean' ),
				'error'     => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			// readonly is read LITERALLY by tests/mcp-capabilities.php's source
			// scan, so it is spelled out per registration rather than shared.
			'annotations'  => array( 'readonly' => true, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );

/**
 * The window from an ability input, clamped to the sensor's range.
 *
 * @param array|null $input
 * @return int
 */
function snt_mr_ledger_days( $input ) {
	$days = is_array( $input ) && isset( $input['days'] ) ? (int) $input['days'] : 30;
	return max( 1, min( 90, $days ) );
}

/**
 * Ability execute callback: signal-noise/get-machine-readers-crosstab.
 *
 * @param array|null $input { days?: int }.
 * @return array
 */
function snt_ability_get_machine_readers_crosstab( $input ) {
	$days = snt_mr_ledger_days( $input );
	$read = snt_mr_fetch( $days );
	if ( empty( $read['ok'] ) ) {
		return array( 'ok' => false, 'days' => $days, 'error' => (string) ( $read['error'] ?? 'unknown' ) );
	}
	$rows = is_array( $read['rows'] ?? null ) ? $read['rows'] : array();
	return array_merge(
		array( 'ok' => true, 'days' => $days ),
		snt_mr_crosstab( $rows ),
		array(
			'taxonomy_absent' => snt_mr_taxonomy_absent( $rows ),
			'truncated'       => ! empty( $read['truncated'] ),
			'error'           => null,
		)
	);
}

/**
 * Ability execute callback: signal-noise/get-rights-reads.
 *
 * The aggregate read is best-effort: it exists only for the ai_rights pair,
 * so its failure leaves the pair null rather than failing the rows.
 *
 * @param array|null $input { days?: int }.
 * @return array
 */
function snt_ability_get_rights_reads( $input ) {
	$days = snt_mr_ledger_days( $input );
	$read = snt_mr_fetch( $days, 'rights' );
	if ( empty( $read['ok'] ) ) {
		return array( 'ok' => false, 'days' => $days, 'error' => (string) ( $read['error'] ?? 'unknown' ) );
	}
	$rows = is_array( $read['rows'] ?? null ) ? $read['rows'] : array();
	$agg  = snt_mr_fetch( $days );
	return array(
		'ok'        => true,
		'days'      => $days,
		'reads'     => array_map( static fn( $r ) => array_intersect_key( $r, array_flip( array( 'observed_at', 'family', 'vendor', 'purpose', 'path', 'hits' ) ) ), $rows ),
		'cadence'   => snt_mr_rights_cadence( $rows ),
		'ai_rights' => empty( $agg['ok'] ) ? null : snt_mr_ai_rights_pair( (array) ( $agg['rows'] ?? array() ), $rows ),
		'truncated' => ! empty( $read['truncated'] ),
		'error'     => null,
	);
}
