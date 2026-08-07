<?php
/**
 * Signal & Noise Tools — agent output-budget workaround for
 * WordPress/openstation#517.
 *
 * The Core AI Client pins `max_tokens: 4096` on every Anthropic
 * /v1/messages request and sends no thinking config. On agent runs,
 * a hard task (e.g. planning a block-markup edit) spends the ENTIRE
 * budget inside a thinking block: the response comes back
 * `stop_reason: "max_tokens"` with a single text-less thinking part,
 * the adapter's toText() finds nothing, the failure is swallowed, and
 * the run reports success with an empty answer ("The agent finished
 * without a text answer"). Evidence: raw provider capture in
 * https://github.com/WordPress/openstation/issues/517.
 *
 * This seam raises the cap so the thinking can complete — bounded on
 * three sides so it self-neutralizes the moment upstream changes
 * anything:
 *
 *   1. ONLY during agent runs — armed from the runner's own
 *      `*_agent_runner_generate` pre-filter, so the Copilot and SN's
 *      own AI helpers never see it (they pin their own budgets).
 *   2. ONLY for Anthropic `/v1/messages` requests.
 *   3. ONLY when the body still carries the pinned int 4096 default —
 *      any other value means upstream (or another plugin) already made
 *      a decision, and this seam defers to it. It can raise, never
 *      lower.
 *
 * max_tokens is a ceiling, not a spend: the raise costs nothing unless
 * the model actually uses the headroom.
 *
 * REMOVE when upstream #517 lands a fix that raises or filters the
 * default AND the installed OpenStation release carries it (the
 * v0.9.8-pin guard in docs/openstation-compat.md applies — an upstream
 * fix does not reach this site until a post-rename release is
 * live-verified).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Arm the raiser for the current request.
 *
 * Rides the runner's pre-filter seam (`desktop_mode_agent_runner_generate`
 * / post-rename `openstation_agent_runner_generate`), which fires once per
 * generate turn inside an agent run and nowhere else. The seam is a
 * short-circuit filter: returning the incoming value untouched lets the
 * runner proceed to the AI Client — arming is a side effect, never a
 * result.
 *
 * The raiser stays hooked for the remainder of the request, which is
 * deliberate: every subsequent generate turn of the same run benefits,
 * and the 4096-shape gate bounds anything else the request might send.
 *
 * @param mixed $generated Whatever an earlier pre-filter produced (null
 *                         when none did — the normal case).
 * @return mixed The same value, untouched.
 */
function snt_agent_budget_arm( $generated = null ) {
	if ( false === has_filter( 'http_request_args', 'snt_agent_budget_raise' ) ) {
		add_filter( 'http_request_args', 'snt_agent_budget_raise', PHP_INT_MAX, 2 );
	}
	return $generated;
}

/**
 * Raise the pinned Anthropic output budget on an agent-run request.
 *
 * Every non-matching request returns BYTE-IDENTICAL args — a decode +
 * re-encode of an untouched body would still perturb the transport, so
 * the body is only rewritten when the raise actually applies.
 *
 * @param array  $args Request args (body is a JSON string on this path).
 * @param string $url  Request URL.
 * @return array Possibly-rewritten args.
 */
function snt_agent_budget_raise( $args, $url ) {
	if ( false === strpos( (string) $url, 'api.anthropic.com/v1/messages' ) ) {
		return $args;
	}
	if ( ! is_array( $args ) || ! isset( $args['body'] ) || ! is_string( $args['body'] ) ) {
		return $args;
	}

	$body = json_decode( $args['body'], true );
	// Strict int 4096 only: the AI Client's pinned default. A string
	// "4096" (or any other value) is not the shape this workaround
	// exists for and passes through untouched.
	if ( ! is_array( $body ) || ! isset( $body['max_tokens'] ) || 4096 !== $body['max_tokens'] ) {
		return $args;
	}

	/**
	 * Filter the raised Anthropic max_tokens for agent runs.
	 *
	 * This seam only ever raises: a value at or below the pinned 4096
	 * leaves the request untouched.
	 *
	 * @param int $max_tokens Raised ceiling. Default 16384.
	 */
	$raised = (int) apply_filters( 'snt_agent_anthropic_max_tokens', 16384 );
	if ( $raised <= 4096 ) {
		return $args;
	}

	$body['max_tokens'] = $raised;
	$args['body']       = wp_json_encode( $body );
	return $args;
}

snt_os_compat_add_filter(
	'desktop_mode_agent_runner_generate',
	'openstation_agent_runner_generate',
	'snt_agent_budget_arm',
	5,
	1
);
