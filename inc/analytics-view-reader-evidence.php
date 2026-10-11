<?php
/**
 * S&N Analytics (classic): the Reader evidence view, how each machine-reader
 * count is known. The native twin is
 * apps/sn-analytics/parts/painters/view-reader-evidence.php; both compose the
 * sections in inc/analytics-machine-views.php.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the view body.
 *
 * @param string $range The page's range token.
 * @return void
 */
function snt_analytics_render_view_reader_evidence( $range ) {
	list( $days, $note ) = snt_mr_view_window( (string) $range );
	echo snt_mr_classic_window_control( $days, $note ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped at its sink.
		. snt_mr_view_reader_evidence_html( snt_mr_view_data( $days, true ), 'classic', array( 'config' => snt_mr_classic_config_link() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the section painters escape at their sinks.
}
