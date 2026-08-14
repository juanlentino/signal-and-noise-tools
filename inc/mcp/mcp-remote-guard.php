<?php
/**
 * Signal & Noise — remote analytics door guard (R3 §3D, Increment 1 origin half).
 *
 * The remote analytics MCP surface is reached by a caller who is NOT the owner's
 * laptop. Everything in this file exists because that changes which way a missing
 * input should be read.
 *
 * THE INVERSION, and it is deliberate: `sn_mcp_read_enabled` is fail-OPEN on
 * absence — an untouched option means "the owner never turned it off", and the
 * read door stays open. `sn_mcp_remote_enabled` is fail-CLOSED on absence — an
 * untouched option means "the owner never turned it ON", and the remote door
 * stays shut. The proposal requires this for any brokered path, and it is what
 * makes this increment safe to ship with no bridge behind it: the whole remote
 * surface is inert in production the day it lands, and the tests are what prove
 * it can be turned on deliberately.
 *
 * ISOLATION: this file never calls into mcp-read-guard.php or mcp-rw-guard.php,
 * and neither calls into it. The kill-switch predicate below is MIRRORED from
 * the read door's rather than shared, exactly as the read door mirrors the write
 * door's. Sharing would couple the doors at the one layer the split exists to
 * keep apart.
 *
 * WHAT THIS FILE DOES NOT DO: it does not rate limit. The remote path's F1
 * ceiling is the edge Durable Object, which is Worker-side and belongs to the
 * increment that builds the bridge. The origin's run-route ceiling is the
 * existing fail-OPEN one in mcp-read-guard.php, extended to cover remote slugs.
 * Do not read this increment as closing F1 — it closes the kill switch.
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owner-facing switch. ABSENT MEANS OFF — see this file's header. */
const SN_MCP_REMOTE_ENABLED_OPTION = 'sn_mcp_remote_enabled';

/**
 * The capability the remote principal holds. Never `manage_options`, and never
 * implied by a role that carries it. NOTHING is granted this capability by the
 * plugin — no role gains it at activation and no migration adds it. It exists to
 * be granted deliberately by the bridge increment.
 */
const SN_MCP_REMOTE_CAPABILITY = 'sn_read_remote_analytics';

/**
 * The slugs a remote principal may reach.
 *
 * A function rather than a `const` array on purpose: a duplicate top-level const
 * across two files silently keeps whichever value loaded first, which is a
 * failure mode no test would catch. Mirrors sn_mcp_allowlist()'s shape.
 *
 * These slugs are DELIBERATELY ABSENT from sn_mcp_allowlist(): the laptop read
 * door gains nothing from this increment.
 *
 * @return string[]
 */
function sn_mcp_remote_slugs() {
	return array(
		'signal-noise/remote-get-analytics-summary',
		'signal-noise/remote-get-analytics-events',
		'signal-noise/remote-get-insights',
		'signal-noise/remote-get-narration',
		'signal-noise/remote-uptime-status',
		'signal-noise/remote-get-health-scan',
		'signal-noise/remote-get-rss-stats',
		'signal-noise/remote-get-deploy-status',
	);
}

/**
 * Remote kill-switch PURE predicate.
 *
 * @param bool $constant_disabled defined('SN_MCP_REMOTE_DISABLED') && SN_MCP_REMOTE_DISABLED.
 * @param bool $option_enabled    The sn_mcp_remote_enabled option's value (default FALSE).
 * @return bool True when the remote door must be treated as disabled.
 */
function sn_mcp_remote_kill_switch_decision( $constant_disabled, $option_enabled ) {
	if ( (bool) $constant_disabled ) {
		return true;
	}
	return ! (bool) $option_enabled;
}

/**
 * Live: is the remote door disabled right now?
 *
 * The `false` default on get_option() is the fail-closed half. Changing it to
 * `true` to "match the read door" would silently ship the remote surface open.
 *
 * @return bool
 */
function sn_mcp_remote_kill_switch_engaged() {
	$constant_disabled = defined( 'SN_MCP_REMOTE_DISABLED' ) && SN_MCP_REMOTE_DISABLED;
	$option_enabled    = function_exists( 'get_option' )
		? (bool) get_option( SN_MCP_REMOTE_ENABLED_OPTION, false )
		: false;
	return sn_mcp_remote_kill_switch_decision( $constant_disabled, $option_enabled );
}

/**
 * The remote gate: switch, then capability, then slug membership. All three.
 *
 * ORDER MATTERS. The switch is first so that "closed" beats "you hold the
 * capability" — the same precedence the read guard achieves by running its kill
 * switch at rest_pre_dispatch priority 10 and its ceiling at 11.
 *
 * The slug arrives as a literal from a per-slug callback rather than being
 * resolved from ambient request state. An ambient resolver hands a NESTED inner
 * gate the OUTER slug, so an ability executing another ability would approve the
 * inner one against a name that was never its own — and it would look healthy,
 * because the membership check passed.
 *
 * @param string $slug The calling ability's own slug, passed as a literal.
 * @return bool
 */
function sn_remote_analytics_allows( $slug ) {
	if ( sn_mcp_remote_kill_switch_engaged() ) {
		return false;
	}
	if ( ! function_exists( 'current_user_can' ) || ! current_user_can( SN_MCP_REMOTE_CAPABILITY ) ) {
		return false;
	}
	return is_string( $slug ) && '' !== $slug && in_array( $slug, sn_mcp_remote_slugs(), true );
}

/**
 * Make the remote kill switch cover the remote slugs' native run routes.
 *
 * Without this, a remote slug held off the read allowlist would reach
 * POST /wp-abilities/v1/abilities/<slug>/run with no kill switch consulted at
 * all — which is the same single-route gap F2 was closed to eliminate, rebuilt
 * one increment later on a path that matters more.
 *
 * Route parsing is duplicated from mcp-read-guard.php rather than imported, per
 * this file's isolation rule.
 *
 * @param mixed $result  Pre-dispatch result; non-null means someone answered.
 * @param mixed $server  Unused.
 * @param mixed $request The REST request.
 * @return mixed Null to continue, or WP_Error to refuse.
 */
function sn_mcp_remote_guard_run_route( $result, $server = null, $request = null ) {
	if ( null !== $result ) {
		return $result;
	}
	if ( ! sn_mcp_remote_kill_switch_engaged() ) {
		return $result;
	}
	$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#', $route, $m ) ) {
		return $result;
	}
	if ( ! in_array( (string) $m[1], sn_mcp_remote_slugs(), true ) ) {
		return $result;
	}
	return new WP_Error(
		'sn_mcp_remote_disabled',
		__( 'The remote analytics door is currently disabled.', 'signal-and-noise-tools' ),
		array( 'status' => 403 )
	);
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_remote_guard_run_route', 10, 3 );
}
