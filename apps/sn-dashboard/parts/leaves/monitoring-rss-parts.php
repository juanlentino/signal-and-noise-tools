<?php
/**
 * S&N Dashboard — Monitoring → RSS helpers: flash, activity stats, the
 * recent-requests table, the settings form, the reset action and
 * maintenance (split out of monitoring-rss.php to stay under the leaf file
 * size guidance).
 *
 * @package SignalNoiseTools
 * @since 13.107.0
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The flash notice: the same six states `sn_rss_tracker_render_flash()`
 * renders, read from the replay's carried params (`sn_rss_ok`) — this
 * module's own get_read, bypassing the shared `?sn_flash=` pipeline exactly
 * as the classic leaf does.
 *
 * @param string $flash Flash code.
 * @return string
 */
function rss_flash_html( $flash ) {
	if ( 'saved' === $flash ) {
		return \snt_kit_notice( 'ok', \snt_kit_esc( __( 'Settings saved.', 'signal-and-noise-tools' ) ), true );
	}
	if ( 'unchanged' === $flash ) {
		return \snt_kit_notice( 'info', \snt_kit_esc( __( 'Settings unchanged: submitted values matched what was already stored.', 'signal-and-noise-tools' ) ), true );
	}
	if ( 'save-error' === $flash ) {
		return \snt_kit_notice( 'err', \snt_kit_esc( __( 'Settings could not be saved. Check the PHP error log for the database error.', 'signal-and-noise-tools' ) ), true );
	}
	if ( 'reset' === $flash ) {
		return \snt_kit_notice( 'ok', \snt_kit_esc( __( 'Settings reset to defaults.', 'signal-and-noise-tools' ) ), true );
	}
	if ( 'purge-error' === $flash ) {
		return \snt_kit_notice( 'err', \snt_kit_esc( __( 'Purge failed: no rows were deleted. Check the PHP error log for the database error.', 'signal-and-noise-tools' ) ), true );
	}
	if ( 0 === strpos( (string) $flash, 'purged-' ) ) {
		$n = (int) substr( (string) $flash, 7 );
		/* translators: %s: number of purged log entries */
		return \snt_kit_notice( 'ok', sprintf( \snt_kit_esc( __( 'Purged %s log entries.', 'signal-and-noise-tools' ) ), \snt_kit_esc( number_format_i18n( $n ) ) ), true );
	}
	return '';
}

/**
 * The activity stats: the same three windows `sn_admin_glance_grid()` shows,
 * plus the "most recent" line.
 *
 * @param array $stats sn_rss_tracker_window_stats_multi() result.
 * @return string
 */
function rss_activity_html( array $stats ) {
	$out = '<div class="snt-stats">';
	foreach ( array(
		1  => __( '24 hours', 'signal-and-noise-tools' ),
		7  => __( '7 days', 'signal-and-noise-tools' ),
		30 => __( '30 days', 'signal-and-noise-tools' ),
	) as $days => $label ) {
		$w    = $stats['windows'][ $days ] ?? array(
			'total'   => 0,
			'uniques' => 0,
		);
		/* translators: %s: unique visitors in the window */
		$out .= \snt_kit_stat( number_format_i18n( (int) $w['total'] ), $label, sprintf( __( '%s unique', 'signal-and-noise-tools' ), number_format_i18n( (int) $w['uniques'] ) ) );
	}
	$out .= '</div>';
	$out .= ! empty( $stats['most_recent'] )
		? '<p class="snt-prose">' . \snt_kit_esc( __( 'Most recent feed request:', 'signal-and-noise-tools' ) ) . ' <os-code>' . \snt_kit_esc( (string) $stats['most_recent'] ) . '</os-code> UTC</p>'
		: '<p class="snt-hint">' . \snt_kit_esc( __( 'No feed requests logged yet.', 'signal-and-noise-tools' ) ) . '</p>';
	return $out;
}

/**
 * The recent-requests table.
 *
 * @param array<int,array<string,mixed>> $recent sn_rss_tracker_recent() rows.
 * @return string
 */
function rss_recent_table_html( array $recent ) {
	$rows = array();
	foreach ( $recent as $row ) {
		$rows[] = array(
			'ts'       => (string) ( $row['ts'] ?? '' ),
			'feed_url' => (string) ( $row['feed_url'] ?? '' ),
			'ua_hash'  => (string) ( $row['ua_hash'] ?? '' ),
		);
	}
	return \snt_kit_table(
		array(
			array(
				'key'   => 'ts',
				'label' => __( 'Time (UTC)', 'signal-and-noise-tools' ),
			),
			array(
				'key'   => 'feed_url',
				'label' => __( 'Feed URL', 'signal-and-noise-tools' ),
			),
			array(
				'key'   => 'ua_hash',
				'label' => __( 'Client', 'signal-and-noise-tools' ),
			),
		),
		$rows,
		array( 'empty' => __( 'No requests logged yet.', 'signal-and-noise-tools' ) )
	);
}

/**
 * The settings form: enable switch, event name, retention. The collector
 * endpoint moved to Measurement → Analytics (v10.46.0) and stays a read-only
 * pointer here — never a second write surface.
 *
 * @param array  $settings sn_rss_tracker_settings() result.
 * @param string $tab      The painting tab, for the go-target.
 * @return string
 */
