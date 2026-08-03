<?php
/**
 * Signal & Noise Tools — a per-user courtesy throttle for expensive abilities.
 *
 * The native Abilities run-route (/wp-abilities/v1/abilities/<slug>/run) has no
 * rate limit of its own — only the MCP write door does (sn_mcp_rw_rate_limit_gate).
 * A runaway automation loop calling an O(n^2) or work-heavy ability can therefore
 * pin CPU (or, on paths that reach a provider, spend) unbounded. This gate is a
 * cheap defense against that loop. It is NOT a security boundary: every gated
 * ability already requires `manage_options`, so this only smooths a foot-gun for
 * a caller who is already an administrator.
 *
 * Fail-OPEN by design: absent the WordPress transient API (standalone unit
 * context) the gate returns allow rather than blocking — a courtesy throttle must
 * never harden into an outage when its backing store is unavailable.
 *
 * Window semantics mirror inc/abilities-update-post-surfaces.php's existing
 * per-post write throttle: a fixed count per rolling window, the TTL re-set on
 * each hit.
 *
 * @package SignalNoiseTools
 * @since 10.34.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allow this call, or deny it with a 429 WP_Error, for the current user.
 *
 * @param string $key    Stable slug identifying the throttled operation
 *                        (e.g. 'near_duplicate_scan'). Used in the transient key.
 * @param int    $max    Calls permitted per window (before this call).
 * @param int    $window Window length in seconds.
 * @return true|WP_Error True when the call may proceed; WP_Error (status 429) when throttled.
 */
function snt_ability_rate_gate( $key, $max, $window ) {
	// Fail open without the transient API — see file header.
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
		return true;
	}

	$max    = (int) apply_filters( 'snt_ability_rate_gate_max', (int) $max, $key );
	$window = (int) apply_filters( 'snt_ability_rate_gate_window', (int) $window, $key );
	if ( $max < 1 || $window < 1 ) {
		return true; // A filter that zeroes either bound disables the gate for this key.
	}

	$uid   = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	$tkey  = 'snt_rate_' . $key . '_' . $uid;
	$hits  = (int) get_transient( $tkey );

	if ( $hits >= $max ) {
		return new WP_Error(
			'snt_rate_limited',
			sprintf(
				/* translators: 1: calls allowed, 2: window in seconds. */
				__( 'Rate limit reached for this operation (%1$d calls per %2$d seconds). Retry shortly.', 'signal-and-noise-tools' ),
				$max,
				$window
			),
			array(
				'status'      => 429,
				'retry_after' => $window,
			)
		);
	}

	set_transient( $tkey, $hits + 1, $window );
	return true;
}
