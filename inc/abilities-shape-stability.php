<?php
/**
 * Signal & Noise Tools — Abilities API: has a payload's SHAPE settled?
 *
 * The shape ledger (inc/shape-ledger.php, v13.84.0) fingerprints a payload's
 * STRUCTURE — types and keys, never values — and records when it last changed.
 * v13.85.0 gave it an hourly writer. It has had no reader at all:
 * sn_shape_stability() was called from tests and nowhere else, so the one
 * question the module exists to answer was reachable only by `wp eval`.
 *
 * That is the same defect the purge log carried for eighteen versions, in a
 * module built two days earlier — an instrument nobody can read reports to
 * nobody, and the decision it informs falls back to recollection, which is
 * exactly what the ledger replaced.
 *
 * READ-ONLY, and deliberately RECORDS NOTHING. A reader that took its own
 * fingerprint would add a reading every time it was asked, so polling would
 * drive a subject toward "settled" — a diagnostic reacting to the operator,
 * which this codebase spent 2026-09-03 removing from the cache readout.
 *
 * @package SignalNoiseTools
 * @since   13.88.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/shape-stability', array(
		'label'               => 'Payload Shape Stability',
		'description'         => 'Reports whether a payload\'s STRUCTURE has held still long enough to be frozen — types and keys, never values. Call this before shipping a remote MCP twin, or before any change that copies a payload shape somewhere it becomes expensive to alter: a twin copies its origin output_schema byte-identically, so shipping one freezes that shape and changing it afterwards costs a contract bump plus a worker release. `state` is the answer, per subject: `settled` (unchanged across at least SN_SHAPE_STABLE_READINGS readings spanning at least SN_SHAPE_STABLE_DAYS days — safe to freeze), `settling` (recording, thresholds not met — `reason` says which one is short), `unknown` (never recorded, which is an ABSENCE OF EVIDENCE and never a pass). `readings` and `days` are the measured span since the last change; `since` is when the current shape first appeared. READ `ever_changed` BEFORE INTERPRETING `since`: false means `since` is when recording BEGAN for this subject, true means it is when the shape last MOVED — a recent `since` with `ever_changed` true says the payload is still changing, and waiting is not the answer. `changes` carries the recorded history as {at, at_iso, from, to}, the fingerprints turning \'it moved\' into \'this key changed type\'; the ledger caps it at SN_SHAPE_LEDGER_MAX_CHANGES, so an unstable subject keeps its most recent changes rather than all of them. Read-only and records nothing: a reader that fingerprinted the payload would add a reading, so polling would push a subject toward settled on its own. Subjects are recorded by their producers — reader-anomalies records one on the hourly machine-reader snapshot cron.',
		'category'            => 'diagnostics',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_shape_stability',
		'input_schema'        => array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'state'      => array(
					'type'        => 'string',
					'enum'        => array( 'no_subjects', 'recorded' ),
					'description' => 'no_subjects means the ledger holds nothing yet — an absence of evidence, never a pass.',
				),
				'thresholds' => array(
					'type'        => 'object',
					'description' => 'The gate a subject must clear: readings and days. Reported so a caller can see WHY something is still settling without knowing the constants.',
				),
				'subjects'   => array(
					'type'        => 'array',
					'description' => 'One row per recorded subject: subject, state, readings, days, since, since_iso, reason, ever_changed, and changes[] as {at, at_iso, from, to}. ever_changed disambiguates `since`: false = recording began then, true = the shape moved then.',
				),
				'counts'     => array(
					'type'        => 'object',
					'description' => 'settled / settling / unknown totals across subjects.',
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
 * Ability execute callback: signal-noise/shape-stability.
 *
 * @param array|null $input Unused; the ability takes no arguments.
 * @return array|WP_Error
 */
