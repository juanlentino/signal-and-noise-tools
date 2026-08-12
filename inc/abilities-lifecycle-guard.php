<?php
/**
 * WP 7.1 ability-execution lifecycle guard (forward-compat, v10.38.0).
 *
 * WordPress 7.1 standardizes the ability execution pipeline with core hooks.
 * The SHIPPED set is four FILTERS — no actions (corrected 2026-08-11, verified
 * against the 7.1 dev note "New execution lifecycle filters for the Abilities
 * API", 2026-07-29):
 *
 *   wp_pre_execute_ability        ($pre, $name, $input, $ability)   — top of execute(), pre-normalization
 *   wp_ability_normalize_input    ($input, $name, $ability)         — after defaults applied (unused here)
 *   wp_ability_permission_result  ($permission, $name, $input, $ability)
 *   wp_ability_execute_result     ($result, $name, $input, $ability)
 *
 * WHAT THIS FILE GOT WRONG FROM v10.38.0 THROUGH v10.92.4, because the shape of the mistake is
 * the reason the correction is worth spelling out. The v10.38.0 prep pass was
 * written against pre-release information and registered an
 * `add_action( 'wp_ability_invoked', …, 10, 3 )` as its timing start point.
 * That hook does not exist in shipped 7.1 under any name. It was inert pre-7.1
 * like everything else here, so nothing failed; and it would have stayed inert
 * AFTER 7.1 landed, because a handler on a hook core never fires is
 * indistinguishable from a handler on a hook core has not shipped yet. The
 * visible symptom would have been a `direct`-door telemetry row whose
 * latency_ms was a permanent, plausible-looking 0 (see the note on
 * sn_ability_guard_filter_execute_result). tests/abilities-lifecycle-guard.php
 * asserted the registration and passed — it could only ever confirm OUR side of
 * a two-sided contract. It now also asserts the inverse: that every hook this
 * file registers is a member of the shipped set above, which is the assertion
 * that would have caught it.
 *
 * Until 7.1, ALL of this plugin's hardening (kill switches, telemetry, rw
 * audit) is wired into the two MCP REST routes and sn_mcp_call_tool() only —
 * abilities executed through the native /wp-abilities/v1/.../run route or
 * Desktop Mode bypass every layer of it and rely solely on each ability's own
 * permission_callback. This file closes that gap by attaching the SAME policy
 * to the core hooks, additively:
 *
 *   - ENFORCEMENT (tighten-only): while the rw kill switch is engaged, any
 *     ability on the rw-door allowlist is denied on EVERY execution path, not
 *     just through the MCP rw route. A denial from the ability's own callback
 *     is never overridden in the allow direction. The READ kill switch is
 *     deliberately NOT extended here: read abilities are REST-reachable by
 *     design (their permission callbacks are the gate), and the read switch
 *     governs the MCP transport, not the data.
 *
 *   - OBSERVABILITY: direct (non-MCP) executions of our abilities are
 *     telemetry-recorded with door 'direct' (existing MCP rows keep their
 *     'read'/'rw' doors), and write-class direct executions land in the rw
 *     audit log. sn_mcp_call_tool() brackets its execute() call with
 *     sn_ability_guard_mcp_depth(±1) so nothing is double-recorded.
 *
 * Pre-7.1 the hooks simply never fire, so registering the handlers is a
 * guaranteed no-op — this file changes nothing on a 7.0 site. All MCP-module
 * calls are function_exists-guarded: the guard degrades to pass-through if
 * the MCP module is absent, never fatals.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ability-lifecycle hooks WordPress 7.1 actually ships, as filter name =>
 * callback arity. The single source of truth for what this file is allowed to
 * register on, asserted in both directions by
 * tests/abilities-lifecycle-guard.php.
 *
 * A function rather than a const on purpose: a top-level const in a file the
 * CLI suites also include keeps the FIRST loader-order value on collision, and
 * the suites would never see the difference.
 *
 * Transcribed from the 7.1 dev note "New execution lifecycle filters for the
 * Abilities API" (make.wordpress.org, 2026-07-29). Re-verify against the note
 * — not against this array — before adding a name.
 *
 * @return array<string,int> filter name => expected accepted-args count.
 */
