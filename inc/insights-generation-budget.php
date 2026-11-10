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
 * the same night (the history is in inc/openstation-agent-output-budget.php's
 * docblock; since #1613 that module rides openstation_ai_model_config, a seam
 * this SN-only call never reaches); v13.20.5 tried
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
 * HOW IT REACHES THE REQUEST. Through the AI Client. `thinking` and
 * `output_config` are Anthropic parameters the builder has no method for, so
 * they travel as ModelConfig custom options: the Anthropic provider copies
 * every custom option into the request parameters (read at
 * ai-provider-for-anthropic 1.0.5, AnthropicTextGenerationModel::
 * prepareGenerateTextParams()). The ceiling goes through using_max_tokens and
 * the timeout through core's wp_ai_client_default_request_timeout filter. All
 * three are options of snt_ai_generate_with_constraints(), which keeps its 4096
 * clamp for every caller that does not pass a ceiling.
 *
 * Through 2026.11.20 this module rewrote the JSON body on `http_request_args`
 * at PHP_INT_MAX, on the premise that the builder could not carry these keys.
 * The request that reaches Anthropic is the same; nothing is decoded or
 * re-encoded on the way out any more.
 *
 * SCOPE. The options are returned for the one call in snt_insights_call_ai()
 * and belong to that call's builder, so no other feature can see them.
 *
 * @package SignalNoiseTools
 * @since   13.20.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ceiling for the Insights request when an effort is set.
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
 * Transport timeout for that request, in seconds.
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
 * Whether a model id is Claude 5 family (the adaptive-thinking API shape).
 *
 * The effort keys are family-specific: `thinking.type: "enabled"` is REJECTED
 * by Claude 5 with a 400 directing callers to adaptive + output_config.effort,
 * and adaptive would 400 on older families. Anything not matching is left alone
 * rather than guessed at.
 *
 * @param string $model Model id.
 * @return bool
 */
function snt_insights_budget_model_is_claude5( $model ) {
	return 1 === preg_match( '/^claude-[a-z]+-5([.\-]|$)/', (string) $model );
}

/**
 * The request options for an Insights scan: adaptive thinking with an effort,
 * the ceiling that leaves the answer room, and the timeout that generation
 * needs. Empty when any model that could serve the call is not Claude 5, or
 * when the effort is filtered off: the ceiling and the timeout are raised only
 * together with the effort, since headroom on a request whose thinking is
 * ceiling-bounded goes to thinking (v13.20.5: a fast failure became a timeout).
 *
 * The AI Client serves the first listed model the provider exposes, so every
 * id in the list has to be Claude 5 for the effort keys to be safe to send.
 *
 * @param string[] $model_list The model preference list for the call.
 * @return array Options for snt_ai_generate_with_constraints(), or array().
 */
function snt_insights_budget_options( $model_list ) {
	$model_list = (array) $model_list;
	if ( ! $model_list || array_filter( $model_list, 'snt_insights_budget_model_is_claude5' ) !== $model_list ) {
		return array();
	}

	/**
	 * Filter the effort level set on the Insights generation.
	 *
	 * Return '' (or any non-whitelisted value) to send the request plain.
	 *
	 * @since 13.20.6
	 * @param string $effort One of 'low'|'medium'|'high', or '' to disable.
	 */
	$effort = (string) apply_filters( 'snt_insights_anthropic_effort', 'low' );
	if ( ! in_array( $effort, array( 'low', 'medium', 'high' ), true ) ) {
		return array();
	}

	return array(
		'custom_options' => array(
			'thinking'      => array( 'type' => 'adaptive' ),
			'output_config' => array( 'effort' => $effort ),
		),
		/**
		 * Filter the ceiling for the Insights generation. Raise-only.
		 *
		 * @since 13.20.6
		 * @param int $max_tokens Default SN_INSIGHTS_WIRE_MAX_TOKENS.
		 */
		'max_tokens'     => (int) apply_filters( 'snt_insights_anthropic_max_tokens', SN_INSIGHTS_WIRE_MAX_TOKENS ),
		/**
		 * Filter the transport timeout for the Insights generation, in seconds. Raise-only.
		 *
		 * @since 13.20.6
		 * @param int $timeout Default SN_INSIGHTS_WIRE_TIMEOUT.
		 */
		'timeout'        => (int) apply_filters( 'snt_insights_anthropic_timeout', SN_INSIGHTS_WIRE_TIMEOUT ),
	);
}
