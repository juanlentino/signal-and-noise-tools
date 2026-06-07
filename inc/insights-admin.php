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
	echo '<p class="sn-prose">Cross-system synthesis: combines your Plausible analytics, publish history, webhook delivery patterns, and cron freshness into 5 actionable recommendations per scan. One AI call per scan; results cached 7 days.</p>';

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

	// ── RECOMMENDATIONS (rendered by Task 12) ──
	snt_insights_render_recommendations_section( $last );

	// ── SETTINGS section (rendered by Task 12) ──
	snt_insights_render_settings_section();
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
		if ( 'write_about' === $rec['type'] ) {
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
