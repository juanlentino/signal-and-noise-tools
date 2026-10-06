<?php
/**
 * Signal & Noise — Dashboard-home analytics token enqueue.
 *
 * What remains of the first-party analytics dashboard widgets. The four
 * standalone boxes were folded into the single "Signal & Noise" widget
 * (inc/dash-widget.php) in v11.30.0, and their sn_aw_* render functions and
 * assets/analytics/analytics-widget.css were removed once nothing called them.
 *
 * Only the shared 'snt-analytics-tokens' enqueue on index.php is kept.
 *
 * @package signal-and-noise-tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the shared analytics token stylesheet on the Dashboard home screen.
 *
 * @param string $hook Current admin page hook suffix.
 */
function sn_aw_enqueue_styles( $hook ) {
	if ( 'index.php' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'snt-analytics-tokens',
		SNT_URL . 'assets/analytics/analytics-tokens.css',
		array(),
		SNT_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'sn_aw_enqueue_styles' );
