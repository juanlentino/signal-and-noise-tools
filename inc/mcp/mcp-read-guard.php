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
 * The read door's ceiling. Mirrors mcp-rw-guard.php's limiter deliberately
 * rather than calling it: this file's header states that the two doors' guards
 * stay isolated, and sharing the limiter would couple them at exactly the layer
 * the read/write split exists to keep apart.
 *
 * Four times the write cap. Reads are cheap and bursty — a scan-then-read loop
 * is normal agent behaviour — and the number that matters for the threat model
 * is not its exact value but that a ceiling EXISTS at all: before this, the read
 * door had none, and §8 of the agent-surface threat model records why that stops
 * being harmless the moment the caller is not the owner's laptop.
 */
const SN_MCP_READ_RATE_LIMIT_PER_MINUTE     = 120;
const SN_MCP_READ_RATE_LIMIT_WINDOW_SECONDS = 60;
const SN_MCP_READ_RATE_LIMIT_CACHE_GROUP    = 'sn_mcp_read_rate';

/**
 * Bucket a caller. A bound credential names them; failing that the IP hash does;
 * failing both, an EXPLICIT unknown bucket — never an empty key, which would
 * pool every anonymous caller into one counter and let any of them exhaust it
 * for the rest.
 *
 * @param string $app_pw_uuid
 * @param string $ip_hash
 * @return string
 */
function sn_mcp_read_rate_limit_identity( $app_pw_uuid, $ip_hash ) {
	$app_pw_uuid = (string) $app_pw_uuid;
	if ( '' !== $app_pw_uuid ) {
		return 'uuid:' . $app_pw_uuid;
	}
	$ip_hash = (string) $ip_hash;
	return 'ip:' . ( '' !== $ip_hash ? $ip_hash : 'unknown' );
}

/**
 * Store key for an identity.
 *
 * @param string $identity
 * @return string
 */
function sn_mcp_read_rate_limit_key( $identity ) {
	return 'sn_mcp_read_rate_' . md5( (string) $identity );
}

/**
 * Current count for a key, or null when unknown/unavailable.
 *
 * @param string $key
 * @return int|null
 */
function sn_mcp_read_rate_limit_store_get( $key ) {
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' ) ) {
		$v = wp_cache_get( $key, SN_MCP_READ_RATE_LIMIT_CACHE_GROUP );
		return false === $v ? null : (int) $v;
	}
	$v = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
	return false === $v ? null : (int) $v;
}

/**
 * Persist a count for the window.
 *
 * @param string $key
 * @param int    $count
 * @param int    $ttl_seconds
 * @return void
 */
function sn_mcp_read_rate_limit_store_set( $key, $count, $ttl_seconds ) {
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_set' ) ) {
		wp_cache_set( $key, (int) $count, SN_MCP_READ_RATE_LIMIT_CACHE_GROUP, (int) $ttl_seconds );
		return;
	}
	if ( function_exists( 'set_transient' ) ) {
		set_transient( $key, (int) $count, (int) $ttl_seconds );
	}
}

/**
 * The decision, pure: is this call under the cap?
 *
 * @param int $count_in_window Calls already made.
 * @param int $cap
 * @return bool
 */
function sn_mcp_read_rate_limit_decision( $count_in_window, $cap ) {
	return (int) $count_in_window < (int) $cap;
}

/**
 * Is a counter store actually usable right now?
 *
 * THE DISTINCTION THIS EXISTS FOR, and it is not academic: a null from
 * sn_mcp_read_rate_limit_store_get() means EITHER "the store is gone" OR "this
 * identity has no counter yet" — and the second is the normal first call of
 * every window. Fail-open never had to tell them apart, because both answers
 * led to `allow`. Fail-closed does: refusing on a plain key miss would refuse
 * the FIRST remote call in every window, which is an outage wearing a security
 * costume rather than a boundary. A test written for Task 4.A caught exactly
 * that in the first cut of this change.
 *
 * @since 13.50.0
 * @return bool
 */
