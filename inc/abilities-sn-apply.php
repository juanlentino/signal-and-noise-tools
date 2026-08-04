<?php
/**
 * Signal & Noise Tools — Abilities API: sn_apply (MCP consolidation,
 * session 6b). The consolidated write tool — the only tool on the surface
 * that mutates post content, per docs/mcp-consolidation/SN-MCP-new/sn-apply-spec.md.
 *
 * Registered NEW alongside every ability it absorbs (block-migrations-apply,
 * pattern-adoption-apply, ai-alt-apply, ai-drift-apply, ai-link-apply,
 * update-post-surfaces, regenerate-og-card, anchor-sweep) — nothing below
 * this file was touched, unregistered, or deleted (rw door 35 -> 36).
 *
 * Four gates run in this exact order, EVERY one reporting {passed,...} in
 * the response even when an earlier gate already failed:
 *   1. fingerprint  (inc/sn-apply-validation.php)
 *   2. validation   (inc/sn-apply-validation.php, calls sn_validate's
 *                     internal check functions directly)
 *   3. capability   (inc/sn-apply-gates.php)
 *   4. idempotency  (inc/sn-apply-gates.php)
 *
 * dry_run defaults to TRUE per the spec's exact signature. A dry run still
 * runs all four gates and produces the diff, but performs ZERO writes — see
 * tests/abilities-sn-apply.php's DB-verified zero-writes guard (acceptance
 * test 1).
 *
 * Refusals return WP_Error with array('status'=>N) — the EXISTING
 * inc/mcp/mcp-tools.php plumbing (sn_mcp_call_tool(), unmodified by this
 * session) already turns any ability-level WP_Error into an isError:true
 * tool result, never a JSON-RPC protocol error, and already calls
 * sn_mcp_rw_audit_record() for EVERY rw-door outcome (ok/error/denied) —
 * this ability does not reimplement that. It rides an ADDITIONAL, explicit
 * sn_mcp_rw_audit_record() call of its own (see
 * snt_sn_apply_audit_enrichment() below) purely to widen what gets
 * captured: gate outcomes and revision_id are OUTPUT, not input, so the
 * door's own automatic call (which redacts the raw request $args) can never
 * see them. Two audit rows per call is the accepted cost of that — both ride
 * the exact same existing rails function, sn_mcp_rw_audit_record().
 *
 * @package SignalNoiseTools
 * @since 10.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_init', function() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability( 'signal-noise/sn-apply', array(
		'label'               => 'Apply a change to a post (consolidated write tool)',
		'description'         => 'The only tool that mutates post content. Four gates run in order — fingerprint, server-side validation, mode capability, idempotency — every one reported in the response even when an earlier gate already failed. dry_run defaults to TRUE: a caller has to actively ask to write. mode:"revision" stages a WordPress revision without touching the live post (the PR pattern); mode:"publish" writes live. Routine (non-owner) credentials are granted "revision" only — enforced server-side against the calling identity, never a client-chosen parameter. change.type "og_card" (regenerates a PNG file, not a post field) and "anchor_sweep" (dispatches a live HTTP call to the provenance Worker, no post entity involved) are PUBLISH-ONLY — mode:"revision" refuses both explicitly rather than fabricating a staged version of a side effect that cannot be staged. change.type "create_draft" is the mirror image: REVISION-ONLY — mode:"publish" refuses explicitly, because this tool never makes a draft live; the owner schedules drafts by hand. Under mode:"revision", a real draft post IS created (never published); there is no no-op staging for a nonexistent post. Its target is {new_post:true} (no id — the post does not exist yet) and its payload is {title, content (Gutenberg block markup), excerpt?, tags? (existing vocabulary only)}. target may be a single object or an array (batch): per-post writes are atomic, across posts they are independent — one target failing never rolls back another.',
		'category'            => 'tools',
		'permission_callback' => 'snt_ability_perm_manage_options',
		'execute_callback'    => 'snt_ability_sn_apply',
		'input_schema'        => array(
			'type'                 => 'object',
			'required'             => array( 'target', 'change', 'mode' ),
			'properties'           => array(
				'target'          => array(
					'oneOf' => array(
						array(
							'type'       => 'object',
							'properties' => array(
								'post_id'       => array( 'type' => 'integer', 'minimum' => 1 ),
								'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
								'scope'         => array( 'type' => 'string', 'enum' => array( 'provenance_anchors' ) ),
								// session 6c: create_draft's target -- the post doesn't exist yet, so
								// there is no id to carry. Runtime enforces === true (not just
								// truthy) in snt_sn_apply_resolve_target(), same posture as scope's enum above.
								'new_post'      => array( 'type' => 'boolean' ),
							),
						),
						array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'change'          => array(
					'type'       => 'object',
					'required'   => array( 'type' ),
					'properties' => array(
						'type'         => array( 'type' => 'string', 'enum' => SNT_SN_APPLY_CHANGE_TYPES ),
						'payload'      => array( 'type' => 'object' ),
						'candidate_id' => array( 'type' => 'string' ),
						'fingerprint'  => array( 'type' => 'string' ),
					),
				),
				'mode'            => array( 'type' => 'string', 'enum' => array( 'revision', 'publish' ) ),
				'dry_run'         => array( 'type' => 'boolean', 'default' => true ),
				'idempotency_key' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'applied'         => array( 'type' => 'boolean' ),
				'mode'            => array( 'type' => 'string' ),
				'change_type'     => array( 'type' => 'string' ),
				'gates'           => array( 'type' => 'object' ),
				'diff'            => array( 'type' => array( 'object', 'null' ) ),
				'revision_id'     => array( 'type' => array( 'integer', 'null' ) ),
				'rollback'        => array( 'type' => array( 'object', 'null' ) ),
				'replayed'        => array( 'type' => 'boolean' ),
			),
		),
		'meta'                => array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => true, // conservative reading: mode:"publish" overwrites live content.
				'idempotent'  => true, // earned by the idempotency_key gate.
			),
		),
	) );
} );

/**
 * Is $value a JSON-array-shaped batch of targets (vs. a single target
 * object)? array_is_list() is the exact discriminator: a decoded JSON
 * object never produces a 0-based sequential-int-key PHP array unless it
 * used numeric-string keys (not a real target shape), so this is not a
 * heuristic.
 *
 * @param mixed $value
 * @return bool
 */
