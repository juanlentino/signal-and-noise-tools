<?php
/**
 * S&N Dashboard: Settings > Analytics, the line each Measurement leaf keeps
 * saying where its settings went. Its own file because the three leaves load
 * it without loading the pane.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * What moved, with an in-window link to the pane. The sentence and the pane's
 * name are the classic twin's (snt_settings_analytics_moved_text(),
 * snt_settings_analytics_label()). It names settings and never a value.
 *
 * @param string $from    `analytics-native`, `device`, `rss`, `machine-readers` or `insights`.
 * @param string $current The tab the line is painted on.
 * @return string '' before the classic twin is loaded.
 */
function settings_analytics_moved_html( $from, $current = 'monitoring' ) {
	if ( ! function_exists( 'snt_settings_analytics_moved_text' ) ) {
		return '';
	}
	return '<p class="snt-hint">' . \snt_kit_esc( \snt_settings_analytics_moved_text( $from ) ) . ' '
		. \snt_kit_go( \snt_settings_analytics_label(), array( 'tab' => 'settings', 'sub' => 'analytics', 'current' => (string) $current ) ) . '.</p>';
}
