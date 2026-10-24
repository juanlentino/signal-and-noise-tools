<?php
/**
 * Signal & Noise Tools: the Copilot (Ask AI), Agents and Other AI month
 * spend, three readouts beside the cap.
 *
 * The plugin's AI ledger (inc/ai-bootstrap/spend.php) sees its own
 * generate_text_result() calls only. Everything else on the site that
 * generates through the WordPress AI Client (OpenStation's Copilot and
 * agents, the AI plugin's features) runs on the same connector and the same
 * key, so it is priced here, call by call, from core's own event: since 7.0,
 * PromptBuilder::generateResult() dispatches `wp_ai_client_after_generate_result`
 * after EVERY call with the result, carrying that call's token usage and the
 * model the provider actually served. A run that switches models prices each
 * call at its own rate, and no station version is needed for usage.
 *
 * Attribution. The event names no caller, so the source comes from
 * OpenStation's `openstation_ai_model_config` filter, which every station
 * generation path applies right before generate_result() with
 * `$context['source']`. A three-step handoff keeps a call that fails after
 * core's before event from lending its label: the filter notes the source,
 * `wp_ai_client_before_generate_result` claims it (and clears the note), the
 * after event takes it. ponytail: a station call stopped BEFORE that event (a
 * prevented prompt, a model that fails to resolve) leaves its note for the
 * next call in the same request; the plugin's own call consumes it harmlessly,
 * an unlabeled foreign call would wear it. No seam links the filter to the
 * event, so that ceiling stays. Sources:
 * `ai-copilot/search` and `ai-copilot/followup` are Copilot, `agents/runner`
 * is Agents, and everything else (agents/draft, mio/window, the Drafts
 * widget, comment analysis, the AI plugin, any unlabeled call) is Other,
 * never dropped.
 *
 * The plugin's own calls are excluded: snt_ai_generate_with_constraints()
 * holds snt_ai_own_call_depth() above zero around its generate call, and
 * usage-log.php has already priced it into the cap's ledger.
 *
 * Buckets of their OWN, never the cap's. The cap (generate.php) can only
 * refuse the plugin's own features; counting these against it would pause
 * Suggest and Insights sooner without ever pausing the caller. And the
 * feature ledger's buckets must sum to the total the cap reads. Same YYYY-MM
 * shape as SN_AI_SPEND_ROLLUP_OPT, same SN_AI_SPEND_MONTHS window.
 *
 * A call that cannot be priced (a model id the pricing table lacks, zero
 * tokens) is COUNTED in a sibling option, never a silent $0.00.
 *
 * Cache tokens: the provider sums cache reads and writes into the prompt
 * count (WordPress/ai-provider-for-anthropic#33), so every prompt token is
 * priced at the fresh input rate, as before: an over-estimate on a warm
 * prompt cache, hence "at list rates" beside the figures.
 *
 * @package SignalNoiseTools
 * @since 17.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_AI_COPILOT_SPEND_OPT', 'sn_ai_copilot_spend_month' );
define( 'SN_AI_COPILOT_UNPRICED_OPT', 'sn_ai_copilot_unpriced_month' );
define( 'SN_AI_AGENTS_SPEND_OPT', 'sn_ai_agents_spend_month' );
define( 'SN_AI_AGENTS_UNPRICED_OPT', 'sn_ai_agents_unpriced_month' );
define( 'SN_AI_OTHER_SPEND_OPT', 'sn_ai_other_spend_month' );
define( 'SN_AI_OTHER_UNPRICED_OPT', 'sn_ai_other_unpriced_month' );

/**
 * Fold one figure into this month's bucket of a YYYY-MM option, pruned to
 * SN_AI_SPEND_MONTHS. A float delta keeps a rounded float; an int delta keeps
 * an int count. A damaged option is rebuilt from scratch.
 *
 * @param string    $opt   Option name.
 * @param int|float $delta What this call adds.
 * @return void
 * @since 17.4.4
 */
