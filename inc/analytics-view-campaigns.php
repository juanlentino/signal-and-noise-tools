<?php
/**
 * Signal & Noise Tools — Analytics view: Campaigns (v9.29.0).
 *
 * A dedicated dashboard tab for UTM campaign attribution: the Campaigns and
 * Source/Medium breakdowns the worker's packed blob20 feeds (via
 * inc/analytics-utm.php), each with the trend sparklines the referrer sources
 * carry. Unlike the v9.28.0 Content-view placement, this view is ALWAYS present
 * in the tab strip and renders an empty state until campaign traffic arrives —
 * so the feature is discoverable before the first tagged visit.
 *
 * @package SignalNoiseTools
 * @since 9.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * Render the Campaigns view body (inside .sn-an-view; tabs are the dispatcher's).
 *
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 */
function snt_analytics_render_view_campaigns( $from, $to, $class, $granularity ) {
	echo '<p class="sn-an-sep sn-an-sep--full">'
		. esc_html__( 'Campaign attribution — visits whose landing URL carried utm_source / utm_medium / utm_campaign tags. Cookieless: only the five named utm_* params are read, never the raw query string.', 'signal-and-noise-tools' )
		. '</p>';

	// Guarded so a partial install (plugin without the UTM module) degrades to the
	// empty state instead of fatalling.
	$campaigns = function_exists( 'sn_analytics_top_utm_campaigns' )
		? sn_analytics_top_utm_campaigns( $from, $to, $class, 25 )
		: array();
	$sources = function_exists( 'sn_analytics_top_utm_sources' )
		? sn_analytics_top_utm_sources( $from, $to, $class, 25 )
		: array();

	// Trend sparklines: one batched series query per panel, keyed by the rows above.
	$camp_values = array_map( static function ( $r ) { return (string) $r['value']; }, $campaigns );
	$src_values  = array_map( static function ( $r ) { return (string) $r['value']; }, $sources );
	$camp_series = function_exists( 'sn_analytics_utm_series' )
		? sn_analytics_utm_series( 'campaign', $camp_values, $from, $to, $class, $granularity )
		: array();
	$src_series = function_exists( 'sn_analytics_utm_series' )
		? sn_analytics_utm_series( 'source_medium', $src_values, $from, $to, $class, $granularity )
		: array();

	$empty_camp = esc_html__( 'No campaigns in this range. Tag a link with ?utm_source=…&utm_medium=…&utm_campaign=… and campaign visits will appear here.', 'signal-and-noise-tools' );
	$empty_src  = esc_html__( 'No campaign sources in this range.', 'signal-and-noise-tools' );

	echo '<div class="sn-an-grid">';
	snt_analytics_render_dim_table( 'Campaigns', $campaigns, $empty_camp, $camp_series, '', 25 );
	snt_analytics_render_dim_table( 'Source / Medium', $sources, $empty_src, $src_series, '', 25 );
	echo '</div>';

	snt_an_flush_empty_fold();
}
