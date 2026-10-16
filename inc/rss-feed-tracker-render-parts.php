<?php
/**
 * Signal & Noise Tools: rss-feed-tracker.php, the admin tab renderers and
 * their hook.
 *
 * Split from inc/rss-feed-tracker.php (house line cap, 2026-10-16); a pure
 * move, no code changed. The file header there describes the module. Loaded
 * by require_once at the end of that file, in the order the code always ran.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function sn_rss_tracker_render_stats( $stats ) {
	echo '<h2 class="sn-section-h">Activity</h2>';
	if ( function_exists( 'sn_admin_glance_grid' ) ) {
		echo '<section aria-label="RSS activity at a glance">';
		sn_admin_glance_grid( snt_rss_glance_cards( $stats ) );
		echo '</section>';
	}
	// 2026-10-12 audit: feed reach was on S&N Home only.
	if ( function_exists( 'snt_feed_reach_tiles' ) ) {
		if ( function_exists( 'sn_admin_glance_grid' ) ) {
			$reach = array();
			foreach ( snt_feed_reach_tiles() as $r ) {
				$reach[] = array( 'label' => $r['label'], 'value' => null === $r['value'] ? 'not read' : number_format_i18n( (int) $r['value'] ), 'meta_html' => esc_html( '7 days · ' . $r['note'] ) );
			}
			echo '<section aria-label="Feed reach">';
			sn_admin_glance_grid( $reach );
			echo '</section>';
		}
		echo '<p class="sn-field-helper">' . esc_html( snt_feed_reach_sentence() ) . '</p>';
	}

	if ( ! empty( $stats['most_recent'] ) ) {
		echo '<p class="sn-rss-meta">Most recent feed request: <code>' . esc_html( sn_rss_tracker_local_time( (string) $stats['most_recent'] ) ) . '</code></p>';
	} else {
		echo '<p class="sn-rss-meta"><em>No feed requests logged yet.</em></p>';
	}
}

function sn_rss_tracker_render_settings_form( $settings ) {
	echo '<h2 class="sn-section-h">Settings</h2>';
	// Empty action attr = POST to current URL. #1228: the page now lives
	// under admin.php (add_menu_page, see inc/admin-menu.php) — themes.php
	// is stale from before the move; easier to self-post and let admin_init
	// route than to maintain a URL that has to match the registration site
	// exactly.
	echo '<form method="post" class="sn-rss-settings">';
	wp_nonce_field( SN_RSS_TRACKER_NONCE );

	// Stacked .sn-field rows instead of two-column .form-table — fits the
	// narrow right column of the 2-col layout much better.
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label sn-field-label--inline" for="sn_rss_enabled">';
	echo '<input type="checkbox" id="sn_rss_enabled" name="enabled" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Enable feed-request tracking';
	echo '</label>';
	echo '<p class="sn-field-helper">When off, feed requests are neither logged nor sent to the collector.</p>';
	echo '</div>';

	// v10.46.0: the Collector endpoint field moved to Measurement → Analytics.
	// It was never an RSS setting — EVERY beacon on the site posts to that URL,
	// and this leaf is one of its callers, not its owner. A read-only pointer
	// stays because this form's POSTs do go there, so seeing the target while
	// configuring the tracker is genuinely useful; the write surface is single
	// and lives on the Analytics leaf (the same one-writer rule
	// snt_analytics_render_mirrors() enforces in the other direction).
	echo '<div class="sn-field">';
	echo '<label class="sn-field-label">Collector endpoint</label>';
	echo '<p class="sn-field-helper"><code>' . esc_html( (string) $settings['collector_url'] ) . '</code><br>';
	echo 'Where this tracker POSTs feed requests. Configured on <a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=analytics' ) ) . '">Measurement &rarr; Analytics</a>, alongside the credentials that read the same pipeline.</p>';
	echo '</div>';

	echo '<div class="sn-field">';
	echo '<label class="sn-field-label" for="sn_rss_event_name">Event name</label>';
	echo '<input type="text" id="sn_rss_event_name" name="event_name" class="large-text" value="' . esc_attr( $settings['event_name'] ) . '" required>';
	echo '<p class="sn-field-helper">Custom event name recorded in first-party analytics: surfaces under Analytics &rarr; Events. Kept as <code>RSS Feed Request</code> to continue the series imported from Plausible.</p>';
	echo '</div>';

	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_rss_retention">Log retention (days)</label>';
	echo '<input type="number" id="sn_rss_retention" name="log_retention_days" class="small-text" min="7" max="365" value="' . esc_attr( (int) $settings['log_retention_days'] ) . '">';
	echo '<p class="sn-field-helper">How long to keep rows in <code>' . esc_html( $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE ) . '</code>. A daily WP-Cron job prunes rows older than this threshold; Purge now, under Maintenance, forces a prune right now.</p>';
	echo '</div>';

	echo '<p class="submit">';
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_SAVE ) . '" class="button button-primary">Save Settings</button> ';
	// v4.1.1 (U-01): replaced onclick="return confirm(...)" with data-snt-confirm.
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_RESET ) . '" class="button" data-snt-confirm="' . esc_attr__( 'Tracking, the event name and log retention return to their defaults. The collector endpoint on Measurement › Analytics is kept.', 'signal-and-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Reset RSS tracker to defaults?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Reset', 'signal-and-noise-tools' ) . '">Reset to Defaults</button>';
	echo '</p>';
	echo '</form>';
}

function sn_rss_tracker_render_recent_table( $recent ) {
	echo '<h2 class="sn-section-h">Recent requests</h2>';
	if ( empty( $recent ) ) {
		echo '<p class="description"><em>No requests logged yet.</em></p>';
		return;
	}
	echo '<div class="sn-rss-recent">';
	echo '<div class="snt-scroll-table">';
	echo '<table class="widefat striped">';
	echo '<thead><tr><th class="column-primary">Time</th><th>Feed URL</th><th>Client (hashed)</th></tr></thead><tbody>';
	foreach ( $recent as $row ) {
		echo '<tr>';
		echo '<td><code class="sn-mono">' . esc_html( sn_rss_tracker_local_time( (string) $row['ts'] ) ) . '</code></td>';
		echo '<td><code class="sn-mono">' . esc_html( $row['feed_url'] ) . '</code></td>';
		echo '<td><code class="sn-mono">' . esc_html( $row['ua_hash'] ) . '</code></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
	echo '</div>';
}

function sn_rss_tracker_render_maintenance_form( $settings ) {
	// Lives inside the right column, separated from Settings by a single
	// border-top via .sn-rss-maintenance — no extra card chrome.
	echo '<div class="sn-rss-maintenance">';
	echo '<h2 class="sn-section-h">Maintenance</h2>';
	echo '<form method="post">';
	wp_nonce_field( SN_RSS_TRACKER_NONCE );
	echo '<p class="sn-field-helper">Delete rows older than the number of days you enter. First-party collector events are unaffected: only the local <code>' . esc_html( $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE ) . '</code> table is touched. The daily cron runs the same query against the configured retention setting.</p>';
	echo '<div class="sn-field sn-field-w-xs">';
	echo '<label class="sn-field-label" for="sn_rss_purge_days">Older than (days)</label>';
	echo '<input type="number" id="sn_rss_purge_days" name="purge_days" class="small-text" min="7" max="365" value="' . esc_attr( (int) $settings['log_retention_days'] ) . '">';
	echo '</div>';
	echo '<p class="submit sn-submit--tight">';
	// v4.1.1 (U-01): replaced onclick="return confirm(...)" with data-snt-confirm.
	echo '<button type="submit" name="sn_rss_action" value="' . esc_attr( SN_RSS_TRACKER_ACTION_PURGE ) . '" class="button" data-snt-confirm="' . esc_attr__( 'Log entries older than the number of days in this box will be permanently deleted.', 'signal-and-noise-tools' ) . '" data-snt-confirm-title="' . esc_attr__( 'Purge old log entries?', 'signal-and-noise-tools' ) . '" data-snt-confirm-label="' . esc_attr__( 'Purge', 'signal-and-noise-tools' ) . '" data-snt-confirm-danger="1">Purge now</button>';
	echo '</p>';
	echo '</form>';
	echo '</div>';
}

function sn_rss_tracker_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = sn_rss_tracker_settings();
	$stats    = sn_rss_tracker_window_stats_multi( array( 1, 7, 30 ) );
	$recent   = sn_rss_tracker_recent( 20 );
	$flash    = isset( $_GET['sn_rss_ok'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_rss_ok'] ) ) : '';

	sn_rss_tracker_render_flash( $flash );

	// v1.14.0: Activity stats are full-width on top (3 boxes naturally
	// form one row). Below, 2-col content-driven split via .sn-2col:
	// wide left for the table-heavy Recent column, narrow right for
	// the form+maintenance config column. Stacks at <960px.
	sn_rss_tracker_render_stats( $stats );

	echo '<div class="sn-2col">';

	echo '<div class="sn-2col__col">';
	sn_rss_tracker_render_recent_table( $recent );
	echo '</div>';

	echo '<div class="sn-2col__col">';
	sn_rss_tracker_render_settings_form( $settings );
	sn_rss_tracker_render_maintenance_form( $settings );
	echo '</div>';

	echo '</div>';
}
add_action( 'sn_admin_rss_tab', 'sn_rss_tracker_render_admin_tab' );
