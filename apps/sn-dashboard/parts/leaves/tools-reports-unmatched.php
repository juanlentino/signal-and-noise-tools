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
 * The report's card body and its scanners section.
 *
 * @param array $report The check's report payload.
 * @return array<int,string>
 */
function tools_reports_render_unmatched( array $report ) {
	$cells = static function ( array $rows, $people ) {
		$out = array();
		foreach ( $rows as $row ) {
			$cell = array(
				'path'  => (string) ( $row['path'] ?? '' ),
				'views' => \sn_unmatched_visits_words( (array) $row ),
				'seen'  => \sn_unmatched_seen_words( (array) $row ),
			);
			if ( $people ) {
				$cell['status'] = \sn_unmatched_status_words( (array) $row );
				$cell['fix']    = \sn_unmatched_fix_words( (array) $row );
			}
			$out[] = $cell;
		}
		return $out;
	};
	$columns = array(
		array( 'key' => 'path', 'label' => __( 'Path', 'signal-and-noise-tools' ) ),
		array( 'key' => 'views', 'label' => __( 'Views', 'signal-and-noise-tools' ) ),
		array( 'key' => 'seen', 'label' => __( 'Seen', 'signal-and-noise-tools' ) ),
	);
	$people = (array) ( $report['people'] ?? array() );
	$body   = empty( $people )
		? '<p class="snt-prose">' . \snt_kit_esc( \sn_unmatched_headline( $report ) ) . '</p>'
		: \snt_kit_ledger(
			\sn_unmatched_headline( $report ),
			array_merge( $columns, array(
				array( 'key' => 'status', 'label' => __( 'Status', 'signal-and-noise-tools' ) ),
				array( 'key' => 'fix', 'label' => __( 'Suggested fix', 'signal-and-noise-tools' ) ),
			) ),
			$cells( $people, true ),
			TOOLS_REPORTS_ROWS,
			/* translators: %s: paths not listed. */
			tools_reports_ledger_opts( __( '+%s more paths; the list is capped, not complete.', 'signal-and-noise-tools' ) )
		);
	$automated = (array) ( $report['automated'] ?? array() );
	$inner     = empty( $automated )
		? '<p class="snt-hint">' . \snt_kit_esc( __( 'None.', 'signal-and-noise-tools' ) ) . '</p>'
		: \snt_kit_ledger(
			/* translators: %d: probe-shaped or bot-only paths. */
			sprintf( _n( '%d path only scanners or bots reached, kept apart so they never bury a real broken link.', '%d paths only scanners or bots reached, kept apart so they never bury a real broken link.', (int) ( $report['automated_total'] ?? 0 ), 'signal-and-noise-tools' ), (int) ( $report['automated_total'] ?? 0 ) ),
			$columns,
			$cells( $automated, false ),
			TOOLS_REPORTS_ROWS,
			/* translators: %s: paths not listed. */
			tools_reports_ledger_opts( __( '+%s more paths; the list is capped, not complete.', 'signal-and-noise-tools' ) )
		);
	return array( $body, \snt_kit_section( __( 'Scanners and bots', 'signal-and-noise-tools' ), $inner ) );
}
