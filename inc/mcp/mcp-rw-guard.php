<?php
/**
 * Signal & Noise — MCP rw-door guard (v9.51.0, lane SEC-A). Owns the two
 * measures that gate every request reaching POST /mcp-rw, ranked #1 and #2 in
 * the hardening research: the credential split (R1) and the kill switch (R2).
 * inc/mcp/mcp-endpoint.php wires these into the rw route's permission_callback;
 * this file contains no route/REST plumbing of its own.
 *
 * Every decision is a PURE predicate: all state (the authenticated app-password
 * UUID, the bound UUID, the constant, the option) is passed in as a parameter —
 * no get_option()/defined()/rest_get_authenticated_app_password() call lives
 * inside a *_decision() function. Each pure predicate has a paired "live"
 * wrapper (no _decision suffix) that gathers the real WP state and calls it.
 * Tests exercise both: the pure fn directly (no WP bootstrap needed) and the
 * live wrapper (via the get_option()/rest_get_authenticated_app_password()
 * stubs) — this is the "injectable predicate" contract the spec pins.
 *
 * The read door (/mcp) never calls anything in this file — sn_mcp_permission()
 * in mcp-endpoint.php is untouched. That asymmetry (R1's "optional mutual
 * exclusion" is declined) is deliberate: only the rw door is restrictive.
 *
 * @package SignalNoiseTools
 * @since 9.51.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_MCP_RW_BOUND_UUID_OPTION = 'sn_mcp_rw_app_password_uuid';
const SN_MCP_RW_ENABLED_OPTION    = 'sn_mcp_rw_enabled';

/**
 * R2 kill-switch PURE predicate: is the rw door disabled? The wp-config
 * constant (SN_MCP_RW_DISABLED) is bulletproof — an attacker holding only a
 * leaked application password can never flip it — and wins over the option
 * unconditionally. The option (sn_mcp_rw_enabled) is the owner's UI-reachable
 * kill; its default (absent = true = enabled) is a FAIL-OPEN-ON-ABSENCE choice
 * per the spec's ground rules, distinct from R1's fail-CLOSED default for an
 * unbound credential — two different "nothing configured yet" states with two
 * different safe defaults, because an absent kill-switch option means "the
 * owner never touched it" (safe to stay on) while an absent bound credential
 * means "the owner never finished setup" (unsafe to allow).
 *
 * @param bool $constant_disabled Whether defined('SN_MCP_RW_DISABLED') && SN_MCP_RW_DISABLED.
 * @param bool $option_enabled    The sn_mcp_rw_enabled option's value (default true).
 * @return bool True when the rw door must be treated as disabled.
 */
function sn_mcp_rw_kill_switch_decision( $constant_disabled, $option_enabled ) {
	if ( (bool) $constant_disabled ) {
		return true;
	}
	return ! (bool) $option_enabled;
}

/**
 * Live: is the SN_MCP_RW_DISABLED wp-config constant set truthy?
 *
 * @return bool
 */
function sn_mcp_rw_kill_switch_constant_disabled() {
	return defined( 'SN_MCP_RW_DISABLED' ) && SN_MCP_RW_DISABLED;
}

/**
 * Live: the owner's UI kill-switch option. Default true (enabled) — the
 * fail-open-on-absence case a fresh install (or one that's never touched this
 * setting) must land in.
 *
 * @return bool
 */
function sn_mcp_rw_enabled_option() {
	return (bool) get_option( SN_MCP_RW_ENABLED_OPTION, true );
}

/**
 * Live wrapper around sn_mcp_rw_kill_switch_decision(): gathers the real
 * constant + option state. This is what the rw permission_callback actually
 * calls; tests call the _decision() predicate directly with injected params.
 *
 * @return bool
 */
function sn_mcp_rw_kill_switch_engaged() {
	return sn_mcp_rw_kill_switch_decision(
		sn_mcp_rw_kill_switch_constant_disabled(),
		sn_mcp_rw_enabled_option()
	);
}