function sn_mcp_read_rate_limit_store_available() {
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' ) ) {
		return true;
	}
	return function_exists( 'get_transient' ) && function_exists( 'set_transient' );
}

/**
 * On a store MISS, may the call proceed? Pure, so both directions of Task 4.A
 * are testable without simulating a dead cache.
 *
 * Refusal requires BOTH halves: the remote path, and a store that is genuinely
 * unusable. Either alone proceeds.
 *
 * @since 13.50.0
 * @param bool $fail_closed     True on the remote path.
 * @param bool $store_available Whether a counter store is usable.
 * @return bool
 */
function sn_mcp_read_rate_limit_miss_allows( $fail_closed, $store_available ) {
	if ( ! $fail_closed ) {
		return true;
	}
	return (bool) $store_available;
}

/**
 * Count this call and say whether it may proceed.
 *
 * FAIL-OPEN ON THE LOCAL PATH, deliberately and identically to the write door:
 * an absent backing store yields a null count, which reads as zero and allows.
 * A throttle must not harden into an outage when its store is unavailable. On
 * the laptop this is a runaway-loop CEILING, not a security boundary.
 *
 * FAIL-CLOSED ON THE REMOTE PATH (v13.50.0, Task 4.A — Precondition A of the
 * remote phase). The same ceiling covers remote slugs on purpose: `load is load
 * whoever is asking`, per sn_mcp_read_guard_is_read_path(). But a credentialed,
 * phone-reachable path is a different risk object from a laptop one, and §8 of
 * the threat model records F1 failing open as a precondition that must clear
 * before that path widens. With no store, a remote caller is now refused rather
 * than waved through — an unmeasurable throttle on a credentialed path is not a
 * throttle.
 *
 * The asymmetry is the point, and it is why $fail_closed is a PARAMETER rather
 * than a change of default: making the local path fail closed too would turn a
 * missing transient store into a silent site-wide availability regression.
 *
 * @param string $identity
 * @param bool   $fail_closed True on the remote path only. @since 13.50.0.
 * @return array{allow:bool,retry_after:int}
 */
function sn_mcp_read_rate_limit_check( $identity, $fail_closed = false ) {
	$key   = sn_mcp_read_rate_limit_key( $identity );
	$count = sn_mcp_read_rate_limit_store_get( $key );
	if ( null === $count ) {
		if ( ! sn_mcp_read_rate_limit_miss_allows( $fail_closed, sn_mcp_read_rate_limit_store_available() ) ) {
			return array( 'allow' => false, 'retry_after' => SN_MCP_READ_RATE_LIMIT_WINDOW_SECONDS );
		}
		$count = 0;
	}
	if ( sn_mcp_read_rate_limit_decision( $count, SN_MCP_READ_RATE_LIMIT_PER_MINUTE ) ) {
		sn_mcp_read_rate_limit_store_set( $key, $count + 1, SN_MCP_READ_RATE_LIMIT_WINDOW_SECONDS );
		return array( 'allow' => true, 'retry_after' => 0 );
	}
	return array( 'allow' => false, 'retry_after' => SN_MCP_READ_RATE_LIMIT_WINDOW_SECONDS );
}

/**
 * Is this route a REMOTE slug's run route? The pure half of Task 4.A.
 *
 * Split from sn_mcp_read_guard_is_read_path() rather than folded into it,
 * because the two answer different questions: that one asks "does the ceiling
 * apply at all" (yes for both doors), this one asks "which side of the
 * fail-open/fail-closed split is this". Collapsing them would make the
 * asymmetry invisible at the call site.
 *
 * @since 13.50.0
 * @param string $route
 * @return bool
 */
function sn_mcp_read_guard_route_is_remote( $route ) {
	if ( ! function_exists( 'sn_mcp_remote_slugs' ) ) {
		return false;
	}
	$slug = sn_mcp_read_guard_route_slug( (string) $route );
	if ( '' === $slug ) {
		return false;
	}
	return in_array( $slug, sn_mcp_remote_slugs(), true );
}