function snt_sn_apply_is_batch_target( $value ) {
	return is_array( $value ) && array_is_list( $value );
}

/**
 * Ability execute callback: signal-noise/sn-apply.
 *
 * @param array|null $input
 * @return array|WP_Error
 */
function snt_ability_sn_apply( $input ) {
	$input = is_array( $input ) ? $input : array();

	$change = is_array( $input['change'] ?? null ) ? $input['change'] : array();
	$type   = (string) ( $change['type'] ?? '' );
	if ( ! in_array( $type, SNT_SN_APPLY_CHANGE_TYPES, true ) ) {
		return new WP_Error( 'snt_sn_apply_bad_change_type', sprintf( 'change.type must be one of: %s.', implode( ', ', SNT_SN_APPLY_CHANGE_TYPES ) ), array( 'status' => 422 ) );
	}

	$mode = (string) ( $input['mode'] ?? '' );
	if ( ! in_array( $mode, array( 'revision', 'publish' ), true ) ) {
		return new WP_Error( 'snt_sn_apply_bad_mode', 'mode must be "revision" or "publish".', array( 'status' => 422 ) );
	}

	$dry_run         = array_key_exists( 'dry_run', $input ) ? (bool) $input['dry_run'] : true;
	$idempotency_key = (string) ( $input['idempotency_key'] ?? '' );

	$raw_target       = $input['target'] ?? array();
	$canonical_target = snt_sn_apply_canonical_target( $raw_target, $change );

	// Gate 4, replay shortcut: a genuine second call with the SAME (key,
	// target) pair returns the FIRST call's response verbatim — no gate
	// recomputation, no re-execution. TARGET-SCOPED (review HIGH,
	// v10.40.0): the same key on a DIFFERENT target derives a different
	// store key and falls through to a fresh execution — idempotency
	// protects the retry of the same logical call, never a cross-target
	// dedupe. Everything below this block only runs for a fresh
	// (key, target) pair (or no key at all).
	$idem = snt_sn_apply_gate_idempotency( $idempotency_key, $canonical_target );
	if ( is_array( $idem['target_mismatch'] ?? null ) ) {
		// Belt-and-braces: the stored row was executed against a DIFFERENT
		// target than this request's (impossible under the current key
		// derivation; defends against a future derivation change). Refuse
		// loudly, naming both, rather than replaying the wrong target's
		// result.
		return new WP_Error(
			'snt_sn_apply_idempotency_target_mismatch',
			sprintf(
				'idempotency_key "%s" was previously used against target %s but this request is for target %s. Use a fresh idempotency_key per logical call.',
				$idempotency_key,
				$idem['target_mismatch']['stored'],
				$idem['target_mismatch']['requested']
			),
			array( 'status' => 409 )
		);
	}
	if ( is_array( $idem['replay'] ) ) {
		$replay              = $idem['replay'];
		$replay['replayed']  = true;
		snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, $change, $replay, true );
		return $replay;
	}

	$is_batch = snt_sn_apply_is_batch_target( $raw_target );
	$targets  = $is_batch ? $raw_target : array( $raw_target );

	$results = array();
	foreach ( $targets as $raw_one_target ) {
		$results[] = snt_sn_apply_apply_one( $type, $raw_one_target, $change, $mode, $dry_run );
	}

	if ( $is_batch ) {
		$applied_count = 0;
		$failed_count  = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['applied'] ) ) {
				$applied_count++;
			} elseif ( ! empty( $r['error'] ) ) {
				$failed_count++;
			}
		}
		$response = array(
			'batch'       => true,
			'change_type' => $type,
			'mode'        => $mode,
			'dry_run'     => $dry_run,
			'results'     => $results,
			'summary'     => array(
				'total'   => count( $results ),
				'applied' => $applied_count,
				'failed'  => $failed_count,
			),
			'replayed'    => false,
		);
	} else {
		$response             = $results[0];
		$response['replayed'] = false;

		// Single target only: a gate refusal or write failure (never a
		// dry_run PREVIEW, which never sets 'error') must surface as the
		// ability's own WP_Error return — this is what turns into an
		// isError:true tool result at the MCP layer (unmodified plumbing,
		// see this file's docblock). Batch results stay plain arrays; one
		// target's failure never aborts or fails the whole ability call.
		if ( isset( $response['error'] ) ) {
			snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, $change, $response, false );
			return new WP_Error(
				(string) $response['error']['code'],
				(string) wp_json_encode( $response ),
				array( 'status' => (int) $response['error']['status'] )
			);
		}
	}

	snt_sn_apply_idempotency_record( $idempotency_key, $canonical_target, $response );
	snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, $change, $response, false );

	return $response;
}

