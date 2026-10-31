<?php
/**
 * Signal & Noise Tools: the Indexing report's classic view (Integrity › Reports).
 *
 * Envelope (inc/search-console-indexing.php): request_list, request_total,
 * stuck, stuck_total, publish_check {checked, inbound_judged, failures},
 * generated_at, coverage_run_at. The native twin is
 * apps/sn-dashboard/parts/leaves/tools-reports-indexing.php; both read their
 * words from the helpers here, so the two surfaces say the same thing.
 *
 * @package SignalNoiseTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/search-console-indexing.php'; // SNT_INDEXING_MIN_INBOUND.
require_once __DIR__ . '/health-render-indexing-status.php'; // 2026-10-31: request status, both twins' words.

/**
 * The inbound figure in words: unknown is not zero.
 *
 * @param int|null $n Inbound links from indexed pages.
 * @return string
 */
function sn_indexing_inbound_words( $n ) {
	return null === $n ? __( 'not in the link graph', 'signal-and-noise-tools' ) : (string) (int) $n;
}

/**
 * A row's inbound figure plus what links it from every page (2026-10-22): the
 * body count alone read "0" for a page the site nav links everywhere.
 *
 * @param array $row A request, stuck or failure row (chrome, template[]).
 * @return string
 */
function sn_indexing_row_inbound_words( array $row ) {
	$by    = ! empty( $row['chrome'] ) || 'chrome' === ( $row['inbound_source'] ?? '' ) ? array( __( 'the site header or footer', 'signal-and-noise-tools' ) ) : array();
	$names = array(
		'post' => __( 'every note\'s template', 'signal-and-noise-tools' ),
		'page' => __( 'every page\'s template', 'signal-and-noise-tools' ),
		'home' => __( 'the home page template', 'signal-and-noise-tools' ),
	);
	foreach ( (array) ( $row['template'] ?? array() ) as $type ) {
		if ( isset( $names[ $type ] ) ) {
			$by[] = $names[ $type ];
		}
	}
	$n = sn_indexing_inbound_words( $row['inbound_indexed'] ?? null );
	/* translators: 1: link count, 2: what links it from every page, e.g. "the site header or footer". */
	return array() === $by ? $n : sprintf( __( '%1$s, and linked from %2$s', 'signal-and-noise-tools' ), $n, implode( __( ' and ', 'signal-and-noise-tools' ), $by ) );
}

/**
 * A publish-check failure's reasons in words.
 *
 * @param array $failure One failures[] row.
 * @return string
 */
function sn_indexing_reason_words( array $failure ) {
	$words = array();
	foreach ( (array) ( $failure['reasons'] ?? array() ) as $reason ) {
		if ( 'inbound' === $reason ) {
			/* translators: 1: minimum links, 2: links found, or "not in the link graph". */
			$words[] = sprintf( __( 'fewer than %1$d links from indexed pages (%2$s)', 'signal-and-noise-tools' ), SNT_INDEXING_MIN_INBOUND, sn_indexing_row_inbound_words( $failure ) );
		} elseif ( 'sitemap' === $reason ) {
			$words[] = __( 'not in the sitemap (noindex, or a path the plugin serves noindex)', 'signal-and-noise-tools' );
		} elseif ( 'canonical' === $reason ) {
			$words[] = __( 'its canonical points elsewhere', 'signal-and-noise-tools' );
		}
	}
	return implode( '; ', $words );
}

/**
 * The headline sentence both surfaces print first.
 *
 * @param array $report The report payload.
 * @return string
 */
function sn_indexing_headline( array $report ) {
	if ( empty( $report['coverage_run_at'] ) ) {
		return __( 'The weekly URL Inspection run has not stored a map yet, so nothing can be listed.', 'signal-and-noise-tools' );
	}
	return sprintf(
		/* translators: 1: URLs to request, 2: URLs stuck 30+ days, 3: publish-check failures, 4: date of the last inspection run. */
		__( '%1$d URLs to request (not indexed after 7 days), %2$d stuck past 30 days, %3$d publish-check warnings. Inspection map from %4$s.', 'signal-and-noise-tools' ),
		(int) ( $report['request_total'] ?? 0 ),
		(int) ( $report['stuck_total'] ?? 0 ),
		count( (array) ( $report['publish_check']['failures'] ?? array() ) ),
		gmdate( 'Y-m-d', (int) $report['coverage_run_at'] )
	);
}

/**
 * One table of indexing rows (the request list or the stuck list).
 *
 * @param string $caption Table caption.
 * @param array  $rows    Rows.
 * @param int    $total   The true total.
 * @param array  $status  path => snt_gsc_request_status() (2026-10-31).
 */
