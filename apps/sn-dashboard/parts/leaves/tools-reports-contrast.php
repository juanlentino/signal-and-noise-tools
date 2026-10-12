<?php
/**
 * S&N Dashboard, Integrity > Reports: the contrast report, mirroring the
 * classic `sn_health_render_contrast_report()` (inc/health-render-contrast.php).
 *
 * Split out of tools-reports-parts.php (line cap) on 2026-10-11, when its
 * three folds came out (owner rule: no collapsible sections). The card leads
 * with one tile per tier and the rendered-tier line; the usage tier, the
 * placement-dependent pairings and the hedged arithmetic tier follow as open
 * sections, in the classic order. Only the usage failing count may warn: the
 * arithmetic count misled as a headline three times (owner, 2026-08-11), so
 * it says "would", never "fails". Verdict pills are words per column and the
 * swatches are slug + hex chips (no inline style).
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A threshold ratio as the shortest honest decimal (4.5 stays "4.5", 3.0
 * becomes "3"), mirroring `sn_health_contrast_format_threshold()`.
 *
 * @param float $ratio Threshold.
 * @return string
 */
function tools_reports_threshold_label( $ratio ) {
	return rtrim( rtrim( number_format( (float) $ratio, 1, '.', '' ), '0' ), '.' );
}

/**
 * The contrast report: the card body, then the three tier sections.
 *
 * @param array $report The check's `report` payload.
 * @return string[] The card body first, then the open sections under it.
 */
function tools_reports_render_contrast( array $report ) {
	$usage = isset( $report['usage'] ) && is_array( $report['usage'] ) ? $report['usage'] : array();
	$pairs = isset( $report['pairs'] ) && is_array( $report['pairs'] ) ? $report['pairs'] : array();

	// The tile counts the pairs too, never the stored figure (a pre-2026.10.3
	// cache summed every palette and read "38 of 21").
	$card  = tools_reports_contrast_tiles( $usage, $pairs, \sn_health_contrast_would_fail( $pairs ) );
	$card .= function_exists( 'snt_contrast_rendered' ) ? snt_contrast_rendered_html( snt_contrast_rendered(), 'snt-hint' ) : '';

	return array(
		$card,
		tools_reports_contrast_usage( $usage ),
		tools_reports_contrast_conditional( isset( $usage['conditional'] ) && is_array( $usage['conditional'] ) ? $usage['conditional'] : array() ),
		tools_reports_contrast_arithmetic( $report, $pairs ),
	);
}

/**
 * The reading: one tile per tier. A tier that read nothing says "not measured", never 0.
 *
 * @param array $usage      The report's `usage` block.
 * @param array $pairs      The arithmetic pairs.
 * @param int   $would_fail Pairs that would fail body-text AA.
 * @return string
 */
function tools_reports_contrast_tiles( array $usage, array $pairs, $would_fail ) {
	$unread   = __( 'not measured', 'signal-and-noise-tools' );
	$no_sheet = __( 'no stylesheet was readable', 'signal-and-noise-tools' );
	$failing  = count( (array) ( $usage['failures'] ?? array() ) );
	$read     = 0 !== (int) ( $usage['scanned'] ?? 0 );
	return \snt_kit_grid(
		array(
			$read
				? \snt_kit_stat(
					number_format_i18n( $failing ),
					__( 'Failing pairings', 'signal-and-noise-tools' ),
					/* translators: %s: declared pairing count */
					sprintf( __( 'of %s declared, below body-text AA', 'signal-and-noise-tools' ), number_format_i18n( (int) ( $usage['pairings'] ?? 0 ) ) ),
					$failing > 0 ? 'warn' : 'ok'
				)
				: \snt_kit_stat( $unread, __( 'Failing pairings', 'signal-and-noise-tools' ), $no_sheet, 'neutral' ),
			$read
				? \snt_kit_stat( number_format_i18n( count( (array) ( $usage['conditional'] ?? array() ) ) ), __( 'Placement-dependent', 'signal-and-noise-tools' ), __( 'pass on the page, would fail elsewhere', 'signal-and-noise-tools' ) )
				: \snt_kit_stat( $unread, __( 'Placement-dependent', 'signal-and-noise-tools' ), $no_sheet, 'neutral' ),
			array() !== $pairs
				/* translators: %s: token pair count */
				? \snt_kit_stat( number_format_i18n( (int) $would_fail ), __( 'Would fail if paired', 'signal-and-noise-tools' ), sprintf( __( 'of %s token pairs, a palette property', 'signal-and-noise-tools' ), number_format_i18n( count( $pairs ) ) ) )
				: \snt_kit_stat( $unread, __( 'Would fail if paired', 'signal-and-noise-tools' ), __( 'no palette token was readable', 'signal-and-noise-tools' ), 'neutral' ),
		),
		160,
		10
	);
}

