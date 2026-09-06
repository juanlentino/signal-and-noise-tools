<?php
/**
 * S&N Dashboard — Monitoring → Insights, section builders.
 *
 * One function per section of the classic leaf (inc/insights-admin.php):
 * the Run-Analysis form, the recommendation cards, the AI usage & spend
 * readout, the prompt-cache probe, the scan-status box, and the weekly-cron
 * settings form. Split out of monitoring-insights.php to keep that file
 * under ~200 lines. Every helper is prefixed `insights_` (this leaf's slug)
 * so its names stay unique across the leaves directory.
 *
 * @package SignalNoiseTools
 * @since 13.106.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * `<os-form os-action="post" busy>` for Run Analysis. `snt_kit_form()` has no
 * `busy` option surface, and the classic leaf disables the submit button (not
 * just shows a notice) while the AI client is unavailable — `busy` is the
 * closest documented `<os-form>` prop that blocks submission, so this builds
 * the form by hand instead of inventing an attribute. Hidden fields mirror
 * `snt_kit_form()` exactly (sn_action + nonce).
 *
 * @param string $submit_label Submit label.
 * @param bool   $busy         Whether the form is gated.
 * @param string $inner        Painted fields.
 * @return string
 */
function insights_run_form_tag( $submit_label, $busy, $inner ) {
	$hidden = \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => 'sn_action', 'value' => 'insights_run' ) )
		. \snt_kit_tag( 'input', array( 'type' => 'hidden', 'name' => '_wpnonce', 'value' => \snt_kit_nonce() ) );
	return \snt_kit_tag(
		'os-form',
		array(
			'class'        => 'snt-form',
			'os-action'    => 'post',
			'submit-label' => (string) $submit_label,
			'show-reset'   => 'false',
			'columns'      => '1',
			'busy'         => (bool) $busy,
		),
		$inner . $hidden
	);
}

/**
 * The Run Analysis section: the setup-steps notice when AI is unavailable
 * (two doors, same URLs as the classic leaf), the force-refresh checkbox once
 * a prior scan exists, and the gated submit.
 *
 * @param array|null $last     From snt_insights_last_scan().
 * @param bool       $ai_ready From snt_ai_is_available().
 * @return string
 */
function insights_run_form_html( $last, $ai_ready ) {
	$fields = '';
	if ( ! $ai_ready ) {
		$fields .= \snt_kit_notice(
			'err',
			'<b>' . \snt_kit_esc( __( 'AI client not available.', 'signal-and-noise-tools' ) ) . '</b> '
			. \snt_kit_esc( __( 'Two setup steps are required:', 'signal-and-noise-tools' ) ) . ' '
			. \snt_kit_door( __( 'Settings → AI', 'signal-and-noise-tools' ), admin_url( 'options-general.php?page=ai-wp-admin' ) )
			. ' ' . \snt_kit_esc( __( '(global enable + per-feature toggles), and', 'signal-and-noise-tools' ) ) . ' '
			. \snt_kit_door( __( 'Settings → Connectors', 'signal-and-noise-tools' ), admin_url( 'options-general.php?page=connectors' ) )
			. ' ' . \snt_kit_esc( __( '(provider + API key). Both must be configured before this can run.', 'signal-and-noise-tools' ) )
		);
	}
	if ( $last ) {
		$fields .= \snt_kit_field( 'checkbox', 'force', __( 'Force fresh scan (ignore cache)', 'signal-and-noise-tools' ), false );
	}
	$form = insights_run_form_tag( $last ? __( 'Re-run analysis', 'signal-and-noise-tools' ) : __( 'Run Analysis', 'signal-and-noise-tools' ), ! $ai_ready, $fields );
	return \snt_kit_section(
		__( 'Run Analysis', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( 'Single AI call per scan. Returns zero or more unexplored open questions worth developing; "no angle worth a note right now" is a valid result. Re-runs within 7 days return the cached result unless you check "Force fresh scan".', 'signal-and-noise-tools' ) ) . '</p>' . $form
	);
}

