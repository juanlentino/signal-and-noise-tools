<?php
/**
 * Shared assertions for the sn_apply response envelope.
 *
 * WHY THIS EXISTS. v13.95.1 fixed a refused batch that read as a benign
 * success: the fingerprint gate reported failure with `expected` and `observed`
 * IDENTICAL, and the diff carried `changes_applied: 2` with
 * `ledger_impact: "coalesces"` on a batch that applied nothing. The write was
 * correctly refused and nothing was written. Only the READOUT was wrong.
 *
 * Every assertion in the suites at that time checked the refusal CODE. None
 * checked the shape of the envelope carrying it, so a correct refusal wearing a
 * successful-looking readout passed cleanly. These helpers are that missing
 * check, in one place, so a second door cannot re-learn the lesson privately.
 *
 * NOT SWEPT. tests/run.sh globs tests/*.php non-recursively; this file lives in
 * tests/lib/ deliberately. It defines functions and exits 0 in silence unless
 * invoked with --self-test.
 *
 * USAGE. Suites own their own ok()/eq(); this file borrows them rather than
 * redefining, so a failure is reported and counted by the host suite:
 *
 *   require_once __DIR__ . '/lib/assert-envelope.php';
 *   snt_test_envelope_refusal( $r, 'snt_sn_apply_changes_conflict', 'ok', 'eq', 'BATCH' );
 *
 * THE SHAPE, derived from inc/abilities-sn-apply.php rather than assumed:
 *
 *   single target, refused  -> WP_Error( code, wp_json_encode( envelope ), [status] )
 *   batch target, refused   -> plain array inside results[], carrying ['error']
 *   dry run, passed         -> applied:false, diff populated, NO 'error' key
 *   applied                 -> applied:true, diff populated, NO 'error' key
 *
 * A dry run is NOT a refusal. Both have applied:false; only one has 'error'.
 * Conflating them is how a preview gets read as a rejection.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

/**
 * Coerce any sn_apply return into the envelope array.
 *
 * Handles both refusal paths because the ability genuinely has two: a single
 * target's refusal becomes a WP_Error whose MESSAGE is the JSON envelope, while
 * a batch target's stays a plain array so one target's failure cannot abort the
 * loop. A helper that understood only one would silently skip the other.
 *
 * @param mixed $r Return value from snt_ability_sn_apply().
 * @return array|null Envelope, or null if it could not be read.
 */
function snt_test_envelope( $r ) {
	if ( is_array( $r ) ) {
		return $r;
	}
	if ( is_object( $r ) && method_exists( $r, 'get_error_message' ) ) {
		$decoded = json_decode( (string) $r->get_error_message(), true );
		return is_array( $decoded ) ? $decoded : null;
	}
	return null;
}

/**
 * Is this envelope a batch diff? Derived from the payload, not from a flag the
 * caller passes: only batch diffs carry changes_requested (v13.95.1).
 *
 * @param array $env
 * @return bool
 */
function snt_test_envelope_is_batch_diff( array $env ) {
	return is_array( $env['diff'] ?? null ) && array_key_exists( 'changes_requested', $env['diff'] );
}

/**
 * Pin the REFUSAL contract.
 *
 * @param mixed    $r        Return value from snt_ability_sn_apply().
 * @param string   $code     Expected error code.
 * @param callable $ok       Host suite's ok().
 * @param callable $eq       Host suite's eq().
 * @param string   $label    Prefix for assertion messages.
 * @return void
 */