/**
 * The arithmetic tier: every token pair scored as if rendered together, hedged
 * because a pair may never meet on a page. Sorted worst-first, so the cap cuts
 * the safe tail and the "+N more" line names the floor.
 *
 * @param array $report The check's `report` payload.
 * @param array $pairs  The arithmetic pairs.
 * @return string
 */
function tools_reports_contrast_arithmetic( array $report, array $pairs ) {
	$heading = __( 'Arithmetic tier', 'signal-and-noise-tools' );
	$context = __( 'A property of the palette, not of the site: it moves only when a token changes.', 'signal-and-noise-tools' );
	if ( empty( $pairs ) ) {
		return \snt_kit_section( $heading, '<p class="snt-hint">' . \snt_kit_esc( __( 'No theme palette tokens were readable, so no pairs were scored. A theme.json palette (theme or custom origin) is what this check reads.', 'signal-and-noise-tools' ) ) . '</p>', $context );
	}
	$tokens     = isset( $report['tokens'] ) && is_array( $report['tokens'] ) ? $report['tokens'] : array();
	$thresholds = isset( $report['thresholds'] ) && is_array( $report['thresholds'] ) ? $report['thresholds'] : array();
	$body       = tools_reports_threshold_label( isset( $thresholds['aa_body'] ) ? (float) $thresholds['aa_body'] : 4.5 );
	$large      = tools_reports_threshold_label( isset( $thresholds['aa_large'] ) ? (float) $thresholds['aa_large'] : 3.0 );
	$cap        = TOOLS_REPORTS_ROWS;

	$rows = array();
	foreach ( $pairs as $row ) {
		$rows[] = array(
			'pair'  => (string) ( $row['pair'] ?? '' ),
			'ratio' => number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) . ':1',
			'body'  => ( ! empty( $row['aa_body'] ) ? __( 'Would pass', 'signal-and-noise-tools' ) : __( 'Would fail', 'signal-and-noise-tools' ) ) . ' ' . $body . ':1',
			'large' => ( ! empty( $row['aa_large'] ) ? __( 'Would pass', 'signal-and-noise-tools' ) : __( 'Would fail', 'signal-and-noise-tools' ) ) . ' ' . $large . ':1',
		);
	}
	$legend = '';
	foreach ( $tokens as $slug => $hex ) {
		$legend .= \snt_kit_chip( (string) $slug . ' ' . (string) $hex );
	}
	$floor = number_format( (float) ( $pairs[ min( $cap, count( $pairs ) ) - 1 ]['ratio'] ?? 0 ), 2 );

	$inner = '<p class="snt-prose">' . \snt_kit_esc(
		sprintf(
			/* translators: 1: failing pair count, 2: total pair count, 3: body AA ratio, 4: token count */
			__( '%1$d of %2$d token pairs would fall below %3$s:1 (body-text AA) if rendered together, across %4$d palette tokens.', 'signal-and-noise-tools' ),
			// Counted from the pairs it is printed against, never the stored
			// figure: a report cached before 2026.10.3 summed every palette and
			// read "38 of 21" until the next scan.
			\sn_health_contrast_would_fail( $pairs ),
			count( $pairs ),
			$body,
			count( $tokens )
		)
	) . '</p>' . ( '' !== $legend ? '<os-cluster gap="8">' . $legend . '</os-cluster>' : '' );

	$inner .= \snt_kit_ledger(
		'',
		array(
			array( 'key' => 'pair', 'label' => __( 'Token pair', 'signal-and-noise-tools' ) ),
			array( 'key' => 'ratio', 'label' => __( 'Ratio', 'signal-and-noise-tools' ), 'align' => 'end' ),
			array( 'key' => 'body', 'label' => __( 'Would pass AA (body)', 'signal-and-noise-tools' ) ),
			array( 'key' => 'large', 'label' => __( 'Would pass AA (large)', 'signal-and-noise-tools' ) ),
		),
		$rows,
		$cap,
		/* translators: %%s: pairs not listed (filled later), %s: the lowest ratio still shown */
		array( 'more' => sprintf( __( '+%%s more pairs, every one of them at %s:1 or better; the table is sorted worst-first, so the tail is the safe end.', 'signal-and-noise-tools' ), $floor ) )
	);
	return \snt_kit_section( $heading, $inner, $context );
}