/**
 * R1 credential-split PURE predicate: may this authenticated request reach the
 * rw door? Three outcomes, in order (mirrors the spec's bullet list exactly):
 *
 *   - bound UUID empty            -> deny, rw_credential_unbound (R1 DECISION:
 *     unbound state = deny-closed. The write door does nothing until the
 *     owner names its credential in the leaf — see mcp-connect.php, lane
 *     SEC-C. This is the single most consequential judgment call in this
 *     lane: it means a site that has only ever generated ONE application
 *     password (used for both doors, or for neither) gets a permanently
 *     inert write door until the owner deliberately generates a second one
 *     and binds it. That is the intended trade — see the spec's ground rule
 *     "fail-closed on the SECURITY direction" — but it is a real UX cost the
 *     owner should expect immediately after this ships.)
 *   - bound UUID set, but this request didn't authenticate via an application
 *     password at all (has_app_password_auth false — e.g. cookie+nonce auth
 *     from wp-admin, which still passes manage_options) -> deny,
 *     credential_not_authorized.
 *   - bound UUID set and the authenticated UUID doesn't match -> deny,
 *     credential_not_authorized.
 *   - bound UUID set and matches -> allow.
 *
 * @param string $bound_uuid            sn_mcp_rw_app_password_uuid option ('' = unbound).
 * @param string $authenticated_uuid    The UUID rest_get_authenticated_app_password() returned for THIS request ('' = none).
 * @param bool   $has_app_password_auth Whether this request authenticated via an application password at all.
 * @return array{allow:bool,code:string} code is '' on allow.
 */
function sn_mcp_rw_credential_decision( $bound_uuid, $authenticated_uuid, $has_app_password_auth ) {
	$bound_uuid = (string) $bound_uuid;
	if ( '' === $bound_uuid ) {
		return array( 'allow' => false, 'code' => 'rw_credential_unbound' );
	}
	if ( ! (bool) $has_app_password_auth ) {
		return array( 'allow' => false, 'code' => 'credential_not_authorized' );
	}
	if ( ! hash_equals( $bound_uuid, (string) $authenticated_uuid ) ) {
		return array( 'allow' => false, 'code' => 'credential_not_authorized' );
	}
	return array( 'allow' => true, 'code' => '' );
}

/**
 * Live: the rw-bound application-password UUID, or '' if never configured.
 *
 * @return string
 */
function sn_mcp_rw_bound_uuid() {
	$uuid = get_option( SN_MCP_RW_BOUND_UUID_OPTION, '' );
	return is_string( $uuid ) ? $uuid : '';
}

/**
 * Live: the application-password UUID that authenticated the CURRENT request,
 * or '' if this request didn't authenticate via an application password (e.g.
 * cookie+nonce, or WP < 5.7 where the function doesn't exist at all — guarded
 * per the spec's "guard function_exists (WP 5.7+)" instruction).
 *
 * @return string
 */
function sn_mcp_rw_authenticated_app_password_uuid() {
	if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
		return '';
	}
	$uuid = rest_get_authenticated_app_password();
	return is_string( $uuid ) ? $uuid : '';
}

/**
 * Live wrapper around sn_mcp_rw_credential_decision(): gathers the real bound
 * UUID + the current request's authenticated UUID. This is what the rw
 * permission_callback actually calls; tests call the _decision() predicate
 * directly with injected params.
 *
 * @return array{allow:bool,code:string}
 */
function sn_mcp_rw_credential_authorize() {
	$authenticated_uuid = sn_mcp_rw_authenticated_app_password_uuid();
	return sn_mcp_rw_credential_decision(
		sn_mcp_rw_bound_uuid(),
		$authenticated_uuid,
		'' !== $authenticated_uuid
	);
}

/**
 * Does a string look like a UUID (any version/variant — WP's
 * wp_generate_uuid4() output, which is what an application password UUID
 * actually is)? Used before ever persisting a value into the bound-UUID
 * option, so a malformed/injected value can never even get written — the
 * credential-decision comparison downstream never has to defend against
 * garbage in that option.
 *
 * @param string $uuid
 * @return bool
 */
function sn_mcp_rw_uuid_shape_valid( $uuid ) {
	return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $uuid );
}