function sn_ability_lifecycle_hooks_71() {
	return array(
		'wp_pre_execute_ability'       => 4, // ($pre, $name, $input, $ability) — short-circuit filter.
		'wp_ability_normalize_input'   => 3, // ($input, $name, $ability) — unused here.
		'wp_ability_permission_result' => 4, // ($permission, $name, $input, $ability)
		'wp_ability_execute_result'    => 4, // ($result, $name, $input, $ability)
	);
}

/**
 * Is this ability one of ours? Plugin abilities live under signal-noise/,
 * theme abilities under signal-and-noise/. The slash is part of the match so
 * a hostile "signal-noisex/..." namespace never rides our policy.
 *
 * @param string $ability_name
 * @return bool
 */
function sn_ability_guard_is_ours( $ability_name ) {
	$name = (string) $ability_name;
	return 0 === strpos( $name, 'signal-noise/' ) || 0 === strpos( $name, 'signal-and-noise/' );
}

/**
 * MCP-dispatch depth counter. sn_mcp_call_tool() increments around its
 * $ability->execute() call; the observers below stand down while depth > 0 so
 * an MCP call (which records its own telemetry/audit) is never double-logged.
 * Floors at zero: an unbalanced decrement must not poison later requests.
 *
 * @param int $delta +1 / -1 / 0 (peek).
 * @return int Current depth after applying $delta.
 */
function sn_ability_guard_mcp_depth( $delta = 0 ) {
	static $depth = 0;
	$depth = max( 0, $depth + (int) $delta );
	return $depth;
}

/**
 * Abilities deliberately held off BOTH MCP doors for blast radius (curation
 * documented in inc/mcp/mcp-capabilities.php, sn_mcp_rw_allowlist()'s
 * docblock). They are still reachable through the native abilities REST run
 * route by a privileged caller — which makes them exactly the executions the
 * rw kill switch must cover. Review finding v10.38.0: deriving write-class
 * from rw-allowlist membership alone silently exempted these four.
 *
 * @return string[]
 */
function sn_ability_guard_held_out_writes() {
	return array(
		'signal-noise/run-cron-event',
		'signal-noise/ai-orphan-apply',
		'signal-noise/merge-tags',
		'signal-noise/clear-template-overrides',
	);
}

/**
 * Is this ability write-class for kill-switch / rw-audit purposes?
 *
 * Three signals, first match wins:
 *   1. rw-door allowlist membership (the curated MCP write set).
 *   2. The ability's OWN declared annotations: an explicit readonly value is
 *      authoritative in both directions; declared destructive:true is write.
 *   3. The held-out set above (off both doors by curation, still writes).
 *
 * Unknown abilities with no signal default to READ: the rw kill switch is an
 * incident brake for writes, and misclassifying a future read ability as
 * write would dark half the desktop-mode surface whenever the brake is on.
 * Every write this plugin and the theme actually register is covered by
 * signals 1-3 (writes declare annotations or sit on the rw allowlist).
 *
 * @param string      $ability_name
 * @param object|null $ability WP_Ability (or stand-in) exposing get_meta(), when available.
 * @return bool
 */
function sn_ability_guard_is_write_class( $ability_name, $ability = null ) {
	$name = (string) $ability_name;
	if ( function_exists( 'sn_mcp_is_allowed' ) && defined( 'SN_MCP_DOOR_RW' )
		&& sn_mcp_is_allowed( $name, SN_MCP_DOOR_RW ) ) {
		return true;
	}
	if ( is_object( $ability ) && method_exists( $ability, 'get_meta' ) ) {
		$meta = (array) $ability->get_meta();
		$decl = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
		if ( array_key_exists( 'readonly', $decl ) ) {
			return empty( $decl['readonly'] );
		}
		if ( ! empty( $decl['destructive'] ) ) {
			return true;
		}
	}
	return in_array( $name, sn_ability_guard_held_out_writes(), true );
}

