<?php
/**
 * Signal & Noise Tools: the generation budget of an agent-run turn.
 *
 * WHY A BUDGET AT ALL. The Core AI Client pins `max_tokens: 4096` on every
 * Anthropic /v1/messages request and sends neither `thinking` nor
 * `output_config`. On claude-sonnet-5 thinking is then CEILING-BOUNDED: it
 * consumes whatever output budget exists (4096 of 4096, 6144 of 6144, still
 * reasoning past 7.4k at 16384), the turn truncates inside the thinking
 * block, no text part lands, and the run reads "The agent finished without
 * a text answer". Raising the ceiling alone fixes nothing (v10.53.0,
 * falsified live the night it shipped). The working configuration, verified
 * live 2026-08-07: an explicit effort makes thinking DEMAND-BOUNDED (about
 * 3.7k tokens for a sentence-edit plan at any ceiling), and a ceiling above
 * that demand leaves the answer room. Both keys travel together: the ceiling
 * is raised only when thinking is bounded, or the headroom goes to thinking.
 *
 * THE SEAM (#1613). `openstation_ai_model_config`, Experimental, shipped in
 * OpenStation 1.1.0 (docs/hooks-reference.md; recipe
 * docs/examples/ai-model-config.md, "Spend effort only where it pays"). The
 * AI client applies it on every generating path with `$context['source']`
 * naming the caller; `agents/runner` is the one this module shapes. Keys:
 * `max_tokens` goes through ModelConfig::setMaxTokens, `custom_options` are
 * provider-native parameter names copied verbatim into the request body
 * (WordPress/ai-provider-for-anthropic 1.0.4 forwards `thinking` and
 * `output_config` untouched), and a string `model` is a soft preference via
 * using_model_preference(): picked when the connector lists it, otherwise
 * the client's own choice stands.
 *
 * DEFERENTIAL. The seam starts empty, but a callback at a lower priority (a
 * per-agent model override, another plugin's budget) may already have shaped
 * the config, and this callback builds on it rather than over it: a config
 * already carrying `thinking` or `output_config` comes back byte-identical,
 * a higher `max_tokens` stays, sibling `custom_options` keys stay, and a
 * `model` already chosen stays. The pin only ever raises and only ever adds.
 *
 * Through 17.4.3 this module armed `http_request_args` at PHP_INT_MAX from the
 * runner's pre-filter and rewrote the JSON body after the client had built
 * it: a decode and re-encode of every outbound request for the rest of the
 * PHP request, and a Claude 5 gate read off the body. The filter carries no
 * resolved model, so the gate became the model pin below, which is what SN's
 * own features already do (SN_AI_DEFAULT_MODEL, inc/ai-bootstrap.php).
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shape the model config of an agent-run turn.
 *
 * @param mixed $config  { model?, max_tokens?, temperature?, custom_options? }.
 * @param mixed $context { user_id, request_id, source, has_tools, has_schema }.
 * @return mixed The config, shaped only when the source is the agents runner.
 */
function snt_agent_model_config( $config, $context = array() ) {
	if ( ! is_array( $config ) ) {
		$config = array();
	}
	if ( 'agents/runner' !== ( is_array( $context ) ? ( $context['source'] ?? '' ) : '' ) ) {
		return $config;
	}

	// An earlier callback that already bounded the thinking owns the turn.
	$existing = ( isset( $config['custom_options'] ) && is_array( $config['custom_options'] ) ) ? $config['custom_options'] : array();
	if ( isset( $existing['thinking'] ) || isset( $existing['output_config'] ) ) {
		return $config;
	}

	/**
	 * Filter the effort level of agent-run generations.
	 *
	 * Return '' (or any value outside the list) to disable the shaping
	 * entirely: thinking is then unbounded, so the ceiling is left alone
	 * too. "low" is the live-verified default.
	 *
	 * @param string $effort One of 'low'|'medium'|'high', or '' to disable.
	 */
	$effort = (string) apply_filters( 'snt_agent_anthropic_effort', 'low' );
	if ( ! in_array( $effort, array( 'low', 'medium', 'high' ), true ) ) {
		return $config;
	}

	/**
	 * Filter the raised max_tokens of agent-run generations.
	 *
	 * With effort bounding the thinking, 8192 leaves room for a full
	 * structured answer plus a tool call carrying whole post markup. A value
	 * at or below the client's 4096 pin leaves the pin in place, and a
	 * ceiling an earlier callback already raised higher stays.
	 *
	 * @param int $max_tokens Raised ceiling. Default 8192.
	 */
	$config['max_tokens']     = max( (int) ( $config['max_tokens'] ?? 0 ), 4096, (int) apply_filters( 'snt_agent_anthropic_max_tokens', 8192 ) );
	$config['custom_options'] = $existing + array(
		'thinking'      => array( 'type' => 'adaptive' ),
		'output_config' => array( 'effort' => $effort ),
	);
	if ( ! isset( $config['model'] ) && defined( 'SN_AI_DEFAULT_MODEL' ) ) {
		$config['model'] = SN_AI_DEFAULT_MODEL;
	}
	return $config;
}

// The seam is Experimental and lives in includes/ai-copilot/client.php,
// which OpenStation requires at plugin load; the guard reads on
// plugins_loaded so plugin order cannot decide it.
add_action( 'plugins_loaded', function () {
	if ( ! function_exists( 'openstation_ai_apply_model_config' ) ) {
		return;
	}
	add_filter( 'openstation_ai_model_config', 'snt_agent_model_config', 10, 2 );
} );
