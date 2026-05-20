<?php
/**
 * Signal & Noise Tools — Cron Dashboard admin tab renderer.
 *
 * Hooks into the sn_admin_cron_tab action (dispatched by
 * inc/admin-page.php when ?tab=cron) and renders the cron events
 * table with the live filter input + Run-now buttons.
 *
 * @package SignalNoiseTools
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'sn_admin_cron_tab', 'snt_cron_render_admin_tab' );

add_action( 'admin_enqueue_scripts', function( $hook_suffix ) {
	// $hook_suffix for the cron page is like 'signal-noise_page_sn-cron'.
	// Match by 'sn-cron' substring so the JS only loads on this tab.
	if ( strpos( (string) $hook_suffix, 'sn-cron' ) === false ) {
		return;
	}
	wp_enqueue_script(
		'sn-cron-dashboard',
		plugins_url( 'assets/cron-dashboard.js', SNT_PATH . 'signal-and-noise-tools.php' ),
		array( 'wp-api-fetch', 'wp-data' ),
		SNT_VERSION,
		true
	);
} );

function snt_cron_render_admin_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'signal-noise-tools' ) );
	}

	$rows = function_exists( 'snt_cron_get_events_impl' )
		? snt_cron_get_events_impl()
		: array();

	echo '<div class="sn-cron-dashboard">';

	if ( empty( $rows ) ) {
		echo '<div class="sn-card"><h3>No scheduled events.</h3>';
		echo '<p>This is unusual — WordPress core typically schedules <code>wp_version_check</code>, <code>wp_update_plugins</code>, <code>wp_update_themes</code>, and <code>wp_scheduled_delete</code> at install. If your cron is empty, something has cleared it. Check your hosting provider\'s cron configuration.</p></div></div>';
		return;
	}

	echo '<p class="sn-field-helper">' . count( $rows ) . ' scheduled event(s). Signal &amp; Noise–owned events pinned at top.</p>';

	echo '<p><input type="search" id="sn-cron-filter" placeholder="Filter by hook name..." style="width: 320px; padding: 6px 10px;" /></p>';

	echo '<table class="widefat striped" id="sn-cron-table">';
	echo '<thead><tr>';
	echo '<th>Hook</th>';
	echo '<th>Next run</th>';
	echo '<th>Recurrence</th>';
	echo '<th>Last fired</th>';
	echo '<th>Args</th>';
	echo '<th>Actions</th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$row_class = $row['is_sn_owned'] ? 'sn-cron-row sn-cron-owned' : 'sn-cron-row';
		echo '<tr class="' . esc_attr( $row_class ) . '" data-hook="' . esc_attr( $row['hook'] ) . '" data-sig="' . esc_attr( $row['args_signature'] ) . '">';

		// Hook
		echo '<td><code>' . esc_html( $row['hook'] ) . '</code>';
		if ( $row['is_sn_owned'] ) {
			echo ' <span class="sn-badge">SN</span>';
		}
		if ( ! $row['has_handler'] ) {
			echo ' <span class="sn-badge sn-badge-warn">orphan</span>';
		}
		echo '</td>';

		// Next run
		$next_str = wp_date( 'Y-m-d H:i:s', $row['next_run_ts'] );
		$next_rel = human_time_diff( time(), $row['next_run_ts'] );
		echo '<td>' . esc_html( $next_str ) . '<br><small>in ' . esc_html( $next_rel ) . '</small></td>';

		// Recurrence
		if ( $row['schedule'] ) {
			echo '<td>' . esc_html( $row['schedule'] );
			if ( $row['interval_s'] ) {
				echo '<br><small>' . esc_html( human_time_diff( 0, $row['interval_s'] ) ) . '</small>';
			}
			echo '</td>';
		} else {
			echo '<td><small>single event</small></td>';
		}

		// Last fired
		if ( $row['last_fired_ts'] ) {
			$last_str = wp_date( 'Y-m-d H:i:s', $row['last_fired_ts'] );
			$last_rel = human_time_diff( $row['last_fired_ts'], time() );
			echo '<td class="sn-cron-last-fired">' . esc_html( $last_str ) . '<br><small>' . esc_html( $last_rel ) . ' ago</small></td>';
		} else {
			echo '<td class="sn-cron-last-fired">&mdash;</td>';
		}

		// Args
		if ( ! empty( $row['args'] ) ) {
			echo '<td><code>' . esc_html( wp_json_encode( $row['args'] ) ) . '</code></td>';
		} else {
			echo '<td><small>&mdash;</small></td>';
		}

		// Actions
		echo '<td>';
		if ( $row['has_handler'] ) {
			echo '<button class="button button-small sn-cron-run-now" type="button">Run now</button>';
		} else {
			echo '<button class="button button-small" type="button" disabled title="No handler registered">Run now</button>';
		}
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}
