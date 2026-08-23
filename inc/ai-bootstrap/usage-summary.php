<?php
/**
 * Signal & Noise — AI bootstrap: the aggregated usage readout.
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
 * Provides: snt_ai_usage_summary()
 *
 * @package SignalNoiseTools
 * @since 12.21.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Summarize recorded AI token usage (and estimated cost) over the trailing
 * $days window.
 *
 * @param int $days Trailing window in days. Default 30.
 * @return array {
 *   calls, prompt, completion, total (ints),
 *   cost (float, USD list-price estimate), cost_unpriced_calls (int),
 *   window_start (int, oldest counted entry timestamp; 0 when none),
 *   by_feature: { <feature> => { calls, total, cost } }
 * }
 *
 * @since 6.29.0
 * @since 6.41.0 Adds `cost`, `cost_unpriced_calls`, `window_start`, and a per-feature `cost`.
 */
function snt_ai_usage_summary( $days = 30 ) {
	$out = array(
		'calls'               => 0,
		'prompt'              => 0,
		'completion'          => 0,
		'total'               => 0,
		'cost'                => 0.0,
		'cost_unpriced_calls' => 0,
		'window_start'        => 0,
		'by_feature'          => array(),
	);
	$log = get_option( SN_AI_USAGE_LOG_OPT, array() );
	if ( ! is_array( $log ) ) {
		return $out;
	}
	// Hoist the rate map once — snt_ai_usage_summary( 1 ) runs on the prepop
	// path, so we avoid re-running the `snt_ai_model_pricing` filter per entry.
	$rates  = snt_ai_model_pricing();
	$cutoff = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
	foreach ( $log as $entry ) {
		if ( ! is_array( $entry ) || (int) ( $entry['ts'] ?? 0 ) < $cutoff ) {
			continue;
		}
		++$out['calls'];
		$ts = (int) ( $entry['ts'] ?? 0 );
		if ( 0 === $out['window_start'] || $ts < $out['window_start'] ) {
			$out['window_start'] = $ts;
		}
		$prompt_t     = (int) ( $entry['prompt'] ?? 0 );
		$completion_t = (int) ( $entry['completion'] ?? 0 );
		$out['prompt']     += $prompt_t;
		$out['completion'] += $completion_t;
		$out['total']      += (int) ( $entry['total'] ?? 0 );
		$f = (string) ( $entry['feature'] ?? 'generic' );
		if ( ! isset( $out['by_feature'][ $f ] ) ) {
			$out['by_feature'][ $f ] = array(
				'calls' => 0,
				'total' => 0,
				'cost'  => 0.0,
			);
		}
		++$out['by_feature'][ $f ]['calls'];
		$out['by_feature'][ $f ]['total'] += (int) ( $entry['total'] ?? 0 );

		// v6.41.0: price on the SERVED model so a provider substitution prices
		// correctly; fall back to the requested `model` when served is blank
		// (older entries, or a provider that didn't populate model metadata).
		$pmodel = (string) ( $entry['served_model'] ?? '' );
		if ( '' === $pmodel ) {
			$pmodel = (string) ( $entry['model'] ?? '' );
		}
		if ( isset( $rates[ $pmodel ]['in'], $rates[ $pmodel ]['out'] ) ) {
			$c = ( $prompt_t * (float) $rates[ $pmodel ]['in']
				+ $completion_t * (float) $rates[ $pmodel ]['out'] ) / 1000000.0;
			$out['cost']                     += $c;
			$out['by_feature'][ $f ]['cost'] += $c;
		} else {
			++$out['cost_unpriced_calls'];
		}
	}
	return $out;
}
