<?php
/**
 * Signal & Noise Tools: change_class_backfill's gates, diff and write. Loaded
 * by inc/sn-apply/change-class-backfill.php, whose header describes the type.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate 1: the plan, and the fingerprint over its rows. A refused plan is a
 * plan_error (422, reported as a validation finding), never a stale hash.
 *
 * @param array $change
 * @return array
 */
function snt_sn_apply_gate1_change_class_backfill( array $change ) {
	$payload = (array) ( $change['payload'] ?? array() );
	if ( array() !== snt_sn_apply_backfill_payload_problems( $payload ) ) {
		// Nothing to plan; gate 2 names every problem.
		return array( 'passed' => true, 'expected' => null, 'observed' => null, 'skipped' => 'payload_invalid', 'detail' => null, 'new_content' => null );
	}
	$plan     = snt_sn_apply_backfill_plan( $payload );
	$observed = snt_sn_apply_backfill_fingerprint( $plan['rows'] );
	$provided = array_key_exists( 'fingerprint', $change );
	$base     = array( 'expected' => $provided ? (string) $change['fingerprint'] : null, 'observed' => $observed, 'skipped' => null, 'new_content' => null, 'backfill_plan' => $plan );
	if ( array() !== $plan['refused'] ) {
		$named = array_map( function ( $r ) {
			$v = array_map( function ( $c ) {
				return 'v' . $c['version'] . ' at ' . $c['minted_at'];
			}, $r['candidates'] );
			return sprintf( 'post %d has %d window versions%s', $r['post_id'], count( $v ), $v ? ' (' . implode( ', ', $v ) . ')' : '' );
		}, $plan['refused'] );
		return $base + array(
			'passed'         => false,
			'detail'         => null,
			'fingerprint_ok' => $provided && hash_equals( $observed, (string) $change['fingerprint'] ),
			'plan_error'     => 'Every listed post needs exactly one version on the window dates; nothing was planned: ' . implode( '; ', $named ) . '. See diff.refused.',
			'error_code'     => 'snt_sn_apply_backfill_ambiguous',
			'error_status'   => 422,
		);
	}
	if ( ! $provided ) {
		return $base + array(
			'passed'       => false,
			'detail'       => 'change.fingerprint is required for change_class_backfill: this response\'s gates.fingerprint.observed IS the plan fingerprint and diff.rows the plan. Re-issue the call with it.',
			'error_code'   => 'snt_sn_apply_missing_fingerprint',
			'error_status' => 422,
		);
	}
	$passed = hash_equals( $observed, (string) $change['fingerprint'] );
	return $base + array(
		'passed' => $passed,
		'detail' => $passed ? null : 'The planned versions or their labels changed since this fingerprint was observed. Re-run the dry run and retry with the new fingerprint.',
	);
}

/**
 * Gate 2: payload shape, and expected_counts (labels this call applies, per
 * class) against the plan when the caller supplied them.
 *
 * @param array $change
 * @return array{checks:string[],findings:array}
 */
function snt_sn_apply_gate2_change_class_backfill( array $change ) {
	$payload  = (array) ( $change['payload'] ?? array() );
	$problems = snt_sn_apply_backfill_payload_problems( $payload );
	if ( array() === $problems && array_key_exists( 'expected_counts', $payload ) ) {
		$planned = array();
		foreach ( snt_sn_apply_backfill_plan( $payload )['rows'] as $r ) {
			$planned[ $r['proposed_class'] ] = ( $planned[ $r['proposed_class'] ] ?? 0 ) + 1;
		}
		$expected = array_filter( array_map( 'intval', (array) $payload['expected_counts'] ) );
		ksort( $planned );
		ksort( $expected );
		if ( $planned !== $expected ) {
			$problems[] = sprintf( 'payload.expected_counts %s does not match the plan %s.', wp_json_encode( $expected ), wp_json_encode( $planned ) );
		}
	}
	$findings = array_map( function ( $p ) {
		return array( 'check' => 'change_class_backfill', 'severity' => 'error', 'message' => $p );
	}, $problems );
	return array( 'checks' => array( 'change_class_backfill' ), 'findings' => $findings );
}

