<?php
/**
 * Signal & Noise Tools — Abilities API: which watches have come due.
 *
 * The registry (inc/watches.php) is read by the morning brief, which mails the
 * owner at 07:00. That is one surface, and a human one. This is the other:
 * shipping an instrument only a single surface can see is the defect this
 * codebase found twice in three days — the purge log written for eighteen
 * versions and read by nothing (v13.86.0), and the shape ledger filling for
 * four days with sn_shape_stability() called only from tests (v13.88.0).
 *
 * READ-ONLY, and it evaluates rather than stores: ripeness is computed from
 * live state on every call, so there is no cached verdict to go stale and
 * nothing here to keep in step with the brief.
 *
 * @package SignalNoiseTools
 * @since   13.90.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/watches', array(
		'label'               => 'Watches Due',
		'description'         => 'Returns the registered watches — decisions deferred to a later date or a later STATE — and which have come due. Call this when asked what is outstanding, before planning work, or when a session resumes and needs to know what the site has been waiting on. `ripe` lists only watches that have come due; an empty `ripe` list means nothing needs attention, which is the normal state and is NOT an error. Each row carries `label`, `note` (the evidence that ripened it, or the date), `read` (the exact call that answers it) and `why` (what acting on it means). `date_only` distinguishes the two kinds and matters: a state-tested watch ripened because something measurable changed, while a date-only watch ripened because a clock passed and NOTHING was measured — those warrant different confidence. A watch that cannot be tested (its module absent, its reader unavailable) is never reported ripe, on the same rule the rest of this plugin keeps: absence of evidence is not a finding. Read-only; evaluates live state and stores nothing.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_watches',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ripe'       => array(
					'type'        => 'array',
					'description' => 'Watches that have come due, each with label, note, read, why and date_only. Empty is the normal state.',
				),
				'pending'    => array(
					'type'        => 'array',
					'description' => 'Registered watches not yet due, so a caller can see what is being waited on without inferring it from silence.',
				),
				'counts'     => array(
					'type'        => 'object',
					'description' => 'ripe / pending / total.',
				),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'        => true,
				'idempotent'      => true,
				'open_world_hint' => false,
			),
		),
	) );
} );

/**
 * Ability execute callback: signal-noise/watches.
 *
 * @param array|null $input Unused; the ability takes no arguments.
 * @return array|WP_Error
 */
function snt_ability_watches( $input ) {
	unset( $input );

	if ( ! function_exists( 'snt_watches' ) || ! function_exists( 'snt_watches_ripe' ) ) {
		return new WP_Error( 'snt_watches_unavailable', 'Watch registry not loaded.', array( 'status' => 500 ) );
	}

	$all  = snt_watches();
	$ripe = snt_watches_ripe();

	$ripe_ids = array();
	foreach ( $ripe as $r ) {
		$ripe_ids[ (string) ( $r['id'] ?? '' ) ] = true;
	}

	// PENDING is reported, not left to inference. A caller seeing only an empty
	// `ripe` list cannot tell "nothing is due" from "nothing is registered",
	// and those are different facts.
	$pending = array();
	foreach ( $all as $w ) {
		if ( isset( $ripe_ids[ (string) ( $w['id'] ?? '' ) ] ) ) {
			continue;
		}
		$pending[] = array(
			'id'        => (string) ( $w['id'] ?? '' ),
			'label'     => (string) ( $w['label'] ?? '' ),
			'read'      => (string) ( $w['read'] ?? '' ),
			'date_only' => ! empty( $w['date_only'] ),
			'due'       => (string) ( $w['due'] ?? '' ),
		);
	}

	$rows = array();
	foreach ( $ripe as $r ) {
		$rows[] = array(
			'id'        => (string) ( $r['id'] ?? '' ),
			'label'     => (string) ( $r['label'] ?? '' ),
			'note'      => (string) ( $r['note'] ?? '' ),
			'read'      => (string) ( $r['read'] ?? '' ),
			'why'       => (string) ( $r['why'] ?? '' ),
			'date_only' => ! empty( $r['date_only'] ),
			'due'       => (string) ( $r['due'] ?? '' ),
		);
	}

	return array(
		'ripe'    => $rows,
		'pending' => $pending,
		'counts'  => array(
			'ripe'    => count( $rows ),
			'pending' => count( $pending ),
			'total'   => count( $all ),
		),
	);
}