/**
 * PURE permission decision — tighten-only.
 *
 * @param bool|WP_Error $permission      Upstream permission result.
 * @param bool          $is_ours         Ability is in our namespaces.
 * @param bool          $is_write_class  Ability is on the rw-door allowlist.
 * @param bool          $rw_kill_engaged The rw kill switch is engaged.
 * @return bool|WP_Error
 */
function sn_ability_guard_permission_decision( $permission, $is_ours, $is_write_class, $rw_kill_engaged ) {
	if ( true !== $permission ) {
		return $permission; // Upstream denial (false / WP_Error) is never loosened.
	}
	if ( $is_ours && $is_write_class && $rw_kill_engaged ) {
		return new WP_Error(
			'sn_rw_kill_switch',
			'Write abilities are disabled: the Signal & Noise write kill switch is engaged.',
			array( 'status' => 503 )
		);
	}
	return $permission;
}

/**
 * Live wp_ability_permission_result handler.
 *
 * @param bool|WP_Error $permission
 * @param string        $ability_name
 * @param mixed         $input
 * @param object|null   $ability
 * @return bool|WP_Error
 */
function sn_ability_guard_filter_permission( $permission, $ability_name, $input = null, $ability = null ) {
	$engaged = function_exists( 'sn_mcp_rw_kill_switch_engaged' ) && sn_mcp_rw_kill_switch_engaged();
	return sn_ability_guard_permission_decision(
		$permission,
		sn_ability_guard_is_ours( $ability_name ),
		sn_ability_guard_is_write_class( $ability_name, $ability ),
		$engaged
	);
}

/**
 * Shared t0 store for latency measurement (invoked -> execute_result), keyed
 * by ability name. Each key holds a STACK, so an ability that (directly or
 * transitively) re-enters itself pairs each execute_result with its own
 * invocation LIFO instead of the inner call consuming the outer call's stamp
 * and leaving the outer one reporting a hard 0ms.
 *
 * @param string     $ability_name
 * @param float|null $set   microtime(true) to push, null to pop.
 * @return float|null Popped t0, null when the stack is empty.
 */
function sn_ability_guard_t0( $ability_name, $set = null ) {
	static $stacks = array();
	$key = (string) $ability_name;
	if ( null !== $set ) {
		if ( ! isset( $stacks[ $key ] ) ) {
			$stacks[ $key ] = array();
		}
		$stacks[ $key ][] = (float) $set;
		return $set;
	}
	if ( empty( $stacks[ $key ] ) ) {
		return null;
	}
	$t0 = array_pop( $stacks[ $key ] );
	if ( empty( $stacks[ $key ] ) ) {
		unset( $stacks[ $key ] );
	}
	return $t0;
}

/**
 * wp_pre_execute_ability observer: stamp t0 for direct executions of our
 * abilities. Inside MCP dispatch the wrapper measures its own latency.
 *
 * This is a SHORT-CIRCUIT filter: core returns $pre instead of executing when
 * it comes back non-null. Two consequences this handler is built around.
 *
 * 1. It returns $pre by identity, always. Observing must never become
 *    executing-by-accident: any non-null return here would silently replace
 *    every one of our abilities' results with whatever we returned.
 * 2. It does NOT stamp t0 when $pre is already non-null. A short circuit means
 *    the execute callback never runs, so wp_ability_execute_result never fires,
 *    so a t0 pushed here would never be popped — and the LIFO stack would hand
 *    that stale stamp to the NEXT execution of the same ability, reporting a
 *    latency measured from someone else's request. Residual, accepted: a filter
 *    at a LATER priority than ours can still short-circuit after we have
 *    stamped. That window leaks one stack entry per occurrence and inflates one
 *    later reading; closing it needs a hook that fires on the short-circuit
 *    path, which 7.1 does not provide. Nothing in this plugin or theme
 *    registers on this hook, so the window is third-party-only.
 *
 * @param mixed       $pre          Short-circuit value; non-null means core skips execution.
 * @param string      $ability_name
 * @param mixed       $input
 * @param object|null $ability
 * @return mixed $pre, unchanged.
 */
