<?php
/**
 * Signal & Noise Tools — Insights admin tab.
 *
 * Render-only. The 4 form actions (insights_run, insights_dismiss,
 * insights_snooze, insights_mark_done) route through sn_handle_admin_post
 * in inc/admin-page.php — same shared sn_theme_options_nonce pattern as
 * every other SN tab (v3.5.1 lesson encoded).
 *
 * Uses the bespoke .sn-fieldset / .sn-field / .sn-pill design system
 * (matches cloudflare-purge.php, plausible-admin.php, webhooks-admin.php).
 *
 * @package SignalNoiseTools
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_insights_tab', 'snt_insights_render_admin_tab' );

function snt_insights_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$last = function_exists( 'snt_insights_last_scan' ) ? snt_insights_last_scan() : null;
	$ai_ready = function_exists( 'snt_ai_is_available' ) && snt_ai_is_available();

	sn_admin_shell_open();

	// ── MAIN COLUMN: the scan workflow (configure -> run -> review) ──
	echo '<p class="sn-prose">Cross-system synthesis: reads your Plausible analytics, publish history, webhook delivery patterns, and cron freshness, then surfaces unexplored open questions worth developing for your Notes (or nothing, when none clears the bar). One AI call per scan; results cached 7 days.</p>';

	// ── RUN ANALYSIS form ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Run Analysis</h2>';
	echo '<p class="sn-fieldset-intro">Single AI call per scan. Returns zero or more unexplored open questions worth developing; "no angle worth a note right now" is a valid result. Re-runs within 7 days return the cached result unless you check "Force fresh scan".</p>';
	if ( ! $ai_ready ) {
		echo '<p class="sn-field-helper sn-text--err"><strong>AI client not available.</strong> Two setup steps are required: <a href="' . esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) ) . '">Settings → AI</a> (global enable + per-feature toggles), and <a href="' . esc_url( admin_url( 'options-general.php?page=connectors' ) ) . '">Settings → Connectors</a> (provider + API key). Both must be configured before this can run.</p>';
	}
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="insights_run" class="button button-primary"' . ( $ai_ready ? '' : ' disabled' ) . '>' . esc_html( $last ? 'Re-run analysis' : 'Run Analysis' ) . '</button>';
	if ( $last ) {
		echo ' <label class="sn-ml-auto"><input type="checkbox" name="force" value="1"> Force fresh scan (ignore cache)</label>';
	}
	echo '</div>';
	echo '</div>';
	echo '</form>';

	// ── RECOMMENDATIONS cards (rendered by Task 12) ──
	snt_insights_render_recommendations_section( $last );

	// ── AI USAGE & SPEND — a wide four-column table, so it belongs in the MAIN
	// column (v6.45.0: moved out of the asymmetric rail, where a wide table would
	// wrap its headers; the narrower side holds only compact readouts). ──
	snt_insights_render_usage_section();

	// ── PROMPT-CACHE PROBE — sits directly under the spend readout, which has
	// to disclaim that its estimate excludes prompt-cache discounts. This is
	// the answer to "could we get that discount?", and it is also a wide
	// table, so it belongs in the main column too. ──
	snt_insights_render_cache_probe_section();

	// ── RIGHT RAIL: compact passive readouts + automation ──
	// The scan status and the weekly-cron settings are compact reference/config,
	// so they sit in the narrower side; the main opens on the scan workflow.
	sn_admin_shell_rail( 'Scan status and automation' );
	snt_insights_render_status_section( $last );
	snt_insights_render_settings_section();

	sn_admin_shell_close();
}

/**
 * Renders the compact scan-status box (rail tenant): last-scan age, the
 * active/dismissed/done counts, and a state pill — or a prompt to run the
 * first scan. Extracted from the tab body in v6.42.0 when the status moved
 * into the right rail.
 *
 * @param array|null $last The last scan record, or null when none has run.
 * @return void
 */
