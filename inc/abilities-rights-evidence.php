<?php
/**
 * Signal & Noise Tools, Abilities API: rights evidence.
 *
 *   - signal-noise/rights-evidence       the stored ledger of monthly records (read)
 *   - signal-noise/rights-evidence-now   run the pass: compose for review, post what is past its window (write)
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
		'description'         => 'The site\'s ledger of rights-evidence records: per month, per AI-training crawler family the sensor saw, the record\'s uuid, content hash, status (composed, unanchored, pending, confirmed, conflict, refused, retracted), ledger path and the last error; a composed record carries review_until (no pass posts it more than an hour before then) and summary (its reads and train counts, kept after posting for the jump rule the next month applies); a refused record carries the worker\'s divergences; a retracted record also carries retraction_path and retraction_hash, the signed retraction the worker committed beside it. hold_reasons says why a rule held a month (a compose or ledger-walk error, a train count that moved more than 3x against the previous month, a worker refusal); in_review lists composed, unposted, unheld months with the time they may post. One record per family per month, composed from the edge sensor on the first daily pass after the month closes and posted by a later pass once its 72-hour review window has passed: the reservation in force (every rights-signal version in force during the month, from its Bitcoin anchor to the next one), that family\'s fetches of the rights files split into training reads and retrieval reads, and its crawling per day with the training share (schema 2). The worker signs and OpenTimestamps-anchors it under `rights-evidence/<uuid>/v1`; `ledger_base` + ledger_path + `.json` is the record, `.ots` its proof. `ready` says whether the worker, its secret and the sensor are configured. Read-only.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_rights_evidence',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'ready' => array( 'type' => 'boolean' ), 'ledger_base' => array( 'type' => 'string' ), 'months' => array( 'type' => 'object' ), 'hold' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'backlog' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'hold_reasons' => array( 'type' => 'object' ), 'in_review' => array( 'type' => 'object' ), 'note' => array( 'type' => 'string' ) ) ),
		'meta'                => array( 'show_in_rest' => true, 'mcp' => array( 'public' => true, 'type' => 'tool' ), 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'open_world_hint' => false ) ),
	) );
	wp_register_ability( 'signal-noise/rights-evidence-now', array(
		'label'               => 'Rights evidence: run the daily pass now',
		'description'         => 'Runs the daily pass now: for the oldest lifted month in the backlog, else the last complete month, refreshes stored records from the ledger and composes a record per AI-training family the sensor saw and not yet stored, with a 72-hour review window. It posts to the provenance worker only what is composed, past its review window and not held: a month composed by this call posts on a later pass, never now (Post now on Monitoring > Machine Readers is the owner\'s bypass). A held month composes (so its payloads can be viewed) and is queued, but never posts. Idempotent: a family already on the ledger is skipped, a composed record that failed to post is re-sent byte-identical. Publishes to a public, append-only ledger: a posted record cannot be edited, only retracted. One aggregate read, one filtered rights read per family and the ledger\'s rights-signal history; stored records are first refreshed from the ledger. No model call.',
		'category'            => 'maintenance',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_rights_evidence_now',
		'input_schema'        => array( 'type' => array( 'object', 'null' ), 'properties' => array(), 'additionalProperties' => false ),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ), 'month' => array( 'type' => 'string' ), 'composed' => array( 'type' => 'integer' ), 'posted' => array( 'type' => 'integer' ), 'anchored' => array( 'type' => 'integer' ), 'failed' => array( 'type' => 'integer' ), 'refused' => array( 'type' => 'integer' ), 'in_review' => array( 'type' => 'integer' ), 'error' => array( 'type' => 'string' ) ) ),
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
		'hold'        => sn_rights_evidence_held( false ), // months the pass refuses to compose or post
		'backlog'     => sn_rights_evidence_backlog(), // months skipped while held, composed once lifted
		'hold_reasons' => (object) array_intersect_key( sn_rights_evidence_hold_reasons(), array_flip( sn_rights_evidence_held( false ) ) ), // why a rule holds a month now; none for an owner's hold
		'in_review'   => (object) array_map( static fn( $t ) => $t ? gmdate( 'c', $t ) : '', sn_rights_evidence_pending() ), // composed, unposted, unheld: when each may post
		'note'        => 'One record per AI-training family per month. status: composed (bytes stored, not yet posted; posted by a pass within an hour before review_until or later, unless the month is held), unanchored (the post failed; re-sent daily), refused (the worker answered 422; the bytes were dropped, the month held with the divergences as its reason, and the next pass recomposes; never re-sent), pending (on the ledger, awaiting the Bitcoin block), confirmed, conflict (the ledger already held other bytes at that path; its record stands, nothing is retried), retracted (the owner posted a signed retraction; the record\'s bytes stay on the ledger, retraction_path names the retraction under ledger_base; final, never re-read). The record and its .ots proof live at ledger_base + ledger_path. hold: months the pass composes but never posts (the owner\'s Hold, or a rule: hold_reasons says which); backlog: held months, worked first once lifted (within the sensor\'s 90-day window for composing; stored bytes post at any age).',
	);
}

function snt_ability_rights_evidence_now( $input = array() ) {
	return sn_rights_evidence_run();
}