function snt_ai_copilot_bump_month( $opt, $delta ) {
	$roll = get_option( $opt, array() );
	if ( ! is_array( $roll ) ) {
		$roll = array();
	}
	$key          = snt_ai_spend_month_key();
	$cur          = $roll[ $key ] ?? 0;
	$roll[ $key ] = is_int( $delta ) ? (int) $cur + $delta : round( (float) $cur + $delta, 6 );
	if ( count( $roll ) > SN_AI_SPEND_MONTHS ) {
		ksort( $roll );
		$roll = array_slice( $roll, -SN_AI_SPEND_MONTHS, null, true );
	}
	update_option( $opt, $roll, false );
}

/**
 * The plugin's own generation depth: snt_ai_generate_with_constraints()
 * holds it above zero around its generate call, so the recorder below stands
 * down for a call the cap's ledger already priced. Floors at zero, like
 * sn_ability_guard_mcp_depth(): an unbalanced decrement must not poison later calls.
 *
 * @param int $delta +1 / -1 / 0 (peek).
 * @return int Current depth after applying $delta.
 */
function snt_ai_own_call_depth( $delta = 0 ) {
	static $depth = 0;
	$depth = max( 0, $depth + (int) $delta );
	return $depth;
}

/**
 * The source handoff: `note` (the station's filter), `claim` (core's before
 * event: the note becomes the in-flight label and is cleared), `take` (core's
 * after event: returns the in-flight label and clears it).
 *
 * @param string $op     note | claim | take.
 * @param string $source The source to note.
 * @return string The in-flight label, for `take`; '' otherwise.
 */
function snt_ai_spend_source( $op, $source = '' ) {
	static $pending = '', $inflight = '';
	if ( 'note' === $op ) {
		$pending = (string) $source;
	} elseif ( 'claim' === $op ) {
		$inflight = $pending;
		$pending  = '';
	} elseif ( 'take' === $op ) {
		$taken    = $inflight;
		$inflight = '';
		return $taken;
	}
	return '';
}

/**
 * Note the source of the station call about to generate. Pass-through: the
 * config comes back exactly as it arrived.
 *
 * @param mixed $config  { model?, max_tokens?, temperature?, custom_options? }.
 * @param mixed $context { user_id, request_id, source, has_tools, has_schema }.
 * @return mixed
 */
function snt_ai_spend_note_source( $config, $context = array() ) {
	snt_ai_spend_source( 'note', is_array( $context ) ? (string) ( $context['source'] ?? '' ) : '' );
	return $config;
}
add_filter( 'openstation_ai_model_config', 'snt_ai_spend_note_source', 10, 2 );

/**
 * Claim the noted source for the call core is about to send.
 *
 * @return void
 */
function snt_ai_spend_claim_source() {
	snt_ai_spend_source( 'claim' );
}
add_action( 'wp_ai_client_before_generate_result', 'snt_ai_spend_claim_source' );

/**
 * Price one finished AI Client call into its bucket.
 *
 * @param mixed $event WordPress\AiClient\Events\AfterGenerateResultEvent.
 * @return void
 */
function snt_ai_spend_record_call( $event ) {
	$source = snt_ai_spend_source( 'take' );
	if ( snt_ai_own_call_depth() > 0 || ! is_object( $event ) || ! is_callable( array( $event, 'getResult' ) ) ) {
		return;
	}
	$result = $event->getResult();
	$usage  = is_object( $result ) && is_callable( array( $result, 'getTokenUsage' ) ) ? $result->getTokenUsage() : null;
	$meta   = is_object( $result ) && is_callable( array( $result, 'getModelMetadata' ) ) ? $result->getModelMetadata() : null;
	$bucket = array(
		'ai-copilot/search'   => array( SN_AI_COPILOT_SPEND_OPT, SN_AI_COPILOT_UNPRICED_OPT ),
		'ai-copilot/followup' => array( SN_AI_COPILOT_SPEND_OPT, SN_AI_COPILOT_UNPRICED_OPT ),
		'agents/runner'       => array( SN_AI_AGENTS_SPEND_OPT, SN_AI_AGENTS_UNPRICED_OPT ),
	)[ $source ] ?? array( SN_AI_OTHER_SPEND_OPT, SN_AI_OTHER_UNPRICED_OPT );
	snt_ai_station_price_run(
		array(
			'usage' => is_object( $usage ) ? array(
				'prompt'     => (int) $usage->getPromptTokens(),
				'completion' => (int) $usage->getCompletionTokens(),
			) : null,
			'model' => is_object( $meta ) && is_callable( array( $meta, 'getId' ) ) ? array( 'id' => (string) $meta->getId() ) : null,
		),
		$bucket[0],
		$bucket[1]
	);
}
add_action( 'wp_ai_client_after_generate_result', 'snt_ai_spend_record_call' );

