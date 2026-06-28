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
	echo '<p class="sn-prose">Cross-system synthesis: combines your Plausible analytics, publish history, webhook delivery patterns, and cron freshness into 5 actionable recommendations per scan. One AI call per scan; results cached 7 days.</p>';

	// ── RUN ANALYSIS form ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Run Analysis</h2>';
	echo '<p class="sn-fieldset-intro">Single AI call per scan. Returns 5 recommendations across types: write_about, update_post, cadence_change, topic_double_down, topic_pivot. Re-runs within 7 days return the cached result unless you check "Force fresh scan".</p>';
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

	// ── WEEKLY DIGEST (narration, v6.30.0) — read-only prose + Generate ──
	snt_insights_render_narration_section( $ai_ready );

	// ── RECOMMENDATIONS cards (rendered by Task 12) ──
	snt_insights_render_recommendations_section( $last );

	// ── RIGHT RAIL: passive readouts + automation (v6.42.0) ──
	// The scan status, the AI spend readout, and the weekly-cron settings are
	// reference/config, not the scan workflow — they move to the rail so the
	// main column opens directly on Run Analysis + recommendations.
	sn_admin_shell_rail( 'Scan status, AI spend, and automation' );
	snt_insights_render_status_section( $last );
	snt_insights_render_usage_section();
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
		echo '<p class="sn-status-box-body">' . esc_html( $active_count ) . ' active &middot; ' . esc_html( $dismissed_count ) . ' dismissed &middot; ' . esc_html( $done_count ) . ' done · scan ran in ' . esc_html( (int) $last['elapsed_ms'] ) . 'ms · cached until ' . esc_html( wp_date( 'Y-m-d H:i', (int) $last['scanned_at'] + SN_INSIGHTS_CACHE_TTL ) ) . '.</p>';
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
 * Renders the recommendations cards section.
 */
function snt_insights_render_recommendations_section( $last ) {
	if ( ! $last || empty( $last['recommendations'] ) ) {
		return;
	}

	$state = snt_insights_state_read();
	$active = snt_insights_filter_active( $last['recommendations'] );

	if ( empty( $active ) ) {
		echo '<div class="sn-fieldset">';
		echo '<h2 class="sn-fieldset-h">No active recommendations</h2>';
		echo '<p class="sn-fieldset-intro">All recommendations from the last scan are either dismissed or snoozed. Run a fresh scan to get new ones.</p>';
		echo '</div>';
		return;
	}

	$type_labels = array(
		'write_about'        => 'Write About',
		'update_post'        => 'Update Post',
		'cadence_change'     => 'Cadence',
		'topic_double_down'  => 'Double Down',
		'topic_pivot'        => 'Pivot',
	);
	$done_ids_flip = array_flip( $state['done_ids'] );

	// Phase 1 widen: with the leaf wrapper cap gone, the main column now reaches
	// its 820px width, so the recommendation cards lay out 2-up at wide widths
	// (the .sn-rec-grid auto-fit grid) instead of stacking at half-width. The
	// Run Analysis + Weekly digest cards stay full main-width — only this
	// recommendation-card loop is gridded.
	echo '<div class="sn-rec-grid">';
	foreach ( $active as $rec ) {
		$id = (string) $rec['id'];
		$is_done = isset( $done_ids_flip[ $id ] );
		$type_label = isset( $type_labels[ $rec['type'] ] ) ? $type_labels[ $rec['type'] ] : $rec['type'];

		echo '<div class="sn-fieldset' . ( $is_done ? ' sn-fieldset--muted' : '' ) . '">';
		echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">';
		echo esc_html( $rec['title'] );
		echo ' <span class="sn-pill sn-pill--ok">' . esc_html( $type_label ) . '</span>';
		if ( $is_done ) {
			echo ' <span class="sn-pill sn-pill--done">done</span>';
		}
		echo '</h2>';

		echo '<p class="sn-fieldset-intro">' . esc_html( $rec['rationale'] ) . '</p>';

		// Evidence pills.
		if ( ! empty( $rec['evidence_pills'] ) ) {
			echo '<p>';
			foreach ( (array) $rec['evidence_pills'] as $pill ) {
				// v4.1.1 (U-14): evidence pills are data snippets ("3 posts in 7 days"),
				// not status — use the base .sn-pill (neutral gray), not --ok (green).
				// The semantic-ok color was misread by users as "all good."
				echo '<span class="sn-pill sn-pill--spaced">' . esc_html( $pill ) . '</span>';
			}
			echo '</p>';
		}

		// Target link.
		if ( ! empty( $rec['target']['post_id'] ) ) {
			$edit_url = admin_url( 'post.php?post=' . (int) $rec['target']['post_id'] . '&action=edit' );
			echo '<p><a href="' . esc_url( $edit_url ) . '" class="button button-small">Open target post →</a></p>';
		}

		// Action buttons (form per card to share the nonce + carry rec_id).
		echo '<form method="post" class="sn-fieldset-actions sn-fieldset-actions--inline">';
		wp_nonce_field( 'sn_theme_options_nonce' );
		echo '<input type="hidden" name="rec_id" value="' . esc_attr( $id ) . '">';
		// v4.11.0 (T5): write_about recs can seed a Notes draft in one click —
		// zero new AI calls (the rationale becomes the draft body). The clicked
		// button's sn_action wins, so it shares this card's nonce + rec_id form.
		if ( 'write_about' === $rec['type'] && ! $is_done ) {
			echo '<button type="submit" name="sn_action" value="insights_create_draft" class="button button-small button-primary">Create draft</button> ';
		}
		if ( ! $is_done ) {
			echo '<button type="submit" name="sn_action" value="insights_mark_done" class="button button-small">Mark done</button> ';
		}
		echo '<button type="submit" name="sn_action" value="insights_snooze" class="button button-small">Snooze 30d</button> ';
		// v4.1.1 (U-01): replaced onclick="return confirm(...)" with data-snt-confirm attribute.
		echo '<button type="submit" name="sn_action" value="insights_dismiss" class="button button-small button-link-delete" data-snt-confirm="' . esc_attr__( "It won't appear again on this scan.", 'signal-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Dismiss this recommendation?', 'signal-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Dismiss', 'signal-noise-tools' ) . '" data-snt-confirm-danger="1">Dismiss</button>';
		echo '</form>';

		echo '</div>';
	}
	echo '</div>'; // .sn-rec-grid
}

