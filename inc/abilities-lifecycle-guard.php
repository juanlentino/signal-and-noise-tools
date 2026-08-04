<?php
/**
 * WP 7.1 ability-execution lifecycle guard (forward-compat, v10.38.0).
 *
 * WordPress 7.1 standardizes the ability execution pipeline with core hooks:
 *
 *   wp_ability_invoked            (action; execution start)
 *   wp_ability_permission_result  (filter; after permission_callback)
 *   wp_ability_execute_result     (filter; after execute, before output validation)
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
	$is_write = function_exists( 'sn_mcp_is_allowed' ) && defined( 'SN_MCP_DOOR_RW' )
		&& sn_mcp_is_allowed( (string) $ability_name, SN_MCP_DOOR_RW );
	$engaged  = function_exists( 'sn_mcp_rw_kill_switch_engaged' ) && sn_mcp_rw_kill_switch_engaged();
	return sn_ability_guard_permission_decision(
		$permission,
		sn_ability_guard_is_ours( $ability_name ),
		$is_write,
		$engaged
	);
}

/**
 * Shared t0 map for latency measurement (invoked -> execute_result), keyed by
 * ability name. Last-write-wins is acceptable: overlapping same-name
 * executions in one request only skew a latency figure, never correctness.
 *
 * @param string     $ability_name
 * @param float|null $set   microtime(true) to stamp, null to consume.
 * @return float|null Stamped t0 on consume, null when absent.
 */
function sn_ability_guard_t0( $ability_name, $set = null ) {
	static $map = array();
	$key = (string) $ability_name;
	if ( null !== $set ) {
		$map[ $key ] = (float) $set;
		return $set;
	}
	if ( ! array_key_exists( $key, $map ) ) {
		return null;
	}
	$t0 = $map[ $key ];
	unset( $map[ $key ] );
	return $t0;
}

/**
 * wp_ability_invoked observer: stamp t0 for direct executions of our
 * abilities. Inside MCP dispatch the wrapper measures its own latency.
 *
 * @param string      $ability_name
 * @param mixed       $input
 * @param object|null $ability
 * @return void
 */
function sn_ability_guard_on_invoked( $ability_name, $input = null, $ability = null ) {
	if ( ! sn_ability_guard_is_ours( $ability_name ) || sn_ability_guard_mcp_depth() > 0 ) {
		return;
	}
	sn_ability_guard_t0( $ability_name, microtime( true ) );
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

	$is_write = function_exists( 'sn_mcp_is_allowed' ) && defined( 'SN_MCP_DOOR_RW' )
		&& sn_mcp_is_allowed( (string) $ability_name, SN_MCP_DOOR_RW );
	if ( $is_write && function_exists( 'sn_mcp_rw_audit_record' ) ) {
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
add_action( 'wp_ability_invoked', 'sn_ability_guard_on_invoked', 10, 3 );
add_filter( 'wp_ability_permission_result', 'sn_ability_guard_filter_permission', 10, 4 );
add_filter( 'wp_ability_execute_result', 'sn_ability_guard_filter_execute_result', 10, 4 );
