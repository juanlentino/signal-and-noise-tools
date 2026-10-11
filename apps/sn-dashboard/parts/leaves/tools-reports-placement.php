<?php
/**
 * S&N Dashboard, Integrity > Reports: the contrast report's placement-dependent
 * pairings, mirroring the classic `sn_health_render_contrast_conditional()`
 * (inc/health-render-contrast-conditional.php).
 *
 * 2026-10-11 (owner rule: no collapsible sections): out of its fold into an
 * open section, a finding built from the rows (count, worst ratio) then a
 * capped ledger. The table used to stop at 25 rows with no line saying so,
 * and dropped the stylesheet each row came from; the cut is counted and the
 * stylesheet named now.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * Placement-dependent pairings: pass on the page background, would fail on
 * another surface. Not failures (a stylesheet cannot say where they land), so
 * the finding says "would" and the tile above paints no warning.
 *
 * @param array $conditional Rows from the usage scan, sorted worst-first.
 * @return string Empty when there is nothing to show.
 */
function tools_reports_contrast_conditional( array $conditional ) {
	if ( empty( $conditional ) ) {
		return '';
	}
	$rows = array();
	foreach ( $conditional as $row ) {
		$rows[] = array(
			'selector' => tools_reports_usage_selector( (array) $row ),
			'pair'     => (string) ( $row['pair'] ?? '' ),
			'ratio'    => number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) . ':1',
			'palette'  => (string) ( $row['palette'] ?? '' ),
		);
	}
	$count   = count( $conditional );
	$worst   = min( array_map( 'floatval', array_column( $conditional, 'ratio' ) ) );
	$finding = sprintf(
		/* translators: 1: placement-dependent pairing count, 2: the lowest ratio among them */
		_n( '%1$s pairing passes on the page background and would fail on another surface the design system paints; the worst would read %2$s:1.', '%1$s pairings pass on the page background and would fail on another surface the design system paints; the worst would read %2$s:1.', $count, 'signal-and-noise-tools' ),
		number_format_i18n( $count ),
		number_format( $worst, 2 )
	);
	return \snt_kit_section(
		__( 'Placement-dependent pairings', 'signal-and-noise-tools' ),
		\snt_kit_ledger(
			$finding,
			tools_reports_usage_columns( __( 'Would be', 'signal-and-noise-tools' ) ),
			$rows,
			defined( 'SN_HEALTH_CONTRAST_USAGE_MAX_ROWS' ) ? SN_HEALTH_CONTRAST_USAGE_MAX_ROWS : 25,
			/* translators: %s: placement-dependent pairings not listed. */
			array( 'more' => __( '+%s more placement-dependent pairings, sorted worst-first; the tail is the safe end.', 'signal-and-noise-tools' ) )
		),
		__( 'These components set no background of their own, so where they land decides whether they pass. They are not counted as failures, because a stylesheet cannot say where they land.', 'signal-and-noise-tools' )
	);
}
