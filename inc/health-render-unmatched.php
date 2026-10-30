<?php
/**
 * Signal & Noise Tools: the classic Integrity > Reports view of the
 * unmatched-paths report (2026-10-29). Words from inc/health-unmatched-paths.php,
 * shared with the native twin (apps/sn-dashboard/parts/leaves/
 * tools-reports-unmatched.php). Every path is escaped plain text, never a link:
 * it is visitor text, and a placeholder path would link nowhere.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/health-unmatched-paths.php'; // The words both twins share.

/**
 * One table of rows.
 *
 * @param string $caption Caption.
 * @param array  $rows    Rows shown.
 * @param int    $total   The true total.
 * @param bool   $people  People columns, or the scanners' short ones.
 */
function sn_health_render_unmatched_table( $caption, array $rows, $total, $people ) {
	if ( empty( $rows ) ) {
		echo '<p class="sn-field-helper">' . esc_html( $caption ) . ': ' . esc_html__( 'none.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	$rows = array_slice( $rows, 0, SN_404_LIST_CAP );
	echo '<div class="snt-scroll-table"><table class="widefat striped snt-mt-half">';
	/* translators: 1: caption, 2: rows shown, 3: total. */
	echo '<caption>' . esc_html( sprintf( __( '%1$s (%2$d of %3$d)', 'signal-and-noise-tools' ), $caption, count( $rows ), (int) $total ) ) . '</caption><thead><tr>';
	$heads = $people
		? array( __( 'Path', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Seen', 'signal-and-noise-tools' ), __( 'Status', 'signal-and-noise-tools' ), __( 'Suggested fix', 'signal-and-noise-tools' ) )
		: array( __( 'Path', 'signal-and-noise-tools' ), __( 'Views', 'signal-and-noise-tools' ), __( 'Seen', 'signal-and-noise-tools' ) );
	foreach ( $heads as $h ) {
		echo '<th scope="col">' . esc_html( $h ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		echo '<tr><td><code>' . esc_html( (string) ( $row['path'] ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( sn_unmatched_visits_words( $row ) ) . '</td>';
		echo '<td>' . esc_html( sn_unmatched_seen_words( $row ) ) . '</td>';
		if ( $people ) {
			echo '<td>' . esc_html( sn_unmatched_status_words( $row ) ) . '</td>';
			echo '<td>' . esc_html( sn_unmatched_fix_words( $row ) ) . '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

/**
 * The report: people first, then scanners and bots.
 *
 * @param array $report The check's report payload.
 */
function sn_health_render_unmatched_report( $report ) {
	$report = (array) $report;
	echo '<p class="sn-health-report__headline">' . esc_html( sn_unmatched_headline( $report ) ) . '</p>';
	sn_health_render_unmatched_table( __( 'People', 'signal-and-noise-tools' ), (array) ( $report['people'] ?? array() ), (int) ( $report['people_total'] ?? 0 ), true );
	sn_health_render_unmatched_table( __( 'Scanners and bots', 'signal-and-noise-tools' ), (array) ( $report['automated'] ?? array() ), (int) ( $report['automated_total'] ?? 0 ), false );
}
