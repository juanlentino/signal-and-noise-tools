<?php
/**
 * Signal & Noise — the Worker→origin bridge (R3 §3D, Increment 1 bridge half).
 *
 * ONE route, POST /signal-noise/v1/bridge, that lets the remote analytics Worker
 * call exactly the slugs on sn_mcp_remote_slugs() and nothing else.
 *
 * THE REGISTRATION GATE IS THE DESIGN. The route is registered only when the
 * remote kill switch is on AND SN_BRIDGE_TOKEN is defined. Both failure modes
 * are therefore a route that does not exist — a 404 — rather than a handler that
 * decides to refuse. An unregistered route cannot be reached by a handler bug, a
 * filter ordering mistake, or a future refactor.
 *
 * It also refuses to leak: an earlier draft answered 503 when the secret was
 * missing, to separate misconfiguration from a client error. A 503 tells an
 * unauthenticated caller the route exists and the site means to serve it, which
 * is exactly the reconnaissance a 404 denies. Diagnosis lives in the admin
 * status panel, which is authenticated.
 *
 * THE SECRET IS A CONSTANT, NOT AN OPTION, and that is stronger rather than more
 * awkward: an option is readable by anything that reaches the database — an
 * admin-level compromise, a plugin vulnerability, a leaked SQL dump — while
 * wp-config.php is readable by no web request. Same reasoning as
 * SN_MCP_READ_DISABLED and SN_MCP_REMOTE_DISABLED.
 *
 * Prior art for verifying an INBOUND Worker credential is
 * inc/analytics-refresh-rest.php, which compares SN_SRV_TOKEN with hash_equals().
 * (SN_MR_READ_TOKEN in inc/machine-readers-api.php is OUTBOUND — WP calling a
 * Worker — and is not the pattern here.)
 *
 * @package SignalNoiseTools
 * @since 10.101.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bridge secret, or '' when it is not usable.
 *
 * Absent and empty are deliberately the same answer: a constant defined as ''
 * must never authenticate anybody.
 *
 * @return string
 */
function sn_bridge_secret() {
	return ( defined( 'SN_BRIDGE_TOKEN' ) && '' !== (string) SN_BRIDGE_TOKEN )
		? (string) SN_BRIDGE_TOKEN
		: '';
}

/**
 * Does the Authorization header carry the configured secret?
 *
 * hash_equals() rather than === so the comparison does not leak the secret's
 * prefix through timing. Mirrors inc/analytics-refresh-rest.php.
 *
 * An empty $secret refuses everything. That case cannot reach here through
 * sn_bridge_register_routes() — the route would not exist — but the function is
 * public and must not become an authenticator for a misconfigured site if it is
 * ever called from somewhere else.
 *
 * @param string|null $header The raw Authorization header.
 * @param string      $secret The configured secret.
 * @return bool
 */
function sn_bridge_bearer_matches( $header, $secret ) {
	$secret = (string) $secret;
	if ( '' === $secret ) {
		return false;
	}
	$header = (string) $header;
	if ( 0 !== strncmp( $header, 'Bearer ', 7 ) ) {
		return false;
	}
	return hash_equals( $secret, substr( $header, 7 ) );
}

/**
 * The in-flight verification flag.
 *
 * A module-scoped static rather than a global so nothing outside this file can
 * set it. The capability filter consults ONLY this — never the request — so a
 * request that did not pass verification cannot be granted anything even if the
 * filter is somehow still attached.
 *
 * @param bool $value
 * @return void
 */
function sn_bridge_set_verified( $value ) {
	sn_bridge_verified_state( (bool) $value );
}

/**
 * @return bool
 */
function sn_bridge_is_verified() {
	return sn_bridge_verified_state( null );
}

/**
 * Single owner of the flag's storage.
 *
 * @param bool|null $set Null reads; a bool writes.
 * @return bool
 */
function sn_bridge_verified_state( $set = null ) {
	static $verified = false;
	if ( null !== $set ) {
		$verified = (bool) $set;
	}
	return $verified;
}

/**
 * Grant the remote capability, and ONLY while a verified request is in flight.
 *
 * Attached to `user_has_cap` for the duration of one dispatch and removed in a
 * `finally`. Grants exactly one capability — never a role, never
 * manage_options, and never anything derived from the request.
 *
 * @param array $allcaps
 * @return array
 */
