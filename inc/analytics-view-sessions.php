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
 * @param array $attribution From sn_goal_attribution(): array{entry,conversions} rows.
 */
function snt_analytics_render_summary_panels( $metrics, $paths, $funnels, $capped, $attribution = array() ) {
	snt_an_panel_open( 'Visit quality', array( 'header_meta' => 'within-day · resets at UTC midnight' ) );
	snt_an_annotation( sn_annotation_visit_quality( $metrics ) ); // v9.5.0 read: high/low engaged-read range
	if ( (int) $metrics['visits'] < 1 ) {
		echo '<p class="sn-an-empty sn-an-empty--panel">' . esc_html__( 'No visits in this range yet.', 'signal-and-noise-tools' ) . '</p>';
	} else {
		// Cohesive with the Overview KPI strip — same sn-kpi-row / sn-kpi cards. No
		// period-over-period delta here yet, so the delta slot carries a muted
		// descriptor (matches the strip's three-line card rhythm).
		$cards = array(
			array( 'l' => __( 'Visits', 'signal-and-noise-tools' ),          'n' => number_format_i18n( (int) $metrics['visits'] ),                'sub' => __( 'with a pageview', 'signal-and-noise-tools' ),    'promoted' => true ),
			array( 'l' => __( 'Bounce rate', 'signal-and-noise-tools' ),     'n' => number_format_i18n( $metrics['bounce_rate'] * 100, 1 ) . '%',  'sub' => __( 'single-page visits', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Pages / visit', 'signal-and-noise-tools' ),   'n' => number_format_i18n( $metrics['pages_per_visit'], 2 ),          'sub' => __( 'mean', 'signal-and-noise-tools' ),               'promoted' => true ),
			array( 'l' => __( 'Median duration', 'signal-and-noise-tools' ), 'n' => number_format_i18n( (int) $metrics['median_duration'] ) . 's', 'sub' => __( 'per visit', 'signal-and-noise-tools' ) ),
			array( 'l' => __( 'Engaged reads', 'signal-and-noise-tools' ),   'n' => number_format_i18n( $metrics['engaged_rate'] * 100, 1 ) . '%', 'sub' => __( 'scroll + dwell', 'signal-and-noise-tools' ) ),
		);
		echo '<div class="sn-kpi-row">';
		foreach ( $cards as $c ) {
			echo '<div class="sn-kpi' . ( ! empty( $c['promoted'] ) ? ' sn-kpi-promoted' : '' ) . '">';
			echo '<p class="sn-kpi-label">' . esc_html( $c['l'] ) . '</p>';
			echo '<p class="sn-kpi-value">' . esc_html( $c['n'] ) . '</p>';
			echo '<span class="sn-kpi-delta sn-delta-flat">' . esc_html( $c['sub'] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
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
	snt_analytics_render_dim_table( __( 'Top paths', 'signal-and-noise-tools' ), $rows, '' );

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

	// Conversion sources — where the visits that converted first landed. Single-metric
	// bars (entry page → converting visits); the distribution helper owns its own empty
	// fold, so a window with no contact conversions collapses under this title rather
	// than emitting a hollow panel. Wide labels because entry paths run long.
	$attr_rows = array();
	foreach ( (array) $attribution as $a ) {
		$attr_rows[] = array( 'label' => (string) ( $a['entry'] ?? '' ), 'views' => (int) ( $a['conversions'] ?? 0 ) );
	}
	// v9.5.0 read: one entry page dominates conversions. The distribution helper
	// owns its own chrome, so the read renders just above that panel.
	snt_an_annotation( sn_annotation_conversions( $attribution ) );
	snt_analytics_render_distribution( __( 'Contact conversions by entry page', 'signal-and-noise-tools' ), $attr_rows, '', true );

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
		echo '<p class="sn-an-empty sn-an-empty--note">' . esc_html__( 'Visit analytics need live Analytics Engine data for this window.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	// A "visit" requires >= 1 pageview: server events (srv:1 / RSS ce) and orphan
	// scroll/timing beacons are not visits (they live in the Events view). Without
	// this, an RSS feed reader polling hourly gap-splits into dozens of phantom
	// pageview-less "visits" and wrecks bounce / pages-per-visit / duration.
	$visits  = sn_pageview_visits( $data['summaries'] );
	$metrics = sn_session_metrics( $visits );
	$paths   = sn_session_paths( $visits, 15 );
	$funnels = array();
	foreach ( sn_analytics_session_funnels() as $def ) {
		$funnels[] = array(
			'title'  => $def['title'],
			'report' => sn_funnel_report( $visits, $def['steps'] ),
		);
	}
	// Conversion attribution for the contact-* goal family (theme v10.28.0 emits
	// contact-<alias> per /contact email link). Computed from the same visits — no
	// extra query. Overridable prefix stays internal; the panel is contact-scoped.
	$attribution = sn_goal_attribution( $visits, 'contact-' );
	snt_analytics_render_summary_panels( $metrics, $paths, $funnels, ! empty( $data['capped'] ), $attribution );
}
