<?php
/**
 * S&N Dashboard, Integrity > Reports: the Indexing report, painted from the kit.
 *
 * The native twin of `sn_health_render_indexing_report()` (inc/health-render-
 * indexing.php): same fields, same sentences (the words come from that file's
 * helpers), same order. Rows are plain list items rather than an
 * `<os-table>` because each carries a live link to Search Console's URL
 * Inspection, and ledger rows hold scalar data only (the link-isolation
 * leaf's reasoning). No fold: every list is open (owner rule).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * One list of indexing rows.
 *
 * @param array $rows  Rows.
 * @param int   $total The true total.
 * @return string
 */
function tools_reports_indexing_list( array $rows, $total ) {
	if ( empty( $rows ) ) {
		return '<p class="snt-hint">' . \snt_kit_esc( __( 'None.', 'signal-and-noise-tools' ) ) . '</p>';
	}
	$items = '';
	foreach ( $rows as $row ) {
		$items .= '<li>' . \snt_kit_link( (string) ( $row['path'] ?? '' ), (string) ( $row['url'] ?? '' ) )
			. ( ! empty( $row['core'] ) ? ' ' . \snt_kit_badge( '', __( 'core', 'signal-and-noise-tools' ) ) : '' )
			. ' · ' . \snt_kit_esc( (string) ( $row['coverage_state'] ?? '' ) )
			/* translators: %d: days since the page was published. */
			. ' · ' . \snt_kit_esc( sprintf( __( '%d days since publish', 'signal-and-noise-tools' ), (int) ( $row['days'] ?? 0 ) ) )
			/* translators: %s: link count, or "not in the link graph". */
			. ' · ' . \snt_kit_esc( sprintf( __( 'links from indexed pages: %s', 'signal-and-noise-tools' ), \sn_indexing_inbound_words( $row['inbound_indexed'] ?? null ) ) )
			. ' · ' . \snt_kit_link( __( 'Inspect in Search Console', 'signal-and-noise-tools' ), (string) ( $row['inspect_url'] ?? '' ) )
			. '</li>';
	}
	$out = '<ul class="snt-plain">' . $items . '</ul>';
	if ( (int) $total > count( $rows ) ) {
		/* translators: 1: rows shown, 2: true total. */
		$out .= '<p class="snt-hint">' . \snt_kit_esc( sprintf( __( 'Showing %1$d of %2$d; the list is capped, not complete.', 'signal-and-noise-tools' ), count( $rows ), (int) $total ) ) . '</p>';
	}
	return $out;
}

/**
 * The Indexing report: the card's body (headline and request list), then the
 * stuck list and the publish check as open sections.
 *
 * @param array $report The check's `report` payload.
 * @return array<int,string>
 */
function tools_reports_render_indexing( array $report ) {
	$body = '<p class="snt-prose">' . \snt_kit_esc( \sn_indexing_headline( $report ) ) . '</p>'
		. '<p class="snt-prose"><b>' . \snt_kit_esc( __( 'Request indexing this week', 'signal-and-noise-tools' ) ) . '</b></p>'
		. tools_reports_indexing_list( (array) ( $report['request_list'] ?? array() ), (int) ( $report['request_total'] ?? 0 ) );

	$stuck = \snt_kit_section( __( 'Stuck past 30 days', 'signal-and-noise-tools' ), tools_reports_indexing_list( (array) ( $report['stuck'] ?? array() ), (int) ( $report['stuck_total'] ?? 0 ) ) );

	$check    = (array) ( $report['publish_check'] ?? array() );
	$failures = (array) ( $check['failures'] ?? array() );
	$inner    = empty( $check['inbound_judged'] ) ? '<p class="snt-hint">' . \snt_kit_esc( __( 'Inbound links are not judged until the inspection map exists.', 'signal-and-noise-tools' ) ) . '</p>' : '';
	if ( empty( $failures ) ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc( __( 'Every note and core page published 3 or more days ago has 2 links from indexed pages, is in the sitemap and names itself canonical.', 'signal-and-noise-tools' ) ) . '</p>';
	} else {
		$items = '';
		foreach ( $failures as $f ) {
			$items .= '<li>' . \snt_kit_link( (string) ( $f['path'] ?? '' ), (string) ( $f['url'] ?? '' ) ) . ': ' . \snt_kit_esc( \sn_indexing_reason_words( (array) $f ) ) . '</li>';
		}
		$inner .= '<ul class="snt-plain">' . $items . '</ul>';
	}
	/* translators: 1: pages with a warning, 2: pages checked. */
	$publish = \snt_kit_section( sprintf( __( 'Publish check: %1$d of %2$d notes and core pages need a look', 'signal-and-noise-tools' ), count( $failures ), (int) ( $check['checked'] ?? 0 ) ), $inner );

	return array( $body, $stuck, $publish );
}
