<?php
/**
 * Signal & Noise Tools — Analytics view: Visits (cookieless within-day) (v8.8.0).
 *
 * Renders within-day visit quality, engaged-read, transitions, and funnels from
 * the session engine (inc/analytics-sessions.php). "Visits" is a within-day
 * approximation that resets at UTC midnight — never a cross-day identity.
 *
 * @package SignalNoiseTools
 * @since 8.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/analytics-panels.php';

/**
 * Render the visit-quality summary + transitions + funnels panels.
 *
 * @param array $metrics From sn_session_metrics().
 * @param array $paths   From sn_session_paths().
 * @param array $funnels List of array{title:string,report:array} (from sn_funnel_report()).
 * @param bool  $capped  Whether the raw row cap was hit.
 */
function snt_analytics_render_summary_panels( $metrics, $paths, $funnels, $capped ) {
	snt_an_panel_open( 'Visit quality', array( 'header_meta' => 'within-day · resets at UTC midnight' ) );
	if ( (int) $metrics['visits'] < 1 ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'No visits in this range yet.', 'signal-and-noise-tools' ) . '</p>';
	} else {
		$stats = array(
			array( __( 'Visits', 'signal-and-noise-tools' ), number_format_i18n( $metrics['visits'] ) ),
			array( __( 'Bounce rate', 'signal-and-noise-tools' ), number_format_i18n( $metrics['bounce_rate'] * 100, 1 ) . '%' ),
			array( __( 'Pages / visit', 'signal-and-noise-tools' ), number_format_i18n( $metrics['pages_per_visit'], 2 ) ),
			array( __( 'Median duration', 'signal-and-noise-tools' ), number_format_i18n( $metrics['median_duration'] ) . 's' ),
			array( __( 'Engaged reads', 'signal-and-noise-tools' ), number_format_i18n( $metrics['engaged_rate'] * 100, 1 ) . '%' ),
		);
		echo '<dl class="sn-an-statgrid">';
		foreach ( $stats as $stat ) {
			echo '<div class="sn-an-stat"><dt>' . esc_html( $stat[0] ) . '</dt><dd>' . esc_html( $stat[1] ) . '</dd></div>';
		}
		echo '</dl>';
		if ( $capped ) {
			echo '<p class="sn-an-empty">' . esc_html__( 'Results capped for this window — narrow the date range for exact figures.', 'signal-and-noise-tools' ) . '</p>';
		}
	}
	snt_an_panel_close();

	// Transitions — the table helper owns its own panel chrome + empty-state, so
	// build the rows and hand off directly (matching every sibling view). An
	// empty transition set collapses into the empty fold, no hollow panel.
	$rows = array();
	foreach ( (array) $paths as $p ) {
		$rows[] = array(
			'value'  => $p['from'] . ' → ' . $p['to'],
			'views'  => (int) $p['count'],
			'visits' => (int) $p['count'],
		);
	}
	snt_analytics_render_dim_table( __( 'Page → next page', 'signal-and-noise-tools' ), $rows, '' );

	// Funnels as completion bars. The distribution helper owns its own panel +
	// empty-note keyed on the funnel title, so an all-zero funnel (nobody
	// reached step 1) collapses into the empty fold under its OWN name rather
	// than emitting a hollow titled panel + a mislabeled "Reached step" note.
	foreach ( (array) $funnels as $f ) {
		$bars = array();
		foreach ( (array) $f['report'] as $step ) {
			$bars[] = array(
				'label' => $step['label'],
				'views' => (int) $step['reached'],
			);
		}
		snt_analytics_render_distribution( $f['title'], $bars, '', true );
	}

	snt_an_flush_empty_fold();
}

/**
 * View entry point — dispatched from snt_analytics_render_dashboard().
 *
 * @param string $from  Window start (Y-m-d).
 * @param string $to    Window end (Y-m-d).
 * @param string $class Traffic class.
 */
function snt_analytics_render_view_sessions( $from, $to, $class ) {
	$data = sn_analytics_fetch_session_events( $from, $to, $class );
	if ( empty( $data['configured'] ) ) {
		echo '<p class="sn-an-empty">' . esc_html__( 'Visit analytics need live Analytics Engine data for this window.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	$metrics = sn_session_metrics( $data['summaries'] );
	$paths   = sn_session_paths( $data['summaries'], 15 );
	$funnels = array();
	foreach ( sn_analytics_session_funnels() as $def ) {
		$funnels[] = array(
			'title'  => $def['title'],
			'report' => sn_funnel_report( $data['summaries'], $def['steps'] ),
		);
	}
	snt_analytics_render_summary_panels( $metrics, $paths, $funnels, ! empty( $data['capped'] ) );
}