function sn_health_render_indexing_table( $caption, array $rows, $total, array $status = array() ) {
	if ( empty( $rows ) ) {
		echo '<p class="sn-field-helper">' . esc_html( $caption ) . ': ' . esc_html__( 'none.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	echo '<div class="snt-scroll-table"><table class="widefat striped snt-mt-half">';
	/* translators: 1: caption, 2: rows shown, 3: total. */
	echo '<caption>' . esc_html( sprintf( __( '%1$s (%2$d of %3$d)', 'signal-and-noise-tools' ), $caption, count( $rows ), (int) $total ) ) . '</caption>';
	echo '<thead><tr><th scope="col">' . esc_html__( 'URL', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Google\'s state', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Days since publish', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Links from indexed pages', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Your request', 'signal-and-noise-tools' ) . '</th><th scope="col">' . esc_html__( 'Action', 'signal-and-noise-tools' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$path = (string) ( $row['path'] ?? '' );
		echo '<tr><td><a href="' . esc_url( (string) ( $row['url'] ?? '' ) ) . '"><code>' . esc_html( $path ) . '</code></a>' . ( ! empty( $row['core'] ) ? ' <span class="sn-badge">' . esc_html__( 'core', 'signal-and-noise-tools' ) . '</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( (string) ( $row['coverage_state'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( (string) (int) ( $row['days'] ?? 0 ) ) . '</td>';
		echo '<td>' . esc_html( sn_indexing_row_inbound_words( $row ) ) . '</td>';
		echo '<td>' . esc_html( sn_indexing_status_words( $status[ $path ] ?? null ) ) . '</td>';
		$mark = sn_indexing_mark_label( $status[ $path ] ?? null );
		/* translators: %s: the path to request. */
		echo '<td><a class="button button-small" target="_blank" rel="noopener noreferrer" href="' . esc_url( (string) ( $row['inspect_url'] ?? '' ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Request %s in Search Console (opens a new tab)', 'signal-and-noise-tools' ), $path ) ) . '">' . esc_html__( 'Request in Search Console', 'signal-and-noise-tools' ) . '</a>'
			/* translators: 1: button text, 2: path. */
			. ( '' === $mark ? '' : sn_indexing_classic_button( 'gsc_mark_requested', $mark, array( 'path' => $path ), sprintf( __( '%1$s: %2$s', 'signal-and-noise-tools' ), $mark, $path ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sn_indexing_classic_button() escapes every part.
	}
	echo '</tbody></table></div>';
}

/**
 * The Indexing report: the request list, the stuck list, the publish check.
 *
 * @param array $report The check's `report` payload.
 */
function sn_health_render_indexing_report( $report ) {
	$report = (array) $report;
	echo '<p class="sn-health-report__headline">' . esc_html( sn_indexing_headline( $report ) ) . '</p>';
	echo '<p class="sn-field-helper">' . esc_html( sn_indexing_checked_words( $report ) ) . '</p>' . sn_indexing_classic_button( 'gsc_check_now', __( 'Check now with Google', 'signal-and-noise-tools' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	$status = (array) ( $report['request_status'] ?? array() );
	sn_health_render_indexing_table( __( 'Request indexing this week', 'signal-and-noise-tools' ), (array) ( $report['request_list'] ?? array() ), (int) ( $report['request_total'] ?? 0 ), $status );
	sn_health_render_indexing_table( __( 'Stuck past 30 days', 'signal-and-noise-tools' ), (array) ( $report['stuck'] ?? array() ), (int) ( $report['stuck_total'] ?? 0 ), $status );
	if ( ! empty( $report['indexed_after_request'] ) ) {
		echo '<h3>' . esc_html__( 'Indexed after your request', 'signal-and-noise-tools' ) . '</h3><ul class="sn-health-report__list">';
		foreach ( (array) $report['indexed_after_request'] as $a ) {
			echo '<li>' . esc_html( sn_indexing_after_words( (array) $a ) ) . '</li>';
		}
		echo '</ul>';
	}

	$check    = (array) ( $report['publish_check'] ?? array() );
	$failures = (array) ( $check['failures'] ?? array() );
	/* translators: 1: pages with a warning, 2: pages checked. */
	echo '<h3>' . esc_html( sprintf( __( 'Publish check: %1$d of %2$d notes and core pages need a look', 'signal-and-noise-tools' ), count( $failures ), (int) ( $check['checked'] ?? 0 ) ) ) . '</h3>';
	if ( empty( $check['inbound_judged'] ) ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'Inbound links are not judged until the inspection map exists.', 'signal-and-noise-tools' ) . '</p>';
	}
	if ( empty( $failures ) ) {
		echo '<p class="sn-field-helper">' . esc_html__( 'Every note and core page published 3 or more days ago has 2 links from indexed pages, is in the sitemap and names itself canonical.', 'signal-and-noise-tools' ) . '</p>';
		return;
	}
	echo '<ul class="sn-health-report__list">';
	foreach ( $failures as $f ) {
		echo '<li><a href="' . esc_url( (string) ( $f['url'] ?? '' ) ) . '"><code>' . esc_html( (string) ( $f['path'] ?? '' ) ) . '</code></a>: ' . esc_html( sn_indexing_reason_words( (array) $f ) ) . '</li>';
	}
	echo '</ul>';
}