/**
 * The preview: planned rows, refusals, and the totals before and after.
 *
 * @param array $change
 * @param array $gate1
 * @return array
 */
function snt_sn_apply_change_class_backfill_diff( array $change, array $gate1 ) {
	$payload = (array) ( $change['payload'] ?? array() );
	$plan    = $gate1['backfill_plan'] ?? ( array() === snt_sn_apply_backfill_payload_problems( $payload ) ? snt_sn_apply_backfill_plan( $payload ) : array( 'rows' => array(), 'refused' => array() ) );
	return array(
		'rows'                  => $plan['rows'],
		'refused'               => $plan['refused'],
		'window_basis'          => 'UTC calendar day of each chain entry\'s committed_at',
		'change_classes_before' => snt_sn_apply_backfill_totals(),
		'change_classes_after'  => snt_sn_apply_backfill_totals( $plan['rows'] ),
		'audit'                 => array(
			'records_versions' => false,
			'note'             => 'The rw audit log records neither the post nor the version an sn_apply write minted, so audit_match is day-level: whether any applied sn_apply write landed that UTC day.',
			'flagged'          => array_values( array_map( function ( $r ) {
				return $r['post_id'];
			}, array_filter( $plan['rows'], function ( $r ) {
				return 'sn_apply_write_same_day' !== $r['audit_match'];
			} ) ) ),
		),
		'blocks_touched'        => 0,
	);
}

/**
 * The write: re-plan (the TOCTOU check), require the fingerprint, then label
 * every planned version or none.
 *
 * @param array $change
 * @return array|WP_Error
 */
function snt_sn_apply_write_change_class_backfill( array $change ) {
	$payload = (array) ( $change['payload'] ?? array() );
	if ( array() !== snt_sn_apply_backfill_payload_problems( $payload ) ) {
		return new WP_Error( 'snt_sn_apply_validation_failed', __( 'payload failed validation at the write step.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	$plan = snt_sn_apply_backfill_plan( $payload );
	if ( array() !== $plan['refused'] ) {
		return new WP_Error( 'snt_sn_apply_backfill_ambiguous', __( 'A listed post no longer has exactly one window version.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( ! array_key_exists( 'fingerprint', $change ) ) {
		return new WP_Error( 'snt_sn_apply_missing_fingerprint', __( 'change.fingerprint is required.', 'signal-and-noise-tools' ), array( 'status' => 422 ) );
	}
	if ( ! hash_equals( snt_sn_apply_backfill_fingerprint( $plan['rows'] ), (string) $change['fingerprint'] ) ) {
		return new WP_Error( 'snt_sn_apply_fingerprint_stale', __( 'The plan changed since this fingerprint was observed.', 'signal-and-noise-tools' ), array( 'status' => 409 ) );
	}
	$before = snt_sn_apply_backfill_totals();
	$snap   = array();
	foreach ( $plan['rows'] as $r ) {
		$snap[ $r['post_id'] ] = sn_prov_get_chain( $r['post_id'] );
	}
	foreach ( $plan['rows'] as $r ) {
		if ( ! sn_prov_set_change_class( $r['post_id'], $r['version'], $r['proposed_class'] ) ) {
			foreach ( $snap as $pid => $chain ) {
				sn_prov_put_chain( $pid, $chain );
			}
			return new WP_Error( 'snt_sn_apply_backfill_write_failed', sprintf( 'Labeling post %d v%d failed; every label in this call was rolled back.', $r['post_id'], $r['version'] ), array( 'status' => 500 ) );
		}
	}
	$diff                          = snt_sn_apply_change_class_backfill_diff( $change, array( 'backfill_plan' => $plan ) );
	$diff['change_classes_before'] = $before;
	$diff['change_classes_after']  = snt_sn_apply_backfill_totals();
	return array(
		'ok'           => true,
		'diff'         => $diff,
		'revision_id'  => null,
		'write_result' => array( 'ok' => true, 'labeled' => count( $plan['rows'] ) ),
	);
}
