<?php
/**
 * S&N Dashboard, Integrity > Reports: the unmatched-paths report, painted from
 * the kit (2026-10-29). The native twin of sn_health_render_unmatched_report()
 * (inc/health-render-unmatched.php): same rows, same words (the helpers in
 * inc/health-unmatched-paths.php), same order. Paths are plain text: they are
 * visitor text, and ledger cells hold scalars only. No fold (owner rule).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * The report's card body, then its served and scanners sections.
 *
 * @param array $report The check's report payload.
 * @return array<int,string>
 */
function tools_reports_render_unmatched( array $report ) {
	$status  = array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) );
	$fix     = array( 'key' => 'fix', 'label' => __( 'Suggested fix', 'signal-and-noise-tools' ) );
	$columns = array(
		array( 'key' => 'path', 'label' => __( 'Path', 'signal-and-noise-tools' ) ),
		array( 'key' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ) ),
		array( 'key' => 'seen', 'label' => __( 'Seen', 'signal-and-noise-tools' ) ),
	);
	// One ledger per list; "+N more" counts from the true total, not the rows the scan kept.
	$ledger = static function ( $finding, $key, array $extra ) use ( $report, $columns ) {
		$rows  = array_slice( (array) ( $report[ $key ] ?? array() ), 0, TOOLS_REPORTS_ROWS );
		$keys  = array_flip( array_column( array_merge( $columns, $extra ), 'key' ) );
		$cells = array();
		foreach ( $rows as $row ) {
			$cells[] = array_intersect_key( array(
				'path'   => (string) ( $row['path'] ?? '' ),
				'views'  => \sn_unmatched_visits_words( (array) $row ),
				'seen'   => \sn_unmatched_seen_words( (array) $row ),
				'status' => \sn_unmatched_status_words( (array) $row ),
				'fix'    => \sn_unmatched_fix_words( (array) $row ),
			), $keys ); // Only the listed columns: the table carries its cells as data.
		}
		/* translators: %s: paths not listed. */
		$more = \snt_kit_more( (int) ( $report[ $key . '_total' ] ?? 0 ) - count( $rows ), __( '+%s more paths; the list is capped, not complete.', 'signal-and-noise-tools' ) );
		return \snt_kit_ledger( $finding, array_merge( $columns, $extra ), $cells, TOOLS_REPORTS_ROWS, tools_reports_ledger_opts( '' ) ) . $more;
	};
	$none   = static fn( $text ) => '<p class="snt-hint">' . \snt_kit_esc( $text ) . '</p>';
	$body   = empty( $report['people'] )
		? '<p class="snt-prose">' . \snt_kit_esc( \sn_unmatched_headline( $report ) ) . '</p>'
		: $ledger( \sn_unmatched_headline( $report ), 'people', array( $status, $fix ) );
	$served = (int) ( $report['served_total'] ?? 0 );
	$auto   = (int) ( $report['automated_total'] ?? 0 );
	return array(
		$body,
		\snt_kit_section( __( 'Served by the site', 'signal-and-noise-tools' ), 0 === $served ? $none( __( 'None among the paths checked.', 'signal-and-noise-tools' ) ) : $ledger(
			/* translators: %d: paths the site serves or already redirects. */
			sprintf( _n( '%d path the site serves or already redirects: a theme route, a feed, or a redirect that sends it on (where it lands is not checked).', '%d paths the site serves or already redirects: theme routes, feeds, or redirects that send them on (where they land is not checked).', $served, 'signal-and-noise-tools' ), $served ),
			'served',
			array( $status )
		) ),
		\snt_kit_section( __( 'Scanners and bots', 'signal-and-noise-tools' ), 0 === $auto ? $none( __( 'None.', 'signal-and-noise-tools' ) ) : $ledger(
			/* translators: %d: probe-shaped or bot-only paths. */
			sprintf( _n( '%d path only scanners or bots reached, kept apart so they never bury a real broken link.', '%d paths only scanners or bots reached, kept apart so they never bury a real broken link.', $auto, 'signal-and-noise-tools' ), $auto ),
			'automated',
			array()
		) ),
	);
}