/**
 * One recommendation card: question + badges, the three notes, the door to
 * the adjacent post when it names one, and the triage actions (mark done —
 * omitted once done —, snooze, dismiss-with-confirm). Each action is its own
 * tiny `<os-form>` so `rec_id` survives as a named field, the same oracle the
 * classic per-recommendation `<form>` offers.
 *
 * @param array $rec     One recommendation row.
 * @param bool  $is_done Whether it is in the done list.
 * @return string
 */
function insights_rec_card_html( array $rec, $is_done ) {
	$id     = (string) ( $rec['id'] ?? '' );
	$badges = \snt_kit_badge( 'ok', __( 'Open question', 'signal-and-noise-tools' ) );
	if ( $is_done ) {
		$badges .= \snt_kit_badge( '', __( 'done', 'signal-and-noise-tools' ) );
	}
	$body = '<os-cluster gap="8">' . $badges . '</os-cluster>';
	if ( ! empty( $rec['adjacent_note'] ) ) {
		$body .= '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'Extends:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $rec['adjacent_note'] ) . '</p>';
	}
	if ( ! empty( $rec['why_uncovered'] ) ) {
		$body .= '<p class="snt-hint"><b>' . \snt_kit_esc( __( 'Not yet covered:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $rec['why_uncovered'] ) . '</p>';
	}
	if ( ! empty( $rec['wall_check'] ) ) {
		$body .= '<p class="snt-hint"><b>' . \snt_kit_esc( __( 'Wall check:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( (string) $rec['wall_check'] ) . '</p>';
	}
	if ( ! empty( $rec['target']['post_id'] ) ) {
		$body .= '<p>' . \snt_kit_door( __( 'Open adjacent note →', 'signal-and-noise-tools' ), admin_url( 'post.php?post=' . (int) $rec['target']['post_id'] . '&action=edit' ) ) . '</p>';
	}
	$id_field = \snt_kit_field( 'hidden', 'rec_id', '', $id );
	$actions  = '<os-cluster gap="8">';
	if ( ! $is_done ) {
		$actions .= \snt_kit_form( 'insights_mark_done', $id_field, array( 'submit' => __( 'Mark done', 'signal-and-noise-tools' ) ) );
	}
	$actions .= \snt_kit_form( 'insights_snooze', $id_field, array( 'submit' => __( 'Snooze 30d', 'signal-and-noise-tools' ) ) );
	$actions .= \snt_kit_form(
		'insights_dismiss',
		$id_field,
		array(
			'submit'  => __( 'Dismiss', 'signal-and-noise-tools' ),
			'confirm' => __( "It won't appear again on this scan.", 'signal-and-noise-tools' ),
			'danger'  => true,
		)
	);
	$actions .= '</os-cluster>';
	return \snt_kit_section( (string) ( $rec['question'] ?? '' ), $body . $actions );
}

/**
 * The recommendations block: absent scan paints nothing (the status box
 * already prompts to run one), an empty result paints the "no angle worth a
 * note" card, an all-filtered result paints "no active questions", otherwise
 * one card per active recommendation.
 *
 * @param array|null $last From snt_insights_last_scan().
 * @return string
 */
function insights_recommendations_html( $last ) {
	if ( ! $last ) {
		return '';
	}
	$recs = ( isset( $last['recommendations'] ) && is_array( $last['recommendations'] ) ) ? $last['recommendations'] : array();
	if ( empty( $recs ) ) {
		return \snt_kit_section(
			__( 'No angle worth a note right now', 'signal-and-noise-tools' ),
			'<p class="snt-prose">' . \snt_kit_esc( __( 'The last scan found no unexplored question that clears the wall. That is an expected outcome, not a failure. Re-run after you publish or revise a note.', 'signal-and-noise-tools' ) ) . '</p>'
		);
	}
	$state  = function_exists( 'snt_insights_state_read' ) ? snt_insights_state_read() : array( 'done_ids' => array() );
	$active = function_exists( 'snt_insights_filter_active' ) ? snt_insights_filter_active( $recs ) : $recs;
	if ( empty( $active ) ) {
		return \snt_kit_section(
			__( 'No active questions', 'signal-and-noise-tools' ),
			'<p class="snt-prose">' . \snt_kit_esc( __( 'Every question from the last scan is dismissed or snoozed. Run a fresh scan to surface new ones.', 'signal-and-noise-tools' ) ) . '</p>'
		);
	}
	$done_flip = array_flip( (array) ( $state['done_ids'] ?? array() ) );
	$cards     = '';
	foreach ( $active as $rec ) {
		if ( ! is_array( $rec ) ) {
			continue;
		}
		$cards .= insights_rec_card_html( $rec, isset( $done_flip[ (string) ( $rec['id'] ?? '' ) ] ) );
	}
	return \snt_kit_tag( 'os-grid', array( 'columns' => '2', 'gap' => '8' ), $cards );
}

/**
 * AI usage & spend: trailing 30/7-day summaries, the monthly budget readout,
 * the per-feature table (or its empty state), and the footnotes.
 *
 * @return string
 */
function insights_usage_html() {
	if ( ! function_exists( 'snt_ai_usage_summary' ) ) {
		return '';
	}
	$s30 = snt_ai_usage_summary( 30 );
	$s7  = snt_ai_usage_summary( 7 );
	$fmt_cost = static function ( $c ) {
		$c = (float) $c;
		if ( $c > 0 && $c < 0.005 ) {
			return '<$0.01';
		}
		return '$' . number_format_i18n( $c, 2 );
	};
	$out = '<p class="snt-prose">' . \snt_kit_esc( __( "Estimated spend for this plugin's own AI features (Insights, meta descriptions, OG titles, alt text, tag suggestions…), computed from the tokens each call recorded, at Anthropic list pricing.", 'signal-and-noise-tools' ) )
		. ' ' . \snt_kit_esc( __( 'The full per-request log of all AI Connector traffic lives in', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_door( __( 'Settings → AI', 'signal-and-noise-tools' ), admin_url( 'options-general.php?page=ai-wp-admin' ) )
		. ' ' . \snt_kit_esc( __( '→ AI Request Logs.', 'signal-and-noise-tools' ) ) . '</p>';
	$out .= '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'Last 30 days:', 'signal-and-noise-tools' ) ) . '</b> '
		. \snt_kit_esc( number_format_i18n( (int) ( $s30['calls'] ?? 0 ) ) ) . ' '
		. \snt_kit_esc( _n( 'call', 'calls', (int) ( $s30['calls'] ?? 0 ), 'signal-and-noise-tools' ) ) . ', '
		. \snt_kit_esc( number_format_i18n( (int) ( $s30['total'] ?? 0 ) ) ) . ' '
		. \snt_kit_esc( __( 'tokens, est.', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( $fmt_cost( $s30['cost'] ?? 0 ) ) . '. '
		. '<b>' . \snt_kit_esc( __( 'Last 7 days:', 'signal-and-noise-tools' ) ) . '</b> '
		. \snt_kit_esc( number_format_i18n( (int) ( $s7['total'] ?? 0 ) ) ) . ' ' . \snt_kit_esc( __( 'tokens, est.', 'signal-and-noise-tools' ) ) . ' ' . \snt_kit_esc( $fmt_cost( $s7['cost'] ?? 0 ) ) . '.</p>';

	$budget = function_exists( 'sn_setting' ) ? (float) sn_setting( 'theme.ai_monthly_budget', 0 ) : 0.0;
	$spent  = function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0;
	if ( $budget > 0 ) {
		$pct  = (int) round( min( 100, ( $spent / $budget ) * 100 ) );
		$out .= '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'This month:', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_esc( $fmt_cost( $spent ) ) . ' '
			. \snt_kit_esc( sprintf( __( 'of $%s budget (%d%%)', 'signal-and-noise-tools' ), number_format_i18n( $budget, 2 ), $pct ) )
			. ( $spent >= $budget ? ' ' . \snt_kit_esc( __( '— limit reached; AI features are paused until next month', 'signal-and-noise-tools' ) ) : '' ) . '.</p>';
	} else {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'No monthly AI budget set (unlimited). Set a cap on the Front-End settings tab to pause AI features once a month\'s spend is reached.', 'signal-and-noise-tools' ) ) . '</p>';
	}

	if ( ! empty( $s30['by_feature'] ) && is_array( $s30['by_feature'] ) ) {
		$by_feature = $s30['by_feature'];
		uasort(
			$by_feature,
			static function ( $a, $b ) {
				return ( (float) ( $b['cost'] ?? 0 ) ) <=> ( (float) ( $a['cost'] ?? 0 ) );
			}
		);
		$rows = array();
		foreach ( $by_feature as $feature => $row ) {
			$rows[] = array( 'feature' => (string) $feature, 'calls' => number_format_i18n( (int) ( $row['calls'] ?? 0 ) ), 'tokens' => number_format_i18n( (int) ( $row['total'] ?? 0 ) ), 'cost' => $fmt_cost( $row['cost'] ?? 0 ) );
		}
		$out .= \snt_kit_table(
			array( array( 'key' => 'feature', 'label' => __( 'Feature', 'signal-and-noise-tools' ) ), array( 'key' => 'calls', 'label' => __( 'Calls', 'signal-and-noise-tools' ), 'align' => 'end' ), array( 'key' => 'tokens', 'label' => __( 'Tokens', 'signal-and-noise-tools' ), 'align' => 'end' ), array( 'key' => 'cost', 'label' => __( 'Est. cost', 'signal-and-noise-tools' ), 'align' => 'end' ) ),
			$rows
		);
	} else {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'No AI calls recorded in the trailing window yet.', 'signal-and-noise-tools' ) ) . '</p>';
	}

	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'List-price estimate — excludes prompt-cache and batch discounts;', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_door( __( 'Settings → AI', 'signal-and-noise-tools' ), admin_url( 'options-general.php?page=ai-wp-admin' ) )
		. ' ' . \snt_kit_esc( __( 'holds the authoritative per-request record.', 'signal-and-noise-tools' ) ) . '</p>';
	if ( (int) ( $s30['cost_unpriced_calls'] ?? 0 ) > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( '%s call(s) used a model with no list price on file — tokens are counted but excluded from the dollar figure.', 'signal-and-noise-tools' ), number_format_i18n( (int) $s30['cost_unpriced_calls'] ) ) ) . '</p>';
	}
	if ( defined( 'SN_AI_USAGE_LOG_CAP' ) && (int) ( $s30['window_start'] ?? 0 ) > 0 ) {
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'The usage log keeps the last %s calls (oldest retained: %s).', 'signal-and-noise-tools' ), number_format_i18n( SN_AI_USAGE_LOG_CAP ), wp_date( 'Y-m-d', (int) $s30['window_start'] ) ) ) . '</p>';
	}
	return \snt_kit_section( __( 'AI usage & spend', 'signal-and-noise-tools' ), $out );
}