/**
 * Is this route on the READ PATH? Both routes count — the MCP read door and the
 * native Abilities run route for a read-allowlisted ability. The same reasoning
 * as F2: gate the path, not one route on it.
 *
 * @param string $route
 * @return bool
 */
function sn_mcp_read_guard_is_read_path( $route ) {
	$route = (string) $route;
	$ns    = function_exists( 'sn_mcp_namespace' )
		? sn_mcp_namespace()
		: ( defined( 'SN_REST_NAMESPACE' ) ? SN_REST_NAMESPACE : 'signal-noise/v1' );
	if ( '/' . $ns . '/mcp' === $route ) {
		return true;
	}
	$slug = sn_mcp_read_guard_route_slug( $route );
	if ( '' === $slug ) {
		return false;
	}
	// The remote analytics slugs are deliberately OFF sn_mcp_allowlist() — the
	// laptop door must not gain them. But "off the exposure list" must not mean
	// "off the ceiling": left out, a remote slug's run route would have no rate
	// limit at all. A ceiling is about LOAD, and load is load whoever is asking.
	//
	// This is the ONLY read-guard function remote slugs enter. In particular
	// sn_mcp_read_guard_run_route() is untouched, so the READ kill switch — which
	// is fail-OPEN on absence — never answers for a remote slug. The remote door
	// has its own switch in mcp-remote-guard.php, and it fails CLOSED.
	if ( function_exists( 'sn_mcp_remote_slugs' ) && in_array( $slug, sn_mcp_remote_slugs(), true ) ) {
		return true;
	}
	if ( ! function_exists( 'sn_mcp_allowlist' ) ) {
		return false;
	}
	return in_array( $slug, sn_mcp_allowlist(), true );
}

/**
 * The current caller's rate-limit identity, from what the request layer exposes.
 *
 * @return string
 */
function sn_mcp_read_rate_limit_current_identity() {
	$uuid = '';
	if ( function_exists( 'wp_get_current_user' ) && function_exists( 'get_current_user_id' ) ) {
		$uuid = (string) get_current_user_id();
		$uuid = '0' === $uuid ? '' : 'user' . $uuid;
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return sn_mcp_read_rate_limit_identity( $uuid, '' !== $ip ? md5( $ip ) : '' );
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

/**
 * Apply the read ceiling to the whole read path (F1).
 *
 * Runs AFTER the kill-switch guard on the same hook, so a disabled door answers
 * 403 rather than 429: "closed" is a stronger statement than "slow down", and a
 * caller told to slow down will keep trying.
 *
 * @param mixed $result  Pre-dispatch result; non-null means someone answered.
 * @param mixed $server  Unused.
 * @param mixed $request The REST request.
 * @return mixed
 */
function sn_mcp_read_guard_rate_limit_dispatch( $result, $server = null, $request = null ) {
	if ( null !== $result ) {
		return $result;
	}
	$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	if ( '' === $route || ! sn_mcp_read_guard_is_read_path( $route ) ) {
		return $result;
	}
	$decision = sn_mcp_read_rate_limit_check(
		sn_mcp_read_rate_limit_current_identity(),
		sn_mcp_read_guard_route_is_remote( $route )
	);
	if ( ! empty( $decision['allow'] ) ) {
		return $result;
	}
	return new WP_Error(
		'sn_mcp_read_rate_limited',
		__( 'The MCP read door is rate limited; retry shortly.', 'signal-and-noise-tools' ),
		array( 'status' => 429, 'retry_after' => (int) $decision['retry_after'] )
	);
}
if ( function_exists( 'add_filter' ) ) {
	// Priority 11: strictly after the kill-switch guard at 10.
	add_filter( 'rest_pre_dispatch', 'sn_mcp_read_guard_rate_limit_dispatch', 11, 3 );
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
