<?php
/**
 * Signal & Noise Tools — Abilities API: family-drift (the sn-status source
 * for the `family_drift` section; measurement weave, Phase 5).
 *
 * Reads the STORED report only. Reading a verdict never causes a fetch —
 * the weekly cron owns the run, and an agent asking "did the enums drift?"
 * must not be able to make the origin call three third parties.
 *
 * @package SignalNoiseTools
 * @since 13.62.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Execute callback: signal-noise/family-drift. */
function snt_ability_family_drift( $input ) {
	unset( $input );
	if ( ! function_exists( 'sn_family_drift_report' ) || ! function_exists( 'sn_family_drift_health' ) ) {
		return new WP_Error( 'snt_helper_unavailable', __( 'Family-drift module not loaded.', 'signal-and-noise-tools' ), array( 'status' => 500 ) );
	}
	$r = sn_family_drift_report();
	$h = sn_family_drift_health( $r, time() );
	return array(
		'ok'      => true,
		'status'  => (string) $h['status'],
		'summary' => (string) $h['summary'],
		'last'    => $r['last'],
		'last_ok' => $r['last_ok'],
	);
}

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	wp_register_ability( 'signal-noise/family-drift', array(
		'label'               => 'Crawler-family enum drift (stored report)',
		'description'         => 'Did the hand-maintained crawler-family enum drift? The plugin\'s snt_mr_valid_families() is mirrored by hand in the rights-signals worker, and nothing else checks that the two copies agree or that the enum still matches the world. A weekly run diffs the plugin enum against the DEPLOYED worker\'s (read at its source_commit), and both against two pinned MIT data corpora (monperrus/crawler-user-agents, ai-robots-txt/ai.robots.txt) re-fetched live. Rows: mirror_parity (unequal is CRITICAL), ours_unmatched (families classifying nothing upstream), unobservable (families that CANNOT classify anything by construction and are therefore exempt from ours_unmatched rather than reported as drift — apple-ai, because Applebot-Extended is a robots.txt token that never fetches), upstream_unmapped (upstream tags no family claims; counts, not hits — the ledger has no UA dimension), vendor_gap (AI operators absent from our families), respect_flips and vocabulary changes since the pin. status/summary are the Site Health verdict; last is the latest attempt (may be unavailable — fail-closed on any failed fetch, never an empty diff), last_ok the latest completed report with its computed_at. Read-only over stored options; never fetches.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_family_drift',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'ok'      => array( 'type' => 'boolean' ),
				'status'  => array( 'type' => 'string', 'enum' => array( 'good', 'recommended', 'critical' ) ),
				'summary' => array( 'type' => 'string' ),
				'last'    => array( 'type' => array( 'object', 'null' ) ),
				'last_ok' => array( 'type' => array( 'object', 'null' ) ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'idempotent' => true, 'open_world_hint' => false ),
		),
	) );
} );