function snt_insights_render_status_section( $last ) {
	if ( $last ) {
		$state           = snt_insights_state_read();
		$active          = snt_insights_filter_active( $last['recommendations'] );
		$active_count    = count( $active );
		$dismissed_count = count( $state['dismissed_ids'] );
		$done_count      = count( $state['done_ids'] );

		$pill = $active_count > 0 ? 'ok' : 'warn';
		echo '<div class="sn-status-box' . ( 'ok' === $pill ? '' : ' sn-status-box--warn' ) . '">';
		echo '<div>';
		echo '<p class="sn-status-box-title">Last scan ' . esc_html( human_time_diff( (int) $last['scanned_at'], time() ) ) . ' ago</p>';
		echo '<p class="sn-status-box-body">' . esc_html( $active_count ) . ' active &middot; ' . esc_html( $dismissed_count ) . ' dismissed &middot; ' . esc_html( $done_count ) . ' done · scan ran in ' . esc_html( snt_health_format_elapsed( (int) $last['elapsed_ms'] ) ) . ' · cached until ' . esc_html( wp_date( 'Y-m-d H:i', (int) $last['scanned_at'] + SN_INSIGHTS_CACHE_TTL ) ) . '.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--' . esc_attr( $pill ) . '">' . esc_html( $active_count > 0 ? 'Recommendations ready' : 'All caught up' ) . '</span>';
		echo '</div>';
	} else {
		echo '<div class="sn-status-box sn-status-box--warn">';
		echo '<div>';
		echo '<p class="sn-status-box-title">No scan run yet</p>';
		echo '<p class="sn-status-box-body">Click <strong>Run Analysis</strong> in the main column to populate recommendations. ~$0.01 per scan; 7-day cache.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}
}

/**
 * Read-only "AI usage & spend" section: token usage + estimated USD cost for
 * the plugin's OWN AI features, from the recorded usage log
 * (snt_ai_usage_summary). This is the plugin-scoped, cost-annotated complement
 * to WordPress's native AI Request Logs (Settings → AI), which hold the full
 * per-request log of all AI Connector traffic. Cost is a list-price estimate
 * derived from the real recorded token counts.
 *
 * @since 6.41.0
 */
function snt_insights_render_usage_section() {
	if ( ! function_exists( 'snt_ai_usage_summary' ) ) {
		return;
	}
	$s30 = snt_ai_usage_summary( 30 );
	$s7  = snt_ai_usage_summary( 7 );

	// Format a USD estimate: cents precision, with a sub-cent floor so a tiny
	// real spend never reads as a misleading "$0.00".
	$fmt_cost = static function ( $c ) {
		$c = (float) $c;
		if ( $c > 0 && $c < 0.005 ) {
			return '<$0.01';
		}
		return '$' . number_format_i18n( $c, 2 );
	};
	$plural = static function ( $n ) {
		return 1 === (int) $n ? '' : 's';
	};

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">AI usage &amp; spend</h2>';
	echo '<p class="sn-fieldset-intro">Estimated spend for this plugin&rsquo;s own AI features (Insights, meta descriptions, OG titles, alt text, tag suggestions&hellip;), computed from the tokens each call recorded, at Anthropic list pricing. The full per-request log of all AI&nbsp;Connector traffic lives in <a href="' . esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) ) . '">Settings &rarr; AI</a> &rarr; AI&nbsp;Request&nbsp;Logs.</p>';

	echo '<p class="sn-status-box-body"><strong>Last 30 days:</strong> '
		. esc_html( number_format_i18n( (int) $s30['calls'] ) ) . ' call' . esc_html( $plural( $s30['calls'] ) ) . ', '
		. esc_html( number_format_i18n( (int) $s30['total'] ) ) . ' tokens, est. '
		. esc_html( $fmt_cost( $s30['cost'] ) ) . '. &nbsp; <strong>Last 7 days:</strong> '
		. esc_html( number_format_i18n( (int) $s7['total'] ) ) . ' tokens, est. '
		. esc_html( $fmt_cost( $s7['cost'] ) ) . '.</p>';

	// v9.26.0: calendar-month spend vs the owner's budget cap (0 = no cap). The
	// "this month" figure reads the durable rollup, so it is exact regardless of
	// the FIFO log window the trailing summaries above use.
	$sn_budget = function_exists( 'sn_setting' ) ? (float) sn_setting( 'theme.ai_monthly_budget', 0 ) : 0.0;
	$sn_spent  = function_exists( 'snt_ai_spend_this_month' ) ? (float) snt_ai_spend_this_month() : 0.0;
	if ( $sn_budget > 0 ) {
		$sn_pct = (int) round( min( 100, ( $sn_spent / $sn_budget ) * 100 ) );
		echo '<p class="sn-status-box-body"><strong>This month:</strong> '
			. esc_html( $fmt_cost( $sn_spent ) ) . ' of $' . esc_html( number_format_i18n( $sn_budget, 2 ) )
			. ' budget (' . esc_html( (string) $sn_pct ) . '%)'
			. ( $sn_spent >= $sn_budget ? ' &mdash; <strong>limit reached; AI features are paused until next month</strong>' : '' )
			. '.</p>';
	} else {
		echo '<p class="sn-field-helper">No monthly AI budget set (unlimited). Set a cap on the Front-End settings tab to pause AI features once a month&rsquo;s spend is reached.</p>';
	}

	if ( ! empty( $s30['by_feature'] ) ) {
		$rows = $s30['by_feature'];
		uasort(
			$rows,
			static function ( $a, $b ) {
				return ( (float) ( $b['cost'] ?? 0 ) ) <=> ( (float) ( $a['cost'] ?? 0 ) );
			}
		);
		echo '<table class="widefat striped"><thead><tr><th>Feature</th><th>Calls</th><th>Tokens</th><th>Est. cost</th></tr></thead><tbody>';
		foreach ( $rows as $feature => $row ) {
			echo '<tr><td>' . esc_html( $feature ) . '</td><td>'
				. esc_html( number_format_i18n( (int) $row['calls'] ) ) . '</td><td>'
				. esc_html( number_format_i18n( (int) $row['total'] ) ) . '</td><td>'
				. esc_html( $fmt_cost( $row['cost'] ?? 0 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p class="sn-field-helper">No AI calls recorded in the trailing window yet.</p>';
	}

	echo '<p class="sn-field-helper">List-price estimate &mdash; excludes prompt-cache and batch discounts; <a href="' . esc_url( admin_url( 'options-general.php?page=ai-wp-admin' ) ) . '">Settings &rarr; AI</a> holds the authoritative per-request record.</p>';
	if ( (int) $s30['cost_unpriced_calls'] > 0 ) {
		echo '<p class="sn-field-helper">' . esc_html( number_format_i18n( (int) $s30['cost_unpriced_calls'] ) ) . ' call' . esc_html( $plural( $s30['cost_unpriced_calls'] ) ) . ' used a model with no list price on file &mdash; tokens are counted but excluded from the dollar figure.</p>';
	}
	if ( defined( 'SN_AI_USAGE_LOG_CAP' ) && (int) $s30['window_start'] > 0 ) {
		echo '<p class="sn-field-helper">The usage log keeps the last ' . esc_html( number_format_i18n( SN_AI_USAGE_LOG_CAP ) ) . ' calls (oldest retained: ' . esc_html( wp_date( 'Y-m-d', (int) $s30['window_start'] ) ) . ').</p>';
	}

	echo '</div>';
}

/**
 * Read-only "Prompt-cache probe" section: does Anthropic prompt caching have
 * anything to save on this site?
 *
 * The companion to the section above, which has to disclaim that its estimate
 * "excludes prompt-cache discounts" — this one says whether that discount is
 * reachable at all. It is deliberately a VERDICT, not a dump: the raw numbers
 * mislead on their own, because a repeated prefix under the model's minimum
 * cacheable size looks like a signal and is worth exactly nothing.
 *
 * Caching pays only when a prefix clears its model's floor AND repeats inside
 * the TTL. Both conditions are rendered, always, so a "no" states which half
 * failed rather than reading as a shrug.
 *
 * @since 10.52.0
 */
function snt_insights_render_cache_probe_section() {
	if ( ! function_exists( 'snt_ai_cache_probe_verdict' ) ) {
		return;
	}

	$v       = snt_ai_cache_probe_verdict();
	$summary = $v['summary'];
	$best    = $v['best'];

	// One sentence per state, each naming WHICH condition decided it. The pill
	// is the same judgement in a word.
	$states = array(
		'no_data'        => array( 'warn', 'Nothing measured yet', 'No Anthropic call has passed through the probe since it was installed. This is <strong>not a verdict</strong> &mdash; it is an empty window. Run an AI feature, or a couple of Ask&nbsp;AI turns, then reload.' ),
		'below_floor'    => array( 'ok', 'Caching cannot pay here', 'Every prefix measured is below its model&rsquo;s minimum cacheable size, so Anthropic would cache nothing even if a breakpoint were sent &mdash; silently, with zeros in the response. Repeats do not change that.' ),
		'no_repeats'     => array( 'warn', 'Large enough, but never repeated', 'A prefix clears the floor, but no prefix repeated inside the 5-minute cache window. A cache write with no read costs 1.25&times; and returns nothing.' ),
		'candidate'      => array( 'err', 'Caching would pay', 'A prefix clears its model&rsquo;s floor <em>and</em> repeats inside the cache window. Reads bill at 0.1&times; against a 1.25&times; write, so this breaks even on the second call.' ),
		'caching_active' => array( 'ok', 'Caching is active', 'Cache reads are being reported, so something in the stack now emits a breakpoint.' ),
		'unknown_floor'  => array( 'warn', 'No floor on file', 'Calls were recorded against a model whose minimum cacheable size is not known here, so no claim is made either way.' ),
	);
	list( $pill, $title, $body ) = $states[ $v['state'] ] ?? $states['no_data'];

	// v13.20.3: --wide. This card holds the prompt-cache probe's SIX-column
	// table (Model / Calls / Repeated / Largest prefix / Minimum to cache /
	// Verdict), which does not fit the 820px .sn-fieldset cap.
	echo '<div class="sn-fieldset sn-fieldset--wide">';
	echo '<h2 class="sn-fieldset-h">Prompt-cache probe <span class="sn-pill sn-pill--' . esc_attr( $pill ) . '">' . esc_html( $title ) . '</span></h2>';
	echo '<p class="sn-fieldset-intro">' . wp_kses_post( $body ) . '</p>';

	if ( 'no_data' !== $v['state'] ) {
		echo '<p class="sn-status-box-body">'
			. '<strong>' . esc_html( number_format_i18n( (int) $summary['calls'] ) ) . '</strong> call' . esc_html( 1 === (int) $summary['calls'] ? '' : 's' ) . ' recorded, '
			. '<strong>' . esc_html( number_format_i18n( (int) $summary['prefixes'] ) ) . '</strong> distinct prefix' . esc_html( 1 === (int) $summary['prefixes'] ? '' : 'es' ) . ', '
			. '<strong>' . esc_html( number_format_i18n( (int) $summary['repeatable'] ) ) . '</strong> repeated within the cache window. '
			. 'Largest prefix seen: <strong>' . esc_html( number_format_i18n( (int) $summary['max_prefix_bytes'] ) ) . '</strong> bytes.</p>';

		// Per model, because the floor is NOT the same across models — 1,024
		// tokens on Sonnet 5 but 4,096 on Haiku 4.5, the economy tier. A single
		// site-wide comparison would be wrong for half the traffic.
		if ( ! empty( $v['models'] ) ) {
			echo '<table class="widefat striped"><thead><tr><th>Model</th><th>Calls</th><th>Repeated</th><th>Largest prefix</th><th>Minimum to cache</th><th>Verdict</th></tr></thead><tbody>';
			foreach ( $v['models'] as $m ) {
				$floor = $m['floor'];
				if ( null === $floor ) {
					$verdict = '<span class="sn-pill sn-pill--warn">floor unknown</span>';
					$floor_s = '&mdash;';
				} elseif ( true === $m['may_clear_floor'] ) {
					$verdict = '<span class="sn-pill sn-pill--ok">clears the floor</span>';
					$floor_s = esc_html( number_format_i18n( (int) $floor ) ) . ' tokens';
				} else {
					$verdict = '<span class="sn-pill">below the floor</span>';
					$floor_s = esc_html( number_format_i18n( (int) $floor ) ) . ' tokens';
				}
				echo '<tr><td><code>' . esc_html( '' !== $m['model'] ? $m['model'] : 'unknown' ) . '</code></td>'
					. '<td>' . esc_html( number_format_i18n( (int) $m['calls'] ) ) . '</td>'
					. '<td>' . esc_html( number_format_i18n( (int) $m['repeatable'] ) ) . '</td>'
					. '<td>' . esc_html( number_format_i18n( (int) $m['max_prefix_bytes'] ) ) . ' bytes (&le;&nbsp;' . esc_html( number_format_i18n( (int) $m['max_prefix_tokens'] ) ) . ' tokens)</td>'
					. '<td>' . wp_kses_post( $floor_s ) . '</td>'
					. '<td>' . wp_kses_post( $verdict ) . '</td></tr>';
			}
			echo '</tbody></table>';

			// v11.8.0: the aggregate cannot answer "where did THAT come from?".
			// The probe is origin-agnostic by design (it hooks http_response and
			// records every api.anthropic.com/v1/messages call the site makes,
			// including other plugins routed through the WP AI Client), so a
			// model SN never pins can legitimately appear here — and the table
			// above gives no way to place it. Timestamps and a tool count do:
			// an agent run carries a large tool manifest, a connectivity check
			// carries none. Shown ONLY for the non-dominant models, because the
			// dominant one is the site's own traffic and needs no explaining.
			$minor = array();
			foreach ( $v['models'] as $m ) {
				$is_best = isset( $v['best']['model'] ) && $v['best']['model'] === $m['model'];
				if ( ! $is_best && ! empty( $m['samples'] ) ) {
					$minor[] = $m;
				}
			}
			if ( $minor ) {
				echo '<p class="sn-field-helper">Recent calls on the models above that are not the dominant one. '
					. 'The probe records every Anthropic call the site makes, including other plugins, so a model this plugin never pins can appear here. '
					. 'Sizes and counts only &mdash; prompt text is never stored.</p>';
				echo '<table class="widefat striped"><thead><tr><th>Model</th><th>When</th><th>Tools</th><th>System prompt</th><th>Messages</th></tr></thead><tbody>';
				foreach ( $minor as $m ) {
					foreach ( $m['samples'] as $s ) {
						$when = (int) $s['ts'] > 0
							? esc_html( wp_date( 'Y-m-d H:i:s T', (int) $s['ts'] ) )
							: '&mdash;';
						echo '<tr><td><code>' . esc_html( '' !== $m['model'] ? $m['model'] : 'unknown' ) . '</code></td>'
							. '<td>' . wp_kses_post( $when ) . '</td>'
							. '<td>' . esc_html( number_format_i18n( (int) $s['tools_count'] ) ) . '</td>'
							. '<td>' . esc_html( number_format_i18n( (int) $s['sys_bytes'] ) ) . ' bytes</td>'
							. '<td>' . esc_html( number_format_i18n( (int) $s['msg_count'] ) ) . '</td></tr>';
					}
				}
				echo '</tbody></table>';
			}
		}

		// Absent vs measured-zero, surfaced rather than flattened: "0 of 0
		// measured" and "0 across 40 measured calls" are different answers.
		echo '<p class="sn-field-helper">Cache tokens reported by Anthropic: '
			. esc_html( number_format_i18n( (int) $summary['cache_read'] ) ) . ' read, '
			. esc_html( number_format_i18n( (int) $summary['cache_write'] ) ) . ' written, across '
			. esc_html( number_format_i18n( (int) $summary['measured'] ) ) . ' call' . esc_html( 1 === (int) $summary['measured'] ? '' : 's' ) . ' that reported the fields. '
			. 'Zero here means the API was asked and answered &ldquo;nothing cached&rdquo;, which is different from never having asked.</p>';
	}

	echo '<p class="sn-field-helper">Token counts are upper bounds: the smaller of a dense byte estimate and the request&rsquo;s own reported input, which the cacheable prefix is always a subset of. '
		. 'Nothing here can change until the provider can emit a cache breakpoint &mdash; tracked upstream at <a href="https://github.com/WordPress/ai-provider-for-anthropic/issues/33" rel="noopener">ai-provider-for-anthropic#33</a>. '
		. 'The probe records the last ' . esc_html( number_format_i18n( SN_AI_CACHE_PROBE_CAP ) ) . ' calls and never stores prompt text.</p>';

	echo '</div>';
}

/**
 * Renders the open-question cards section.
 *
 * "Recommend nothing" is a first-class outcome: when a scan ran but surfaced no
 * question that clears the wall, we render an explicit "No angle worth a note
 * right now" card rather than a blank space, so the empty result reads as the
 * expected, deliberate output it is (not a failure or a missing render).
 */
function snt_insights_render_recommendations_section( $last ) {
	if ( ! $last ) {
		// No scan has run yet; the rail status box already prompts to run one.
		return;
	}

	$recs = ( isset( $last['recommendations'] ) && is_array( $last['recommendations'] ) ) ? $last['recommendations'] : array();

	// Scan ran, nothing to suggest. This is valid and expected, not an error.
	if ( empty( $recs ) ) {
		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h">No angle worth a note right now</h2>';
		echo '<p class="sn-fieldset-intro">The last scan found no unexplored question that clears the wall. That is an expected outcome, not a failure. Re-run after you publish or revise a note.</p>';
		echo '</div>';
		return;
	}

	$state  = snt_insights_state_read();
	$active = snt_insights_filter_active( $recs );

	if ( empty( $active ) ) {
		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h">No active questions</h2>';
		echo '<p class="sn-fieldset-intro">Every question from the last scan is dismissed or snoozed. Run a fresh scan to surface new ones.</p>';
		echo '</div>';
		return;
	}

	$done_ids_flip = array_flip( $state['done_ids'] );

	// Phase 1 widen: with the leaf wrapper cap gone, the main column reaches its
	// 820px width, so the question cards lay out 2-up at wide widths (the
	// .sn-rec-grid auto-fit grid) instead of stacking at half-width. The Run
	// Analysis + Weekly digest cards stay full main-width; only this card loop
	// is gridded.
	echo '<div class="sn-rec-grid">';
	foreach ( $active as $rec ) {
		$id      = (string) $rec['id'];
		$is_done = isset( $done_ids_flip[ $id ] );

		echo '<div class="sn-fieldset' . ( $is_done ? ' sn-fieldset--muted' : '' ) . '">';
		echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
		echo esc_html( isset( $rec['question'] ) ? (string) $rec['question'] : '' );
		echo ' <span class="sn-pill sn-pill--ok">Open question</span>';
		if ( $is_done ) {
			echo ' <span class="sn-pill sn-pill--done">done</span>';
		}
		echo '</h2>';

		if ( ! empty( $rec['adjacent_note'] ) ) {
			echo '<p class="sn-fieldset-intro"><strong>Extends:</strong> ' . esc_html( (string) $rec['adjacent_note'] ) . '</p>';
		}
		if ( ! empty( $rec['why_uncovered'] ) ) {
			echo '<p class="sn-field-helper"><strong>Not yet covered:</strong> ' . esc_html( (string) $rec['why_uncovered'] ) . '</p>';
		}
		if ( ! empty( $rec['wall_check'] ) ) {
			echo '<p class="sn-field-helper"><strong>Wall check:</strong> ' . esc_html( (string) $rec['wall_check'] ) . '</p>';
		}

		// Link the adjacent note when it is a specific existing post. This points
		// at prior work to build on; it does not prescribe a new post to write.
		if ( ! empty( $rec['target']['post_id'] ) ) {
			$edit_url = admin_url( 'post.php?post=' . (int) $rec['target']['post_id'] . '&action=edit' );
			echo '<p><a href="' . esc_url( $edit_url ) . '" class="button button-small">Open adjacent note →</a></p>';
		}

		// Triage actions only (mark done / snooze / dismiss). There is no
		// "create draft" path: this advisor names questions, it does not seed posts.
		echo '<form method="post" class="sn-fieldset-actions sn-fieldset-actions--inline">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="rec_id" value="' . esc_attr( $id ) . '">';
		if ( ! $is_done ) {
			echo '<button type="submit" name="sn_action" value="insights_mark_done" class="button button-small">Mark done</button> ';
		}
		echo '<button type="submit" name="sn_action" value="insights_snooze" class="button button-small">Snooze 30d</button> ';
		// v4.1.1 (U-01): data-snt-confirm attribute (not inline onclick).
		echo '<button type="submit" name="sn_action" value="insights_dismiss" class="button button-small button-link-delete" data-snt-confirm="' . esc_attr__( "It won't appear again on this scan.", 'signal-and-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Dismiss this question?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Dismiss', 'signal-and-noise-tools' ) . '" data-snt-confirm-danger="1">Dismiss</button>';
		echo '</form>';

		echo '</div>';
	}
	echo '</div>'; // .sn-rec-grid
}

/**
 * Renders the weekly-cron settings section.
 */
function snt_insights_render_settings_section() {
	$enabled = function_exists( 'snt_insights_weekly_cron_enabled' ) ? snt_insights_weekly_cron_enabled() : false;

	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=sn-insights' ) ) . '">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Settings</h2>';
	echo '<p class="sn-fieldset-intro">A weekly automated scan can be enabled here. Defaults off. When enabled, fires weekly. You can still click Run Analysis any time.</p>';
	echo '<div class="sn-field">';
	echo '<label><input type="checkbox" name="insights_weekly_cron" value="1"' . checked( $enabled, true, false ) . '> Run a weekly scan automatically</label>';
	echo '</div>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="save_insights_settings" class="button button-primary">Save settings</button>';
	echo '</div>';
	echo '</div>';
	echo '</form>';
}
