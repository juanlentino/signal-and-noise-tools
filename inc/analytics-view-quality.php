<?php
/**
 * Signal & Noise Tools — Analytics view: Quality (v8.5.0 extraction).
 *
 * Bot-share trend, traffic-quality breakdown, bot-confidence distribution.
 * Moved verbatim from the dispatcher's switch (inc/analytics-admin.php).
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Quality view body.
 *
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 */
function snt_analytics_render_view_quality( $from, $to, $class, $granularity ) {
	snt_analytics_render_bot_trend( sn_analytics_class_series( $from, $to, $granularity ) );
	snt_analytics_render_bot_breakdown( sn_analytics_bot_breakdown( $from, $to ) );
	snt_analytics_render_distribution(
		'Bot confidence',
		sn_analytics_distribution( 'botscore', $from, $to, $class ),
		'No bot-confidence scores in this range — needs traffic recorded with Cloudflare Bot Management enabled (scores arrive as 1–99).'
	);
}
