<?php
/**
 * Signal & Noise Tools — agent generation-budget shaping for
 * WordPress/openstation#517.
 *
 * The Core AI Client pins `max_tokens: 4096` on every Anthropic
 * /v1/messages request and sends neither `thinking` nor `output_config`.
 * On models with uncontrolled-effort reasoning (measured live on
 * claude-sonnet-5), thinking is CEILING-BOUNDED: it consumes whatever
 * output budget exists — 4096/4096 at the pin, 6144/6144 with the pin
 * raised, still reasoning past ~7.4k at 16384 — so the turn truncates
 * inside the thinking block, carries no text part, and the run surfaces
 * as "The agent finished without a text answer". Raising the ceiling
 * alone therefore fixes nothing (the v10.53.0 approach, falsified live
 * the same night it shipped).
 *
 * The working configuration, live-verified 2026-08-07: an explicit
 * effort config makes thinking DEMAND-BOUNDED (~3.7k tokens for a
 * sentence-edit plan regardless of ceiling), and a ceiling modestly
 * above demand leaves room for the answer — stop_reason "end_turn",
 * full structured text. This seam injects both:
 *
 *   1. ONLY during agent runs — armed from the runner's own
 *      `*_agent_runner_generate` pre-filter, so the Copilot and SN's own
 *      AI helpers never see it (they pin their own budgets).
 *   2. ONLY for Anthropic `/v1/messages` requests carrying a Claude 5
 *      family model (`claude-<name>-5…`): the effort keys are
 *      model-family-specific — `thinking.type: "enabled"` is REJECTED by
 *      Claude 5 (400: use adaptive + output_config.effort), and adaptive
 *      would 400 on older families. Non-matching requests pass through
 *      BYTE-IDENTICAL.
 *   3. DEFERENTIAL: any request already carrying `thinking` or
 *      `output_config` is left untouched, and the ceiling is rewritten
 *      only when it still holds the exact pinned int 4096 — the moment
 *      upstream ships its own config (openstation#531) or changes the
 *      pin, this seam self-neutralizes.
 *
 * `max_tokens` is a ceiling, not a spend — the raise costs nothing
 * unless the answer actually uses the headroom.
 *
 * REMOVE when upstream ships and the installed OpenStation release
 * carries a generation config for reasoning models (openstation#531)
 * plus the empty-answer error surface (openstation#530) — the v0.9.8 pin
 * guard in docs/openstation-compat.md applies.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Arm the request shaper for the current request.
 *
 * Rides the runner's pre-filter seam (`desktop_mode_agent_runner_generate`
 * / post-rename `openstation_agent_runner_generate`), which fires once per
 * generate turn inside an agent run and nowhere else. The seam is a
 * short-circuit filter: returning the incoming value untouched lets the
 * runner proceed to the AI Client — arming is a side effect, never a
 * result. The shaper stays hooked for the remainder of the request, which
 * is deliberate: every subsequent generate turn of the same run benefits.
 *
 * @param mixed $generated Whatever an earlier pre-filter produced (null
 *                         when none did — the normal case).
 * @return mixed The same value, untouched.
 */
function snt_agent_budget_arm( $generated = null ) {
	if ( false === has_filter( 'http_request_args', 'snt_agent_budget_shape' ) ) {
		add_filter( 'http_request_args', 'snt_agent_budget_shape', PHP_INT_MAX, 2 );
	}
	return $generated;
}

/**
 * Whether a request body's model is Claude 5 family (adaptive-thinking
 * API shape: claude-sonnet-5, claude-opus-5, claude-fable-5, including
 * dated/point variants).
 *
 * @param string $model Model id from the request body.
 * @return bool
 */
function snt_agent_budget_model_is_claude5( $model ) {
	return 1 === preg_match( '/^claude-[a-z]+-5([.\-]|$)/', (string) $model );
}

/**
 * Shape an agent-run Anthropic request so the answer can complete:
 * inject adaptive thinking + an effort level, and give the pinned
 * ceiling text headroom.
 *
 * Every non-matching request returns BYTE-IDENTICAL args — a decode +
 * re-encode of an untouched body would still perturb the transport, so
 * the body is only rewritten when at least one shaping actually applies.
 *
 * @param array  $args Request args (body is a JSON string on this path).
 * @param string $url  Request URL.
 * @return array Possibly-rewritten args.
 */
function snt_agent_budget_shape( $args, $url ) {
	if ( false === strpos( (string) $url, 'api.anthropic.com/v1/messages' ) ) {
		return $args;
	}
	if ( ! is_array( $args ) || ! isset( $args['body'] ) || ! is_string( $args['body'] ) ) {
		return $args;
	}

	$body = json_decode( $args['body'], true );
	if ( ! is_array( $body ) || ! snt_agent_budget_model_is_claude5( $body['model'] ?? '' ) ) {
		return $args;
	}

	$changed = false;

	if ( ! isset( $body['thinking'] ) && ! isset( $body['output_config'] ) ) {
		/**
		 * Filter the effort level injected on agent-run generations.
		 *
		 * Return '' (or any non-whitelisted value) to disable the
		 * injection entirely. "low" is the live-verified default: it
		 * bounds thinking by demand (~3.7k tokens on a sentence-edit
		 * plan) instead of by ceiling.
		 *
		 * @param string $effort One of 'low'|'medium'|'high', or '' to disable.
		 */
		$effort = (string) apply_filters( 'snt_agent_anthropic_effort', 'low' );
		if ( in_array( $effort, array( 'low', 'medium', 'high' ), true ) ) {
			$body['thinking']      = array( 'type' => 'adaptive' );
			$body['output_config'] = array( 'effort' => $effort );
			$changed               = true;
		}
	}

	// Strict int 4096 only: the AI Client's pinned default. Any other
	// value means upstream (or another plugin) already made a decision,
	// and this seam defers to it. Raise-only.
	if ( isset( $body['max_tokens'] ) && 4096 === $body['max_tokens'] ) {
		/**
		 * Filter the raised Anthropic max_tokens for agent runs.
		 *
		 * With effort bounding the thinking (~3.7k), 8192 leaves room
		 * for a full structured answer plus a tool call carrying whole
		 * post markup. A value at or below the pinned 4096 leaves the
		 * ceiling untouched.
		 *
		 * @param int $max_tokens Raised ceiling. Default 8192.
		 */
		$raised = (int) apply_filters( 'snt_agent_anthropic_max_tokens', 8192 );
		if ( $raised > 4096 ) {
			$body['max_tokens'] = $raised;
			$changed            = true;
		}
	}

	if ( ! $changed ) {
		return $args;
	}

	$args['body'] = wp_json_encode( $body );
	return $args;
}

snt_os_compat_add_filter(
	'desktop_mode_agent_runner_generate',
	'openstation_agent_runner_generate',
	'snt_agent_budget_arm',
	5,
	1
);
