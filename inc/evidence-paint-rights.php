<?php
/**
 * Signal & Noise Tools: rights-surface reads, feed fetches and what changed.
 * Section painters for the Machine readers view, host-agnostic. Capped
 * tables with a "+N more" line, never a fold.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Rights-surface events a table shows before its "+N more" line. */
const SN_EV_RIGHTS_ROWS = 15;

/** Rows for a rights table: when, vendor, purpose (unknown reads unmapped), document, user agent. */
function snt_ev_rights_rows( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		$vendor = snt_mr_normalize_vendor( $r['vendor'] ?? '' );
		$out[]  = array(
			'when'       => substr( preg_replace( '/[^0-9T:.\-Z]/', '', (string) ( $r['observed_at'] ?? '' ) ), 0, 20 ),
			'vendor'     => '' !== $vendor ? $vendor : '—',
			'purpose'    => snt_mr_purpose_label( (string) ( $r['purpose'] ?? 'unknown' ) ),
			'document'   => substr( (string) ( $r['path'] ?? '' ), 0, 120 ),
			'user_agent' => substr( (string) ( $r['user_agent'] ?? '' ), 0, 200 ),
		);
	}
	return $out;
}

/**
 * Rights-surface reads: external reads newest first, then this site's own
 * checks apart; each capped with a "+N more" line.
 *
 * @param array|null $rows    snt_mr_fetch( $days, 'rights' ) rows, or null when unread.
 * @param string     $surface kit|classic.
 * @return string
 */
function snt_ev_paint_rights( $rows, $surface = 'kit' ) {
	$title = __( 'Rights-surface reads', 'signal-and-noise-tools' );
	if ( null === $rows ) {
		return snt_ev_box( $surface, $title, snt_ev_lead( $surface, __( 'The rights stream could not be read: not measured.', 'signal-and-noise-tools' ) ) );
	}
	$ours = array_values( array_filter( $rows, static fn( $r ) => 'signal-and-noise' === snt_mr_normalize_vendor( $r['vendor'] ?? '' ) ) );
	$ext  = array_values( array_filter( $rows, static fn( $r ) => 'signal-and-noise' !== snt_mr_normalize_vendor( $r['vendor'] ?? '' ) ) );
	usort( $ext, static fn( $a, $b ) => strcmp( (string) ( $b['observed_at'] ?? '' ), (string) ( $a['observed_at'] ?? '' ) ) );
	$cols = array( 'when' => __( 'When', 'signal-and-noise-tools' ), 'vendor' => __( 'Vendor', 'signal-and-noise-tools' ), 'purpose' => __( 'Purpose', 'signal-and-noise-tools' ), 'document' => __( 'Document', 'signal-and-noise-tools' ), 'user_agent' => __( 'User agent', 'signal-and-noise-tools' ) );
	$lead = sprintf(
		/* translators: 1: external reads, 2: reads from this site's own checks. */
		__( '%1$s external reads of the rights files; %2$s from this site’s own checks, shown apart.', 'signal-and-noise-tools' ),
		number_format_i18n( count( $ext ) ),
		number_format_i18n( count( $ours ) )
	);
	$out = snt_ev_lead( $surface, $lead );
	if ( array() !== $ext ) {
		$out .= snt_ev_table( $surface, $cols, snt_ev_rights_rows( array_slice( $ext, 0, SN_EV_RIGHTS_ROWS ) ), max( 0, count( $ext ) - SN_EV_RIGHTS_ROWS ), $title );
	}
	if ( array() !== $ours ) {
		$out .= '<p class="snt-ev-muted">' . esc_html__( 'This site’s own checks (not readership)', 'signal-and-noise-tools' ) . '</p>'
			. snt_ev_table( $surface, $cols, snt_ev_rights_rows( array_slice( $ours, 0, 5 ) ), max( 0, count( $ours ) - 5 ), __( 'This site’s own checks', 'signal-and-noise-tools' ) );
	}
	return snt_ev_box( $surface, $title, $out );
}

/**
 * Feed fetches per window.
 *
 * @param array  $stats   sn_rss_tracker_window_stats_multi() shape.
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_feeds( array $stats, $surface = 'kit' ) {
	$windows = is_array( $stats['windows'] ?? null ) ? $stats['windows'] : array();
	if ( array() === $windows ) {
		return snt_ev_box( $surface, __( 'Feeds', 'signal-and-noise-tools' ), snt_ev_lead( $surface, __( 'No feed fetches recorded yet.', 'signal-and-noise-tools' ) ) );
	}
	ksort( $windows );
	$rows = array();
	foreach ( $windows as $days => $w ) {
		/* translators: %s: days. */
		$rows[] = array( 'window' => sprintf( __( 'last %s days', 'signal-and-noise-tools' ), number_format_i18n( (int) $days ) ), 'fetches' => number_format_i18n( (int) ( $w['total'] ?? 0 ) ), 'fetchers' => number_format_i18n( (int) ( $w['uniques'] ?? 0 ) ) );
	}
	/* translators: %s: the most recent fetch. */
	$lead = '' !== (string) ( $stats['most_recent'] ?? '' ) ? sprintf( __( 'Feed fetches (RSS and JSON Feed), never summed with crawler reads. Most recent: %s.', 'signal-and-noise-tools' ), (string) $stats['most_recent'] ) : __( 'Feed fetches (RSS and JSON Feed), never summed with crawler reads.', 'signal-and-noise-tools' );
	return snt_ev_box( $surface, __( 'Feeds', 'signal-and-noise-tools' ), snt_ev_lead( $surface, $lead ) . snt_ev_table( $surface, array( 'window' => __( 'Window', 'signal-and-noise-tools' ), 'fetches' => __( 'Fetches', 'signal-and-noise-tools' ), 'fetchers' => __( 'Fetchers', 'signal-and-noise-tools' ) ), $rows, 0, __( 'Feeds', 'signal-and-noise-tools' ) ) );
}

/**
 * What changed: the family delta cards, as notices.
 *
 * @param array  $cards   snt_mr_family_delta_cards().
 * @param string $surface kit|classic.
 * @return string
 */
function snt_ev_paint_delta_cards( array $cards, $surface = 'kit' ) {
	$out = '';
	foreach ( $cards as $c ) {
		$body = '<b>' . esc_html( (string) ( $c['title'] ?? '' ) ) . '</b>' . ( '' !== (string) ( $c['detail'] ?? '' ) ? ' ' . esc_html( (string) $c['detail'] ) : '' );
		$out .= 'kit' === $surface ? snt_kit_notice( 'info', $body ) : '<div class="notice notice-info inline"><p>' . $body . '</p></div>';
	}
	return $out;
}