function sn_bridge_grant_capability( $allcaps ) {
	if ( ! sn_bridge_is_verified() ) {
		return $allcaps;
	}
	$allcaps = is_array( $allcaps ) ? $allcaps : array();
	$cap     = defined( 'SN_MCP_REMOTE_CAPABILITY' ) ? SN_MCP_REMOTE_CAPABILITY : 'sn_read_remote_analytics';
	$allcaps[ $cap ] = true;
	return $allcaps;
}

/**
 * The refusal an UNAUTHENTICATED caller sees — byte-identical to the one
 * WordPress itself returns for a route that was never registered.
 *
 * WHY IT IS COPIED FROM CORE RATHER THAN NAMED OURSELVES. The registration gate
 * means "switch off" and "secret absent" are a route that does not exist, and
 * core answers that with `rest_no_route` / 404. If our own pre-auth refusal
 * carries a DIFFERENT code or message, an anonymous prober still separates the
 * two — the status matches but the body does not, and a REST client reads the
 * body. Answering 404 instead of 401 (v11.0.0) closed the status half of that
 * oracle and left the body half open. This closes it.
 *
 * The literal is `WP_REST_Server::dispatch()`'s, verbatim:
 *
 *     return new WP_Error(
 *         'rest_no_route',
 *         __( 'No route was found matching the URL and request method.' ),
 *         array( 'status' => 404 )
 *     );
 *
 * THE `default` TEXT DOMAIN IS THE POINT, not an oversight. Core translates that
 * string in the `default` domain; ours must resolve through the same catalogue
 * or the two bodies diverge on every non-English site — which would rebuild the
 * oracle for exactly the installs least likely to notice. Do not "fix" it to
 * signal-and-noise-tools.
 *
 * A caller who ALREADY HOLDS the secret is answered with sn_bridge_not_found
 * instead. That distinction is deliberate: they are authenticated, so telling
 * them the slug was unknown leaks nothing, and the Worker gets a code it can log.
 *
 * @return WP_Error
 */
function sn_bridge_absent_route_error() {
	return new WP_Error(
		'rest_no_route',
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Deliberate: byte-parity with core's own rest_no_route body. See the docblock above.
		// WHAT IS VERIFIED ABOUT THIS IGNORE, and what is not. Removing it leaves
		// `composer lint` green — that sniff is not enabled in the local WPCS
		// ruleset — so the ignore is NOT load-bearing there. It is kept for
		// Plugin Check, which compares the domain against the plugin SLUG and
		// would read 'default' as a mismatch. That second half is REASONED, not
		// measured: CI has only ever run this file WITH the ignore present, and
		// a pass in that state cannot tell "needed" from "harmless". If you ever
		// want to know, drop the ignore on a throwaway branch and read Plugin
		// Check — do not infer it from a green run that included it.
		__( 'No route was found matching the URL and request method.', 'default' ),
		array( 'status' => 404 )
	);
}

/**
 * Should the bridge route exist at all on this request?
 *
 * BOTH gates, and neither alone. The kill switch is checked through the remote
 * guard so there is one definition of "the remote door is open".
 *
 * @return bool
 */
function sn_bridge_should_register() {
	if ( ! function_exists( 'sn_mcp_remote_kill_switch_engaged' ) ) {
		return false;
	}
	if ( sn_mcp_remote_kill_switch_engaged() ) {
		return false;
	}
	return '' !== sn_bridge_secret();
}

/**
 * Register the route, or do nothing at all.
 *
 * @return void
 */
function sn_bridge_register_routes() {
	if ( ! sn_bridge_should_register() ) {
		return;
	}
	if ( ! function_exists( 'register_rest_route' ) ) {
		return;
	}
	register_rest_route(
		'signal-noise/v1',
		'/bridge',
		array(
			'methods'             => 'POST',
			// Absent from GET /wp-json/signal-noise/v1. The index listed /bridge
			// only when both gates were open, which was a free oracle for
			// "the door is armed" — cheaper than probing the endpoint itself.
			'show_in_index'       => false,
			// Authentication happens in the handler, in ONE ordered place, so
			// there is never a state where a partially-authenticated request is
			// already inside the abilities layer.
			'permission_callback' => '__return_true',
			'callback'            => 'sn_bridge_handle_request',
		)
	);
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'sn_bridge_register_routes' );
}

