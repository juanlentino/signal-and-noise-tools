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
		'description'         => 'Which notes published in the last two days have ZERO inbound links, and which older notes the daily pass has already judged as ready to link to them. A new note is born unlinked by construction (nothing can link a future post), and on this site every not-indexed note had zero inbound links. Daily, for each new note with no inbound link in the link graph, the pass takes its top related published notes and runs the pair-suggest judgment (older → new); a `link` verdict with a valid anchor is listed here under notes[].pairs[] as outcome:ready with the anchor, and the same verdict is what the link_opportunities worklist reads, so Apply (ai-link-apply, source=older note, target=new note) is one call. state:unavailable means the AI provider did not answer — fail-closed, never zero pairs; artifact:unbuilt on a note means the ML related artifact had not indexed it yet. Read-only over the stored option; never calls the model.',
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
