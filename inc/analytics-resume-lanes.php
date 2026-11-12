<?php
/**
 * Signal & Noise Tools: the résumé lanes panel (owner only, 2026-11-02).
 *
 * Per /resume lane, music and operations: views, time and scroll per view
 * (the rows read's resume_lane dimension, inc/abilities-analytics-rows.php),
 * and the two goal families the page's links carry as data-sn-goal (the
 * theme beacon sends any data-sn-goal click as a custom event; no allowlist):
 * resume_lane_<lane> on the switch links, resume_hunk_<lane> on the edition
 * marks. Lanes only: no link code, no per-code number. Since 2026-11-12 /resume
 * is one résumé (SN_RESUME_LANES_ENDED): the panel stays for the history. Shown inside the Links
 * view, classic and native, and to manage_options alone.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SN_RESUME_LANES = array( 'music', 'operations' );

/** The day /resume became one résumé (owner, 2026-11-12): the lane field means nothing from it on. */
const SN_RESUME_LANES_ENDED = '2026-11-12';

/**
 * The four goal names the résumé page emits, by lane.
 *
 * @return array<string,array{switch:string,hunk:string}>
 */
function sn_resume_lane_goals() {
	$out = array();
	foreach ( SN_RESUME_LANES as $lane ) {
		$out[ $lane ] = array( 'switch' => 'resume_lane_' . $lane, 'hunk' => 'resume_hunk_' . $lane );
	}
	return $out;
}

/**
 * One row per lane for the window. PURE given the reads.
 *
 * @param array|WP_Error $rows   snt_ability_analytics_rows() for dimensions [resume_lane].
 * @param array          $events sn_analytics_top_events() rows (name, events, visitors).
 * @return array|string Rows (lane, views, time_ms, scroll, switches, hunks), or why the lane read failed.
 */
function sn_resume_lanes_model( $rows, array $events ) {
	if ( is_wp_error( $rows ) ) {
		return (string) $rows->get_error_message();
	}
	$by_lane = array();
	foreach ( (array) ( $rows['rows'] ?? array() ) as $r ) {
		$by_lane[ (string) ( $r['resume_lane'] ?? '' ) ] = $r;
	}
	$counts = array_column( $events, 'events', 'name' );
	$out    = array();
	foreach ( sn_resume_lane_goals() as $lane => $g ) {
		$r     = $by_lane[ $lane ] ?? array();
		$out[] = array(
			'lane'     => $lane,
			'views'    => (int) ( $r['views'] ?? 0 ),
			'time_ms'  => $r['time_avg_per_view'] ?? null,
			'scroll'   => $r['scroll_avg_per_view'] ?? null,
			'switches' => (int) ( $counts[ $g['switch'] ] ?? 0 ),
			'hunks'    => (int) ( $counts[ $g['hunk'] ] ?? 0 ),
		);
	}
	return $out;
}

/**
 * The model for a window, from the existing reads, or null for a non-owner.
 *
 * @param string $from Window start (Y-m-d).
 * @param string $to   Window end (Y-m-d).
 * @return array|string|null
 */
function sn_resume_lanes_read( $from, $to ) {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'snt_ability_analytics_rows' ) ) {
		return null;
	}
	$rows   = snt_ability_analytics_rows( array( 'dimensions' => array( 'resume_lane' ), 'from' => (string) $from, 'to' => (string) $to, 'limit' => 10 ) );
	$events = function_exists( 'sn_analytics_top_events' ) ? sn_analytics_top_events( $from, $to, 500 ) : array();
	return sn_resume_lanes_model( $rows, $events );
}

/**
 * Display cells for one model row. PURE.
 *
 * @param array $r A model row.
 * @return string[] Lane, views, avg time, avg scroll, switched to, mark clicks.
 */
function sn_resume_lanes_cells( array $r ) {
	return array(
		'music' === $r['lane'] ? __( 'Music', 'signal-and-noise-tools' ) : __( 'Operations', 'signal-and-noise-tools' ),
		number_format_i18n( $r['views'] ),
		null === $r['time_ms'] ? '-' : snt_analytics_fmt_time( (float) $r['time_ms'] ),
		null === $r['scroll'] ? '-' : (int) round( (float) $r['scroll'] ) . '%',
		number_format_i18n( $r['switches'] ),
		number_format_i18n( $r['hunks'] ),
	);
}

/** The panel's column labels. */
function sn_resume_lanes_labels() {
	return array( __( 'Lane', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Avg time', 'signal-and-noise-tools' ), __( 'Avg scroll', 'signal-and-noise-tools' ), __( 'Switched to', 'signal-and-noise-tools' ), __( 'Mark clicks', 'signal-and-noise-tools' ) );
}

/** The panel's footnote. */
function sn_resume_lanes_note() {
	/* translators: %s: a date, Y-m-d */
	return sprintf( __( 'Since %s /resume is one résumé, with no switch and no marks: views from then on read as one lane, and Switched to and Mark clicks stay at what they were. Earlier rows keep their history.', 'signal-and-noise-tools' ), SN_RESUME_LANES_ENDED ) . ' ' . __( 'Views, time and scroll per view come from Analytics Engine (about 90 days), human class. Switched to counts clicks on the link to that lane; Mark clicks counts clicks on that lane\'s edition mark. Clicks are custom events, not segmented by class.', 'signal-and-noise-tools' );
}

/**
 * Render the classic panel (owner only; nothing for anyone else).
 *
 * @param string $from Window start (Y-m-d).
 * @param string $to   Window end (Y-m-d).
 */
function snt_analytics_render_resume_lanes( $from, $to ) {
	$model = sn_resume_lanes_read( $from, $to );
	if ( null === $model ) {
		return;
	}
	$title = __( 'Résumé lanes', 'signal-and-noise-tools' );
	if ( is_string( $model ) ) {
		snt_an_note_empty( $title, $model );
		return;
	}
	$cols = array();
	foreach ( sn_resume_lanes_labels() as $i => $label ) {
		$cols[] = array( 'label' => $label, 'class' => $i ? 'num' : '' );
	}
	snt_an_kv_table( $title, array_map( 'sn_resume_lanes_cells', $model ), $cols, array(
		'data_colname' => true,
		'footer'       => '<p class="sn-an-note">' . esc_html( sn_resume_lanes_note() ) . '</p>',
	) );
}
