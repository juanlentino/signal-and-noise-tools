<?php
/**
 * Signal & Noise Tools — Abilities API: inbound-pass (the sn-status source
 * for the `inbound_pass` section). Reads the STORED report only; the daily
 * cron owns the model calls.
 *
 * @package SignalNoiseTools
 * @since 13.68.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Execute callback: signal-noise/inbound-pass. */
function snt_ability_inbound_pass( $input ) {
	unset( $input );
	if ( ! function_exists( 'sn_inbound_pass_report' ) || ! function_exists( 'sn_inbound_pass_health' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Inbound-pass module not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$r = sn_inbound_pass_report();
	$h = sn_inbound_pass_health( $r );
	return array(
		'ok'      => true,
		'status'  => (string) $h['status'],
		'summary' => (string) $h['summary'],
		'last'    => $r,
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/inbound-pass', array(
		'label'               => 'Inbound-link pass for new notes (stored report)',
		'description'         => 'Every published note with ZERO inbound links, the older notes already judged ready to link to it, and every scheduled note with its outbound link count. PAST: for each published post with no inbound link in the link graph, however old, the pass takes its top related published notes and runs the pair-suggest judgment (older → new); a link verdict with a valid anchor is listed under notes[].pairs[] as outcome:ready with the anchor — the same verdict the link_opportunities worklist reads, so Apply (ai-link-apply, source=older note, target=the unlinked note) is one call. A per-run pair budget bounds model calls; deferred counts what waits for the next run. PRESENT: a publish schedules a run minutes later. FUTURE: scheduled[] lists future posts with outbound (their note links); a scheduled note with outbound:0 is fixable before it publishes. state:unavailable means the AI provider did not answer — fail-closed, never zero pairs; artifact:unbuilt on a note means the ML related artifact had not indexed it yet. Read-only over the stored option; never calls the model.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_inbound_pass',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'status'  => array( 'type' => 'string', 'enum' => array( 'good', 'recommended' ) ),
				'summary' => array( 'type' => 'string' ),
				'last'    => array( 'type' => array( 'object', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
