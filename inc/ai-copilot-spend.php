<?php
/**
 * Signal & Noise Tools: the Copilot (Ask AI) month spend, a readout beside the cap.
 *
 * The plugin's AI ledger (inc/ai-bootstrap/spend.php) sees its own
 * generate_text_result() calls only. A Copilot turn runs on the same connector
 * and the same key, but the station calls the AI Client directly, so nothing in
 * the ledger ever saw one. The station reports each finished run on
 * `openstation_ai_search_completed` (Stable, docs/hooks-reference.md in the
 * OpenStation checkout, 1.1.10): `usage` is `{ prompt, completion, total }`
 * summed across every turn, `model` is `{ id, name }` from the AI Client, and
 * either may be null when the provider did not report it. `model.id` is the
 * same id space as snt_ai_model_pricing(), so one subscriber prices the turn.
 *
 * A bucket of its OWN, never the cap's. The cap (generate.php) can only refuse
 * the plugin's own features; counting Ask AI against it would pause Suggest
 * and Insights sooner without ever pausing Ask AI. And the feature ledger's
 * contract is that its buckets sum to the total the cap reads, so a Copilot
 * row inside it would break that sum. Same YYYY-MM shape as
 * SN_AI_SPEND_ROLLUP_OPT, same SN_AI_SPEND_MONTHS window, same zero guard.
 *
 * A turn the subscriber cannot price is COUNTED, never dropped: the station
 * picks its model on its own side, so the first Anthropic id the hand-kept
 * pricing table lacks would otherwise turn the row into a silent $0.00 that
 * reads the same as no Ask AI at all. The count lives in a sibling option of
 * the same shape and paints as the Insights leaf's unpriced-calls hint.
 *
 * The station reports no cache split, so every prompt token is priced at the
 * fresh input rate: an over-estimate on a warm prompt cache, hence "at list
 * rates" beside the figure on AI > Models & Budget.
 *
 * @package SignalNoiseTools
 * @since 17.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SN_AI_COPILOT_SPEND_OPT', 'sn_ai_copilot_spend_month' );
define( 'SN_AI_COPILOT_UNPRICED_OPT', 'sn_ai_copilot_unpriced_month' );

/**
 * Fold one figure into this month's bucket of a YYYY-MM option, pruned to
 * SN_AI_SPEND_MONTHS. A float delta keeps a rounded float; an int delta keeps
 * an int count. A damaged option is rebuilt from scratch.
 *
 * @param string    $opt   Option name.
 * @param int|float $delta What this turn adds.
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
 * Price one finished Copilot run and fold it into this month's Copilot bucket.
 *
 * Hooked on `openstation_ai_search_completed`. A payload the subscriber cannot
 * price (`usage` or `model` null or absent, as on the station's follow-up leg,
 * an unpriced `model.id`, zero tokens) adds one to this month's unpriced count
 * instead: no figure is better than a fabricated one, but a turn that ran must
 * not vanish. Dual-registered through the compat helper (the hook fired as
 * `desktop_mode_ai_search_completed` at v0.9.8 and was renamed by #475 like
 * the rest of the table), so the option increment is guarded by
 * snt_os_compat_seen_once() against a shim that fires both names.
 *
 * @param mixed $payload { query, user_id, request_id, answer_type, iterations, usage, model }.
 * @return void
 * @since 17.4.4
 */
function snt_ai_copilot_record_turn( $payload ) {
	if ( ! is_array( $payload ) ) {
		return;
	}
	if ( function_exists( 'snt_os_compat_seen_once' )
		&& snt_os_compat_seen_once( 'ai_search_completed:' . md5( serialize( $payload ) ) ) ) {
		return;
	}
	$cost  = 0.0;
	$model = is_array( $payload['model'] ?? null ) ? (string) ( $payload['model']['id'] ?? '' ) : '';
	if ( '' !== $model && is_array( $payload['usage'] ?? null ) && function_exists( 'snt_ai_estimate_cost' ) ) {
		$cost = (float) snt_ai_estimate_cost(
			$model,
			(int) ( $payload['usage']['prompt'] ?? 0 ),
			(int) ( $payload['usage']['completion'] ?? 0 )
		);
	}
	if ( $cost > 0 ) {
		snt_ai_copilot_bump_month( SN_AI_COPILOT_SPEND_OPT, $cost );
	} else {
		snt_ai_copilot_bump_month( SN_AI_COPILOT_UNPRICED_OPT, 1 );
	}
}
snt_os_compat_add_action( 'desktop_mode_ai_search_completed', 'openstation_ai_search_completed', 'snt_ai_copilot_record_turn' );

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
 * A recorded zero, not an absent reading: the subscriber is always bound, so
 * $0.00 means no PRICED turn landed this month. Read
 * snt_ai_copilot_unpriced_this_month() to separate that from turns the pricing
 * table could not price.
 *
 * @return float
 * @since 17.4.4
 */
function snt_ai_copilot_spend_this_month() {
	return (float) snt_ai_copilot_month_bucket( SN_AI_COPILOT_SPEND_OPT );
}

/**
 * This calendar month's Copilot turns that ran but could not be priced, 0 when none.
 *
 * @return int
 * @since 17.4.4
 */
function snt_ai_copilot_unpriced_this_month() {
	return (int) snt_ai_copilot_month_bucket( SN_AI_COPILOT_UNPRICED_OPT );
}