/**
 * Set (or clear) the rw-bound application-password UUID. Owned here (SEC-A);
 * called from the SEC-C leaf's settings-save handler. Validates shape before
 * writing — an empty string is a legal, explicit "unbind" (distinct from
 * having never called this at all, though both read back as '' via
 * sn_mcp_rw_bound_uuid()). A non-empty value that isn't a well-formed UUID is
 * rejected outright (returns false, option untouched) rather than stored and
 * trusted downstream.
 *
 * Note on update_option()'s slash asymmetry (project memory: update_option()
 * does NOT unslash): this setter stores a value that has already passed the
 * strict UUID regex above, and a UUID's character set (hex digits + hyphens)
 * can never contain a backslash or quote — so there is no slash-doubling
 * surface here regardless of whether the caller wp_unslash()'d $_POST first.
 * The caller (the SEC-C form handler) should still wp_unslash() its raw
 * $_POST value before calling this, per the plugin's standing convention —
 * belt-and-braces, even though this validator makes it moot for THIS option.
 *
 * @param string $uuid
 * @return bool True on a successful set (including an explicit clear); false
 *              when a non-empty value fails shape validation.
 */
function sn_mcp_set_rw_bound_uuid( $uuid ) {
	$uuid = trim( (string) $uuid );
	if ( '' === $uuid ) {
		return update_option( SN_MCP_RW_BOUND_UUID_OPTION, '' );
	}
	if ( ! sn_mcp_rw_uuid_shape_valid( $uuid ) ) {
		return false;
	}
	return update_option( SN_MCP_RW_BOUND_UUID_OPTION, strtolower( $uuid ) );
}

/**
 * Denial messages per guard code. Every message names the fix where one
 * exists (R1: "the 403 body must name the fix").
 *
 * @return array<string,string>
 */
function sn_mcp_rw_error_messages() {
	return array(
		'rw_disabled'               => __( 'The MCP write door is currently disabled.', 'signal-and-noise-tools' ),
		'rw_credential_unbound'     => __( 'The MCP write door has no Application Password bound to it yet. Set the write-door Application Password in Tools -> MCP.', 'signal-and-noise-tools' ),
		'credential_not_authorized' => __( 'This request did not authenticate with the Application Password bound to the MCP write door. Use that Application Password, or re-bind the write door in Tools -> MCP.', 'signal-and-noise-tools' ),
	);
}

/**
 * Build the WP_Error a permission_callback returns for a denied rw request.
 * Every guard denial is a 403 (deny, not "not found" — the door exists, the
 * credential just isn't authorized for it).
 *
 * @param string $code
 * @param int    $status
 * @return WP_Error
 */
function sn_mcp_rw_error( $code, $status = 403 ) {
	$messages = sn_mcp_rw_error_messages();
	$message  = $messages[ (string) $code ] ?? __( 'The MCP write door denied this request.', 'signal-and-noise-tools' );
	return new WP_Error( 'sn_mcp_rw_' . (string) $code, $message, array( 'status' => (int) $status ) );
}

/* ════════════════════════════════════════════════════════════════════════
 * R7 (lane SEC-C) — rate limit on the rw door. Token-bucket-shaped (in
 * practice a fixed-window counter, which is what "modest per-minute cap,
 * catches loops" actually needs — a true leaky bucket buys nothing extra for
 * a single-owner low-volume door) keyed on the authenticated app-password UUID,
 * falling back to a hashed IP when no app-password authenticated the request.
 * Identity NEVER resolves to "unlimited": sn_mcp_rw_rate_limit_identity()
 * always returns a concrete, non-empty bucket key — the probe's explicit
 * question ("Rate-limit key can't be spoofed to bypass... never to
 * 'unlimited'").
 *
 * JUDGMENT CALL (flagged per the task): the spec placed R7 conceptually
 * alongside R1/R2 in mcp-rw-guard.php, and R1/R2 are sequenced into the rw
 * door's permission_callback (inc/mcp/mcp-endpoint.php's
 * sn_mcp_rw_permission()) — a file this lane is NOT scoped to edit. R7's own
 * text says a denial is "a JSON-RPC error... carry it in the error data",
 * which is a tools/call-dispatch-layer shape (sn_mcp_call_tool()'s existing
 * `{error:{code,message,data}}` return), not a REST permission_callback/
 * WP_Error shape (R1/R2's layer). Both readings are internally consistent
 * with SOMETHING in the spec; given the explicit error-shape instruction and
 * the file-scope boundary, this file owns the PREDICATES ONLY (as sequenced
 * here) and inc/mcp/mcp-tools.php's sn_mcp_call_tool() (in-scope) is the
 * actual gate, checked first, gated on $door === SN_MCP_DOOR_RW — see that
 * file. The read door never calls anything below this line.
 * ════════════════════════════════════════════════════════════════════════ */