/**
 * Run all four gates + (if warranted) the write for ONE target. Never
 * throws; every failure path is expressed in the returned array's own
 * `error` key (batch semantics: one target's failure must not abort the
 * loop in snt_ability_sn_apply()) — the single-target caller then decides
 * whether to surface it as the ability's own WP_Error return.
 *
 * @param string $type
 * @param mixed  $raw_target
 * @param array  $change
 * @param string $mode
 * @param bool   $dry_run
 * @return array The full per-target response shape (spec's return shape).
 */
function snt_sn_apply_apply_one( $type, $raw_target, array $change, $mode, $dry_run ) {
	$candidate_id = isset( $change['candidate_id'] ) ? (string) $change['candidate_id'] : null;

	$resolved = snt_sn_apply_resolve_target( $type, $raw_target );
	if ( is_wp_error( $resolved ) ) {
		return snt_sn_apply_target_error_response( $type, $mode, $raw_target, $candidate_id, $resolved );
	}

	$gate1 = snt_sn_apply_gate1_fingerprint( $type, $resolved, $change );
	$gate2 = snt_sn_apply_gate2_validation( $type, $resolved, $change, $gate1['new_content'] ?? null );
	$gate3 = snt_sn_apply_gate_capability( $type, $mode );

	$has_error_finding = false;
	foreach ( $gate2['findings'] as $f ) {
		if ( 'error' === ( $f['severity'] ?? '' ) ) {
			$has_error_finding = true;
			break;
		}
	}
	$gate2_passed = ! $has_error_finding;

	$gates = array(
		'fingerprint' => array(
			'passed'   => $gate1['passed'],
			'expected' => $gate1['expected'],
			'observed' => $gate1['observed'],
			'skipped'  => $gate1['skipped'],
			'detail'   => $gate1['detail'],
		),
		'validation'  => array(
			'passed'   => $gate2_passed,
			'findings' => $gate2['findings'],
			'checks'   => $gate2['checks'],
			'skipped'  => empty( $gate2['checks'] ) ? 'no_applicable_checks' : null,
		),
		'capability'  => array(
			'passed'         => $gate3['passed'],
			'granted_modes'  => $gate3['granted_modes'],
			'mode_supported' => $gate3['mode_supported'],
			'reason'         => $gate3['reason'],
		),
		'idempotency' => array( 'passed' => true, 'first_seen' => null ),
	);

	$all_passed = $gate1['passed'] && $gate2_passed && $gate3['passed'];

	$response = array(
		'applied'      => false,
		'mode'         => $mode,
		'target'       => $raw_target,
		'change_type'  => $type,
		'candidate_id' => $candidate_id,
		'gates'        => $gates,
		'diff'         => null,
		'revision_id'  => null,
		'rollback'     => null,
	);

	if ( ! $all_passed ) {
		$response['diff']  = snt_sn_apply_dry_run_diff( $type, $resolved, $change, $gate1 );
		$response['error'] = array(
			'code'    => ! $gate1['passed'] ? 'snt_sn_apply_fingerprint_stale' : ( ! $gate2_passed ? 'snt_sn_apply_validation_failed' : 'snt_sn_apply_mode_not_granted' ),
			'status'  => ! $gate1['passed'] ? 409 : ( ! $gate2_passed ? 422 : 403 ),
		);
		return $response;
	}

	if ( $dry_run ) {
		$response['diff'] = snt_sn_apply_dry_run_diff( $type, $resolved, $change, $gate1 );
		return $response;
	}

	$write = snt_sn_apply_execute_write( $type, $resolved, $change, $mode );
	if ( is_wp_error( $write ) ) {
		$response['diff']  = snt_sn_apply_dry_run_diff( $type, $resolved, $change, $gate1 );
		$response['error'] = array( 'code' => $write->get_error_code(), 'status' => (int) ( $write->get_error_data()['status'] ?? 500 ) );
		return $response;
	}

	$response['applied']     = true;
	$response['diff']        = $write['diff'];
	$response['revision_id'] = $write['revision_id'];
	if ( 'revision' === $mode && $write['revision_id'] ) {
		$response['rollback'] = array( 'method' => 'restore_revision', 'revision_id' => $write['revision_id'] );
	} elseif ( 'create_draft' === $type && ! empty( $write['write_result']['post_id'] ) ) {
		// create_draft's own rollback shape (session 6c) -- there is no
		// revision_id (it never routes through snt_sn_apply_stage_revision()),
		// so the generic revision-mode branch above never fires for it. A
		// draft delete is trash, reversible -- the same "nothing is final
		// yet" posture mode:"revision" promises everywhere else in this tool.
		$response['rollback'] = array( 'method' => 'delete_draft', 'post_id' => (int) $write['write_result']['post_id'] );
	}
	return $response;
}

