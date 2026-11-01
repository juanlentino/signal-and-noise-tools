<?php
/**
 * Signal & Noise Tools: the Sessions tab's list, sessions one by one (owner
 * brief "MCP full analytics access", 2026-10-31: "Add to Sessions tab too").
 *
 * The panel asks the sessions read itself (snt_ability_analytics_sessions(),
 * the callback both MCP doors run) for the view's window, so the tab and the
 * doors list the same rows (tests/analytics-sessions-read.php, owner test 7a).
 * Analytics Engine keeps about 90 days: an older start is moved up to the
 * first day it holds, and the panel says so.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sessions from the read into the table's rows. PURE.
 *
 * @param array $sessions The read's `sessions`.
 * @return array<int,array<int,string>>
 */
function snt_analytics_session_list_rows( array $sessions ) {
	$rows = array();
	foreach ( $sessions as $s ) {
		$pages = array();
		foreach ( (array) $s['pages'] as $p ) {
			$bits    = array_filter( array( null === $p['time_ms'] ? '' : round( $p['time_ms'] / 1000 ) . 's', null === $p['scroll_pct'] ? '' : $p['scroll_pct'] . '%' ), 'strlen' );
			$pages[] = $p['path'] . ( $bits ? ' (' . implode( ', ', $bits ) . ')' : '' );
		}
		$rows[] = array(
			substr( $s['start'], 0, 10 ) . ' ' . substr( $s['start'], 11, 5 ),
			implode( ' → ', $pages ),
			$s['duration_s'] . 's',
			$s['source'],
			implode( ', ', array_filter( array( $s['city'], $s['region'], $s['country'] ), static fn( $v ) => '' !== $v && '(unknown)' !== $v ) ),
			'(unknown)' === $s['device'] ? '' : $s['device'],
			$s['returning'] ? __( 'yes', 'signal-and-noise-tools' ) : '',
			$s['engaged'] ? __( 'yes', 'signal-and-noise-tools' ) : '',
			implode( ', ', $s['goals'] ),
			$s['link_code'],
		);
	}
	return $rows;
}

/**
 * The panel.
 *
 * @param string $from  Window start (Y-m-d, site time).
 * @param string $to    Window end (Y-m-d, site time).
 * @param string $class Traffic class.
 */
function snt_analytics_render_session_list( $from, $to, $class ) {
	$title = __( 'Sessions, one by one', 'signal-and-noise-tools' );
	$floor = snt_analytics_sessions_floor( time() );
	if ( (string) $to < $floor ) {
		/* translators: %s: the first day Analytics Engine holds. */
		snt_an_note_empty( $title, sprintf( __( 'This window is older than Analytics Engine keeps: sessions can be listed from %s on.', 'signal-and-noise-tools' ), $floor ) );
		return;
	}
	$start = max( (string) $from, $floor );
	$read  = snt_ability_analytics_sessions( array( 'from' => $start, 'to' => (string) $to, 'class' => (string) $class ) );
	if ( is_wp_error( $read ) ) {
		snt_an_note_empty( $title, $read->get_error_message() );
		return;
	}
	$meta = sprintf(
		/* translators: 1: sessions in the window, 2: sessions listed */
		__( '%1$d sessions that began in the window (site days; the panels above count UTC days) · the newest %2$d · the rows the MCP sessions read returns', 'signal-and-noise-tools' ),
		$read['total'],
		count( $read['sessions'] )
	) . ( $start > $from ? ' · ' . sprintf( /* translators: %s: first day Analytics Engine holds */ __( 'from %s, the first day Analytics Engine holds', 'signal-and-noise-tools' ), $start ) : '' )
		. ( $read['capped'] ? ' · ' . __( 'capped: narrow the window for every session', 'signal-and-noise-tools' ) : '' );
	snt_an_kv_table(
		$title,
		snt_analytics_session_list_rows( $read['sessions'] ),
		array(
			array( 'label' => __( 'Start', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Pages, in order (time, scroll)', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Duration', 'signal-and-noise-tools' ), 'class' => 'num' ),
			array( 'label' => __( 'Source', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Place', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Device', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Returning', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Engaged', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Goals', 'signal-and-noise-tools' ) ),
			array( 'label' => __( 'Link', 'signal-and-noise-tools' ) ),
		),
		array( 'header_meta' => esc_html( $meta ), 'data_colname' => true, 'empty' => __( 'No sessions in this window.', 'signal-and-noise-tools' ) )
	);
}