const SN_MCP_RW_RATE_LIMIT_PER_MINUTE      = 30; // Generous for a human-driven agent; catches a runaway loop.
const SN_MCP_RW_RATE_LIMIT_WINDOW_SECONDS  = 60;
const SN_MCP_RW_RATE_LIMIT_CACHE_GROUP     = 'sn_mcp_rw_rate';

/**
 * PURE predicate: given how many rw calls this identity has already made in
 * the current window and the configured cap, is another call allowed?
 *
 * @param int $count_in_window
 * @param int $cap
 * @return bool
 */
function sn_mcp_rw_rate_limit_decision( $count_in_window, $cap ) {
	return (int) $count_in_window < (int) $cap;
}

/**
 * Resolve the rate-limit bucket identity for a request. UUID first (an
 * app-password is the intended, precise identity); the hashed IP is the
 * fallback ONLY when no app-password authenticated the request at all — and
 * even that fallback never degrades to an empty/absent key: an empty IP hash
 * (no REMOTE_ADDR, e.g. CLI) still buckets under a concrete shared identity
 * ('ip:unknown'), never "skip the check". This is the probe's pinned property.
 *
 * @param string $app_pw_uuid
 * @param string $ip_hash
 * @return string
 */
function sn_mcp_rw_rate_limit_identity( $app_pw_uuid, $ip_hash ) {
	$app_pw_uuid = (string) $app_pw_uuid;
	if ( '' !== $app_pw_uuid ) {
		return 'uuid:' . $app_pw_uuid;
	}
	$ip_hash = (string) $ip_hash;
	return 'ip:' . ( '' !== $ip_hash ? $ip_hash : 'unknown' );
}

/**
 * The rate-limit store key for an identity — namespaced so it can never
 * collide with an unrelated option/transient/cache key elsewhere in the
 * plugin.
 *
 * @param string $identity
 * @return string
 */
function sn_mcp_rw_rate_limit_key( $identity ) {
	return 'sn_mcp_rw_rate_' . md5( (string) $identity );
}

/**
 * Read the current window's call count for a key. Prefers the external
 * object cache when one is configured (wp_using_ext_object_cache()) — the
 * common case on a real host, and avoids autoloaded-option-style writes on
 * every single rw call — falling back to a transient otherwise. Returns null
 * when the key has never been set (distinct from 0, though both mean
 * "nothing recorded yet" to the caller).
 *
 * @param string $key
 * @return int|null
 */
function sn_mcp_rw_rate_limit_store_get( $key ) {
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' ) ) {
		$v = wp_cache_get( $key, SN_MCP_RW_RATE_LIMIT_CACHE_GROUP );
		return false === $v ? null : (int) $v;
	}
	$v = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
	return false === $v ? null : (int) $v;
}

/**
 * Write the current window's call count for a key, with the window's TTL —
 * same object-cache-or-transient branch as the getter.
 *
 * @param string $key
 * @param int    $count
 * @param int    $ttl_seconds
 * @return void
 */
function sn_mcp_rw_rate_limit_store_set( $key, $count, $ttl_seconds ) {
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_set' ) ) {
		wp_cache_set( $key, (int) $count, SN_MCP_RW_RATE_LIMIT_CACHE_GROUP, (int) $ttl_seconds );
		return;
	}
	if ( function_exists( 'set_transient' ) ) {
		set_transient( $key, (int) $count, (int) $ttl_seconds );
	}
}

/**
 * Check-and-increment for one identity: reads the current count, applies the
 * pure decision, and — ONLY when allowed — persists the incremented count
 * back with a fresh window TTL (a denied call never bumps the counter
 * further; it's already over, and re-arming the TTL on every denial would
 * turn a burst into an indefinitely-renewing lockout).
 *
 * @param string $identity
 * @return array{allow:bool,retry_after:int}
 */
