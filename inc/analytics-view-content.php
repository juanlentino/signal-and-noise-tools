<?php
/**
 * Signal & Noise Tools — Analytics view: Content (v8.5.0).
 *
 * The default landing view, regrouped per the approved 2026-07-03 mockup
 * (content-view.html): "what's read" (Top pages) beside "where it comes from"
 * (Top sources over Referrer categories chips), then the Journeys &
 * diagnostics row (Entry / Exit / Low engagement) under a hairline label.
 * Everything the pre-v8.5.0 view rendered is still here — clamped, not cut.
 *
 * @package SignalNoiseTools
 * @since 8.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php'; // the empty-fold collector this view emits into

/**
 * Render the Content view body (inside .sn-an-view; tabs + drill-down are the
 * dispatcher's).
 *
 * @param string $from        Window start (Y-m-d).
 * @param string $to          Window end (Y-m-d).
 * @param string $class       Traffic class.
 * @param string $granularity 'day' | 'week' | 'month'.
 */
function snt_analytics_render_view_content( $from, $to, $class, $granularity ) {
	echo '<div class="sn-an-content-grid">';

	echo '<div class="sn-an-content-main">';
	snt_analytics_render_paths_table( sn_analytics_top_paths( $from, $to, $class, 25 ) );
	echo '</div>';

	echo '<div class="sn-an-content-side">';
	// Brand-folded sources (self-referrals + www + multi-host providers
	// collapsed); the sparkline series is summed across each label's member
	// hosts, and the drill token carries the canonical label (resolved back
	// to its member hosts by the brand-aware referrer drill-down).
	$ref_rows = sn_analytics_top_sources( $from, $to, $class, 10 );
	$ref_ser  = sn_analytics_top_sources_series( $ref_rows, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( 'Top sources', $ref_rows, 'No referrers in this range.', $ref_ser, 'referrer', 10 );
	snt_analytics_render_referrer_categories( sn_analytics_referrer_categories( $from, $to, $class ) );
	echo '</div>';

	echo '</div>';

	// v6.10.0 entry/exit are HUMAN-ONLY (no class column, consistent with the
	// human-only Plausible history); the note rides the section label now
	// instead of a separator paragraph. Low engagement joins them as the
	// third diagnostics sibling.
	echo '<div class="sn-an-journeys-label">'
		. esc_html__( 'Journeys & diagnostics — entry/exit are human only', 'signal-and-noise-tools' )
		. '</div>';
	echo '<div class="sn-an-journeys-grid">';
	if ( function_exists( 'snt_analytics_render_pageroles_table' ) && function_exists( 'sn_analytics_top_entry_pages' ) ) {
		snt_analytics_render_pageroles_table( sn_analytics_top_entry_pages( $from, $to, 25 ), 'entry' );
		snt_analytics_render_pageroles_table( sn_analytics_top_exit_pages( $from, $to, 25 ), 'exit' );
	}
	snt_analytics_render_lowengage( sn_analytics_low_engagement_paths( $from, $to, $class ) );
	echo '</div>';
	snt_an_flush_empty_fold();
}