/**
 * The prompt-cache probe verdict card: the state pill + sentence, the
 * summary line, the per-model table, the non-dominant-model samples table,
 * the cache-token readout and the closing footnote.
 *
 * @return string
 */
function insights_cache_probe_html() {
	if ( ! function_exists( 'snt_ai_cache_probe_verdict' ) ) {
		return '';
	}
	$v       = snt_ai_cache_probe_verdict();
	$summary = (array) ( $v['summary'] ?? array() );
	$states  = array(
		'no_data'        => array( 'warn', __( 'Nothing measured yet', 'signal-and-noise-tools' ), __( 'No Anthropic call has passed through the probe since it was installed. This is <b>not a verdict</b> — it is an empty window. Run an AI feature, or a couple of Ask AI turns, then reload.', 'signal-and-noise-tools' ) ),
		'below_floor'    => array( 'ok', __( 'Caching cannot pay here', 'signal-and-noise-tools' ), __( "Every prefix measured is below its model's minimum cacheable size, so Anthropic would cache nothing even if a breakpoint were sent — silently, with zeros in the response. Repeats do not change that.", 'signal-and-noise-tools' ) ),
		'no_repeats'     => array( 'warn', __( 'Large enough, but never repeated', 'signal-and-noise-tools' ), __( 'A prefix clears the floor, but no prefix repeated inside the 5-minute cache window. A cache write with no read costs 1.25× and returns nothing.', 'signal-and-noise-tools' ) ),
		'candidate'      => array( 'err', __( 'Caching would pay', 'signal-and-noise-tools' ), __( "A prefix clears its model's floor <i>and</i> repeats inside the cache window. Reads bill at 0.1× against a 1.25× write, so this breaks even on the second call.", 'signal-and-noise-tools' ) ),
		'caching_active' => array( 'ok', __( 'Caching is active', 'signal-and-noise-tools' ), __( 'Cache reads are being reported, so something in the stack now emits a breakpoint.', 'signal-and-noise-tools' ) ),
		'unknown_floor'  => array( 'warn', __( 'No floor on file', 'signal-and-noise-tools' ), __( 'Calls were recorded against a model whose minimum cacheable size is not known here, so no claim is made either way.', 'signal-and-noise-tools' ) ),
	);
	$state   = (string) ( $v['state'] ?? 'no_data' );
	list( $kind, $title, $body ) = $states[ $state ] ?? $states['no_data'];
	$out     = \snt_kit_notice( $kind, '<b>' . \snt_kit_esc( $title ) . '</b> ' . $body );

	if ( 'no_data' !== $state ) {
		$out .= '<p class="snt-prose">' . \snt_kit_esc( sprintf(
			__( '%1$s calls recorded, %2$s distinct prefixes, %3$s repeated within the cache window. Largest prefix seen: %4$s bytes.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) ( $summary['calls'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['prefixes'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['repeatable'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['max_prefix_bytes'] ?? 0 ) )
		) ) . '</p>';

		$models = (array) ( $v['models'] ?? array() );
		if ( ! empty( $models ) ) {
			$rows = array();
			foreach ( $models as $m ) {
				$floor = $m['floor'] ?? null;
				if ( null === $floor ) {
					$verdict = __( 'floor unknown', 'signal-and-noise-tools' );
				} elseif ( true === ( $m['may_clear_floor'] ?? false ) ) {
					$verdict = __( 'clears the floor', 'signal-and-noise-tools' );
				} else {
					$verdict = __( 'below the floor', 'signal-and-noise-tools' );
				}
				$rows[] = array(
					'model'    => '' !== (string) ( $m['model'] ?? '' ) ? (string) $m['model'] : 'unknown',
					'calls'    => number_format_i18n( (int) ( $m['calls'] ?? 0 ) ),
					'repeated' => number_format_i18n( (int) ( $m['repeatable'] ?? 0 ) ),
					'prefix'   => number_format_i18n( (int) ( $m['max_prefix_bytes'] ?? 0 ) ) . ' bytes (≤ ' . number_format_i18n( (int) ( $m['max_prefix_tokens'] ?? 0 ) ) . ' tokens)',
					'floor'    => null === $floor ? '—' : number_format_i18n( (int) $floor ) . ' tokens',
					'verdict'  => $verdict,
				);
			}
			$out .= \snt_kit_table(
				array( array( 'key' => 'model', 'label' => __( 'Model', 'signal-and-noise-tools' ) ), array( 'key' => 'calls', 'label' => __( 'Calls', 'signal-and-noise-tools' ), 'align' => 'end' ), array( 'key' => 'repeated', 'label' => __( 'Repeated', 'signal-and-noise-tools' ), 'align' => 'end' ), array( 'key' => 'prefix', 'label' => __( 'Largest prefix', 'signal-and-noise-tools' ) ), array( 'key' => 'floor', 'label' => __( 'Minimum to cache', 'signal-and-noise-tools' ) ), array( 'key' => 'verdict', 'label' => __( 'Verdict', 'signal-and-noise-tools' ) ) ),
				$rows
			);

			$minor = array();
			foreach ( $models as $m ) {
				$is_best = isset( $v['best']['model'] ) && $v['best']['model'] === ( $m['model'] ?? null );
				if ( ! $is_best && ! empty( $m['samples'] ) ) {
					$minor[] = $m;
				}
			}
			if ( $minor ) {
				$out .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Recent calls on the models above that are not the dominant one. The probe records every Anthropic call the site makes, including other plugins, so a model this plugin never pins can appear here. Sizes and counts only — prompt text is never stored.', 'signal-and-noise-tools' ) ) . '</p>';
				$srows = array();
				foreach ( $minor as $m ) {
					foreach ( (array) ( $m['samples'] ?? array() ) as $s ) {
						$srows[] = array(
							'model'   => '' !== (string) ( $m['model'] ?? '' ) ? (string) $m['model'] : 'unknown',
							'when'    => (int) ( $s['ts'] ?? 0 ) > 0 ? wp_date( 'Y-m-d H:i:s T', (int) $s['ts'] ) : '—',
							'tools'   => number_format_i18n( (int) ( $s['tools_count'] ?? 0 ) ),
							'sys'     => number_format_i18n( (int) ( $s['sys_bytes'] ?? 0 ) ) . ' bytes',
							'msgs'    => number_format_i18n( (int) ( $s['msg_count'] ?? 0 ) ),
						);
					}
				}
				$out .= \snt_kit_table(
					array( array( 'key' => 'model', 'label' => __( 'Model', 'signal-and-noise-tools' ) ), array( 'key' => 'when', 'label' => __( 'When', 'signal-and-noise-tools' ) ), array( 'key' => 'tools', 'label' => __( 'Tools', 'signal-and-noise-tools' ) ), array( 'key' => 'sys', 'label' => __( 'System prompt', 'signal-and-noise-tools' ) ), array( 'key' => 'msgs', 'label' => __( 'Messages', 'signal-and-noise-tools' ) ) ),
					$srows
				);
			}
		}

		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf(
			__( 'Cache tokens reported by Anthropic: %1$s read, %2$s written, across %3$s calls that reported the fields.', 'signal-and-noise-tools' ),
			number_format_i18n( (int) ( $summary['cache_read'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['cache_write'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['measured'] ?? 0 ) )
		) ) . ' ' . \snt_kit_esc( __( 'Zero here means the API was asked and answered "nothing cached", which is different from never having asked.', 'signal-and-noise-tools' ) ) . '</p>';
	}

	$cap  = defined( 'SN_AI_CACHE_PROBE_CAP' ) ? SN_AI_CACHE_PROBE_CAP : 200;
	$out .= '<p class="snt-hint">' . \snt_kit_esc( __( "Token counts are upper bounds: the smaller of a dense byte estimate and the request's own reported input, which the cacheable prefix is always a subset of.", 'signal-and-noise-tools' ) )
		. ' ' . \snt_kit_esc( __( 'Nothing here can change until the provider can emit a cache breakpoint — tracked upstream at', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_link( 'ai-provider-for-anthropic#33', 'https://github.com/WordPress/ai-provider-for-anthropic/issues/33' ) . '. '
		. \snt_kit_esc( sprintf( __( 'The probe records the last %s calls and never stores prompt text.', 'signal-and-noise-tools' ), number_format_i18n( $cap ) ) ) . '</p>';
	return \snt_kit_section( __( 'Prompt-cache probe', 'signal-and-noise-tools' ), $out );
}

/**
 * The scan-status box: last-scan age, the active/dismissed/done counts and a
 * state badge, or the "no scan yet" prompt.
 *
 * @param array|null $last From snt_insights_last_scan().
 * @return string
 */
function insights_status_html( $last ) {
	if ( ! $last ) {
		return \snt_kit_notice( 'warn', '<b>' . \snt_kit_esc( __( 'No scan run yet', 'signal-and-noise-tools' ) ) . '</b> ' . \snt_kit_badge( 'warn', __( 'Inactive', 'signal-and-noise-tools' ) ) . '<br>' . \snt_kit_esc( __( 'Click Run Analysis in the main column to populate recommendations. ~$0.01 per scan; 7-day cache.', 'signal-and-noise-tools' ) ) );
	}
	$state           = function_exists( 'snt_insights_state_read' ) ? snt_insights_state_read() : array( 'dismissed_ids' => array(), 'done_ids' => array() );
	$active          = function_exists( 'snt_insights_filter_active' ) ? snt_insights_filter_active( $last['recommendations'] ?? array() ) : array();
	$active_count    = count( $active );
	$dismissed_count = count( (array) ( $state['dismissed_ids'] ?? array() ) );
	$done_count      = count( (array) ( $state['done_ids'] ?? array() ) );
	$kind            = $active_count > 0 ? 'ok' : 'warn';
	$ttl             = defined( 'SN_INSIGHTS_CACHE_TTL' ) ? SN_INSIGHTS_CACHE_TTL : 7 * DAY_IN_SECONDS;
	$elapsed         = function_exists( 'snt_health_format_elapsed' ) ? snt_health_format_elapsed( (int) ( $last['elapsed_ms'] ?? 0 ) ) : '';
	$body            = sprintf(
		/* translators: 1: how long ago, 2: active count, 3: dismissed count, 4: done count, 5: elapsed, 6: cached-until date */
		__( 'Last scan %1$s ago. %2$s active · %3$s dismissed · %4$s done · scan ran in %5$s · cached until %6$s.', 'signal-and-noise-tools' ),
		human_time_diff( (int) $last['scanned_at'], time() ),
		number_format_i18n( $active_count ),
		number_format_i18n( $dismissed_count ),
		number_format_i18n( $done_count ),
		$elapsed,
		wp_date( 'Y-m-d H:i', (int) $last['scanned_at'] + $ttl )
	);
	return \snt_kit_notice( $kind, \snt_kit_badge( $kind, $active_count > 0 ? __( 'Recommendations ready', 'signal-and-noise-tools' ) : __( 'All caught up', 'signal-and-noise-tools' ) ) . '<br>' . \snt_kit_esc( $body ) );
}

/**
 * The weekly-cron settings form: the same one checkbox, the same action.
 *
 * @return string
 */
function insights_settings_html() {
	$enabled = function_exists( 'snt_insights_weekly_cron_enabled' ) && snt_insights_weekly_cron_enabled();
	$field   = \snt_kit_field( 'checkbox', 'insights_weekly_cron', __( 'Run a weekly scan automatically', 'signal-and-noise-tools' ), $enabled );
	$form    = \snt_kit_form( 'save_insights_settings', $field, array( 'submit' => __( 'Save settings', 'signal-and-noise-tools' ) ) );
	return \snt_kit_section(
		__( 'Settings', 'signal-and-noise-tools' ),
		'<p class="snt-prose">' . \snt_kit_esc( __( 'A weekly automated scan can be enabled here. Defaults off. When enabled, fires weekly. You can still click Run Analysis any time.', 'signal-and-noise-tools' ) ) . '</p>' . $form
	);
}
