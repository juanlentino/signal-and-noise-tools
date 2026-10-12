<?php
/**
 * S&N Dashboard, Integrity > Reports: the contrast usage tier, mirroring the
 * classic `sn_health_render_contrast_usage()` (inc/health-render-contrast.php).
 *
 * Split out of tools-reports-parts.php (line cap) on 2026-10-11, when its
 * failing-pairings table came out of its fold (owner rule: no collapsible
 * sections): an open section, the headline as the finding, then a capped
 * ledger whose "+N more" line counts the cut.
 *
 * @package SignalNoiseTools
 */

namespace SignalNoise\OpenStationHost\Dashboard\Leaves;

if ( ! defined( 'ABSPATH' ) ) {
	defined( 'OPENSTATION_STANDALONE' ) || exit;
}

/**
 * A row's selector with the stylesheet it came from (the classic's source line).
 *
 * @param array $row Usage or conditional row.
 * @return string
 */
function tools_reports_usage_selector( array $row ) {
	$selector = (string) ( $row['selector'] ?? '' );
	return ! empty( $row['source'] ) ? $selector . ' (' . (string) $row['source'] . ')' : $selector;
}

/**
 * The columns both usage tables share; the Palette column only when the
 * rows span more than one palette (2026-10-12: "served (active style)"
 * wrapped to three lines in every row of a one-palette list).
 *
 * @param string $pair         Label of the pairing column.
 * @param bool   $with_palette Whether the rows' palettes differ.
 * @return array<int,array<string,string>>
 */
function tools_reports_usage_columns( $pair, $with_palette = true ) {
	$columns = array(
		array( 'key' => 'selector', 'label' => __( 'Selector', 'signal-and-noise-tools' ) ),
		array( 'key' => 'pair', 'label' => $pair ),
		array( 'key' => 'ratio', 'label' => __( 'Ratio', 'signal-and-noise-tools' ), 'align' => 'end' ),
	);
	if ( $with_palette ) {
		$columns[] = array( 'key' => 'palette', 'label' => __( 'Palette', 'signal-and-noise-tools' ) );
	}
	return $columns;
}

/**
 * The usage tier: pairings actually declared in stylesheets.
 *
 * @param array $usage The report's `usage` block.
 * @return string
 */
function tools_reports_contrast_usage( array $usage ) {
	$failures = isset( $usage['failures'] ) && is_array( $usage['failures'] ) ? $usage['failures'] : array();
	$palettes = isset( $usage['palettes'] ) && is_array( $usage['palettes'] ) ? $usage['palettes'] : array();
	$scanned  = (int) ( $usage['scanned'] ?? 0 );
	$declared = (int) ( $usage['pairings'] ?? 0 );
	$heading  = __( 'Usage tier', 'signal-and-noise-tools' );
	$context  = __( 'Pairings actually declared in stylesheets, scored under every palette the site serves.', 'signal-and-noise-tools' );

	if ( 0 === $scanned ) {
		return \snt_kit_section( $heading, '<p class="snt-hint">' . \snt_kit_esc( __( "No stylesheets were readable, so nothing was scored. This tier reads the plugin's front-end CSS and the active theme's.", 'signal-and-noise-tools' ) ) . '</p>', $context );
	}

	$inner = '<p class="snt-prose">' . \snt_kit_esc(
		empty( $failures )
			? sprintf(
				/* translators: 1: declared pairing count, 2: stylesheet count, 3: palette count */
				__( 'No declared pairing falls below AA: %1$s pairings across %2$d stylesheets, each scored under %3$d palette(s).', 'signal-and-noise-tools' ),
				number_format_i18n( $declared ),
				$scanned,
				count( $palettes )
			)
			: sprintf(
				/* translators: 1: failing count, 2: declared pairing count, 3: stylesheet count, 4: palette count */
				__( '%1$s of %2$s declared pairings fall below body-text AA, across %3$d stylesheets and %4$d palette(s).', 'signal-and-noise-tools' ),
				number_format_i18n( count( $failures ) ),
				number_format_i18n( $declared ),
				$scanned,
				count( $palettes )
			)
	) . '</p>';

	if ( ! empty( $palettes ) ) {
		$inner .= '<p class="snt-hint">' . \snt_kit_esc(
			sprintf(
				/* translators: %s: comma-separated palette names */
				__( 'Scored under: %s. The served palette is listed first: a pairing that passes at root and fails under a variation is still a live defect for whoever is being shown that variation.', 'signal-and-noise-tools' ),
				implode( ', ', array_map( 'strval', $palettes ) )
			)
		) . '</p>';
	}

	// The limits sit beside the headline: a reader who trusts a "0 failing"
	// count is exactly the reader who did not scroll up to the caveat.
	$inner .= '<p class="snt-hint">' . \snt_kit_esc( \sn_health_contrast_usage_limits() ) . '</p>';

	if ( ! empty( $failures ) ) {
		$rows = array();
		foreach ( $failures as $row ) {
			$pair = (string) ( $row['pair'] ?? '' );
			if ( ! empty( $row['literal'] ) ) {
				$pair .= ' [' . __( 'hardcoded', 'signal-and-noise-tools' ) . ']';
			}
			if ( empty( $row['anchored'] ) ) {
				$pair .= ' [' . __( 'on page background', 'signal-and-noise-tools' ) . ']';
			}
			$rows[] = array(
				'selector' => tools_reports_usage_selector( $row ),
				'pair'     => $pair,
				'ratio'    => number_format( (float) ( $row['ratio'] ?? 0 ), 2 ) . ':1',
				'palette'  => (string) ( $row['palette'] ?? '' ),
			);
		}
		$one    = tools_reports_one_palette( $failures );
		$inner .= \snt_kit_ledger(
			/* translators: %s: the one palette every listed pairing is scored under. */
			'' !== $one ? sprintf( __( 'Every failing pairing is scored under the %s palette.', 'signal-and-noise-tools' ), $one ) : '',
			tools_reports_usage_columns( __( 'Pairing', 'signal-and-noise-tools' ), '' === $one ),
			$rows,
			TOOLS_REPORTS_ROWS,
			/* translators: %s: failing pairings not listed. */
			tools_reports_ledger_opts( __( '+%s more failing pairings, sorted worst-first; the tail is the safe end.', 'signal-and-noise-tools' ) )
		);
	}
	return \snt_kit_section( $heading, $inner, $context );
}
