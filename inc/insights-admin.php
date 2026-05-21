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

	// ── INTRO ──
	echo '<p class="sn-prose">Cross-system synthesis: combines your Plausible analytics, publish history, webhook delivery patterns, and cron freshness into 5 actionable recommendations per scan. One AI call per scan; results cached 7 days. <strong>Net-new in v3.6.0.</strong></p>';

	// ── STATUS BOX ──
	if ( $last ) {
		$state = snt_insights_state_read();
		$active = snt_insights_filter_active( $last['recommendations'] );
		$active_count = count( $active );
		$dismissed_count = count( $state['dismissed_ids'] );
		$done_count = count( $state['done_ids'] );

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
		echo '<p class="sn-status-box-body">Click <strong>Run Analysis</strong> below to populate recommendations. ~$0.01 per scan; 7-day cache.</p>';
		echo '</div>';
		echo '<span class="sn-pill sn-pill--warn">Inactive</span>';
		echo '</div>';
	}

	// ── RUN ANALYSIS form ──
	echo '<form method="post">';
	wp_nonce_field( 'sn_theme_options_nonce' );
	echo '<div class="sn-fieldset">';
	echo '<h2 class="sn-fieldset-h">Run Analysis</h2>';
	echo '<p class="sn-fieldset-intro">Single AI call per scan. Returns 5 recommendations across types: write_about, update_post, cadence_change, topic_double_down, topic_pivot. Re-runs within 7 days return the cached result unless you check "Force fresh scan".</p>';
	if ( ! $ai_ready ) {
		echo '<p class="sn-field-helper" style="color:#dc3232;"><strong>AI client not available.</strong> Configure a provider under <a href="' . esc_url( admin_url( 'options-general.php?page=ai-connectors' ) ) . '">Settings → Connectors</a> before running.</p>';
	}
	echo '<div class="sn-fieldset-actions">';
	echo '<button type="submit" name="sn_action" value="insights_run" class="button button-primary"' . ( $ai_ready ? '' : ' disabled' ) . '>' . esc_html( $last ? 'Re-run analysis' : 'Run Analysis' ) . '</button>';
	if ( $last ) {
		echo ' <label style="margin-left:1rem;"><input type="checkbox" name="force" value="1"> Force fresh scan (ignore cache)</label>';
	}
	echo '</div>';
	echo '</div>';
	echo '</form>';

	// ── RECOMMENDATIONS (rendered by Task 12) ──
	snt_insights_render_recommendations_section( $last );

	// ── SETTINGS section (rendered by Task 12) ──
	snt_insights_render_settings_section();
}

/**
 * Renders the recommendations cards section. Stub for Task 12.
 */
function snt_insights_render_recommendations_section( $last ) {
	if ( ! $last || empty( $last['recommendations'] ) ) {
		return;
	}
	// Filled in Task 12.
}

/**
 * Renders the weekly-cron settings section. Stub for Task 12.
 */
function snt_insights_render_settings_section() {
	// Filled in Task 12.
}
