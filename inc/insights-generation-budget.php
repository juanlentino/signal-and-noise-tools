<?php
/**
 * Signal & Noise Tools — generation-budget shaping for the Insights scan.
 *
 * WHY THIS EXISTS. On claude-sonnet-5 with no explicit effort config, thinking
 * is CEILING-BOUNDED: it consumes whatever `max_tokens` allows and leaves the
 * answer no room. Measured live on this site 2026-08-28 — the scan burned its
 * entire 2048-token budget and returned 310 characters of JSON, cut mid-string.
 * ~1,970 output tokens generated and billed without ever reaching the text.
 *
 * RAISING THE CEILING DOES NOT FIX IT, and this is the second time that has
 * been established here. v10.53.0 tried it on the agent path and was falsified
 * the same night (see inc/openstation-agent-output-budget.php); v13.20.5 tried
 * it here, 2048 -> 4096, and the bigger thinking block simply took longer than
 * the 30s HTTP timeout — cURL error 28, 0 bytes received. Do not try it a third
 * time.
 *
 * THE WORKING CONFIGURATION, live-verified on the agent path 2026-08-07: an
 * explicit effort config makes thinking DEMAND-bounded (~3.7k tokens rather
 * than however much is offered), and a ceiling modestly above demand leaves
 * room for the answer. This module is that same seam, scoped to the Insights
 * call — which needed its own, because the agent seam is armed only from the
 * agent runner and explicitly never fires for SN's own AI helpers.
 *
 * WHY A REQUEST FILTER AND NOT A PARAMETER. snt_ai_generate_with_constraints()
 * can only pass what the WP AI Client builder exposes — prompt, system,
 * max_tokens, model list — and `thinking`/`output_config` are Anthropic-specific,
 * below that ceiling. But every Anthropic request still leaves through core's
 * `wp_remote_request()`, so `http_request_args` can rewrite the JSON body after
 * the builder produced it. That also lets the ceiling here exceed the helper's
 * own min(4096, ...) clamp, which applies to the argument and not to the wire.
 *
 * SCOPE. Armed immediately around the one call in snt_insights_call_ai() and
 * disarmed straight after, so no other feature — and no other plugin's traffic
 * — can see it. Non-matching requests are returned BYTE-IDENTICAL: a decode +
 * re-encode of an untouched body would still perturb the transport.
 *
 * @package SignalNoiseTools
 * @since   13.20.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ceiling for the shaped Insights request.
 *
 * Effort bounds thinking by demand (~3.7k measured on the agent path); the scan
 * itself needs roughly 2k for three verbose questions (established in v7.1.1).
 * 8192 covers both with headroom. A ceiling is not a spend — output bills only
 * when generated.
 */
if ( ! defined( 'SN_INSIGHTS_WIRE_MAX_TOKENS' ) ) {
	define( 'SN_INSIGHTS_WIRE_MAX_TOKENS', 8192 );
}

/**
 * Transport timeout for the shaped request, in seconds.
 *
 * The default 30s is what v13.20.5 died against once the generation grew. A
 * thinking pass plus a structured answer runs well past it. This is an explicit
 * owner-initiated scan or a cron run, so a long wait is acceptable where it
 * would not be on a page render.
 */
if ( ! defined( 'SN_INSIGHTS_WIRE_TIMEOUT' ) ) {
	define( 'SN_INSIGHTS_WIRE_TIMEOUT', 180 );
}

/**
 * Arm the shaper for the current request. Idempotent.
 *
 * @return void
 */
function snt_insights_budget_arm() {
	if ( false === has_filter( 'http_request_args', 'snt_insights_budget_shape' ) ) {
		add_filter( 'http_request_args', 'snt_insights_budget_shape', PHP_INT_MAX, 2 );
	}
}

/**
 * Disarm it. Called in a finally, so a thrown or errored call cannot leave the
 * filter hooked for the rest of the request.
 *
 * @return void
 */
