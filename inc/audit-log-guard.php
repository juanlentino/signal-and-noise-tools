<?php
/**
 * Signal & Noise Tools: the audit log's lockout facts, from the login guard.
 *
 * The lockout plugin the audit log used to poll was removed on 2026-10-18; the edge worker
 * (sn-login-guard) now refuses repeat offenders itself and logs each refusal
 * to Analytics Engine: 'lockout' (the cooldown after a run of capped windows),
 * 'throttle' (the per-IP limit) and 'account_throttle' (the per-account limit).
 * The audit log reads those 7-day counts from the cached login-defense
 * headline instead of polling another plugin's options. It observes; the
 * worker enforces.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The guard's 7-day lockout counts, with a state that keeps a failed read
 * from ever reading as zeros.
 *
 * @return array{state:string,locked_out:int,throttled:int,account_throttled:int} state: ok, unavailable or unconfigured.
 */
function snt_audit_guard_lockouts() {
	$out = array( 'state' => 'unconfigured', 'locked_out' => 0, 'throttled' => 0, 'account_throttled' => 0 );
	if ( ! function_exists( 'sn_login_defense_headline' ) ) {
		return $out;
	}
	$lg = sn_login_defense_headline();
	if ( empty( $lg['configured'] ) ) {
		return $out;
	}
	if ( ! empty( $lg['unavailable'] ) ) {
		$out['state'] = 'unavailable';
		return $out;
	}
	return array(
		'state'             => 'ok',
		'locked_out'        => (int) ( $lg['locked_out'] ?? 0 ),
		'throttled'         => (int) ( $lg['throttled'] ?? 0 ),
		'account_throttled' => (int) ( $lg['account_throttled'] ?? 0 ),
	);
}
