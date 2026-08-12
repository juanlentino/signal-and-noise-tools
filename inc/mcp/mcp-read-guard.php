<?php
/**
 * Signal & Noise — MCP read-door guard: the read kill switch (v10.9.0).
 *
 * The rw door has had a kill switch since v9.51.0 (R2, mcp-rw-guard.php);
 * the read door deliberately had none — its permission path was BYTE-FROZEN
 * so the rw hardening could never destabilize reads. This file is the
 * owner-requested (2026-07-30) read-side lever: instantly darken corpus
 * visibility (scheduled/draft bodies, operational reads) without deauthing
 * the application password or touching the rw door.
 *
 * The freeze's real invariant survives intact:
 *   - sn_mcp_permission() (the manage_options floor) is byte-identical.
 *   - This file NEVER calls into mcp-rw-guard.php, and vice versa — the two
 *     doors' guards stay isolated, per the read/write split.
 * What changed is the read route's permission_callback: now the layered
 * sn_mcp_read_permission() (kill switch first, then the frozen floor) —
 * the pinned contract in tests/mcp-endpoint.php was amended the same day.
 *
 * Same decision semantics as the rw switch, same reasoning:
 *   - wp-config constant SN_MCP_READ_DISABLED is bulletproof (an attacker
 *     holding only a leaked app password can never flip it) and wins over
 *     the option unconditionally.
 *   - Option sn_mcp_read_enabled is the owner's UI-reachable kill;
 *     absent = enabled (FAIL-OPEN-ON-ABSENCE: an untouched switch means
 *     "the owner never turned it off", exactly like the rw door's).
 *
 * @package SignalNoiseTools
 * @since 10.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_READ_ENABLED_OPTION = 'sn_mcp_read_enabled';

/**
 * The ability slug on a native Abilities RUN route, or '' for anything else.
 *
 * Route shape: /wp-abilities/v1/abilities/<slug>/run — the slug itself contains
 * a slash (`signal-noise/get-analytics-events`), so this anchors on the whole
 * route rather than splitting on separators. Anchored at both ends on purpose:
 * a route that merely CONTAINS /run is not a run route.
 *
 * @param string $route REST route, e.g. from WP_REST_Request::get_route().
 * @return string Ability slug, or ''.
 */
function sn_mcp_read_guard_route_slug( $route ) {
	if ( ! is_string( $route ) || '' === $route ) {
		return '';
	}
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#', $route, $m ) ) {
		return '';
	}
	return (string) $m[1];
}

/**
 * Make the read kill switch cover the READ PATH, not one route on it.
 *
 * THE BUG THIS CLOSES (F2, found while writing §8 of the agent-surface threat
 * model): sn_mcp_read_permission() was referenced in exactly one place — the MCP
 * endpoint's read route. The native Abilities run-route never consulted it, so an
 * owner-identity caller reached every read ability with the switch set to OFF,
 * while the switch read as though it had closed the door. The REST audit's §0
 * finding already said each ability's own permission_callback is the binding
 * constraint and the MCP floor is defense-in-depth; the kill switch had been
 * living entirely in the defense-in-depth layer.
 *
 * Harmless while the only caller is the owner's laptop, and load-bearing the
 * moment it is not — which is what roadmap 3D would change. Fixed here, on its
 * own merits, rather than bundled with the trust boundary that would make its
 * absence matter.
 *
 * SCOPE, and it is deliberately narrow: only slugs on the READ allowlist. The
 * two doors' guards are isolated by design (see this file's header) and the two
 * allowlists are disjoint — a read kill that also killed writes would be a worse
 * bug than the one it replaces. A slug on neither list is not this guard's
 * business.
 *
 * @param mixed  $result  Pre-dispatch result; non-null means someone already answered.
 * @param mixed  $server  Unused.
 * @param mixed  $request The REST request.
 * @return mixed Null to continue, or WP_Error to refuse.
 */
function sn_mcp_read_guard_run_route( $result, $server = null, $request = null ) {
	// Never override an answer another filter already gave.
	if ( null !== $result ) {
		return $result;
	}
	if ( ! sn_mcp_read_kill_switch_engaged() ) {
		return $result;
	}
	$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	$slug  = sn_mcp_read_guard_route_slug( $route );
	if ( '' === $slug || ! function_exists( 'sn_mcp_allowlist' ) ) {
		return $result;
	}
	if ( ! in_array( $slug, sn_mcp_allowlist(), true ) ) {
		return $result;
	}
	return new WP_Error(
		// The SAME code the MCP door returns: one switch, one verdict, whichever
		// route the caller arrived on.
		'sn_mcp_read_disabled',
		__( 'The MCP read door is currently disabled.', 'signal-and-noise-tools' ),
		array( 'status' => 403 )
	);
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_read_guard_run_route', 10, 3 );
}

/**
 * Read kill-switch PURE predicate. Mirrors sn_mcp_rw_kill_switch_decision()
 * exactly; duplicated rather than shared because the door guards must not
 * import each other (the isolation IS the design).
 *
 * @param bool $constant_disabled Whether defined('SN_MCP_READ_DISABLED') && SN_MCP_READ_DISABLED.
 * @param bool $option_enabled    The sn_mcp_read_enabled option's value (default true).
 * @return bool True when the read door must be treated as disabled.
 */
function sn_mcp_read_kill_switch_decision( $constant_disabled, $option_enabled ) {
	if ( (bool) $constant_disabled ) {
		return true;
	}
	return ! (bool) $option_enabled;
}

/**
 * Live: is the read door disabled right now?
 *
 * @return bool
 */
function sn_mcp_read_kill_switch_engaged() {
	$constant_disabled = defined( 'SN_MCP_READ_DISABLED' ) && SN_MCP_READ_DISABLED;
	$option_enabled    = function_exists( 'get_option' )
		? (bool) get_option( SN_MCP_READ_ENABLED_OPTION, true )
		: true;
	return sn_mcp_read_kill_switch_decision( $constant_disabled, $option_enabled );
}

/**
 * Read-door permission callback (v10.9.0): kill switch FIRST — a 403 here
 * means tools/list can never leak the read tool set while the door is dark —
 * then the unchanged manage_options floor.
 *
 * @return true|false|WP_Error
 */
function sn_mcp_read_permission() {
	if ( sn_mcp_read_kill_switch_engaged() ) {
		return new WP_Error(
			'sn_mcp_read_disabled',
			__( 'The MCP read door is currently disabled.', 'signal-and-noise-tools' ),
			array( 'status' => 403 )
		);
	}
	return sn_mcp_permission();
}
