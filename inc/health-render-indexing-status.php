<?php
/**
 * Signal & Noise Tools: the Indexing report's request status, in words both
 * twins print (2026-10-31). The data is inc/search-console-requested.php's;
 * the classic table (inc/health-render-indexing.php) and the native list
 * (apps/sn-dashboard/parts/leaves/tools-reports-indexing.php) read their
 * sentences from here, so the two surfaces say the same thing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A day the owner reads, in the site's timezone: "Oct 31". */
function sn_indexing_day( $ts ) {
	return function_exists( 'wp_date' ) ? (string) wp_date( 'M j', (int) $ts ) : gmdate( 'M j', (int) $ts );
}

/**
 * One row's status line, or '' for a report stored before marks existed.
 *
 * @param array|null $status snt_gsc_request_status().
 * @return string
 */
function sn_indexing_status_words( $status ) {
	if ( ! is_array( $status ) || empty( $status['state'] ) ) {
		return '';
	}
	$crawl = (int) ( $status['last_crawl'] ?? 0 );
	$asked = sn_indexing_day( (int) ( $status['requested_at'] ?? 0 ) );
	/* translators: %s: day Google last crawled the page. */
	$seen = $crawl > 0 ? sprintf( __( 'last crawled %s', 'signal-and-noise-tools' ), sn_indexing_day( $crawl ) ) : __( 'never crawled', 'signal-and-noise-tools' );
	switch ( $status['state'] ) {
		case 'indexed':
			return __( 'Indexed', 'signal-and-noise-tools' );
		case 'crawled_since':
			/* translators: 1: day the row was marked requested, 2: day Google crawled it. */
			return sprintf( __( 'Requested %1$s (marked); Google crawled it %2$s and still left it out: request again', 'signal-and-noise-tools' ), $asked, sn_indexing_day( $crawl ) );
		case 'waiting':
			return ( empty( $status['quiet'] )
				/* translators: %s: day the row was marked requested. */
				? sprintf( __( 'Requested %s (marked); no crawl since in 14 days: request again', 'signal-and-noise-tools' ), $asked )
				/* translators: %s: day the row was marked requested. */
				: sprintf( __( 'Requested %s (marked); waiting on Google', 'signal-and-noise-tools' ), $asked ) ) . ' · ' . $seen;
	}
	return __( 'Not requested yet', 'signal-and-noise-tools' ) . ' · ' . $seen;
}

/**
 * The mark button's label, or '' when the row is waiting quietly (nothing to do).
 *
 * @param array|null $status snt_gsc_request_status().
 * @return string
 */
function sn_indexing_mark_label( $status ) {
	$state = is_array( $status ) ? (string) ( $status['state'] ?? '' ) : '';
	if ( '' === $state || 'indexed' === $state || ( 'waiting' === $state && ! empty( $status['quiet'] ) ) ) {
		return '';
	}
	return 'not_requested' === $state ? __( 'Mark requested', 'signal-and-noise-tools' ) : __( 'Mark requested again', 'signal-and-noise-tools' );
}

/**
 * The last Check now, in one sentence.
 *
 * @param array $report The report payload.
 * @return string
 */
function sn_indexing_checked_words( array $report ) {
	$c = $report['checked_now'] ?? null;
	if ( ! is_array( $c ) || empty( $c['at'] ) ) {
		return __( 'Not checked live yet: the rows read the weekly inspection map.', 'signal-and-noise-tools' );
	}
	$when = function_exists( 'wp_date' ) ? (string) wp_date( 'M j, g:i a', (int) $c['at'] ) : gmdate( 'M j, H:i', (int) $c['at'] );
	/* translators: 1: date and time, 2: pages checked. */
	$line = sprintf( _n( 'Checked live with Google %1$s: %2$d page.', 'Checked live with Google %1$s: %2$d pages.', (int) $c['checked'], 'signal-and-noise-tools' ), $when, (int) $c['checked'] );
	if ( (int) ( $c['errors'] ?? 0 ) > 0 ) {
		/* translators: %d: pages that did not answer. */
		$line .= ' ' . sprintf( __( '%d did not answer and keep their last answer.', 'signal-and-noise-tools' ), (int) $c['errors'] );
	}
	if ( (int) ( $c['remaining'] ?? 0 ) > 0 ) {
		/* translators: %d: pages not checked before the time limit. */
		$line .= ' ' . sprintf( __( '%d left at the time limit: press Check now again.', 'signal-and-noise-tools' ), (int) $c['remaining'] );
	}
	return $line;
}

/**
 * "Indexed after your request" rows in words: "/notes/x · requested Oct 31".
 *
 * @param array $row One indexed_after_request row.
 * @return string
 */
function sn_indexing_after_words( array $row ) {
	/* translators: 1: path, 2: day the row was marked requested. */
	return sprintf( __( '%1$s · requested %2$s (marked), now indexed', 'signal-and-noise-tools' ), (string) ( $row['path'] ?? '' ), sn_indexing_day( (int) ( $row['requested_at'] ?? 0 ) ) );
}

/**
 * A one-button classic form for the report's two writes (the insights_mark_done
 * shape): the action and its nonce ride the URL and the button's formaction.
 *
 * @param string $action gsc_mark_requested or gsc_check_now.
 * @param string $label  Button text.
 * @param array  $hidden name => value.
 * @param string $aria   aria-label, '' for none.
 * @return string Escaped HTML.
 */
function sn_indexing_classic_button( $action, $label, array $hidden = array(), $aria = '' ) {
	$out = '<form method="post" action="' . esc_url( sn_admin_post_url( $action ) ) . '" class="sn-fieldset-actions sn-fieldset-actions--inline">';
	foreach ( $hidden as $name => $value ) {
		$out .= '<input type="hidden" name="' . esc_attr( (string) $name ) . '" value="' . esc_attr( (string) $value ) . '">';
	}
	return $out . '<button type="submit"' . sn_admin_post_button( $action ) . ' class="button button-small"' . ( '' === $aria ? '' : ' aria-label="' . esc_attr( $aria ) . '"' ) . '>' . esc_html( $label ) . '</button></form>';
}