/**
 * A target that failed to resolve (404/422) never reaches gates 1-3 (there
 * is nothing to check a fingerprint or run a capability check AGAINST) —
 * still reports all four gates, all `skipped`, so a batch caller sees a
 * consistent shape across every result regardless of WHERE a target failed.
 *
 * @param string   $type
 * @param string   $mode
 * @param mixed    $raw_target
 * @param string|null $candidate_id
 * @param WP_Error $resolved_error
 * @return array
 */
function snt_sn_apply_target_error_response( $type, $mode, $raw_target, $candidate_id, WP_Error $resolved_error ) {
	return array(
		'applied'      => false,
		'mode'         => $mode,
		'target'       => $raw_target,
		'change_type'  => $type,
		'candidate_id' => $candidate_id,
		'gates'        => array(
			'fingerprint' => array( 'passed' => false, 'expected' => null, 'observed' => null, 'skipped' => 'target_not_resolved', 'detail' => null ),
			'validation'  => array( 'passed' => false, 'findings' => array(), 'checks' => array(), 'skipped' => 'target_not_resolved' ),
			'capability'  => array( 'passed' => false, 'granted_modes' => array(), 'mode_supported' => false, 'reason' => 'target_not_resolved' ),
			'idempotency' => array( 'passed' => true, 'first_seen' => null ),
		),
		'diff'         => null,
		'revision_id'  => null,
		'rollback'     => null,
		'error'        => array(
			'code'   => $resolved_error->get_error_code(),
			'status' => (int) ( $resolved_error->get_error_data()['status'] ?? 404 ),
		),
	);
}

