<?php
/**
 * Signal & Noise — AI bootstrap: the capped per-call usage log.
 *
 * Split out of inc/ai-bootstrap.php in v12.21.4, which had grown to 1,054
 * lines. Nothing about behaviour changed.
 *
 * This layer has no registry and no dispatch map — other modules call these
 * functions DIRECTLY, so the public surface is the contract.
 * tests/ai-bootstrap-surface-coverage.php pins all 21 declarations, the eight
 * SN_AI_* constants, the two load-time route registrations, and the single
 * admin_enqueue_scripts hook, so a symbol lost in a move is a build failure
 * rather than a silent behaviour change.
 *
 * Provides: snt_ai_record_usage()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record one AI call's token usage to the capped FIFO log option.
 *
 * Reads the TokenUsage DTO off the GenerativeAiResult — the metadata that
 * the prior ->generate_text() path discarded inside the SDK. Every accessor
 * is is_callable()-guarded so a provider/connector that doesn't populate
 * usage degrades to a no-op rather than fataling.
 *
 * Two model fields are stored: `model` is the requested preference string
 * (kept as the cost-summary key for backward-compat), and `served_model`
 * (v6.39.2) is the model the provider ACTUALLY served — read via the now-
 * verified GenerativeAiResult::getModelMetadata()->getId() accessor — so
 * attribution survives a provider substituting a model. `served_model`
 * degrades to '' when the accessor is absent.
 *
 * @param string $feature Feature label (e.g. 'insights', 'insights_narration').
 * @param string $model   Requested model preference string.
 * @param mixed  $result  GenerativeAiResult from generate_text_result().
 * @return void
 *
 * @since 6.29.0
 */
function snt_ai_record_usage( $feature, $model, $result ) {
	if ( ! is_object( $result ) || ! is_callable( array( $result, 'getTokenUsage' ) ) ) {
		return;
	}
	$usage = $result->getTokenUsage();
	if ( ! is_object( $usage ) ) {
		return;
	}

	$prompt_t     = is_callable( array( $usage, 'getPromptTokens' ) ) ? (int) $usage->getPromptTokens() : 0;
	$completion_t = is_callable( array( $usage, 'getCompletionTokens' ) ) ? (int) $usage->getCompletionTokens() : 0;
	$total_t      = is_callable( array( $usage, 'getTotalTokens' ) ) ? (int) $usage->getTotalTokens() : ( $prompt_t + $completion_t );

	// v6.39.2: also record the model the provider ACTUALLY served, so cost
	// attribution survives a provider substituting a different model than the
	// pinned `$model` preference (e.g. Sonnet requested, a fallback served). The
	// requested `model` field stays as-is for backward-compat with the usage
	// summary; `served_model` is additive. Every hop is is_callable-guarded —
	// an older SDK / a provider that doesn't populate ModelMetadata degrades to
	// '' rather than fataling. Accessor verified against php-ai-client trunk:
	// GenerativeAiResult::getModelMetadata(): ModelMetadata → getId(): string.
	$served_model = '';
	if ( is_callable( array( $result, 'getModelMetadata' ) ) ) {
		$meta = $result->getModelMetadata();
		if ( is_object( $meta ) && is_callable( array( $meta, 'getId' ) ) ) {
			$served_model = (string) $meta->getId();
		}
	}

	// v10.70.0: recover the TRUE cache split, which the provider flattened into
	// $prompt_t before we ever saw it (WordPress/ai-provider-for-anthropic#33).
	// The probe observed it at the HTTP layer earlier in this same request.
	// A null result means "no matching observation" and we price exactly as
	// before — a miss is never worse than the old behaviour.
	$split = function_exists( 'snt_ai_cache_obs_take' )
		? snt_ai_cache_obs_take( $prompt_t, $completion_t )
		: null;

	$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = array(
		'ts'           => time(),
		'feature'      => (string) $feature,
		'model'        => (string) $model,
		'served_model' => $served_model,
		'prompt'       => $prompt_t,
		'completion'   => $completion_t,
		'total'        => $total_t,
		// null (not 0) when unobserved, mirroring the probe's own
		// absent-versus-measured discipline. A reader must be able to tell
		// "no caching happened" from "we never saw".
		'cache_write'  => is_array( $split ) ? (int) $split['cache_write'] : null,
		'cache_read'   => is_array( $split ) ? (int) $split['cache_read'] : null,
	);
	if ( count( $log ) > SN_AI_USAGE_LOG_CAP ) {
		$log = array_slice( $log, -SN_AI_USAGE_LOG_CAP );
	}
	update_option( SN_AI_USAGE_LOG_OPT, $log, false );

	// v9.26.0: also fold this call's cost into the durable monthly rollup, which
	// (unlike the capped FIFO log above) never evicts — so the budget cap and the
	// spend readout see a full month even under heavy use. Served model wins for
	// attribution when the provider substituted a different one.
	snt_ai_add_month_spend(
		snt_ai_estimate_cost( '' !== $served_model ? $served_model : (string) $model, $prompt_t, $completion_t, $split )
	);
}

/**
 * The most recent usage-log entry for one feature, or null when none exists.
 *
 * Added v13.20.4 so a failure can report the call's TOKEN ACCOUNTING instead of
 * only its text. The motivating case: an Insights scan returned three
 * characters, the parser correctly said "not valid JSON", and the real cause —
 * the call had generated and billed its entire output budget — was invisible on
 * the admin page. Reading the log is what turns that into a diagnosis.
 *
 * Returns null (never a zero-filled array) when the log holds nothing for the
 * feature: "no record" and "recorded zero tokens" are different answers and the
 * caller must be able to tell them apart.
 *
 * @param string $feature Feature slug as passed to snt_ai_record_usage().
 * @return array|null The newest matching entry, or null.
 */
function snt_ai_usage_last( $feature ) {
	$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
	if ( ! is_array( $log ) || ! $log ) {
		return null;
	}
	for ( $i = count( $log ) - 1; $i >= 0; $i-- ) {
		$entry = $log[ $i ];
		if ( is_array( $entry ) && isset( $entry['feature'] ) && (string) $feature === (string) $entry['feature'] ) {
			return $entry;
		}
	}
	return null;
}