function snt_insights_budget_disarm() {
	remove_filter( 'http_request_args', 'snt_insights_budget_shape', PHP_INT_MAX );
}

/**
 * Whether a model id is Claude 5 family (the adaptive-thinking API shape).
 *
 * The effort keys are family-specific: `thinking.type: "enabled"` is REJECTED
 * by Claude 5 with a 400 directing callers to adaptive + output_config.effort,
 * and adaptive would 400 on older families. Anything not matching is left alone
 * rather than guessed at.
 *
 * @param string $model Model id from the request body.
 * @return bool
 */
function snt_insights_budget_model_is_claude5( $model ) {
	return 1 === preg_match( '/^claude-[a-z]+-5([.\-]|$)/', (string) $model );
}

/**
 * Shape the Insights Anthropic request so the answer can complete.
 *
 * @param array  $args Request args; body is a JSON string on this path.
 * @param string $url  Request URL.
 * @return array Possibly-rewritten args.
 */
function snt_insights_budget_shape( $args, $url ) {
	if ( false === strpos( (string) $url, 'api.anthropic.com/v1/messages' ) ) {
		return $args;
	}
	if ( ! is_array( $args ) || ! isset( $args['body'] ) || ! is_string( $args['body'] ) ) {
		return $args;
	}

	$body = json_decode( $args['body'], true );
	if ( ! is_array( $body ) || ! snt_insights_budget_model_is_claude5( $body['model'] ?? '' ) ) {
		return $args;
	}

	$changed = false;

	// Is thinking BOUNDED on this request — by someone else's decision, or by
	// the effort config injected below? The ceiling raise depends on it: with
	// thinking demand-bounded, headroom goes to the answer; with thinking
	// ceiling-bounded, headroom goes to thinking and the answer still never
	// lands. That is precisely how v13.20.5 turned a fast JSON failure into a
	// 30-second timeout. Never raise the ceiling of an unbounded request.
	$bounded = isset( $body['thinking'] ) || isset( $body['output_config'] );

	// DEFERENTIAL: if anything upstream already decided how this request
	// reasons, that decision wins and this seam self-neutralizes.
	if ( ! $bounded ) {
		/**
		 * Filter the effort level injected on the Insights generation.
		 *
		 * Return '' (or any non-whitelisted value) to disable the injection and
		 * leave the request untouched.
		 *
		 * @since 13.20.6
		 * @param string $effort One of 'low'|'medium'|'high', or '' to disable.
		 */
		$effort = (string) apply_filters( 'snt_insights_anthropic_effort', 'low' );
		if ( in_array( $effort, array( 'low', 'medium', 'high' ), true ) ) {
			$body['thinking']      = array( 'type' => 'adaptive' );
			$body['output_config'] = array( 'effort' => $effort );
			$bounded               = true;
			$changed               = true;
		}
	}

	// Raise-only, and only above whatever the builder sent. Effort bounds the
	// thinking; this leaves the answer somewhere to go.
	/**
	 * Filter the wire-level ceiling for the Insights generation.
	 *
	 * @since 13.20.6
	 * @param int $max_tokens Default SN_INSIGHTS_WIRE_MAX_TOKENS.
	 */
	$ceiling = (int) apply_filters( 'snt_insights_anthropic_max_tokens', SN_INSIGHTS_WIRE_MAX_TOKENS );
	if ( $bounded && isset( $body['max_tokens'] ) && $ceiling > (int) $body['max_tokens'] ) {
		$body['max_tokens'] = $ceiling;
		$changed            = true;
	}

	if ( ! $changed ) {
		return $args;
	}

	// Only extend the transport window for a request we actually shaped — a
	// longer generation is the direct consequence of the shaping.
	$timeout = (int) apply_filters( 'snt_insights_anthropic_timeout', SN_INSIGHTS_WIRE_TIMEOUT );
	if ( $timeout > (int) ( $args['timeout'] ?? 0 ) ) {
		$args['timeout'] = $timeout;
	}

	$args['body'] = wp_json_encode( $body );
	return $args;
}