/**
 * Renders the weekly digest (narration) card — read-only prose + a Generate
 * button. v6.30.0. Native WP styling (no brutalist treatment in admin), all
 * dynamic output escaped, cookieless (prose only describes aggregate counts).
 *
 * @param bool $ai_ready Whether the AI client is configured.
 */
function snt_insights_render_narration_section( $ai_ready ) {
	$narration = function_exists( 'snt_narration_last' ) ? snt_narration_last() : null;

	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h sn-fieldset-h--row">Weekly digest';
	echo ' <span class="sn-pill sn-pill--' . esc_attr( $narration ? 'ok' : 'warn' ) . '">' . esc_html( $narration ? 'Generated' : 'Not generated' ) . '</span>';
	echo '</h2>';
	echo '<p class="sn-fieldset-intro">A plain-language summary of what happened this week — what people read, where they came from, and how it changed versus the prior week. One AI call; cached 7 days.</p>';

	if ( $narration ) {
		echo '<p class="sn-status-box-title">' . esc_html( (string) $narration['headline'] ) . '</p>';
		foreach ( (array) $narration['paragraphs'] as $p ) {
			echo '<p class="sn-prose">' . esc_html( (string) $p ) . '</p>';
		}
		if ( ! empty( $narration['highlights'] ) ) {
			echo '<p>';
			foreach ( (array) $narration['highlights'] as $h ) {
				echo '<span class="sn-pill sn-pill--spaced">' . esc_html( (string) $h ) . '</span>';
			}
			echo '</p>';
		}
		echo '<p class="sn-field-helper">Generated ' . esc_html( human_time_diff( (int) $narration['generated_at'], time() ) ) . ' ago &middot; in ' . esc_html( (string) (int) $narration['elapsed_ms'] ) . 'ms.</p>';
	} else {
		echo '<p class="sn-fieldset-intro">No digest yet. Click <strong>Generate digest</strong> to create one (~$0.01).</p>';
	}

	echo '<form method="post" class="sn-fieldset-actions">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<button type="submit" name="sn_action" value="narration_run" class="button button-primary"' . ( $ai_ready ? '' : ' disabled' ) . '>' . esc_html( $narration ? 'Regenerate digest' : 'Generate digest' ) . '</button>';
	if ( $narration ) {
		echo ' <label class="sn-ml-auto"><input type="checkbox" name="force" value="1"> Force fresh (ignore cache)</label>';
	}
	echo '</form>';
	echo '</div>';
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
	$narration_enabled = function_exists( 'snt_narration_enabled' ) && snt_narration_enabled();
	echo '<div class="sn-field">';
	echo '<label><input type="checkbox" name="insights_narration" value="1"' . checked( $narration_enabled, true, false ) . '> Generate a weekly digest automatically</label>';
	echo '</div>';
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="save_insights_settings" class="button button-primary">Save settings</button>';
	echo '</div>';
	echo '</div>';
	echo '</form>';
}
