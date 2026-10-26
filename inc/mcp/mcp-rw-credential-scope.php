<?php
/**
 * Signal & Noise Tools: the write door's credential reaches the write door only.
 *
 * The Application Password bound to /mcp-rw is a full administrator credential
 * to WordPress. Before this, the run-route guard held it to the door's controls
 * on /wp-abilities/v1/abilities/<slug>/run for write abilities, but nothing held
 * it anywhere else: it reached the destructive abilities neither door exposes
 * (clear-template-overrides, run-cron-event, merge-tags) and every core route
 * (/wp/v2/posts, /wp/v2/settings, ...). 2026-10-26 MCP doors audit, N5 and N0.
 *
 * Now, on any REST request authenticated by the bound credential, only two
 * routes are allowed: the door itself (/signal-noise/v1/mcp-rw) and the run
 * route of an ability on the write door's allowlist. Everything else is refused
 * before dispatch, 403, and written to the rw audit log. Other credentials, and
 * the owner's own cookie-authenticated session, are untouched.
 *
 * @package SignalNoise
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PURE: may the bound write credential use this route?
 *
 * @param string   $route   The REST route, as WP_REST_Request::get_route() gives it.
 * @param string[] $rw_door The write door's allowlist.
 * @return bool
 */
function sn_mcp_rw_credential_scope_allows( $route, array $rw_door ) {
	$route = strtolower( rtrim( (string) $route, '/' ) );
	if ( '/signal-noise/v1/mcp-rw' === $route ) {
		return true;
	}
	// Core matches routes case-insensitively, so the slug is read the same way;
	// the allowlist comparison stays exact, as the registry's is.
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#', $route, $m ) ) {
		return false;
	}
	return in_array( $m[1], $rw_door, true );
}

/**
 * rest_pre_dispatch, before every door guard (priority 9): refuse the bound
 * write credential outside its scope. Never overrides an earlier answer.
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
	// mcp-capabilities.php and mcp-rw-guard.php load before this file.
	if ( ! function_exists( 'sn_mcp_rw_authenticated_app_password_uuid' ) || ! function_exists( 'sn_mcp_rw_bound_uuid' ) ) {
		return $result;
	}
	$bound         = sn_mcp_rw_bound_uuid();
	$authenticated = sn_mcp_rw_authenticated_app_password_uuid();
	if ( '' === $bound || '' === $authenticated || ! hash_equals( $bound, $authenticated ) ) {
		return $result;
	}
	$route = (string) $request->get_route();
	if ( sn_mcp_rw_credential_scope_allows( $route, sn_mcp_rw_allowlist() ) ) {
		return $result;
	}
	$error = new WP_Error(
		'sn_mcp_rw_credential_out_of_scope',
		__( 'The MCP write door\'s Application Password works only on the write door and its tools.', 'signal-and-noise-tools' ),
		array( 'status' => 403 )
	);
	if ( function_exists( 'sn_mcp_rw_audit_record' ) ) {
		// Truncated: the route is caller-chosen, and the row is stored as is.
		sn_mcp_rw_audit_record( 'route:' . substr( $route, 0, 120 ), array(), 'denied', $error );
	}
	return $error;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_rw_credential_scope', 9, 3 );
}
