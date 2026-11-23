<?php
/**
 * Signal & Noise: Settings > Analytics, the names both twins share: the
 * pane's address and label, the ONE list of its box titles, and the line each
 * Measurement leaf keeps saying where its settings went. Its own file so a
 * leaf can load the line without the pane.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pane's address, for the lines the old screens keep.
 *
 * @return string
 */
function snt_settings_analytics_url() {
	return function_exists( 'snt_desktop_admin_url' )
		? snt_desktop_admin_url( 'sn-settings', 'analytics' )
		: admin_url( 'admin.php?page=sn-theme-options&tab=settings&sub=analytics' );
}

/**
 * The pane's name as every pointer says it.
 *
 * @return string
 */
function snt_settings_analytics_label() {
	return __( 'Settings › Analytics', 'signal-and-noise-tools' );
}

/**
 * The pane's box titles, in pane order. Both twins head their boxes from
 * this list and the Measurement > Analytics pointer names its boxes from it,
 * so a heading and the sentence that points at it cannot drift.
 *
 * @return array<string,string>
 */
function snt_settings_analytics_titles() {
	return array(
		'collector'       => __( 'Collector endpoint', 'signal-and-noise-tools' ),
		'roles'           => __( 'Roles kept out', 'signal-and-noise-tools' ),
		'tuning'          => __( 'Engine tuning', 'signal-and-noise-tools' ),
		'funnels'         => __( 'Session funnels', 'signal-and-noise-tools' ),
		'publish'         => __( 'Publish windows', 'signal-and-noise-tools' ),
		'north_star'      => __( 'North star', 'signal-and-noise-tools' ),
		'feed'            => __( 'Feed tracking', 'signal-and-noise-tools' ),
		'machine_readers' => __( 'Machine readers', 'signal-and-noise-tools' ),
		'insights'        => __( 'Insights', 'signal-and-noise-tools' ),
	);
}

/**
 * The sentence an old screen keeps, up to the link. It names settings and
 * never a value. Measurement > Analytics names the boxes it lost by their
 * titles; the native leaf also had North star, which the classic leaf never
 * had (its classic twin was born on the pane).
 *
 * @param string $from `analytics`, `analytics-native`, `rss`, `machine-readers` or `insights`.
 * @return string
 */
function snt_settings_analytics_moved_text( $from ) {
	if ( 'analytics' === $from || 'analytics-native' === $from ) {
		$titles = snt_settings_analytics_titles();
		$boxes  = array( $titles['collector'], $titles['roles'], $titles['tuning'], $titles['funnels'], $titles['publish'] );
		$last   = 'analytics-native' === $from ? $titles['north_star'] : array_pop( $boxes );
		/* translators: 1: a comma-separated list of box titles, 2: the last box title. */
		return sprintf( __( '%1$s and %2$s moved to', 'signal-and-noise-tools' ), implode( ', ', $boxes ), $last );
	}
	$texts = array(
		'rss'             => __( 'The tracking switch, the event name and the log retention moved to', 'signal-and-noise-tools' ),
		'machine-readers' => __( 'The Worker URL moved to', 'signal-and-noise-tools' ),
		'insights'        => __( 'The weekly scan switch moved to', 'signal-and-noise-tools' ),
	);
	return (string) ( $texts[ $from ] ?? '' );
}

/**
 * That line on a classic screen, with its link to the pane.
 *
 * @param string $from See snt_settings_analytics_moved_text().
 * @return void
 */
function snt_settings_analytics_moved_line( $from ) {
	echo '<p class="sn-field-helper">' . esc_html( snt_settings_analytics_moved_text( $from ) ) . ' <a href="' . esc_url( snt_settings_analytics_url() ) . '">'
		. esc_html( snt_settings_analytics_label() ) . '</a>.</p>';
}
