<?php
/**
 * Signal & Noise Tools: each door's bound credential reaches its own door only.
 *
 * The Application Password bound to /mcp-rw is a full administrator credential
 * to WordPress. Before this, nothing held it outside the door: it reached the
 * destructive abilities neither door exposes (clear-template-overrides,
 * run-cron-event, merge-tags) and every core route (/wp/v2/posts,
 * /wp/v2/settings, ...). 2026-10-26 MCP doors audit, N5 and N0.
 *
 * On any REST request authenticated by a door's bound credential, only that
 * door's own route and the run routes of that door's tools are allowed;
 * everything else is refused before dispatch, 403, and written to the rw audit
 * log. The write door's binding is required (the door is inactive without it);
 * the read door's is optional (mcp-read-binding.php) and scopes only the
 * password bound to it. Other credentials, and the owner's own
 * cookie-authenticated session, are untouched.
 *
 * Runs at rest_pre_dispatch 9, before the door guards at 10: a bound read
 * credential on a read tool's run route passes here and is still closed by the
 * read kill switch there.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE: may a door's bound credential use this route?
 *
 * @param string   $route      The REST route, as WP_REST_Request::get_route() gives it.
 * @param string[] $door_slugs The door's allowlist.
 * @param string   $door_route The door's own route (default: the write door).
 * @return bool
 */
function sn_mcp_rw_credential_scope_allows( $route, array $door_slugs, $door_route = '/signal-noise/v1/mcp-rw' ) {
	$route = strtolower( rtrim( (string) $route, '/' ) );
	if ( $door_route === $route ) {
		return true;
	}
	// Core matches routes case-insensitively, so the slug is read the same way;
	// the allowlist comparison stays exact, as the registry's is.
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#', $route, $m ) ) {
		return false;
	}
	return in_array( $m[1], $door_slugs, true );
}

/**
 * rest_pre_dispatch, before every door guard (priority 9): refuse a door's
 * bound credential outside its door. Never overrides an earlier answer.
 *
 * @param mixed       $result
 * @param mixed       $server
 * @param object|null $request
 * @return mixed
 */
function sn_mcp_rw_credential_scope( $result, $server = null, $request = null ) {
	if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
		return $result;
	}
	// mcp-capabilities.php, mcp-rw-guard.php and mcp-read-binding.php load before this file.
	if ( ! function_exists( 'sn_mcp_rw_authenticated_app_password_uuid' ) || ! function_exists( 'sn_mcp_rw_bound_uuid' ) ) {
		return $result;
	}
	$authenticated = sn_mcp_rw_authenticated_app_password_uuid();
	if ( '' === $authenticated ) {
		return $result;
	}
	$read_bound = function_exists( 'sn_mcp_read_bound_uuid' ) ? sn_mcp_read_bound_uuid() : '';
	$route      = (string) $request->get_route();
	$bound      = sn_mcp_rw_bound_uuid();
	if ( '' !== $bound && hash_equals( $bound, $authenticated ) ) {
		if ( sn_mcp_rw_credential_scope_allows( $route, sn_mcp_rw_allowlist() ) ) {
			return $result;
		}
		$error = new WP_Error(
			'sn_mcp_rw_credential_out_of_scope',
			__( 'The MCP write door\'s Application Password works only on the write door and its tools.', 'signal-and-noise-tools' ),
			array( 'status' => 403 )
		);
	} elseif ( '' !== $read_bound && hash_equals( $read_bound, $authenticated ) ) {
		if ( sn_mcp_rw_credential_scope_allows( $route, sn_mcp_allowlist(), '/signal-noise/v1/mcp' ) ) {
			return $result;
		}
		$error = new WP_Error(
			'sn_mcp_read_credential_out_of_scope',
			__( 'The MCP read door\'s Application Password works only on the read door and its tools.', 'signal-and-noise-tools' ),
			array( 'status' => 403 )
		);
		// The read password is the lower-trust credential, and these refusals
		// run before the read rate limit: tag them, and record at most
		// SN_MCP_READ_SCOPE_AUDIT_PER_MINUTE a minute, so a flood from it can
		// neither roll the write door's rows out of the shared rw audit log
		// nor mail the owner once a request.
		if ( ! sn_mcp_read_scope_audit_allowed() ) {
			return $error;
		}
		$tag = 'read-route:';
	} else {
		return $result;
	}
	if ( function_exists( 'sn_mcp_rw_audit_record' ) ) {
		// Truncated: the route is caller-chosen, and the row is stored as is.
		sn_mcp_rw_audit_record( ( $tag ?? 'route:' ) . substr( $route, 0, 120 ), array(), 'denied', $error );
	}
	return $error;
}

const SN_MCP_READ_SCOPE_AUDIT_PER_MINUTE = 10;

/**
 * Count one read-scope refusal against this minute's audit allowance.
 *
 * @return bool True while the minute has room left.
 */
function sn_mcp_read_scope_audit_allowed() {
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
		return true;
	}
	$key = 'sn_mcp_read_scope_audit_' . (int) floor( time() / 60 );
	$n   = (int) get_transient( $key );
	if ( $n >= SN_MCP_READ_SCOPE_AUDIT_PER_MINUTE ) {
		return false;
	}
	set_transient( $key, $n + 1, 120 );
	return true;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_rw_credential_scope', 9, 3 );
}