function snt_test_envelope_refusal( $r, $code, $ok, $eq, $label ) {
	$env = snt_test_envelope( $r );
	call_user_func( $ok, is_array( $env ), "$label: the refusal carries a readable envelope" );
	if ( ! is_array( $env ) ) {
		return;
	}

	call_user_func( $eq, false, $env['applied'] ?? null, "$label: applied is false" );
	call_user_func( $ok, isset( $env['error'] ), "$label: the envelope carries an 'error' key — this is what separates a refusal from a dry-run preview" );
	call_user_func( $eq, $code, $env['error']['code'] ?? null, "$label: error.code" );
	call_user_func( $ok, (int) ( $env['error']['status'] ?? 0 ) >= 400, "$label: error.status is an HTTP error code (" . ( $env['error']['status'] ?? 'missing' ) . ')' );

	// ── v13.95.1, defect 1 ────────────────────────────────────────────────
	// A plan failure is not a fingerprint failure. If the gate reports the SAME
	// hash for expected and observed, nothing about the hash was wrong, so it
	// must not be the gate reported as failed — that reading sends a caller to
	// re-fetch a value that already matched.
	$fp       = is_array( $env['gates']['fingerprint'] ?? null ) ? $env['gates']['fingerprint'] : array();
	$expected = $fp['expected'] ?? null;
	$observed = $fp['observed'] ?? null;
	if ( null !== $expected && '' !== $expected && $expected === $observed ) {
		call_user_func(
			$eq,
			true,
			$fp['passed'] ?? null,
			"$label: fingerprint.passed is TRUE when expected === observed — a plan failure is not a fingerprint failure (v13.95.1)"
		);
	}

	// ── v13.95.1, defect 2 ────────────────────────────────────────────────
	// A refused batch applied nothing, so its diff must not describe work. The
	// old code resolved `after` as `new_content ?? before`, diffing the post
	// against itself and yielding ledger_impact "coalesces" — the reading that
	// means "applied, no new version" — beside a changes_applied taken from the
	// REQUESTED count.
	if ( snt_test_envelope_is_batch_diff( $env ) ) {
		$diff = $env['diff'];
		call_user_func( $eq, 0, $diff['changes_applied'] ?? -1, "$label: diff.changes_applied is 0 on a refusal" );
		call_user_func( $ok, array_key_exists( 'after', $diff ) && null === $diff['after'], "$label: diff.after is NULL, not a copy of before" );
		call_user_func( $ok, array_key_exists( 'ledger_impact', $diff ) && null === $diff['ledger_impact'], "$label: diff.ledger_impact is NULL, never 'coalesces'" );
		call_user_func( $ok, array_key_exists( 'changes_requested', $diff ), "$label: diff.changes_requested names what was ASKED for, kept distinct from what was applied" );
	}
}

/**
 * Pin a DRY RUN. Not a refusal: applied is false, but there is no error.
 *
 * @param mixed    $r
 * @param callable $ok
 * @param callable $eq
 * @param string   $label
 * @return void
 */
function snt_test_envelope_dry_run( $r, $ok, $eq, $label ) {
	$env = snt_test_envelope( $r );
	call_user_func( $ok, is_array( $env ), "$label: dry run returns a readable envelope" );
	if ( ! is_array( $env ) ) {
		return;
	}
	call_user_func( $eq, false, $env['applied'] ?? null, "$label: dry run has applied:false" );
	call_user_func( $ok, ! isset( $env['error'] ), "$label: a dry run carries NO error key — a preview is not a rejection" );
	call_user_func( $ok, is_array( $env['diff'] ?? null ), "$label: dry run carries a diff to review" );
}

/**
 * Pin an APPLIED write.
 *
 * @param mixed    $r
 * @param callable $ok
 * @param callable $eq
 * @param string   $label
 * @return void
 */
function snt_test_envelope_applied( $r, $ok, $eq, $label ) {
	$env = snt_test_envelope( $r );
	call_user_func( $ok, is_array( $env ), "$label: applied write returns a readable envelope" );
	if ( ! is_array( $env ) ) {
		return;
	}
	call_user_func( $eq, true, $env['applied'] ?? null, "$label: applied is true" );
	call_user_func( $ok, ! isset( $env['error'] ), "$label: an applied write carries no error key" );
	call_user_func( $ok, is_array( $env['diff'] ?? null ), "$label: applied write carries a diff" );
}

/**
 * ONE post write per applied content change.
 *
 * The count is the claim. Asserting the final content is right would pass just
 * as well on three writes — and for a Note, three writes is three anchored
 * provenance versions, which is the whole reason batching exists.
 *
 * @param array    $write_calls Host suite's write counter, e.g. $GLOBALS['__write_calls'].
 * @param callable $eq
 * @param string   $label
 * @param int      $expected    Defaults to 1.
 * @return void
 */
function snt_test_one_write( array $write_calls, $eq, $label, $expected = 1 ) {
	call_user_func(
		$eq,
		$expected,
		(int) ( $write_calls['wp_update_post'] ?? -1 ),
		"$label: exactly $expected wp_update_post — the write COUNT is the claim, not the resulting content"
	);
}

/* ════════════════════════════════════════════════════════════════════════
 * --self-test
 *
 * Must REJECT the known-bad v13.95.1 envelope and ACCEPT a good one. Without
 * the flag this file defines functions and exits 0 with no output, so a suite
 * that requires it sees nothing.
 * ════════════════════════════════════════════════════════════════════════ */
