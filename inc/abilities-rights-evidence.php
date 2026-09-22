<?php
/**
 * Signal & Noise Tools, Abilities API: rights evidence.
 *
 *   - signal-noise/rights-evidence       the stored ledger of monthly records (read)
 *   - signal-noise/rights-evidence-now   compose and post what the last month lacks (write)
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
	wp_register_ability( 'signal-noise/rights-evidence', array(
		'label'               => 'Rights evidence: the monthly records',
		'description'         => 'The site\'s ledger of rights-evidence records: per month, per AI-training crawler family the sensor saw, the record\'s uuid, content hash, status (composed, unanchored, pending, confirmed, conflict), ledger path and the last error. One record per family per month, composed from the edge sensor on the first daily pass after the month closes: the reservation in force (the public ledger\'s rights-signal versions and hashes), every fetch of the rights files by that family, and its crawling per day with the training share. The worker signs and OpenTimestamps-anchors it under `rights-evidence/<uuid>/v1`; `ledger_base` + ledger_path + `.json` is the record, `.ots` its proof. `ready` says whether the worker, its secret and the sensor are configured. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_rights_evidence',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'ready' => array( 'type' => 'boolean' ), 'ledger_base' => array( 'type' => 'string' ), 'months' => array( 'type' => 'object' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
	wp_register_ability( 'signal-noise/rights-evidence-now', array(
		'label'               => 'Rights evidence: compose and post the last month now',
		'description'         => 'Runs the daily pass now: for the last complete month, composes a record per AI-training family the sensor saw and not yet on the ledger, and posts it to the provenance worker. Idempotent: a family already on the ledger is skipped, a composed record that failed to post is re-sent byte-identical. Publishes to a public, append-only ledger: a posted record cannot be edited, only retracted. Two sensor reads and one ledger read; no model call.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_rights_evidence_now',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'month' => array( 'type' => 'string' ), 'composed' => array( 'type' => 'integer' ), 'posted' => array( 'type' => 'integer' ), 'anchored' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => false, 'type' => 'tool' ), 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => true ) ),
	) );
} );

function snt_ability_rights_evidence( $input = array() ) {
	$months = array();
	foreach ( sn_rights_evidence_data() as $month => $families ) {
		foreach ( (array) $families as $family => $e ) {
			$months[ $month ][ $family ] = array_diff_key( (array) $e, array( 'canonical' => 1 ) ); // The bytes stay home; the ledger serves them.
		}
	}
	krsort( $months );
	return array(
		'ok'          => true,
		'ready'       => sn_rights_evidence_is_ready(),
		'ledger_base' => function_exists( 'sn_prov_integrity_ledger_base' ) ? sn_prov_integrity_ledger_base() : '',
		'months'      => (object) $months, // no records yet is {} at the door, never []
		'note'        => 'One record per AI-training family per month. status: composed (bytes stored, not yet posted), unanchored (the post failed; re-sent daily), pending (on the ledger, awaiting the Bitcoin block), confirmed, conflict (the ledger already held other bytes at that path; its record stands, nothing is retried). The record and its .ots proof live at ledger_base + ledger_path.',
	);
}

function snt_ability_rights_evidence_now( $input = array() ) {
	return sn_rights_evidence_run();
}