/**
 * Handle one bridge call.
 *
 * VERIFICATION ORDER, each step failing closed:
 *
 *   0. both gates still open      -> else 404 (rest_no_route)
 *   1. Bearer matches             -> else 404 (rest_no_route)
 *   2. slug is on the remote list -> else 404 sn_bridge_not_found (never 403)
 *   3. the ability resolves       -> else 404 sn_bridge_not_found
 *
 * STEP 0 IS DEFENCE IN DEPTH, not the primary control. The registration gate
 * already guaranteed both gates were open when `rest_api_init` ran, so this can
 * only differ from it inside the window between registration and dispatch on a
 * SINGLE request — an owner unchecking the toggle mid-flight. That is not a
 * durable bypass and was never one; re-checking simply means the last word on
 * "is the door open" is read as late as possible rather than as early. It shares
 * a predicate with registration on purpose: two answers to that question is how
 * they drift apart.
 *
 * STEP 2 ANSWERS 404, NOT 403, ON PURPOSE. A 403 confirms the slug exists and
 * turns this endpoint into an enumeration oracle for the remote allowlist.
 * sn_mcp_call_tool() already answers unknown tools with -32602 rather than a
 * permission error; this is the REST-shaped equivalent.
 *
 * There is no separate scope check: THE REMOTE SLUG LIST IS THE SCOPE. With one
 * secret and one list, a per-secret scope field would encode the same fact twice
 * and could drift out of step with it.
 *
 * @param object $request The REST request.
 * @return array|WP_Error
 */
function sn_bridge_handle_request( $request ) {
	// STEP 0 — the gates, read again as late as possible. Same predicate as
	// registration, so there is one definition of "the door is open".
	if ( ! sn_bridge_should_register() ) {
		return sn_bridge_absent_route_error();
	}

	$header = is_object( $request ) && method_exists( $request, 'get_header' )
		? $request->get_header( 'authorization' )
		: null;

	// A BAD BEARER ANSWERS EXACTLY WHAT AN UNREGISTERED ROUTE ANSWERS, AND THAT
	// IS THE POINT. An earlier version answered 401 here, which told an
	// unauthenticated caller the route exists and the site means to serve it —
	// exactly the leak this design folded the old 503 into registration to
	// prevent. v11.0.0 changed it to 404, which closed the STATUS half of the
	// oracle and left the BODY half open: `sn_bridge_not_found` / "Not found."
	// still read differently from core's `rest_no_route`, and a REST client
	// reads the body. sn_bridge_absent_route_error() is core's, verbatim.
	//
	// So an anonymous probe cannot distinguish: switch off, secret absent, wrong
	// secret, or a plugin that was never installed. A caller who ALREADY HOLDS
	// the secret still learns 400-vs-404 and a distinct code, which is
	// deliberate — they are authenticated, so it leaks nothing.
	if ( ! sn_bridge_bearer_matches( $header, sn_bridge_secret() ) ) {
		return sn_bridge_absent_route_error();
	}

	$body = ( is_object( $request ) && method_exists( $request, 'get_json_params' ) )
		? (array) $request->get_json_params()
		: array();
	$slug = isset( $body['slug'] ) ? (string) $body['slug'] : '';
	$args = ( isset( $body['args'] ) && is_array( $body['args'] ) ) ? $body['args'] : array();

	if ( '' === $slug ) {
		return new WP_Error(
			'sn_bridge_bad_request',
			__( 'Missing slug.', 'signal-and-noise-tools' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'sn_mcp_remote_slugs' ) || ! in_array( $slug, sn_mcp_remote_slugs(), true ) ) {
		return new WP_Error(
			'sn_bridge_not_found',
			__( 'Not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $slug ) : null;
	if ( ! $ability ) {
		return new WP_Error(
			'sn_bridge_not_found',
			__( 'Not found.', 'signal-and-noise-tools' ),
			array( 'status' => 404 )
		);
	}

	// Grant, dispatch, and ALWAYS put it back. The finally is the reason this is
	// safe: an ability that throws must not leave the capability attached.
	sn_bridge_set_verified( true );
	add_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10, 1 );
	try {
		$out = $ability->execute( $args );
	} finally {
		remove_filter( 'user_has_cap', 'sn_bridge_grant_capability', 10 );
		sn_bridge_set_verified( false );
	}

	return is_wp_error( $out ) ? $out : array( 'ok' => true, 'data' => $out );
}