if ( PHP_SAPI === 'cli' && in_array( '--self-test', (array) ( $argv ?? array() ), true ) ) {

	$st_pass = 0;
	$st_fail = 0;
	$st_msgs = array();
	$collect_ok = function ( $cond, $msg ) use ( &$st_pass, &$st_fail, &$st_msgs ) {
		if ( $cond ) { $st_pass++; } else { $st_fail++; $st_msgs[] = $msg; }
	};
	$collect_eq = function ( $expected, $actual, $msg ) use ( &$st_pass, &$st_fail, &$st_msgs ) {
		if ( $expected === $actual ) { $st_pass++; } else { $st_fail++; $st_msgs[] = $msg; }
	};

	/** The envelope as v13.95.1 found it: correct refusal, lying readout. */
	$bad = array(
		'applied' => false,
		'gates'   => array(
			'fingerprint' => array(
				'passed'   => false,                 // <- but the hashes match
				'expected' => 'dd5df6ac1e2f48ecb5',
				'observed' => 'dd5df6ac1e2f48ecb5',
			),
		),
		'diff'    => array(
			'before'            => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			'after'             => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', // <- copy of before
			'changes_applied'   => 2,               // <- nothing was applied
			'changes_requested' => 2,
			'ledger_impact'     => 'coalesces',     // <- reads as "applied, no new version"
		),
		'error'   => array( 'code' => 'snt_sn_apply_changes_conflict', 'status' => 422 ),
	);

	/** The same refusal, told honestly. */
	$good = array(
		'applied' => false,
		'gates'   => array(
			'fingerprint' => array(
				'passed'   => true,
				'expected' => 'dd5df6ac1e2f48ecb5',
				'observed' => 'dd5df6ac1e2f48ecb5',
			),
		),
		'diff'    => array(
			'before'            => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			'after'             => null,
			'changes_applied'   => 0,
			'changes_requested' => 2,
			'ledger_impact'     => null,
		),
		'error'   => array( 'code' => 'snt_sn_apply_changes_conflict', 'status' => 422 ),
	);

	echo "assert-envelope --self-test\n\n";

	// 1. The bad envelope must be REJECTED.
	$st_pass = 0; $st_fail = 0; $st_msgs = array();
	snt_test_envelope_refusal( $bad, 'snt_sn_apply_changes_conflict', $collect_ok, $collect_eq, 'BAD' );
	$bad_failures = $st_fail;
	$bad_detail   = $st_msgs;

	// 2. The good envelope must be ACCEPTED.
	$st_pass = 0; $st_fail = 0; $st_msgs = array();
	snt_test_envelope_refusal( $good, 'snt_sn_apply_changes_conflict', $collect_ok, $collect_eq, 'GOOD' );
	$good_failures = $st_fail;
	$good_detail   = $st_msgs;

	// 3. A dry run must not be mistaken for a refusal.
	$st_pass = 0; $st_fail = 0; $st_msgs = array();
	snt_test_envelope_dry_run(
		array( 'applied' => false, 'diff' => array( 'before' => 'a', 'after' => 'b' ) ),
		$collect_ok, $collect_eq, 'DRY'
	);
	$dry_failures = $st_fail;

	// 4. ...and a refusal must not pass as a dry run.
	$st_pass = 0; $st_fail = 0; $st_msgs = array();
	snt_test_envelope_dry_run( $good, $collect_ok, $collect_eq, 'DRY-ON-REFUSAL' );
	$dry_on_refusal_failures = $st_fail;

	$results = array(
		array( 'the v13.95.1 bad envelope is REJECTED', $bad_failures >= 4, "expected >=4 failures, got {$bad_failures}" ),
		array( 'and the rejection names every defect', count( $bad_detail ) >= 4, implode( ' | ', $bad_detail ) ),
		array( 'the corrected envelope is ACCEPTED', 0 === $good_failures, implode( ' | ', $good_detail ) ),
		array( 'a passing dry run is ACCEPTED', 0 === $dry_failures, 'dry run rejected' ),
		array( 'a refusal is NOT accepted as a dry run', $dry_on_refusal_failures >= 1, 'a refusal passed the dry-run shape' ),
	);

	$passed = 0;
	$failed = 0;
	foreach ( $results as $r ) {
		if ( $r[1] ) {
			$passed++;
			echo "PASS: {$r[0]}\n";
		} else {
			$failed++;
			echo "FAIL: {$r[0]} — {$r[2]}\n";
		}
	}
	echo "\n{$passed} passed, {$failed} failed.\n";
	exit( $failed > 0 ? 1 : 0 );
}
