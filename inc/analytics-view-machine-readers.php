<?php
/**
 * S&N Analytics (classic): the Machine readers view, who reads the site and
 * for what. The native twin is
 * apps/sn-analytics/parts/painters/view-machine-readers.php; both compose the
 * sections in inc/analytics-machine-views.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classic window control: one link per window, the current one marked,
 * and the note when the page's range is longer than the sensor keeps.
 *
 * @param int    $days The window.
 * @param string $note snt_mr_view_window()'s note.
 * @return string
 */
function snt_mr_classic_window_control( $days, $note ) {
	$base = remove_query_arg( array( 'sn_range', 'sn_from', 'sn_to' ) );
	$out  = '<div class="sn-control-group" role="group" aria-label="' . esc_attr__( 'Window', 'signal-and-noise-tools' ) . '"><span class="sn-control-label">' . esc_html__( 'Window', 'signal-and-noise-tools' ) . '</span><span class="button-group">';
	foreach ( SN_MR_VIEW_WINDOWS as $token ) {
		$on   = (int) $days === $token;
		$out .= '<a class="button button-small' . ( $on ? ' active' : '' ) . '"' . ( $on ? ' aria-current="true"' : '' ) . ' href="' . esc_url( add_query_arg( 'sn_range', (string) $token, $base ) ) . '">' . esc_html( $token . 'd' ) . '</a>';
	}
	return $out . '</span></div>' . ( '' !== $note ? '<p class="sn-an-settings-help">' . esc_html( $note ) . '</p>' : '' );
}

/** The way to the sensor's configuration on the Monitoring tab, with visible text. */
function snt_mr_classic_config_link() {
	return '<p><a href="' . esc_url( admin_url( 'admin.php?page=sn-theme-options&tab=monitoring&sub=machine-readers' ) ) . '">' . esc_html__( 'Configure the edge sensor on the Monitoring tab', 'signal-and-noise-tools' ) . '</a></p>';
}

/**
 * Render the view body.
 *
 * @param string $range The page's range token.
 * @return void
 */
function snt_analytics_render_view_machine_readers( $range ) {
	list( $days, $note ) = snt_mr_view_window( (string) $range );
	$evidence            = '<p><a href="' . esc_url( add_query_arg( 'sn_view', 'reader-evidence' ) ) . '">' . esc_html__( 'How each count is known: Reader evidence', 'signal-and-noise-tools' ) . '</a></p>';
	echo snt_mr_classic_window_control( $days, $note ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped at its sink.
		. snt_mr_view_machine_readers_html( snt_mr_view_data( $days ), 'classic', array( 'evidence' => $evidence, 'config' => snt_mr_classic_config_link() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the section painters escape at their sinks.
}