function sn_mcp_rw_rate_limit_check( $identity ) {
	$key   = sn_mcp_rw_rate_limit_key( $identity );
	$count = sn_mcp_rw_rate_limit_store_get( $key );
	$count = null === $count ? 0 : $count;

	$allowed = sn_mcp_rw_rate_limit_decision( $count, SN_MCP_RW_RATE_LIMIT_PER_MINUTE );
	if ( $allowed ) {
		sn_mcp_rw_rate_limit_store_set( $key, $count + 1, SN_MCP_RW_RATE_LIMIT_WINDOW_SECONDS );
		return array( 'allow' => true, 'retry_after' => 0 );
	}
	return array( 'allow' => false, 'retry_after' => SN_MCP_RW_RATE_LIMIT_WINDOW_SECONDS );
}

/**
 * Hash an IP for the rate-limit identity fallback. Independent, tiny
 * duplicate of inc/mcp/mcp-rw-audit.php's sn_mcp_rw_audit_hash_ip() — same
 * rationale as that file's own docblock (each rw-lane file owns its own copy
 * rather than cross-depending for a two-line helper); reuses
 * snt_audit_hash_ip() when inc/audit-log.php is loaded, else an equivalent
 * wp_salt('auth')-salted sha256 fragment.
 *
 * @param string $ip
 * @return string
 */
function sn_mcp_rw_hash_ip( $ip ) {
	if ( function_exists( 'snt_audit_hash_ip' ) ) {
		return snt_audit_hash_ip( $ip );
	}
	$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '';
	return substr( hash( 'sha256', (string) $ip . $salt ), 0, 16 );
}

/**
 * The current request's remote IP, unslashed + sanitized. '' when unavailable
 * (CLI, test harness) — sn_mcp_rw_rate_limit_identity() turns that into
 * 'ip:unknown' rather than skipping the check. The value is only ever hashed
 * into a rate-limit bucket key, never rendered, but it is a security surface,
 * so it is sanitized at the boundary (this file is NOT phpcs-excluded, unlike
 * the sibling audit reads).
 *
 * @return string
 */
function sn_mcp_rw_rate_limit_current_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: '';
}

/**
 * LIVE: the full rate-limit gate for the CURRENT request. Gathers the real
 * authenticated app-password UUID (reuses this file's own
 * sn_mcp_rw_authenticated_app_password_uuid(), already defined above for R1)
 * + the current IP, resolves identity, and checks-and-increments. This is
 * what inc/mcp/mcp-tools.php's sn_mcp_call_tool() calls, gated on
 * $door === SN_MCP_DOOR_RW — see that file's docblock for why the call SITE
 * lives there rather than in this file's door-agnostic predicate layer.
 *
 * @return array{allow:bool,retry_after:int}
 */
function sn_mcp_rw_rate_limit_gate() {
	$app_pw_uuid = sn_mcp_rw_authenticated_app_password_uuid();
	$ip_hash     = sn_mcp_rw_hash_ip( sn_mcp_rw_rate_limit_current_ip() );
	$identity    = sn_mcp_rw_rate_limit_identity( $app_pw_uuid, $ip_hash );
	return sn_mcp_rw_rate_limit_check( $identity );
}

