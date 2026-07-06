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

	// Transitions (want #3-style, but visit-scoped).
	$rows = array();
	foreach ( (array) $paths as $p ) {
		$rows[] = array(
			'value'  => $p['from'] . ' → ' . $p['to'],
			'views'  => (int) $p['count'],
			'visits' => (int) $p['count'],
		);
	}
	if ( empty( $rows ) ) {
		snt_an_note_empty( 'Top paths' );
	} else {
		snt_an_panel_open( 'Top paths' );
		snt_analytics_render_dim_table( __( 'Page → next page', 'signal-and-noise-tools' ), $rows, '' );
		snt_an_panel_close();
	}

	// Funnels as completion bars.
	foreach ( (array) $funnels as $f ) {
		$bars = array();
		foreach ( (array) $f['report'] as $step ) {
			$bars[] = array(
				'label' => $step['label'],
				'views' => (int) $step['reached'],
			);
		}
		if ( empty( $bars ) ) {
			snt_an_note_empty( $f['title'] );
			continue;
		}
		snt_an_panel_open( $f['title'] );
		snt_analytics_render_distribution( __( 'Reached step', 'signal-and-noise-tools' ), $bars, '', true );
		snt_an_panel_close();
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

/**
 * Auto-derived + optional code-defined funnels. A site can add named funnels via
 * the 'sn_analytics_session_funnels' filter; nothing is required for the view to
 * work (transitions + quality render regardless).
 *
 * @return array List of array{title:string,steps:array}.
 */
function sn_analytics_session_funnels() {
	$defaults = array(
		array(
			'title' => __( 'Home → post → subscribe', 'signal-and-noise-tools' ),
			'steps' => array(
				array( 'match' => 'path', 'value' => '/', 'prefix' => false ),
				array( 'match' => 'path', 'value' => '/notes/', 'prefix' => true ),
				array( 'match' => 'ce', 'value' => 'subscribe', 'prefix' => false ),
			),
		),
	);
	return (array) apply_filters( 'sn_analytics_session_funnels', $defaults );
}