/**
 * The additional, enrichment-only sn_mcp_rw_audit_record() call — see the
 * file docblock's "two audit rows per call" note. Flattens exactly the
 * scalar fields sn_mcp_rw_audit_safe_args() now allowlists for this ability
 * (change_type/mode/dry_run/candidate_id/idempotency_key/applied/
 * revision_id/gate_*_passed) — never the full response object, which can
 * carry arbitrary proposed content (payload text) that must never reach the
 * audit log per the door's own default-drop redaction contract.
 *
 * @param string     $type
 * @param string     $mode
 * @param bool       $dry_run
 * @param array      $change
 * @param array      $response
 * @param bool       $replayed
 * @return void
 */
function snt_sn_apply_audit_enrichment( $type, $mode, $dry_run, array $change, array $response, $replayed ) {
	if ( ! function_exists( 'sn_mcp_rw_audit_record' ) ) {
		return;
	}

	$is_batch = ! empty( $response['batch'] );
	$applied  = $is_batch ? ( (int) ( $response['summary']['applied'] ?? 0 ) > 0 ) : (bool) ( $response['applied'] ?? false );
	$gates    = $is_batch ? ( $response['results'][0]['gates'] ?? array() ) : ( $response['gates'] ?? array() );

	$args = array(
		'change_type'             => $type,
		'mode'                    => $mode,
		'dry_run'                 => $dry_run,
		'candidate_id'            => isset( $change['candidate_id'] ) ? (string) $change['candidate_id'] : '',
		'applied'                 => $applied,
		'replayed'                => (bool) $replayed,
		'revision_id'             => $is_batch ? null : ( $response['revision_id'] ?? null ),
		'gate_fingerprint_passed' => (bool) ( $gates['fingerprint']['passed'] ?? false ),
		'gate_validation_passed'  => (bool) ( $gates['validation']['passed'] ?? false ),
		'gate_capability_passed'  => (bool) ( $gates['capability']['passed'] ?? false ),
		'gate_idempotency_passed' => (bool) ( $gates['idempotency']['passed'] ?? true ),
	);

	$outcome = $applied || $dry_run ? 'ok' : 'error';
	sn_mcp_rw_audit_record( 'signal-noise/sn-apply', $args, $outcome );
}