/* ════════════════════════════════════════════════════════════════════════
 * The abilities run route (v13.110.0, enforcement audit 2026-09-11 §Phase 1).
 *
 * THE SEAM. WordPress registers POST /wp-abilities/v1/abilities/<slug>/run
 * for every ability whose meta says show_in_rest => true — which is every rw
 * door slug and the seven pre-consolidation apply abilities. That route is
 * reachable with ANY manage_options application password, and until now none
 * of the four controls above applied to it: the rw kill switch, the bound
 * credential, the rate limit and the audit row all lived on
 * /signal-noise/v1/mcp-rw alone. The comment in inc/sn-apply/gates.php that
 * said "an unbound or mismatched UUID is denied at the door before sn_apply
 * ever runs" was true of the door and false of this route. Only a Cloudflare
 * WAF rule (dashboard, no repo) stood in front of it.
 *
 * WHY THE GUARD KEYS ON THE CREDENTIAL, NOT THE ROUTE. wp-admin's own buttons
 * (assets/snt-ability-run.js, the command palette) call this SAME route with
 * cookie + nonce. A route-keyed guard would break every admin button. So the
 * guard applies exactly when the request authenticated by application
 * password — the shape of the WAF rule, in code — and only for abilities not
 * annotated readonly. Cookie-auth, CLI, and reads pass untouched. The read
 * door's own run-route guard (mcp-read-guard.php) is unchanged and isolated,
 * as before.
 *
 * The four controls run in the door's order and reuse the door's predicates
 * and error vocabulary, so a refusal here is indistinguishable in code and
 * message from a refusal at /mcp-rw. Refusals write a 'denied' audit row
 * here; the outcome of an ALLOWED call is recorded on
 * rest_request_after_callbacks (the door records after execution, and this
 * hook is the only point on this route that sees the result).
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * The ability slug named by a native abilities run route, or '' for anything
 * else. Own copy rather than a cross-call into mcp-read-guard.php: the two
 * guards are isolated by design and must not gain a load-order dependency.
 *
 * @param string $route
 * @return string
 */
function sn_mcp_rw_guard_route_slug( $route ) {
	if ( ! is_string( $route ) || '' === $route ) {
		return '';
	}
	if ( 1 !== preg_match( '#^/wp-abilities/v[0-9]+/abilities/(.+)/run$#i', $route, $m ) ) {
		return '';
	}
	return (string) $m[1];
}

/**
 * Is a registered ability annotated readonly? Unknown ability (or no
 * Abilities API at all) answers TRUE — the guard then steps aside and core
 * answers 404 itself; a guard that refuses what does not exist would be an
 * enumeration oracle.
 *
 * @param string $slug
 * @return bool
 */
function sn_mcp_rw_guard_ability_is_readonly( $slug ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return true;
	}
	$ability = wp_get_ability( (string) $slug );
	if ( ! $ability || ! method_exists( $ability, 'get_meta' ) ) {
		return true;
	}
	$meta = (array) $ability->get_meta();
	$decl = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();
	return ! empty( $decl['readonly'] );
}

/**
 * PURE predicate: the verdict for one app-password-authenticated write on the
 * run route. Every input is injected; every refusal names the same code the
 * door would name.
 *
 * @param bool  $kill_engaged Result of sn_mcp_rw_kill_switch_engaged().
 * @param array $credential   Result of sn_mcp_rw_credential_decision().
 * @param array $rate         Result of sn_mcp_rw_rate_limit_check().
 * @return array{allow:bool,code:string,status:int,retry_after:int}
 */
function sn_mcp_rw_guard_run_route_decision( $kill_engaged, array $credential, array $rate ) {
	if ( (bool) $kill_engaged ) {
		return array( 'allow' => false, 'code' => 'rw_disabled', 'status' => 403, 'retry_after' => 0 );
	}
	if ( empty( $credential['allow'] ) ) {
		return array( 'allow' => false, 'code' => (string) ( $credential['code'] ?? 'credential_not_authorized' ), 'status' => 403, 'retry_after' => 0 );
	}
	if ( empty( $rate['allow'] ) ) {
		return array( 'allow' => false, 'code' => 'rate_limited', 'status' => 429, 'retry_after' => (int) ( $rate['retry_after'] ?? 0 ) );
	}
	return array( 'allow' => true, 'code' => '', 'status' => 200, 'retry_after' => 0 );
}

/**
 * Does THIS request fall under the run-route guard at all? Three conditions,
 * all required: a run route, an application-password credential, and an
 * ability that is not readonly. Returns the slug when it does, '' otherwise.
 *
 * @param object|null $request
 * @return string
 */