function snt_ability_shape_stability( $input ) {
	unset( $input );

	if ( ! function_exists( 'sn_shape_ledger_subjects' ) || ! function_exists( 'sn_shape_stability' ) ) {
		return new WP_Error(
			'snt_shape_ledger_unavailable',
			'Shape ledger module not loaded.',
			array( 'status' => 500 )
		);
	}

	$now        = time();
	$thresholds = array(
		'readings' => defined( 'SN_SHAPE_STABLE_READINGS' ) ? (int) SN_SHAPE_STABLE_READINGS : 0,
		'days'     => defined( 'SN_SHAPE_STABLE_DAYS' ) ? (int) SN_SHAPE_STABLE_DAYS : 0,
	);

	$subjects = sn_shape_ledger_subjects();
	if ( empty( $subjects ) ) {
		// Nothing recorded is NOT "everything is stable". The ledger's own rule.
		return array(
			'state'      => 'no_subjects',
			'thresholds' => $thresholds,
			'subjects'   => array(),
			'counts'     => array( 'settled' => 0, 'settling' => 0, 'unknown' => 0 ),
		);
	}

	$rows   = array();
	$counts = array( 'settled' => 0, 'settling' => 0, 'unknown' => 0 );

	foreach ( $subjects as $subject ) {
		$v     = sn_shape_stability( $subject, $now );
		$state = (string) ( $v['state'] ?? 'unknown' );
		$since = (int) ( $v['since'] ?? 0 );

		// v13.88.1 — WHAT HAPPENED WHEN IT LAST DID NOT HOLD.
		//
		// v13.88.0 reported how long a shape has held and left this out, so
		// `since` was ambiguous in the one way that matters: a recent `since`
		// is either the clock STARTING (first ever recording) or the countdown
		// RESTARTING (the shape actually moved). Those have opposite meanings
		// for "can I freeze this into a twin" — the second says the payload is
		// still moving and no amount of waiting is the answer.
		//
		// sn_shape_ledger_record() appends to `changes` ONLY on a real change;
		// a first record leaves it empty. So the distinction is exact, and
		// `ever_changed` states it rather than making every caller know the
		// rule. `from`/`to` carry the fingerprints, which is what turns "it
		// moved" into "this key changed type".
		//
		// Capped at SN_SHAPE_LEDGER_MAX_CHANGES by the ledger, so a long-lived
		// unstable subject keeps its most recent changes, never all of them —
		// `ever_changed` stays true regardless, because the list only empties
		// on a subject that has never changed.
		$entry   = function_exists( 'sn_shape_ledger_get' ) ? sn_shape_ledger_get( $subject ) : null;
		$changes = ( is_array( $entry ) && isset( $entry['changes'] ) && is_array( $entry['changes'] ) )
			? $entry['changes']
			: array();

		$change_rows = array();
		foreach ( $changes as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$at = (int) ( $c['at'] ?? 0 );
			$change_rows[] = array(
				'at'     => $at > 0 ? $at : null,
				'at_iso' => $at > 0 ? gmdate( 'c', $at ) : null,
				'from'   => (string) ( $c['from'] ?? '' ),
				'to'     => (string) ( $c['to'] ?? '' ),
			);
		}

		$rows[] = array(
			'subject'      => $subject,
			'state'        => $state,
			'readings'     => (int) ( $v['readings'] ?? 0 ),
			// Rounded for reading; the raw span is derivable from `since`.
			'days'         => round( (float) ( $v['days'] ?? 0.0 ), 2 ),
			'since'        => $since > 0 ? $since : null,
			// ISO beside the epoch because correlating a shape change against a
			// deploy is the point, and nobody should be doing that by eye
			// against a unix timestamp.
			'since_iso'    => $since > 0 ? gmdate( 'c', $since ) : null,
			'reason'       => (string) ( $v['reason'] ?? '' ),
			// The disambiguator: false means `since` is when recording began,
			// true means it is when the shape last moved.
			'ever_changed' => ! empty( $change_rows ),
			'changes'      => $change_rows,
		);

		if ( isset( $counts[ $state ] ) ) {
			++$counts[ $state ];
		}
	}

	return array(
		'state'      => 'recorded',
		'thresholds' => $thresholds,
		'subjects'   => $rows,
		'counts'     => $counts,
	);
}