/**
 * Price one call carrying `{ usage, model }` into a spend bucket, or count it
 * in the unpriced bucket when it cannot be priced (usage or model absent, a
 * model id the table lacks, zero tokens).
 *
 * @param array  $run          `usage` { prompt, completion } and `model` { id }.
 * @param string $spend_opt    YYYY-MM option for the dollar figure.
 * @param string $unpriced_opt YYYY-MM option for the unpriced count.
 * @return void
 */
function snt_ai_station_price_run( array $run, $spend_opt, $unpriced_opt ) {
	$cost  = 0.0;
	$model = is_array( $run['model'] ?? null ) ? (string) ( $run['model']['id'] ?? '' ) : '';
	if ( '' !== $model && is_array( $run['usage'] ?? null ) && function_exists( 'snt_ai_estimate_cost' ) ) {
		$cost = (float) snt_ai_estimate_cost(
			$model,
			(int) ( $run['usage']['prompt'] ?? 0 ),
			(int) ( $run['usage']['completion'] ?? 0 )
		);
	}
	if ( $cost > 0 ) {
		snt_ai_copilot_bump_month( $spend_opt, $cost );
	} else {
		snt_ai_copilot_bump_month( $unpriced_opt, 1 );
	}
}

/**
 * This month's bucket of a YYYY-MM option, 0 when none or when the option is damaged.
 *
 * @param string $opt Option name.
 * @return int|float
 * @since 17.4.4
 */
function snt_ai_copilot_month_bucket( $opt ) {
	$roll = get_option( $opt, array() );
	if ( ! is_array( $roll ) ) {
		return 0;
	}
	return $roll[ snt_ai_spend_month_key() ] ?? 0;
}

/**
 * This calendar month's Copilot spend in USD at list rates, 0.0 when none.
 *
 * A recorded zero, not an absent reading: the recorder is always bound, so
 * $0.00 means no PRICED call landed this month. Read
 * snt_ai_copilot_unpriced_this_month() for the calls it could not price.
 *
 * @return float
 * @since 17.4.4
 */
function snt_ai_copilot_spend_this_month() {
	return (float) snt_ai_copilot_month_bucket( SN_AI_COPILOT_SPEND_OPT );
}

/**
 * This calendar month's Copilot calls that ran but could not be priced, 0 when none.
 *
 * @return int
 * @since 17.4.4
 */
function snt_ai_copilot_unpriced_this_month() {
	return (int) snt_ai_copilot_month_bucket( SN_AI_COPILOT_UNPRICED_OPT );
}

/**
 * This calendar month's agent spend in USD at list rates, 0.0 when none.
 *
 * @return float
 */
function snt_ai_agents_spend_this_month() {
	return (float) snt_ai_copilot_month_bucket( SN_AI_AGENTS_SPEND_OPT );
}

/**
 * This calendar month's agent calls that ran but could not be priced, 0 when none.
 *
 * @return int
 */
function snt_ai_agents_unpriced_this_month() {
	return (int) snt_ai_copilot_month_bucket( SN_AI_AGENTS_UNPRICED_OPT );
}

/**
 * This calendar month's Other AI spend (every call nothing labels) in USD at list rates.
 *
 * @return float
 */
function snt_ai_other_spend_this_month() {
	return (float) snt_ai_copilot_month_bucket( SN_AI_OTHER_SPEND_OPT );
}

/**
 * This calendar month's Other AI calls that ran but could not be priced, 0 when none.
 *
 * @return int
 */
function snt_ai_other_unpriced_this_month() {
	return (int) snt_ai_copilot_month_bucket( SN_AI_OTHER_UNPRICED_OPT );
}