function sn_mcp_rw_guard_run_route_applies( $request ) {
	$route = ( is_object( $request ) && method_exists( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
	$slug  = sn_mcp_rw_guard_route_slug( $route );
	if ( '' === $slug ) {
		return '';
	}
	if ( '' === sn_mcp_rw_authenticated_app_password_uuid() ) {
		return '';
	}
	// A slug on the rw allowlist is the door's business whatever its
	// annotation says: describe-tags is returns-only (readonly => true,
	// honestly) and sits on the rw door because it BILLS an AI call — the
	// spend is the write. Annotation-only keying let it walk past.
	$on_rw_door = function_exists( 'sn_mcp_rw_allowlist' ) && in_array( $slug, sn_mcp_rw_allowlist(), true );
	if ( ! $on_rw_door && sn_mcp_rw_guard_ability_is_readonly( $slug ) ) {
		return '';
	}
	return $slug;
}

/**
 * rest_pre_dispatch: the live guard. Never overrides an answer someone else
 * already gave; never touches a request outside its three conditions.
 *
 * @param mixed       $result
 * @param object|null $server
 * @param object|null $request
 * @return mixed
 */
function sn_mcp_rw_guard_run_route( $result, $server = null, $request = null ) {
	if ( null !== $result ) {
		return $result;
	}
	$slug = sn_mcp_rw_guard_run_route_applies( $request );
	if ( '' === $slug ) {
		return $result;
	}

	$verdict = sn_mcp_rw_guard_run_route_decision(
		sn_mcp_rw_kill_switch_engaged(),
		sn_mcp_rw_credential_authorize(),
		sn_mcp_rw_rate_limit_gate()
	);
	if ( $verdict['allow'] ) {
		// The door drops include_template_overrides from purge-all-caches
		// before execute (mcp-tools.php); the same argument rule applies on
		// this route. The run controller reads input from the parsed JSON
		// body, which set_param() updates in place.
		if ( 'signal-noise/purge-all-caches' === $slug && method_exists( $request, 'get_json_params' ) && method_exists( $request, 'set_param' ) ) {
			$json = (array) $request->get_json_params();
			if ( isset( $json['input'] ) && is_array( $json['input'] ) && array_key_exists( 'include_template_overrides', $json['input'] ) ) {
				unset( $json['input']['include_template_overrides'] );
				$request->set_param( 'input', $json['input'] );
			}
		}
		return $result;
	}

	if ( 'rate_limited' === $verdict['code'] ) {
		$error = new WP_Error(
			'sn_mcp_rw_rate_limited',
			sprintf(
				/* translators: %d: seconds until the rate-limit window resets */
				__( 'Rate limit exceeded for the MCP write door. Retry after %d seconds.', 'signal-and-noise-tools' ),
				(int) $verdict['retry_after']
			),
			array( 'status' => 429, 'retry_after' => (int) $verdict['retry_after'] )
		);
	} else {
		$error = sn_mcp_rw_error( $verdict['code'], $verdict['status'] );
	}

	if ( function_exists( 'sn_mcp_rw_audit_record' ) ) {
		$args = ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) ? (array) $request->get_json_params() : array();
		sn_mcp_rw_audit_record( $slug, $args, 'denied', $error );
	}
	return $error;
}

/**
 * rest_request_after_callbacks: record the outcome of an ALLOWED
 * app-password write on the run route, so the audit log shows the same
 * ok/error rows for this route that the door writes for /mcp-rw.
 *
 * @param mixed       $response
 * @param mixed       $handler
 * @param object|null $request
 * @return mixed Untouched.
 */
function sn_mcp_rw_guard_run_route_audit( $response, $handler = null, $request = null ) {
	if ( ! function_exists( 'sn_mcp_rw_audit_record' ) ) {
		return $response;
	}
	$slug = sn_mcp_rw_guard_run_route_applies( $request );
	if ( '' === $slug ) {
		return $response;
	}
	$args = ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) ? (array) $request->get_json_params() : array();
	if ( is_wp_error( $response ) ) {
		sn_mcp_rw_audit_record( $slug, $args, 'error', $response );
	} else {
		sn_mcp_rw_audit_record( $slug, $args, 'ok', null );
	}
	return $response;
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'rest_pre_dispatch', 'sn_mcp_rw_guard_run_route', 10, 3 );
	add_filter( 'rest_request_after_callbacks', 'sn_mcp_rw_guard_run_route_audit', 10, 3 );
}
