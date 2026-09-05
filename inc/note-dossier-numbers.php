<?php
/**
 * Signal & Noise Tools — the note dossier: the numbers block.
 *
 * A glance and a door. Views and visits over the requested window from the
 * durable daily table (both spellings of the path); impressions, clicks and
 * position from the Search Console sync in ITS window, which the switch
 * cannot change; and the machine-reads line, which is honest about the one
 * thing the sensor does not do: count per document. Every tile names its
 * own window, so three windows never read as one.
 *
 * Zeros are earned, never assumed: a note absent from the table while the
 * table holds rows had no views; a table with no rows in the window has no
 * analytics; a failed read is named.
 *
 * @package SignalNoiseTools
 * @since 13.100.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param int $post_id
 * @param int $days One of SN_NOTE_DOSSIER_WINDOWS.
 * @return array<int,array<string,mixed>> Empty for an unpublished note.
 */
function sn_note_dossier_numbers( $post_id, $days ) {
	$post = sn_note_dossier_post( $post_id );
	if ( ! $post || ! sn_note_dossier_is_public( $post ) ) {
		return array();
	}
	$days  = sn_note_dossier_days( $days );
	$dash  = '—';
	$tiles = array();
	$wl    = sprintf( /* translators: %d: days. */ _n( '%d day', '%d days', $days, 'signal-and-noise-tools' ), $days );

	// ── Views and visits: the durable daily table ────────────────────────
	$path = function_exists( 'sn_analytics_post_path' ) ? (string) sn_analytics_post_path( $post->ID ) : (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH );
	if ( function_exists( 'snt_analytics_range_dates' ) ) {
		list( $from, $to ) = snt_analytics_range_dates( $days );
	} else {
		$to   = gmdate( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	}
	$win = ( '' !== $path && function_exists( 'sn_analytics_path_window' ) ) ? sn_analytics_path_window( $path, $from, $to ) : null;
	if ( ! is_array( $win ) ) {
		$tiles[] = array( 'label' => __( 'Views', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => __( 'the analytics table could not be read', 'signal-and-noise-tools' ) );
		$tiles[] = array( 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => '' );
	} elseif ( 0 === (int) $win['site_rows'] ) {
		$tiles[] = array( 'label' => __( 'Views', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => __( 'no analytics recorded in this window', 'signal-and-noise-tools' ) );
		$tiles[] = array( 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $wl, 'note' => '' );
	} else {
		$tiles[] = array( 'label' => __( 'Views', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) $win['views'] ), 'window' => $wl, 'note' => '' );
		$tiles[] = array( 'label' => __( 'Visits', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) $win['visits'] ), 'window' => $wl, 'note' => __( 'visitor-days', 'signal-and-noise-tools' ) );
	}

	// ── Search Console: the sync's own window ────────────────────────────
	$gsc = function_exists( 'snt_gsc_data' ) ? snt_gsc_data() : null;
	if ( ! is_array( $gsc ) ) {
		$tiles[] = array( 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => __( 'Search Console', 'signal-and-noise-tools' ), 'note' => __( 'never synced', 'signal-and-noise-tools' ) );
		$tiles[] = array( 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => __( 'Search Console', 'signal-and-noise-tools' ), 'note' => '' );
	} else {
		$gw  = is_array( $gsc['window'] ?? null ) ? $gsc['window'] : array();
		$gwl = sprintf( /* translators: 1: start date, 2: end date. */ __( '%1$s to %2$s', 'signal-and-noise-tools' ), (string) ( $gw['start'] ?? '?' ), (string) ( $gw['end'] ?? '?' ) );
		$key = function_exists( 'sn_path_join_key' ) ? (string) sn_path_join_key( (string) get_permalink( $post ) ) : '';
		$row = ( '' !== $key && function_exists( 'snt_gsc_metrics_for_path' ) ) ? snt_gsc_metrics_for_path( $key ) : null;
		$tot = function_exists( 'snt_gsc_window_totals' ) ? snt_gsc_window_totals() : null;
		if ( ! is_array( $row ) ) {
			$why     = ( is_array( $tot ) && ! empty( $tot['capped'] ) ) ? __( 'not among the top 250 rows the sync keeps', 'signal-and-noise-tools' ) : __( 'not shown by Google in this window', 'signal-and-noise-tools' );
			$tiles[] = array( 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $gwl, 'note' => $why );
			$tiles[] = array( 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'value' => $dash, 'window' => $gwl, 'note' => '' );
		} else {
			$tiles[] = array( 'label' => __( 'Impressions', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) ( $row['impressions'] ?? 0 ) ), 'window' => $gwl, 'note' => '' );
			$tiles[] = array( 'label' => __( 'Clicks', 'signal-and-noise-tools' ), 'value' => number_format_i18n( (int) ( $row['clicks'] ?? 0 ) ), 'window' => $gwl, 'note' => sprintf( /* translators: %s: average position. */ __( 'average position %s', 'signal-and-noise-tools' ), number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ) ) );
		}
	}

	$blocks   = array();
	$blocks[] = sn_note_dossier_stats(
		'numbers',
		__( 'Numbers', 'signal-and-noise-tools' ),
		$tiles,
		__( 'analytics table; Search Console sync', 'signal-and-noise-tools' ),
		function_exists( 'snt_analytics_page_url' ) ? sn_note_dossier_door( __( 'Open S&N Analytics', 'signal-and-noise-tools' ), snt_analytics_page_url( array( 'sn_view' => 'content', 'sn_range' => $days ) ) ) : null
	);

	// ── Machine reads: not counted per note, by design ───────────────────
	$snap  = function_exists( 'snt_mr_snapshot' ) ? snt_mr_snapshot() : null;
	$total = function_exists( 'snt_mr_snapshot_total' ) ? snt_mr_snapshot_total( $snap ) : null;
	$meta  = __( 'The sensor keeps no document paths, by its privacy contract.', 'signal-and-noise-tools' );
	$meta .= null === $total
		? ' ' . __( 'No site-wide measurement yet.', 'signal-and-noise-tools' )
		: ' ' . sprintf( /* translators: %s: reads. */ __( 'Site-wide over the last 30 days: %s.', 'signal-and-noise-tools' ), number_format_i18n( (int) $total ) );
	$blocks[] = sn_note_dossier_status(
		'numbers',
		__( 'Machine reads', 'signal-and-noise-tools' ),
		'neutral',
		__( 'Not counted per note.', 'signal-and-noise-tools' ),
		$meta,
		__( 'daily snapshot', 'signal-and-noise-tools' ),
		function_exists( 'snt_desktop_admin_url' ) ? sn_note_dossier_door( __( 'Open Machine Readers in S&N Dashboard', 'signal-and-noise-tools' ), snt_desktop_admin_url( 'sn-monitoring', 'machine-readers' ) ) : null
	);
	return $blocks;
}