function rss_settings_form_html( array $settings, $tab ) {
	$fields  = \snt_kit_field( 'switch', 'enabled', __( 'Enable feed-request tracking', 'signal-and-noise-tools' ), ! empty( $settings['enabled'] ), array( 'hint' => __( 'When off, the plugin still loads but skips all DB writes and collector POSTs.', 'signal-and-noise-tools' ) ) );
	$fields .= '<p class="snt-prose">' . \snt_kit_esc( __( 'Collector endpoint:', 'signal-and-noise-tools' ) ) . ' <os-code>' . \snt_kit_esc( (string) ( $settings['collector_url'] ?? '' ) ) . '</os-code></p>'
		. '<p class="snt-hint">' . \snt_kit_esc( __( 'Where this tracker POSTs feed requests. Configured on', 'signal-and-noise-tools' ) ) . ' '
		. \snt_kit_go( __( 'Measurement → Analytics', 'signal-and-noise-tools' ), array(
			'tab'     => 'monitoring',
			'sub'     => 'analytics',
			'current' => $tab,
		) )
		. ', ' . \snt_kit_esc( __( 'alongside the credentials that read the same pipeline.', 'signal-and-noise-tools' ) ) . '</p>';
	$fields .= \snt_kit_field( 'text', 'event_name', __( 'Event name', 'signal-and-noise-tools' ), (string) ( $settings['event_name'] ?? '' ), array(
		'required' => true,
		'hint'     => __( 'Custom event name recorded in first-party analytics: surfaces under Analytics → Events. Kept as "RSS Feed Request" to continue the series imported from Plausible.', 'signal-and-noise-tools' ),
	) );
	$table = isset( $GLOBALS['wpdb'] ) && defined( 'SN_RSS_TRACKER_TABLE' ) ? $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE : '';
	$fields .= \snt_kit_field( 'number', 'log_retention_days', __( 'Log retention (days)', 'signal-and-noise-tools' ), (int) ( $settings['log_retention_days'] ?? 90 ), array(
		'min'  => 7,
		'max'  => 365,
		/* translators: %s: prefixed DB table name, e.g. wp_rss_feed_log */
		'hint' => sprintf( __( 'How long to keep rows in %s. A daily WP-Cron job prunes rows older than this threshold; the manual button below forces a prune right now.', 'signal-and-noise-tools' ), $table ),
	) );
	return rss_form(
		defined( 'SN_RSS_TRACKER_ACTION_SAVE' ) ? SN_RSS_TRACKER_ACTION_SAVE : 'save_settings',
		$fields,
		array( 'submit' => __( 'Save Settings', 'signal-and-noise-tools' ) )
	);
}

/**
 * "Reset to Defaults" — a form of its own: the kit's `<os-form>` has one
 * primary submit, while the classic page carries this as a second button
 * inside the settings `<form>`.
 *
 * @return string
 */
function rss_reset_form_html() {
	return rss_form(
		defined( 'SN_RSS_TRACKER_ACTION_RESET' ) ? SN_RSS_TRACKER_ACTION_RESET : 'reset_defaults',
		'',
		array(
			'submit'        => __( 'Reset to Defaults', 'signal-and-noise-tools' ),
			'confirm'       => __( 'All RSS tracker settings (window threshold, log retention, etc.) will be restored to defaults.', 'signal-and-noise-tools' ),
			'confirm_title' => __( 'Reset RSS tracker to defaults?', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Reset', 'signal-and-noise-tools' ),
		)
	);
}

/**
 * Maintenance: purge log entries older than a threshold.
 *
 * @param array $settings sn_rss_tracker_settings() result.
 * @return string
 */
function rss_maintenance_html( array $settings ) {
	$fields = \snt_kit_field( 'number', 'purge_days', __( 'Older than (days)', 'signal-and-noise-tools' ), (int) ( $settings['log_retention_days'] ?? 90 ), array(
		'min' => 7,
		'max' => 365,
	) );
	$form   = rss_form(
		defined( 'SN_RSS_TRACKER_ACTION_PURGE' ) ? SN_RSS_TRACKER_ACTION_PURGE : 'purge_log',
		$fields,
		array(
			'submit'        => __( 'Purge now', 'signal-and-noise-tools' ),
			'confirm'       => __( 'Log entries older than the configured retention threshold will be permanently deleted.', 'signal-and-noise-tools' ),
			'confirm_title' => __( 'Purge old log entries?', 'signal-and-noise-tools' ),
			'confirm_label' => __( 'Purge', 'signal-and-noise-tools' ),
			'danger'        => true,
		)
	);
	$table  = isset( $GLOBALS['wpdb'] ) && defined( 'SN_RSS_TRACKER_TABLE' ) ? $GLOBALS['wpdb']->prefix . SN_RSS_TRACKER_TABLE : '';
	return \snt_kit_section(
		__( 'Maintenance', 'signal-and-noise-tools' ),
		'<p class="snt-hint">' . \snt_kit_esc( __( 'Delete rows older than the threshold below. First-party collector events are unaffected: only the local', 'signal-and-noise-tools' ) ) . ' <os-code>' . \snt_kit_esc( $table ) . '</os-code> ' . \snt_kit_esc( __( 'table is touched. The daily cron runs the same query against the configured retention setting.', 'signal-and-noise-tools' ) ) . '</p>' . $form
	);
}