function sn_ability_guard_filter_pre_execute( $pre, $ability_name, $input = null, $ability = null ) {
	if ( null !== $pre ) {
		return $pre;
	}
	if ( ! sn_ability_guard_is_ours( $ability_name ) || sn_ability_guard_mcp_depth() > 0 ) {
		return $pre;
	}
	sn_ability_guard_t0( $ability_name, microtime( true ) );
	return $pre;
}

/**
 * wp_ability_execute_result observer: record direct executions (telemetry
 * with door 'direct'; rw audit for write-class), then return the result
 * UNCHANGED — this layer observes, it never recovers or reshapes.
 *
 * @param mixed       $result
 * @param string      $ability_name
 * @param mixed       $input
 * @param object|null $ability
 * @return mixed
 */
function sn_ability_guard_filter_execute_result( $result, $ability_name, $input = null, $ability = null ) {
	if ( ! sn_ability_guard_is_ours( $ability_name ) || sn_ability_guard_mcp_depth() > 0 ) {
		return $result;
	}

	$args     = is_array( $input ) ? $input : array();
	$is_error = is_wp_error( $result );

	// A missing t0 records 0, NOT because zero is true but because
	// `latency_ms INT NOT NULL DEFAULT 0` (inc/mcp/mcp-telemetry.php:103)
	// cannot hold NULL — the same constraint inc/mcp/mcp-telemetry-agents.php
	// documents for the agent seams. So 0 on a `direct` row means "unmeasured",
	// never "instantaneous", and it is reachable only when
	// wp_pre_execute_ability did not fire for this execution: a third-party
	// short circuit at a later priority, or core dropping/renaming the hook.
	// The latter was a live defect for the whole of v10.38.0-v10.92.4 (see the
	// file header) and is exactly why the shipped-hook-set assertion in
	// tests/abilities-lifecycle-guard.php exists — a permanent 0 in this column
	// is not self-announcing, so the test has to be what announces it.
	$t0         = sn_ability_guard_t0( $ability_name );
	$latency_ms = null === $t0 ? 0 : (int) round( ( microtime( true ) - $t0 ) * 1000 );

	if ( function_exists( 'sn_mcp_telemetry_record' ) ) {
		if ( $is_error && function_exists( 'sn_mcp_telemetry_classify_wp_error' ) ) {
			$class = sn_mcp_telemetry_classify_wp_error( $result );
			sn_mcp_telemetry_record( (string) $ability_name, $args, 'direct', $class['outcome'], $class['refusal_gate'], $latency_ms );
		} elseif ( ! $is_error ) {
			$count = function_exists( 'sn_mcp_telemetry_result_count' ) ? sn_mcp_telemetry_result_count( $result ) : null;
			sn_mcp_telemetry_record( (string) $ability_name, $args, 'direct', 'ok', null, $latency_ms, $count );
		}
	}

	if ( sn_ability_guard_is_write_class( $ability_name, $ability ) && function_exists( 'sn_mcp_rw_audit_record' ) ) {
		if ( $is_error ) {
			sn_mcp_rw_audit_record( (string) $ability_name, $args, 'error', $result );
		} else {
			sn_mcp_rw_audit_record( (string) $ability_name, $args, 'ok' );
		}
	}

	return $result;
}

// Registration: plain hook attachment at include time. Pre-7.1 core never
// fires these hooks, so this is inert until the site updates to 7.1.
//
// Every name below must be a member of sn_ability_lifecycle_hooks_71(). That is
// not a stylistic constraint: an unrecognised name here is a handler that never
// runs on any WordPress version, forever, silently. The test asserts the
// membership in both directions.
add_filter( 'wp_pre_execute_ability', 'sn_ability_guard_filter_pre_execute', 10, 4 );
add_filter( 'wp_ability_permission_result', 'sn_ability_guard_filter_permission', 10, 4 );
add_filter( 'wp_ability_execute_result', 'sn_ability_guard_filter_execute_result', 10, 4 );
