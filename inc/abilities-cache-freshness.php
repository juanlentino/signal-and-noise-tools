<?php
/**
 * Signal & Noise Tools — Abilities API: is the edge serving the current render?
 *
 * WHY THIS EXISTS, and it is not a flattering reason. `snt_cf_freshness_summary()`
 * has had exactly two readers since v11.29.0 — the Classic Admin ops cell and
 * the OpenStation cache tile — and no machine reader at all. Six releases
 * (v13.86.0 through v13.91.1) went into making that readout correct, and every
 * one of them was verified by asking the owner to look at a widget and describe
 * it back.
 *
 * That is the third instrument-with-no-agent-reader in this plugin in a week:
 * the purge log written for eighteen versions and read by nothing, the shape
 * ledger filling for four days with its verdict called only from tests, and
 * this. Each got a reader when somebody asked a question it could not answer —
 * never at review. A surface with two renderers looks finished right up until
 * that question.
 *
 * READ-ONLY over the SAME derive layer both widgets render, so a third surface
 * cannot drift from the other two. It triggers no probe: a scan of the edge is
 * what the post-save probe and the manual purge do, and a reader that measured
 * would change what it reports by being asked.
 *
 * @package SignalNoiseTools
 * @since   13.92.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/cache-freshness', array(
		'label'               => 'Edge Cache Freshness',
		'description'         => 'Reports whether the edge is serving the current render, from the same derive layer the Classic Admin cell and the OpenStation tile render — so all three surfaces cannot disagree. `last` is the verdict of the most recent purge: `fresh` (the edge served the current render), `stale` (it did not), `pending` (a purge fired and its deferred verification has not run yet — an auto purge, which is what a plugin or theme update triggers, writes no verdict until its cron verify lands about 75 seconds later), or `unknown` (no usable report at all). READ `pending` AS A KNOWN STATE, not a failure: a purge demonstrably happened and `last_time` says when. `post_save` counts POST-SAVE probes only — manual purges do not write there, so those figures cannot be moved by pressing Purge, and a rising `stale` there means a per-post purge genuinely failed to clear the edge. `state: never_probed` means no verdict has ever been recorded, which is an absence of evidence and never a clean edge. Read-only; triggers no probe, because a reader that measured would change what it reports by being asked.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_cache_freshness',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'     => array(
					'type'        => 'string',
					'enum'        => array( 'never_probed', 'recorded' ),
					'description' => 'never_probed is an absence of evidence, never a clean edge.',
				),
				'last'      => array(
					'type'        => 'string',
					'enum'        => array( 'fresh', 'stale', 'pending', 'unknown' ),
					'description' => 'The most recent purge verdict. pending means verification is scheduled, not that anything is wrong.',
				),
				'last_time' => array( 'type' => array( 'integer', 'null' ) ),
				'last_iso'  => array( 'type' => array( 'string', 'null' ) ),
				'headline'  => array( 'type' => 'string', 'description' => 'The sentence both widgets show, from the shared producer.' ),
				'phrase'    => array( 'type' => 'string', 'description' => 'The age line both widgets show, from the shared producer.' ),
				'post_save' => array(
					'type'        => 'object',
					'description' => 'probes / stale / escalated over POST-SAVE probes only. Manual purges never write here, so pressing Purge cannot move them.',
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
 * Ability execute callback: signal-noise/cache-freshness.
 *
 * @param array|null $input Unused; the ability takes no arguments.
 * @return array|WP_Error
 */
function snt_ability_cache_freshness( $input ) {
	unset( $input );

	if ( ! function_exists( 'snt_cf_freshness_summary' ) ) {
		return new WP_Error(
			'snt_cache_freshness_unavailable',
			'Purge verification module not loaded.',
			array( 'status' => 500 )
		);
	}

	$sum = snt_cf_freshness_summary();

	// NULL from the summary is "nothing has ever been recorded". Reporting that
	// as a clean edge would be the 2026-08-15 failure exactly: a green readout
	// over a 27-hour-old render.
	if ( ! is_array( $sum ) ) {
		return array(
			'state'     => 'never_probed',
			'last'      => 'unknown',
			'last_time' => null,
			'last_iso'  => null,
			'headline'  => function_exists( 'snt_cf_freshness_headline' ) ? snt_cf_freshness_headline( 'unknown' ) : '',
			'phrase'    => '',
			'post_save' => array( 'probes' => 0, 'stale' => 0, 'escalated' => 0 ),
		);
	}

	$t = (int) ( $sum['last_time'] ?? 0 );

	return array(
		'state'     => 'recorded',
		'last'      => (string) ( $sum['last'] ?? 'unknown' ),
		'last_time' => $t > 0 ? $t : null,
		// ISO beside the epoch, because correlating a verdict against a deploy
		// is the usual question and nobody should do that against a unix stamp.
		'last_iso'  => $t > 0 ? gmdate( 'c', $t ) : null,
		// The words BOTH widgets render, carried so a third surface cannot
		// phrase the same verdict differently.
		'headline'  => (string) ( $sum['headline'] ?? '' ),
		'phrase'    => (string) ( $sum['phrase'] ?? '' ),
		'post_save' => array(
			'probes'    => (int) ( $sum['total'] ?? 0 ),
			'stale'     => (int) ( $sum['stale'] ?? 0 ),
			'escalated' => (int) ( $sum['escalated'] ?? 0 ),
		),
	);
}
