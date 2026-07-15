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
	// v9.6.0 (R3b): the recommendations to-do list greets the landing view above
	// the data — a full-width panel that collapses to a one-line empty-state when
	// nothing needs attention. Guarded so a partial install degrades cleanly.
	// Closes the annotations arc (seam + 9 reads + this panel).
	if ( function_exists( 'snt_analytics_render_recommendations_panel' ) ) {
		snt_analytics_render_recommendations_panel();
	}

	echo '<div class="sn-an-content-grid">';

	echo '<div class="sn-an-content-main">';
	// Capture the rows so the annotation and the table share one query (v9.5.0 read).
	$paths = sn_analytics_top_paths( $from, $to, $class, 25 );
	snt_an_annotation( sn_annotation_top_pages( $paths ) );
	snt_analytics_render_paths_table( $paths );
	echo '</div>';

	echo '<div class="sn-an-content-side">';
	// Brand-folded sources (self-referrals + www + multi-host providers
	// collapsed); the sparkline series is summed across each label's member
	// hosts, and the drill token carries the canonical label (resolved back
	// to its member hosts by the brand-aware referrer drill-down).
	// Referrer categories are captured first so the sources read and the chips
	// share one query (v9.5.0 read: direct-heavy / owned-audience).
	$refcats  = sn_analytics_referrer_categories( $from, $to, $class );
	snt_an_annotation( sn_annotation_sources( $refcats ) );
	$ref_rows = sn_analytics_top_sources( $from, $to, $class, 10 );
	$ref_ser  = sn_analytics_top_sources_series( $ref_rows, $from, $to, $class, $granularity );
	snt_analytics_render_dim_table( __( 'Top sources', 'signal-and-noise-tools' ), $ref_rows, __( 'No referrers in this range.', 'signal-and-noise-tools' ), $ref_ser, 'referrer', 10 );
	snt_analytics_render_referrer_categories( $refcats );

	echo '</div>';

	echo '</div>';

	// v6.10.0 entry/exit are HUMAN-ONLY (no class column, consistent with the
	// human-only Plausible history); the note rides the section label now
	// instead of a separator paragraph. Low engagement joins them as the
	// third diagnostics sibling.
	// D4 §4 (T5 review): fetch the three row sets FIRST — when all three are
	// empty their panels all fold, and the hairline label + grid would sit
	// orphaned above nothing on the flagship view. In that case skip the
	// label/grid markup entirely; the renderers still run and note their own
	// folds (each owns its why copy).
	$has_pageroles = function_exists( 'snt_analytics_render_pageroles_table' ) && function_exists( 'sn_analytics_top_entry_pages' );
	$entry_rows    = $has_pageroles ? sn_analytics_top_entry_pages( $from, $to, 25 ) : array();
	$exit_rows     = $has_pageroles ? sn_analytics_top_exit_pages( $from, $to, 25 ) : array();
	$lowe_rows     = sn_analytics_low_engagement_paths( $from, $to, $class );
	$journeys_gone = empty( $entry_rows ) && empty( $exit_rows ) && empty( $lowe_rows );
	if ( ! $journeys_gone ) {
		echo '<div class="sn-an-journeys-label">'
			. esc_html__( 'Journeys & diagnostics — entry/exit are human only', 'signal-and-noise-tools' )
			. '</div>';
		echo '<div class="sn-an-journeys-grid">';
	}
	if ( $has_pageroles ) {
		snt_analytics_render_pageroles_table( $entry_rows, 'entry' );
		snt_analytics_render_pageroles_table( $exit_rows, 'exit' );
	}
	snt_analytics_render_lowengage( $lowe_rows );
	if ( ! $journeys_gone ) {
		echo '</div>';
	}
	snt_an_flush_empty_fold();
}
