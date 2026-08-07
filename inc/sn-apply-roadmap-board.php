<?php
/**
 * Signal & Noise Tools — sn_apply change.type "roadmap_board": the
 * board-as-data write path for the hub-wide maturity roadmap
 * (inc/maturity-roadmap-shortcode.php). Split into its own file per the
 * per-type convention sessions 6c/7 established (sn-apply-create-draft.php,
 * sn-apply-restore-revision.php).
 *
 * WHY THIS EXISTS: the owner's standing rule is that roadmap COPY is
 * content, not code — it should ship without a version bump or a deploy.
 * Before this type, every sentence edit to the board was a PHP edit + a
 * tag + a deploy (v10.56.3 and v10.56.4 were both roadmap-copy releases).
 * This type stores an owner-approved override in an option the shortcode
 * reads (option-canonical when valid; the static PHP array remains the
 * versioned default and disaster-recovery floor).
 *
 * Contract, mirroring the door's existing postures:
 * - PUBLISH-ONLY: an option has no WordPress revision to stage — the same
 *   structural refusal og_card/anchor_sweep use. Combined with gate 3's
 *   identity grant, only the rw door's bound owner credential can execute.
 * - Fingerprint REQUIRED (422 when absent, via array_key_exists — the
 *   codebase's present-vs-empty idiom; 409 when stale): binds to the
 *   CURRENT effective board's hash (sn_maturity_roadmap_board_fingerprint()).
 *   The observe step is a dry_run call itself: gates.fingerprint.observed
 *   always carries the current hash and diff.before the current board, so
 *   a caller needs no separate read tool — deliberate, per the MCP
 *   consolidation program's no-new-tools posture.
 * - Gate 2 delegates to sn_maturity_roadmap_board_problems(): structure
 *   bounds, plain-prose-only, and the banned-token sweep that mirrors the
 *   public page's leak-sweep test — a board that would fail the page's
 *   security contract is refused at the door, never rendered.
 * - payload.reset === true deletes the override (back to code-canonical);
 *   otherwise payload.board is the FULL replacement board (wholesale, like
 *   the option it writes — there is no per-cell patch shape).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate 1 (fingerprint) for roadmap_board — the sentence_replace /
 * restore_revision binding, pointed at the effective board instead of a
 * post's content_hash. Same return shape as every other gate-1 branch.
 *
 * @param array $change The raw change{} input.
 * @return array
 */
function snt_sn_apply_gate1_roadmap_board( array $change ) {
	$observed = sn_maturity_roadmap_board_fingerprint( sn_maturity_roadmap_effective_board() );
	if ( ! array_key_exists( 'fingerprint', $change ) ) {
		return array(
			'passed'       => false,
			'expected'     => null,
			'observed'     => $observed,
			'skipped'      => null,
			'detail'       => 'change.fingerprint is required for roadmap_board: this response\'s gates.fingerprint.observed IS the current fingerprint, and diff.before the current board — re-issue the call with it.',
			'new_content'  => null,
			'error_code'   => 'snt_sn_apply_missing_fingerprint',
			'error_status' => 422,
		);
	}
	$fingerprint = (string) $change['fingerprint'];
	$passed      = hash_equals( $observed, $fingerprint );
	return array(
		'passed'      => $passed,
		'expected'    => $fingerprint,
		'observed'    => $observed,
		'skipped'     => null,
		'detail'      => $passed ? null : 'The roadmap board has changed since this fingerprint was observed (re-run a dry_run to observe the current board and fingerprint, then retry: the stale-branch merge conflict).',
		'new_content' => null,
	);
}

/**
 * Gate 2 (server-side validation) for roadmap_board — every problem the
 * shared validator reports becomes an error-severity finding, which the
 * orchestrator already treats as a blocking gate failure (422). reset:true
 * skips board validation entirely: there is nothing to validate about a
 * deletion, and requiring a valid board alongside reset would make the
 * recovery path depend on the thing being recovered from.
 *
 * @param array $change The raw change{} input.
 * @return array{checks:string[],findings:array}
 */
function snt_sn_apply_gate2_roadmap_board( array $change ) {
	$payload = (array) ( $change['payload'] ?? array() );
	if ( true === ( $payload['reset'] ?? false ) ) {
		return array( 'checks' => array( 'roadmap_board' ), 'findings' => array() );
	}
	$findings = array();
	foreach ( sn_maturity_roadmap_board_problems( $payload['board'] ?? null ) as $problem ) {
		$findings[] = array(
			'check'    => 'roadmap_board',
			'severity' => 'error',
			'message'  => $problem,
		);
	}
	return array( 'checks' => array( 'roadmap_board' ), 'findings' => $findings );
}

/**
 * The dry_run / gate-failure diff for roadmap_board: before is ALWAYS the
 * current effective board (this is the type's read surface — see the file
 * docblock), after is the proposed board (null on reset, mirroring the
 * deletion it previews).
 *
 * @param array $change The raw change{} input.
 * @return array{before:mixed,after:mixed,blocks_touched:int}
 */
function snt_sn_apply_roadmap_board_diff( array $change ) {
	$payload = (array) ( $change['payload'] ?? array() );
	$after   = true === ( $payload['reset'] ?? false ) ? null : ( $payload['board'] ?? null );
	return array(
		'before'         => sn_maturity_roadmap_effective_board(),
		'after'          => $after,
		'blocks_touched' => 0,
	);
}

/**
 * The write: option update (or delete, on reset). Gates 1-3 have already
 * passed when this runs; the payload's board re-validates once more as a
 * belt (a TOCTOU-shaped defense — gate 2 ran on the same request, but this
 * write refuses rather than trusts). autoload 'no': the option is read
 * only when the roadmap page renders.
 *
 * @param array $payload The change.payload input.
 * @return array{ok:bool,diff:array,revision_id:null,write_result:array}|WP_Error
 */
function snt_sn_apply_write_roadmap_board( array $payload ) {
	$before = sn_maturity_roadmap_effective_board();

	if ( true === ( $payload['reset'] ?? false ) ) {
		delete_option( SN_MATURITY_ROADMAP_OPTION );
		return array(
			'ok'           => true,
			'diff'         => array( 'before' => $before, 'after' => sn_maturity_roadmap_static_board(), 'blocks_touched' => 0 ),
			'revision_id'  => null,
			'write_result' => array( 'ok' => true, 'reset' => true ),
		);
	}

	$board = $payload['board'] ?? null;
	if ( array() !== sn_maturity_roadmap_board_problems( $board ) ) {
		return new WP_Error( 'snt_sn_apply_roadmap_board_invalid', __( 'payload.board failed validation at the write step.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	update_option( SN_MATURITY_ROADMAP_OPTION, $board, false );
	return array(
		'ok'           => true,
		'diff'         => array( 'before' => $before, 'after' => $board, 'blocks_touched' => 0 ),
		'revision_id'  => null,
		'write_result' => array( 'ok' => true, 'reset' => false, 'families' => count( (array) $board ) ),
	);
}
