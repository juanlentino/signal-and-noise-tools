<?php
/**
 * Signal & Noise Tools, Abilities API: the machine-readers ledger reads.
 *
 *   - signal-noise/get-machine-readers-crosstab   family x purpose x agent
 *   - signal-noise/get-rights-reads               who fetched the rights files, when
 *
 * Both read the sensor through snt_mr_fetch() (a 15-minute display transient
 * in front of one GET per view) and fold with inc/machine-readers-ledger.php.
 * The crosstab also carries `unnamed`, the edge's top unmatched user-agent
 * samples (its `unknown` view), so the bucket with agent '' can be read.
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

/** How many unnamed user-agent samples the crosstab carries (the edge keeps 50). */
const SN_MR_UNNAMED_TOP = 20;

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/get-machine-readers-crosstab', array(
		'label'               => 'Get Machine Readers Crosstab',
		'description'         => 'Crawler reads at the edge over a window (days: 1-90, default 30) as family x purpose x agent cells, hits descending: `cells[]` of {family, purpose, agent, hits, days, surfaces}, where `days` is how many distinct days the cell was seen on and `surfaces` its hits per surface class. '
			. 'Answers "which purpose did each family read for" directly, which the summary\'s per-family and per-purpose totals cannot: a family whose reads split between `train` and `search` shows as two cells. Purpose `unknown` is an UNMAPPED reader (the taxonomy has no entry for it), not a reader with no purpose; `taxonomy_absent: true` means the edge sent no taxonomy at all and every purpose is unknown for that reason. '
			. 'Each cell also carries its evidence: `basis` claimed (the family, purpose and agent are what the user agent said), and its hits split as {claimed, verified, verified_matched, unverified, unverifiable} by Cloudflare\'s verified-bot category (the rights evidence\'s source and since date; verified_matched: the category fits the claimed purpose; unverifiable: a day before since). Verified is not signed (Web Bot Auth, in the summary\'s identity). `first_party_hits` is the exact part of a cell\'s hits from this site\'s own tools, and `first_party: true` marks a cell where that part is above zero; `impersonation: true` marks a robots.txt control token (Google-Extended, Applebot-Extended), which no crawler presents as its own user agent, so the summary credits those hits to no vendor. '
			. '`truncated: true` means the aggregate read hit the edge\'s row cap and the cells under-count the newest days; `total` sums the cells. User agents are self-reported: observation, never proof of identity. `ok: false` carries the sensor `error` and no cells. '
			. '`agent_networks[]` answers "is this crawler real": per claimed agent {agent, hits, verified_hits (Cloudflare verified the client), not_measured (hits from before the edge recorded the network), networks[] of the top 5 {network, hits}}. Unverified hits from a network the vendor does not own are an impostor. '
			. '`unnamed` answers "what is inside agent \'\'": the top 20 of {ua_sample, hits, verified_hits, not_measured, networks, basis} from the edge\'s own list of user agents that matched no taxonomy entry (a sanitized sample the edge keeps only on a miss), folded per sample, hits descending; networks is the top 5 {network, hits}. When the edge cannot split that list by verification and network (rights-signals before 1.30.0), verified_hits is null and basis unmeasured, never 0. That list carries no day, so hits less verified_hits is unverified OR unverifiable: a read from before the edge recorded verification (2026-09-27 15:49:59 UTC, SN_RIGHTS_EVIDENCE_VERIFIED_SINCE) counts as not verified there. null means that list could not be read; [] means every read matched. A sample is what the client sent (basis claimed), not an identity. Read-only.',
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
				'agent_networks'  => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				// Local only: the remote networks twin is this schema minus the
				// crosstab fields, and tests/abilities-remote-set.php strips this one too.
				'unnamed'         => array( 'type' => array( 'array', 'null' ), 'items' => array( 'type' => 'object' ) ),
				'taxonomy_absent' => array( 'type' => 'boolean' ),
				'truncated'       => array( 'type' => 'boolean' ),
				'error'           => array( 'type' => array( 'string', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			// readonly is read LITERALLY by tests/mcp-capabilities.php's source
			// scan, so it is spelled out per registration rather than shared.
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
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
			'mcp'          => array( 'public' => true, 'type' => 'tool' ),
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ),
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
 * @param array|null $input        { days?: int }.
 * @param bool       $with_unnamed False skips the unknown-view read (the remote twin drops the field).
 * @return array
 */
function snt_ability_get_machine_readers_crosstab( $input, $with_unnamed = true ) {
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
			'agent_networks'  => snt_mr_agent_networks( $rows ),
			'unnamed'         => $with_unnamed ? snt_mr_unnamed_agents( $days ) : null,
			'taxonomy_absent' => snt_mr_taxonomy_absent( $rows ),
			'truncated'       => ! empty( $read['truncated'] ),
			'error'           => null,
		)
	);
}

/**
 * The unnamed bucket, made readable: the edge's top unmatched user-agent
 * samples (its `unknown` view), cut to SN_MR_UNNAMED_TOP.
 *
 * Reads through snt_mr_fetch(), so it rides the same 15-minute transient and
 * request memo as the admin page's own read of this view: an ability call is
 * never an uncached worker fetch. Best-effort like the rights pair: a failed
 * read is null (not measured), never [] (which says every read matched).
 * Each sample is sanitised again here; one that sanitises to '' is dropped.
 *
 * 2026.10.1: each sample also carries verified_hits, not_measured and its top
 * networks, the agent_networks shape. The read asks for the unknown view with
 * detail=network (rights-signals 1.30.0: rows split by verified_bot and
 * network, capped at 10,000), so rows are folded by sample BEFORE the cut. A
 * worker before 1.30.0 ignores the parameter and answers the old top 50 with
 * no `detail` in the envelope: then verified_hits is null and basis
 * `unmeasured`, because the normalizer writes '' for an absent column, which
 * must not read as "none verified".
 *
 * @param int $days Window, already clamped.
 * @return array<int,array{ua_sample:string,hits:int,verified_hits:int|null,not_measured:int,networks:array,basis:string}>|null
 */
function snt_mr_unnamed_agents( $days ) {
	$read = snt_mr_fetch( $days, 'unknown-network' );
	if ( empty( $read['ok'] ) ) {
		return null;
	}
	return snt_mr_unnamed_fold( $read, SN_MR_UNNAMED_TOP );
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
